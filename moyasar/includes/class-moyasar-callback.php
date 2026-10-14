<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The Callback class.
 *
 * @since      8.0.0
 * @package    Moyasar
 * @subpackage Moyasar/includes
 */
class Moyasar_Callback {

    public function __construct() {
        add_action( 'woocommerce_api_moyasar_callback', array( $this, 'handle_callback' ) );
    }

    public function handle_callback() {
        $order_id   = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;
        $status     = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
        $message    = isset( $_GET['message'] ) ? sanitize_text_field( wp_unslash( $_GET['message'] ) ) : '';
        $mode       = isset( $_GET['mode'] ) ? sanitize_text_field( wp_unslash( $_GET['mode'] ) ) : '';
        $is_invoice = 'invoice' === $mode;

        $payment = array();

        Moyasar_Helper::log( 'Callback received: Order ID: ' . $order_id . ' - Status: ' . $status . ' - Message: ' . $message . ' - Is Invoice: ' . ( $is_invoice ? 'yes' : 'no' ), 'debug' );

        if ( ! $order_id ) {
            wp_die( 'Invalid Request', 'Moyasar', array( 'response' => 400 ) );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_die( 'Invalid Order', 'Moyasar', array( 'response' => 404 ) );
        }

        // Dynamically get the configured success and authorized statuses to check against webhook updates
        $success_status    = Moyasar_Helper::get_moyasar_option( 'order_status_success', 'processing' );
        $success_status    = str_replace( 'wc-', '', $success_status );
        $authorized_status = Moyasar_Helper::get_moyasar_option( 'order_status_authorized', 'wc-authorized' );
        $authorized_status = str_replace( 'wc-', '', $authorized_status );
        $paid_statuses     = array_unique( array( 'processing', 'completed', 'authorized', $authorized_status, $success_status ) );

        // SEC-3 Fix: Validate order key to prevent Order ID enumeration
        $key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
        if ( empty( $key ) || ! $order->key_is_valid( $key ) ) {
            wp_die( 'Unauthorized Request', 'Moyasar', array( 'response' => 403 ) );
        }

        // Fetch payment logic to verify
        $payment_id = isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( $_GET['id'] ) ) : '';

        if ( $is_invoice ) {
            $invoice_id = $order->get_meta( '_moyasar_invoice_id' );
            $invoice    = Moyasar_API::fetch_invoice( $invoice_id );
            if ( is_wp_error( $invoice ) ) {
                Moyasar_Helper::log( 'Callback: Invoice fetch failed: ' . $invoice->get_error_message(), 'error' );
                $fresh = wc_get_order( $order_id );
                $redirect_url = $fresh ? $this->get_return_url( $fresh ) : '';
                if ( $fresh && $fresh->has_status( $paid_statuses ) ) {
                    if ( 'modal' === $mode ) {
                        echo '<script data-cfasync="false">window.parent.moyasarComplete("' . esc_url_raw( $redirect_url ) . '");</script>';
                        exit;
                    } else {
                        wp_redirect( $redirect_url );
                        exit;
                    }
                }

                if ( 'paid' === $status && $fresh ) {
                    if ( 'modal' === $mode ) {
                        echo '<script data-cfasync="false">window.parent.moyasarComplete("' . esc_url_raw( $redirect_url ) . '");</script>';
                        exit;
                    } else {
                        wp_redirect( $redirect_url );
                        exit;
                    }
                }

                $status  = 'failed';
                $message = $invoice->get_error_message();
            } else {
                $payment_id = $invoice['payments'][0]['id'];
            }
        }

        // If payment_id is not set, try to get it from the order meta
        if ( ! $payment_id ) {
            $payment_id = $order->get_meta( '_moyasar_payment_id' );
            Moyasar_Helper::log( 'Payment ID not found in callback. Using order meta: ' . $payment_id, 'debug' );
        }

        if ( $payment_id ) {
            $payment = Moyasar_API::fetch_payment( $payment_id );
            if ( 'yes' === Moyasar_Helper::get_moyasar_option( 'testmode' ) ) {
                Moyasar_Helper::log( 'Payment fetched: ' . $payment_id . ' - ' . wp_json_encode( $payment ), 'debug' );
            }

            if ( is_wp_error( $payment ) ) {
                $api_error = $payment->get_error_message();
                Moyasar_Helper::log( 'Callback: Payment fetch failed for ' . $payment_id . ': ' . $api_error, 'error' );

                // Reload order in case a concurrent webhook already marked it paid.
                $order = wc_get_order( $order_id );
                $redirect_url = $order ? $this->get_return_url( $order ) : '';
                if ( $order && $order->has_status( $paid_statuses ) ) {
                    if ( 'modal' === $mode ) {
                        echo '<script data-cfasync="false">window.parent.moyasarComplete("' . esc_url_raw( $redirect_url ) . '");</script>';
                        exit;
                    } else {
                        wp_redirect( $redirect_url );
                        exit;
                    }
                }

                // If the GET parameter says 'paid' but the API failed to verify, redirect them
                // to the thank-you page but do NOT mark the order as paid locally.
                // The webhook will securely mark it as paid once it arrives.
                if ( 'paid' === $status && $order ) {
                    if ( 'modal' === $mode ) {
                        echo '<script data-cfasync="false">window.parent.moyasarComplete("' . esc_url_raw( $redirect_url ) . '");</script>';
                        exit;
                    } else {
                        wp_redirect( $redirect_url );
                        exit;
                    }
                }

                $status  = 'failed';
                $message = $api_error;
                $payment = array( 'message' => $message );
            } else {
                // Determine status from API response, NOT GET param for security    
                $status = isset( $payment['status'] ) ? $payment['status'] : $status;
                
                // Update payment ID in order
                $order->set_transaction_id( $payment_id );
                $order->update_meta_data( '_moyasar_payment_id', $payment_id );
                $order->save();
            }
        } else {
            // Require Payment ID for validation    
            $status = 'failed';
            Moyasar_Helper::log('Payment ID missing in callback.', 'debug');
            $message = __( 'Payment ID missing in callback.', 'moyasar-payments' );
            $payment = array( 'message' => $message );
        }

        // Check if this is a modal request
        $mode = isset( $_GET['mode'] ) ? sanitize_text_field( wp_unslash( $_GET['mode'] ) ) : '';

        if ( 'paid' === $status || 'authorized' === $status ) {
            // Use Central Helper
            if ( 'authorized' === $status ) {
                Moyasar_Helper::handle_payment_authorized( $order, $payment, 'callback' );
            } else {
                Moyasar_Helper::handle_payment_success( $order, $payment, 'callback' );
            }

            $redirect_url = $this->get_return_url( $order );

            if ( 'modal' === $mode ) {
                echo '<script data-cfasync="false">window.parent.moyasarComplete("' . esc_url_raw( $redirect_url ) . '");</script>';
                exit;
            } else {
                wp_redirect( $redirect_url );
                exit;
            }
        } else {
            // Reload to get the latest DB state — the webhook may have set the order
            // to 'processing' between our initial wc_get_order() call and here.
            $order = wc_get_order( $order_id );
            Moyasar_Helper::handle_payment_failed( $order, $payment, 'callback' );

            if ( isset( WC()->session ) ) {
                WC()->session->set( 'order_awaiting_payment', $order_id );
            }
            
            $redirect_url = wc_get_checkout_url();

            // Guarantee a reason for the shopper. A failed 3DS often arrives with an
            // empty GET message even though the payment object carries the real decline
            // reason, so fall back to that (matching the order note produced by
            // handle_payment_failed) and finally to a generic message — a failed 3DS
            // must never reach the shopper with no feedback at all.
            if ( '' === trim( (string) $message ) ) {
                // Sanitize: these come from the external gateway response and are
                // ultimately rendered into the checkout DOM on the frontend, so strip
                // any tags to avoid an XSS vector via an unexpected gateway message.
                if ( is_array( $payment ) && ! empty( $payment['source']['message'] ) ) {
                    $message = sanitize_text_field( (string) $payment['source']['message'] );
                } elseif ( is_array( $payment ) && ! empty( $payment['message'] ) ) {
                    $message = sanitize_text_field( (string) $payment['message'] );
                } else {
                    $message = __( 'Payment failed. Please try again.', 'moyasar-payments' );
                }
            }

            if ( 'modal' === $mode ) {
                 // Clean the message for JS
                 $clean_message = esc_js( $message );
                 echo '<script data-cfasync="false">window.parent.moyasarComplete("", "' . $clean_message . '");</script>';
                 exit;
            } else {
                wp_redirect( $redirect_url );
                exit;
            }
        }
    }

    public function get_return_url( $order ) {
        return $order->get_checkout_order_received_url();
    }

}
