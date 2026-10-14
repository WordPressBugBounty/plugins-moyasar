<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Moyasar STC Pay Gateway.
 *
 * @class       WC_Gateway_Moyasar_STC_Pay
 * @extends     WC_Payment_Gateway
 * @package     Moyasar/includes/gateways
 */
class WC_Gateway_Moyasar_STC_Pay extends WC_Payment_Gateway {

    use Moyasar_Gateway_Trait;

    public function __construct() {
        $this->id                 = 'moyasar_stc_pay';
        $this->method_title       = __( 'STC Pay', 'moyasar-payments' );
        $this->method_description = __( 'Accept payments via STC Pay.', 'moyasar-payments' );
        $this->has_fields         = true;
        $this->supports           = array(
            'products',
            'refunds',
            'block_checkout',
        );
        $this->moyasar_icons = array( 'stc_bank' );

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = Moyasar_Helper::get_moyasar_option( 'moyasar_stc_pay_title', __( 'STC Pay', 'moyasar-payments' ) );
        $this->description = Moyasar_Helper::get_moyasar_option( 'moyasar_stc_pay_description', __( 'Pay with STC Pay', 'moyasar-payments' ) );
        $this->enabled     = $this->get_option( 'enabled' );

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_filter( 'woocommerce_gateway_icon', array( $this, 'filter_gateway_icon' ), 10, 2 );

        add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );

        add_action( 'wp_ajax_moyasar_stc_pay_init', array( $this, 'ajax_init_payment' ) );
        add_action( 'wp_ajax_nopriv_moyasar_stc_pay_init', array( $this, 'ajax_init_payment' ) );
        
        add_action( 'wp_ajax_moyasar_stc_pay_confirm', array( $this, 'ajax_confirm_payment' ) );
        add_action( 'wp_ajax_nopriv_moyasar_stc_pay_confirm', array( $this, 'ajax_confirm_payment' ) );
    }

    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => __( 'Enable/Disable', 'moyasar-payments' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable STC Pay', 'moyasar-payments' ),
                'default' => 'no',
                'description' => sprintf( __( 'You can also manage this from the <a href="%s">Main Moyasar Settings</a>.', 'moyasar-payments' ), admin_url( 'admin.php?page=wc-settings&tab=checkout&section=moyasar_cc' ) ),
            ),
            'global_settings_info' => array(
                'title'       => __( 'API Configuration', 'moyasar-payments' ),
                'type'        => 'title',
                'description' => sprintf( __( 'API Keys and Webhook settings are now managed in the <a href="%s">Main Moyasar Settings</a>.', 'moyasar-payments' ), admin_url( 'admin.php?page=wc-settings&tab=checkout&section=moyasar_cc' ) ),
            ),
        );
    }

    public function payment_scripts() {
        if ( ! is_checkout() && ! isset( $_GET['pay_for_order'] ) && ! is_add_payment_method_page() ) {
            return;
        }

        if ( 'no' === $this->enabled ) {
            return;
        }

        wp_register_script( 'moyasar_stc_pay', MOYASAR_WC_PLUGIN_URL . 'assets/js/moyasar-stc-pay.js', array( 'jquery' ), MOYASAR_WC_VERSION, true );
        
        wp_localize_script( 'moyasar_stc_pay', 'moyasar_stc_params', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'init_nonce' => wp_create_nonce( 'moyasar_stc_init' ),
            'confirm_nonce' => wp_create_nonce( 'moyasar_stc_confirm' ),
            'strings' => array(
                'error_invalid_mobile'   => __( 'Please enter a valid STC Pay mobile number (05xxxxxxxx).', 'moyasar-payments' ),
                'error_payment_failed'   => __( 'Payment verification failed. Please try again.', 'moyasar-payments' ),
                'processing_payment'     => __( 'Processing Payment...', 'moyasar-payments' ),
                'stc_pay_verification'   => __( 'STC Pay Verification', 'moyasar-payments' ),
                'enter_otp_message'      => __( 'Please enter the OTP sent to your mobile number.', 'moyasar-payments' ),
                'enter_otp_placeholder'  => __( 'Enter OTP', 'moyasar-payments' ),
                'confirm_payment_btn'    => __( 'Confirm Payment', 'moyasar-payments' ),
                'otp_required'           => __( 'Please enter the OTP.', 'moyasar-payments' ),
                'verification_failed'    => __( 'Verification failed.', 'moyasar-payments' ),
                'verification_cancelled' => __( 'Payment verification cancelled. Please try again.', 'moyasar-payments' ),
            )
        ));

        wp_enqueue_script( 'moyasar_stc_pay' );
    }

    public function payment_fields() {
        if ( $this->description ) {
            echo wp_kses_post( wpautop( wptexturize( $this->description ) ) );
        }
        
        ?>
        <fieldset id="wc-moyasar-stc-pay-form" class="wc-payment-form">
            <div class="form-row form-row-wide">
                <label><?php esc_html_e( 'Mobile Number', 'moyasar-payments' ); ?> <span class="required">*</span></label>
                <input id="moyasar_stc_pay_mobile" name="moyasar_stc_pay_mobile" type="text" autocomplete="off" placeholder="05xxxxxxxx">
            </div>
            <div class="clear"></div>
        </fieldset>
        <?php
    }

    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );
        Moyasar_Helper::log( "STC Pay: Processing Payment for Order #$order_id", 'debug' );

        $mobile = isset( $_POST['moyasar_stc_pay_mobile'] ) 
            ? wc_clean( $_POST['moyasar_stc_pay_mobile'] ) 
            : '';

        if ( ! empty( $mobile ) ) {
            // Remove spaces and dashes
            $mobile = preg_replace( '/[\s\-]/', '', $mobile );

            // Normalize to 05xxxxxxxxx
            if ( strpos( $mobile, '+966' ) === 0 ) {
                $mobile = '0' . substr( $mobile, 4 );
            } elseif ( strpos( $mobile, '00966' ) === 0 ) {
                $mobile = '0' . substr( $mobile, 5 );
            } elseif ( strpos( $mobile, '966' ) === 0 ) {
                $mobile = '0' . substr( $mobile, 3 );
            } elseif ( strpos( $mobile, '5' ) === 0 ) {
                $mobile = '0' . $mobile;
            }
        }

        // Final validation
        if ( empty( $mobile ) || ! preg_match( '/^05\d{8}$/', $mobile ) ) {
            Moyasar_Helper::log( "STC Pay Error: Invalid Mobile Number ($mobile)", 'error' );
            wc_add_notice( __( 'Please enter a valid STC Pay mobile number (05xxxxxxxx)', 'moyasar-payments' ), 'error' );
            return array(
                'result'   => 'failure',
                'messages' => __( 'Please enter a valid STC Pay mobile number (05xxxxxxxx)', 'moyasar-payments' ),
                'message'  => __( 'Please enter a valid STC Pay mobile number (05xxxxxxxx)', 'moyasar-payments' ),
            );
        }

        // 1. Initiate Payment
        $amount = Moyasar_Helper::to_minor_units( $order->get_total(), $order->get_currency() );
        $currency = $order->get_currency();
        $description = 'Order #' . $order->get_id();

        $payment_data = array(
            'amount' => $amount,
            'currency' => $currency,
            'description' => $description,
            'callback_url' => $this->get_return_url( $order ),
            'source' => array(
                'type' => 'stcpay',
                'mobile' => $mobile
            ),
            'metadata' => $this->get_payment_metadata( $order ),
        );

        Moyasar_Helper::log( "STC Pay: Sending API Request (Initiate). Mobile: $mobile", 'debug' );
        $response = Moyasar_API::create_payment( $payment_data, $this->get_api_key() );

        if ( is_wp_error( $response ) ) {
            Moyasar_Helper::log( "STC Pay Error: Initiation Failed: " . $response->get_error_message(), 'error' );
            wc_add_notice( $response->get_error_message(), 'error' );
            return array(
                'result'   => 'failure',
                'messages' => $response->get_error_message(),
                'message'  => $response->get_error_message(),
            );
        }

        if ( ! isset( $response['id'] ) || ! isset( $response['source']['transaction_url'] ) ) {
            Moyasar_Helper::log( "STC Pay Error: Invalid API Response (ID or URL missing)", 'error' );
            wc_add_notice( __( 'Payment initiation failed.', 'moyasar-payments' ), 'error' );
            return array(
                'result'   => 'failure',
                'messages' => __( 'Payment initiation failed.', 'moyasar-payments' ),
                'message'  => __( 'Payment initiation failed.', 'moyasar-payments' ),
            );
        }

        $payment_id = $response['id'];
        $transaction_url = $response['source']['transaction_url'];
        
        Moyasar_Helper::log( "STC Pay: Initiated Successfully. ID: $payment_id. Waiting for OTP.", 'debug' );

        // 2. Log & Save
        $order->add_order_note( sprintf( __( 'STC Pay Payment Initiated. ID: %s. OTP sent to %s.', 'moyasar-payments' ), $payment_id, $mobile ) );
        $order->update_meta_data( '_moyasar_stc_payment_id', $payment_id );
        $order->update_meta_data( '_moyasar_stc_transaction_url', $transaction_url );
        $order->update_status( 'pending', __( 'Awaiting STC Pay OTP verification.', 'moyasar-payments' ) );
        $order->save();

        // Block (Store API) checkout: return the payment id so the React block
        // can collect the OTP and confirm via the ajax_confirm_payment endpoint.
        if ( isset( $_POST['mysr_form'] ) && 'blocks' === sanitize_text_field( wp_unslash( $_POST['mysr_form'] ) ) ) {
            // The block's onCheckoutSuccess handler intercepts (via moyasar_stc_otp)
            // and holds the flow open for OTP entry, so this redirect only fires as a
            // safety net if the OTP step is somehow skipped — back to checkout, never
            // a false "order received" for an unverified payment.
            return array(
                'result'                 => 'success',
                'redirect'               => wc_get_checkout_url(),
                'moyasar_stc_otp'        => 'yes',
                'moyasar_stc_payment_id' => $payment_id,
            );
        }

        // 3. Return Hash Redirect to trigger Client-Side Modal (classic checkout)
        return array(
            'result'   => 'success',
            'redirect' => '#moyasar-stc-verify:' . $payment_id . ':' . base64_encode( $transaction_url )
        );
    }

    /**
     * AJAX: Confirm OTP and Finalize Order
     */
    public function ajax_confirm_payment() {
        check_ajax_referer( 'moyasar_stc_confirm', 'nonce' );

        $payment_id = isset( $_POST['payment_id'] ) ? wc_clean( $_POST['payment_id'] ) : '';
        $otp = isset( $_POST['otp'] ) ? wc_clean( $_POST['otp'] ) : '';

        Moyasar_Helper::log( "STC Pay: Confirming OTP for Payment ID: $payment_id", 'debug' );

        if ( empty( $payment_id ) || empty( $otp ) ) {
            Moyasar_Helper::log( "STC Pay Error: Missing confirmation data (Payment ID or OTP)", 'error' );
            wp_send_json_error( array( 'message' => 'Missing data.' ) );
        }

        // Retrieve order server-side to prevent parameter tampering & SSRF
        $order = false;
        $orders = wc_get_orders( array(
            'meta_key'   => '_moyasar_stc_payment_id',
            'meta_value' => $payment_id,
            'limit'      => 1,
            'return'     => 'ids'
        ) );
        if ( ! empty( $orders ) ) {
            $order = wc_get_order( $orders[0] );
        }

        if ( ! $order ) {
            $order = Moyasar_Helper::get_order_from_payment_id( $payment_id );
        }

        if ( ! $order ) {
            Moyasar_Helper::log( "STC Pay Error: Order not found for Payment ID $payment_id", 'error' );
            wp_send_json_error( array( 'message' => 'Order not found for payment.' ) );
        }

        // SEC-8: Verify order ownership if user is logged in
        if ( is_user_logged_in() && $order->get_customer_id() > 0 && $order->get_customer_id() !== get_current_user_id() ) {
            Moyasar_Helper::log( "STC Pay Error: Order ownership mismatch for Order #" . $order->get_id(), 'error' );
            wp_send_json_error( array( 'message' => 'Unauthorized request.' ) );
        }

        // SEC-1: Retrieve transaction URL from metadata
        $transaction_url = $order->get_meta( '_moyasar_stc_transaction_url' );
        if ( empty( $transaction_url ) ) {
            Moyasar_Helper::log( "STC Pay Error: Transaction URL not found in order metadata.", 'error' );
            wp_send_json_error( array( 'message' => 'Transaction URL not found.' ) );
        }

        // SEC-1: Validate transaction URL starts with https://api.moyasar.com/
        if ( strpos( $transaction_url, Moyasar_API::get_base_url() ) !== 0 ) {
            Moyasar_Helper::log( "STC Pay Error: Invalid transaction URL host ($transaction_url)", 'error' );
            wp_send_json_error( array( 'message' => 'Invalid transaction URL.' ) );
        }

        // Verify with Moyasar
        Moyasar_Helper::log( "STC Pay: Sending OTP to $transaction_url", 'debug' );
        $response = wp_remote_post( $transaction_url, array(
            'body' => wp_json_encode( array( 'otp_value' => $otp ) ),
            'headers' => array( 'Content-Type' => 'application/json' ),
            'timeout' => 45
        ));

        if ( is_wp_error( $response ) ) {
            Moyasar_Helper::log( "STC Pay Error: OTP Verification Request Failed: " . $response->get_error_message(), 'error' );
            wp_send_json_error( array( 'message' => $response->get_error_message() ) );
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );
        
        Moyasar_Helper::log( "STC Pay: OTP Response: " . substr($body, 0, 500), 'debug' );

        if ( isset( $data['status'] ) && 'paid' === $data['status'] ) {
            Moyasar_Helper::log( "STC Pay: OTP Verified. Status is PAID.", 'debug' );
            Moyasar_Helper::handle_payment_success( $order, $data, 'stc_pay' );
            wp_send_json_success( array( 
                'message' => 'Paid',
                'redirect_url' => $this->get_return_url( $order )
            ) );
        } else {
            $msg = isset( $data['message'] ) ? $data['message'] : 'OTP Verification Failed';
            Moyasar_Helper::log( "STC Pay Error: OTP Verification Failed. Msg: $msg", 'error' );
            wp_send_json_error( array( 'message' => $msg ) );
        }
    }
}
