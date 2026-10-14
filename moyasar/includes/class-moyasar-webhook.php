<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The Webhook class.
 *
 * @since      8.0.0
 * @package    Moyasar
 * @subpackage Moyasar/includes
 */
class Moyasar_Webhook {

    public function __construct() {
        add_action( 'woocommerce_api_moyasar_webhook', array( $this, 'handle_webhook' ) );
    }

    public function handle_webhook() {
        $payload = file_get_contents( 'php://input' );
        $event   = json_decode( $payload, true );

        // Validate payload structure before using any keys.
        if ( ! is_array( $event ) 
            || ! isset( $event['secret_token'] ) || ! is_string( $event['secret_token'] )
            || ! isset( $event['id'] ) || ! is_string( $event['id'] )
            || ! isset( $event['type'] ) || ! is_string( $event['type'] )
            || ! isset( $event['data'] ) || ! is_array( $event['data'] ) 
        ) {
            status_header( 400 );
            exit( 'Invalid payload structure' );
        }

        $data = $event['data'];
        if ( ! isset( $data['id'] ) || ! is_string( $data['id'] )
            || ! isset( $data['status'] ) || ! is_string( $data['status'] )
            || ! isset( $data['amount'] ) || ! is_numeric( $data['amount'] ) 
        ) {
            status_header( 400 );
            exit( 'Invalid payload data' );
        }

        $webhook_secret = Moyasar_Helper::get_moyasar_option( 'webhook_secret', '' );
        $received_secret = isset( $event['secret_token'] ) ? sanitize_text_field( wp_unslash( $event['secret_token'] ) ) : '';
        if ( ! $this->secureCompare( (string) $webhook_secret, (string) $received_secret ) ) {
            Moyasar_Helper::log( 'Webhook received with invalid secret', 'error' );
            status_header( 401 );
            header( 'Content-Type: application/json' );
            echo wp_json_encode( array(
                'success' => false,
                'message' => 'Webhook received with invalid secret',
            ) );
            exit;
        }

        // Webhook event deduplication (SEC-7 replay protection)
        $event_id = sanitize_key( $event['id'] );
        $transient_key = 'moyasar_webhook_event_' . $event_id;
        if ( get_transient( $transient_key ) ) {
            Moyasar_Helper::log( "Duplicate webhook event detected and ignored: " . $event_id, 'info' );
            status_header( 200 );
            exit( 'Duplicate event' );
        }
        set_transient( $transient_key, 'yes', 86400 ); // 24 hours

        // Log Raw Payload if in test mode
        if ( 'yes' === Moyasar_Helper::get_moyasar_option( 'testmode' ) ) {
            Moyasar_Helper::log( 'Webhook Payload JSON: ' . $payload, 'debug' );
        }

        $payment_id = $data['id'];
        $status     = $data['status'];
        $amount     = $data['amount'];
        $message    = ( isset( $data['source'] ) && is_array( $data['source'] ) && isset( $data['source']['message'] ) ) ? $data['source']['message'] : '';
        $company    = ( isset( $data['source'] ) && is_array( $data['source'] ) && isset( $data['source']['company'] ) ) ? $data['source']['company'] : 'Unknown';

        if ( ! empty( $data['invoice_id'] ) ) {
            $invoice = Moyasar_API::fetch_invoice( $data['invoice_id'] );
            if ( is_wp_error( $invoice ) ) {
                status_header( 200 );
                exit( 'Invoice not found for Payment ID: ' . $payment_id );
            }
            $payment_id = $invoice['payments'][0]['id'];
            $order = wc_get_order( $invoice['metadata']['order_id'] ?? 0 );
        } else {
            $order    = false;
            $metadata = ( isset( $data['metadata'] ) && is_array( $data['metadata'] ) ) ? $data['metadata'] : array();

            // Prefer the order_id embedded in the payment metadata. It is
            // authoritative: the plugin sets it (order_id + wc_order_key) when the
            // payment is created, and Moyasar echoes it back on the webhook. Resolving
            // by stored payment id is only a fallback for legacy payments that predate
            // metadata support.
            if ( ! empty( $metadata['order_id'] ) ) {
                $candidate = wc_get_order( absint( $metadata['order_id'] ) );

                // Guard against a mismatched/tampered metadata order_id: if the
                // metadata also carries the order key, it must match the loaded order.
                if ( $candidate && ! empty( $metadata['wc_order_key'] ) ) {
                    if ( (string) $candidate->get_order_key() === (string) $metadata['wc_order_key'] ) {
                        $order = $candidate;
                    } else {
                        Moyasar_Helper::log( "Webhook: metadata order_id " . absint( $metadata['order_id'] ) . " does not match wc_order_key for Payment ID $payment_id. Ignoring metadata order_id.", 'error' );
                    }
                } elseif ( $candidate ) {
                    $order = $candidate;
                }
            }

            if ( ! $order ) {
                $order = Moyasar_Helper::get_order_from_payment_id( $payment_id );
            }
        }
        
        
        
        if ( ! $order ) {
            // Log warning but return 200 to acknowledge
            Moyasar_Helper::log( "Webhook Error: Order not found for Payment ID $payment_id (Invoice ID: " . ($data['invoice_id'] ?? 'N/A') . ")", 'error' );
            status_header( 200 );
            exit( 'Order not found for Payment ID: ' . $payment_id );
        }

        // For refund events, wc_get_order() can resolve a WC_Order_Refund object
        // (e.g. when the payment id is stored on the refund's meta). Refund objects
        // do not implement update_status(), so normalize to the parent WC_Order.
        if ( $order instanceof WC_Order_Refund ) {
            $parent_id = $order->get_parent_id();
            $order     = $parent_id ? wc_get_order( $parent_id ) : false;

            if ( ! $order ) {
                Moyasar_Helper::log( "Webhook Error: Resolved a refund object but could not load its parent order for Payment ID $payment_id", 'error' );
                status_header( 200 );
                exit( 'Parent order not found for refund of Payment ID: ' . $payment_id );
            }
        }

        Moyasar_Helper::log( "Webhook: Order #" . $order->get_id() . " found for Payment ID $payment_id", 'debug' );

        // Detailed Log Message Construction
        $log_message = sprintf(
            "Moyasar Webhook Event: %s\nPayment ID: %s\nStatus: %s\nAmount: %s %s\nSource: %s\nMessage: %s",
            $event['type'],
            $payment_id,
            $status,
            Moyasar_Helper::from_minor_units( $amount, $order->get_currency() ),
            $data['currency'] ?? '',
            $company,
            $message
        );

        // Fetch Gateway Settings for Status Mapping
        $status_paid     = Moyasar_Helper::get_moyasar_option('order_status_success', 'processing');
        $status_failed   = Moyasar_Helper::get_moyasar_option('order_status_failed', 'failed');
        $status_refunded = Moyasar_Helper::get_moyasar_option('order_status_refunded', 'refunded');
        $status_voided   = Moyasar_Helper::get_moyasar_option('order_status_voided', 'cancelled');
        
        // Normalize event type (handle both payment.paid and payment_paid just in case, but prioritize user request)
        $type = str_replace( '.', '_', strtolower( $event['type'] ) );

        // Idempotency check for Paid/Captured/Failed events
        if ( ( 'payment_paid' === $type || 'paid' === $type || 'payment_captured' === $type || 'captured' === $type ) && $order->has_status( array( 'processing', 'completed' ) ) && 'captured' === $order->get_meta( '_moyasar_payment_status' ) ) {
            $order->add_order_note( "Webhook: Received '$type' event, but order is already complete and captured. ID: $payment_id" );
            exit( 'Order already processed' );
        }
        
        if ( 'payment_failed' === $type && $order->has_status( 'failed' ) ) {
             exit( 'Order already failed' );
        }

        // Switch based on Event Type
        switch ( $type ) {
            case 'paid':
            case 'payment_paid':
                Moyasar_Helper::handle_payment_success( $order, $data, 'webhook' );
                break;

            case 'authorized':
            case 'payment_authorized':
                Moyasar_Helper::handle_payment_authorized( $order, $data, 'webhook' );
                break;

            case 'captured':
            case 'payment_captured':
                Moyasar_Helper::handle_payment_captured( $order, $data, 'webhook' );
                break;
                
            case 'payment_failed':
                Moyasar_Helper::handle_payment_failed( $order, $data, 'webhook' );
                break;
                
            case 'payment_refunded':
                // Check if full refund
                // Note: Payload key is 'refunded', not 'amount_refunded' based on user sample
                $refunded_amount = isset( $data['refunded'] ) ? intval( $data['refunded'] ) : ( isset( $data['amount_refunded'] ) ? intval( $data['amount_refunded'] ) : 0 );
                $total_amount = isset( $data['amount'] ) ? intval( $data['amount'] ) : 0;
                
                // Log debug info
                $debug_info = "Total: $total_amount, Refunded: $refunded_amount";
                Moyasar_Helper::log( "Webhook Refund Check: $debug_info", 'debug' );
            
                
                // If amount_refunded equals or exceeds total amount
                if ( $refunded_amount >= $total_amount && $total_amount > 0 ) {
                    $order->update_status( $status_refunded, "↩ Payment Refunded by Moyasar Dashboard (Webhook).\n" . $log_message );
                    Moyasar_Helper::log( "Order status updated to $status_refunded", 'info' );
                } else {
                    $refunded_fmt = Moyasar_Helper::from_minor_units( $refunded_amount, $order->get_currency() );
                    $order->add_order_note( "↩ Partial Refund by Moyasar Dashboard (Webhook)(Status not changed).\n" . $log_message . "\nRefunded Amount in this event: " . $refunded_fmt . "\n(Debug: $debug_info, Status: $status)" );
                    Moyasar_Helper::log( "Partial refund note added. $debug_info, Status: $status", 'info' );
                }
                break;
                
            case 'payment_voided':
                $order->update_status( $status_voided, "🚫 Payment Voided (Webhook).\n" . $log_message );
                break;
                
            default:
                // Log unhandled event
                Moyasar_Helper::log( "Webhook: Unhandled event type '$type' for Order #" . $order->get_id(), 'debug' );
                break;
        }

        status_header( 200 );
        exit( 'Webhook processed' );
    }

    /**
     * Timing-safe comparison of two strings.
     *
     * @param string $str1 First string.
     * @param string $str2 Second string.
     * @return bool True if equal.
     */
    private function secureCompare( $str1, $str2 ) {
        return hash_equals( (string) $str1, (string) $str2 );
    }
}
new Moyasar_Webhook();
