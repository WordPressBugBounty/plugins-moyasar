<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Moyasar Samsung Pay Gateway.
 *
 * @class       WC_Gateway_Moyasar_Samsung_Pay
 * @extends     WC_Payment_Gateway
 * @package     Moyasar/includes/gateways
 */
class WC_Gateway_Moyasar_Samsung_Pay extends WC_Payment_Gateway {

    use Moyasar_Gateway_Trait;

    public function __construct() {
        $this->id                 = 'moyasar_samsung_pay';
        $this->method_title       = __( 'Moyasar Samsung Pay', 'moyasar-payments' );
        $this->method_description = __( 'Accept payments via Samsung Pay.', 'moyasar-payments' );
        $this->supports           = array(
            'products',
            'refunds',
            'block_checkout',
        );
        $this->moyasar_icons = array( 'samsung_pay' );
        
        $this->init_form_fields();
        $this->init_settings();

        $this->title       = Moyasar_Helper::get_moyasar_option( 'moyasar_samsung_pay_title', __( 'Samsung Pay', 'moyasar-payments' ) );
        $this->description = Moyasar_Helper::get_moyasar_option( 'moyasar_samsung_pay_description', __( 'Pay with Samsung Pay', 'moyasar-payments' ) );
        $this->enabled     = $this->get_option( 'enabled' );

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_filter( 'woocommerce_gateway_icon', array( $this, 'filter_gateway_icon' ), 10, 2 );
        add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
    }

    public function payment_scripts() {
        if ( ! is_checkout() && ! isset( $_GET['pay_for_order'] ) && ! is_add_payment_method_page() ) {
            return;
        }

        if ( 'no' === $this->enabled ) {
            return;
        }

        // Samsung Pay Web SDK
        wp_enqueue_script( 'moyasar_samsung_pay_sdk', 'https://img.mpay.samsung.com/gsmpi/sdk/samsungpay_web_sdk.js', array(), null, true );

        wp_register_script( 'moyasar_samsung_pay', MOYASAR_WC_PLUGIN_URL . 'assets/js/moyasar-samsung-pay.js', array( 'jquery', 'moyasar_samsung_pay_sdk' ), MOYASAR_WC_VERSION, true );
        
        $service_id = Moyasar_Helper::get_moyasar_option( 'samsung_pay_service_id', '' );
        $test_mode  = Moyasar_Helper::get_moyasar_option( 'testmode', 'no' );

        wp_localize_script( 'moyasar_samsung_pay', 'moyasar_samsung_params', array(
            'service_id'           => $service_id,
            'amount'               => WC()->cart->get_total( 'raw' ),
            'currency'             => get_woocommerce_currency(),
            'country'              => WC()->countries->get_base_country(),
            'is_product'           => is_product(),
            'debug'                => 'yes' === $test_mode ? true : false,
            'environment'          => 'yes' === $test_mode ? 'STAGE' : 'PRODUCTION',
            'button_height'        => Moyasar_Helper::get_moyasar_option( 'samsung_pay_checkout_btn_height', 'medium' ),
            'button_border_radius' => Moyasar_Helper::get_moyasar_option( 'samsung_pay_checkout_btn_border_radius', '4' ),
            'store_name'           => get_bloginfo( 'name', 'display' ),
            'supported_networks'   => Moyasar_Helper::get_moyasar_option( 'allowed_brands', array() )
        ));

        wp_enqueue_script( 'moyasar_samsung_pay' );
    }

    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => __( 'Enable/Disable', 'moyasar-payments' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable Moyasar Samsung Pay', 'moyasar-payments' ),
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

    public function payment_fields() {
        if ( $this->description ) {
            echo wp_kses_post( wpautop( wptexturize( $this->description ) ) );
        }
        
        // Output the Samsung Pay button container
        echo '<div id="moyasar-samsung-pay-button-container" class="samsung-pay-checkout-container"></div>';
        echo '<input type="hidden" id="moyasar_samsung_pay_token" name="moyasar_samsung_pay_token" />';
        echo '<p id="moyasar-samsung-pay-error" style="color:red; display:none;"></p>';
    }

    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );

        Moyasar_Helper::log( "Samsung Pay: Processing Payment for Order #$order_id", 'debug' );

        $raw_token  = isset( $_POST['moyasar_samsung_pay_token'] ) ? $_POST['moyasar_samsung_pay_token'] : '';
        $token_data = Moyasar_Helper::parse_samsung_pay_token( $raw_token );
        
        if ( empty( $token_data ) ) {
            Moyasar_Helper::log( "Samsung Pay Error: Token missing or malformed for Order #$order_id. Raw input: " . ( is_string( $raw_token ) ? $raw_token : print_r( $raw_token, true ) ), 'error' );
            wc_add_notice( __( 'Samsung Pay token is missing or malformed.', 'moyasar-payments' ), 'error' );
            return array(
                'result'   => 'failure',
                'messages' => __( 'Samsung Pay token is missing or malformed.', 'moyasar-payments' ),
                'message'  => __( 'Samsung Pay token is missing or malformed.', 'moyasar-payments' ),
            );
        }

        $amount = Moyasar_Helper::to_minor_units( $order->get_total(), $order->get_currency() );
        
        $source = array(
            'type' => 'samsungpay',
            'token' => $token_data,
        );

        if ( Moyasar_Helper::is_manual() ) {
            $source['manual'] = true;
        }

        $payment_data = array(
            'amount' => $amount,
            'currency' => $order->get_currency(),
            'description' => 'Order #' . $order_id,
            'callback_url' => $this->get_return_url( $order ),
            'source' => $source,
            'metadata' => $this->get_payment_metadata( $order ),
        );

        Moyasar_Helper::log( "Samsung Pay: Sending API Request...", 'debug' );
        $response = Moyasar_API::create_payment( $payment_data, $this->get_api_key() );

        if ( is_wp_error( $response ) ) {
            Moyasar_Helper::log( "Samsung Pay Error: API Request Failed: " . $response->get_error_message(), 'error' );
            wc_add_notice( $response->get_error_message(), 'error' );
            return array(
                'result'   => 'failure',
                'messages' => $response->get_error_message(),
                'message'  => $response->get_error_message(),
            );
        }

        if ( isset( $response['status'] ) && ( 'paid' === $response['status'] || 'authorized' === $response['status'] ) ) {
            Moyasar_Helper::log( "Samsung Pay: Payment Successful/Authorized (ID: " . $response['id'] . ")", 'debug' );
            // Use Central Helper
            if ( 'authorized' === $response['status'] ) {
                Moyasar_Helper::handle_payment_authorized( $order, $response, 'samsung_pay' );
            } else {
                Moyasar_Helper::handle_payment_success( $order, $response, 'samsung_pay' );
            }

            return array(
                'result' => 'success',
                'redirect' => $this->get_return_url( $order )
            );
        } else {
             // Use Central Helper
             Moyasar_Helper::handle_payment_failed( $order, $response, 'samsung_pay' );

             $msg = isset( $response['message'] ) ? $response['message'] : __( 'Payment failed.', 'moyasar-payments' );
             Moyasar_Helper::log( "Samsung Pay Error: Payment Failed (Status: " . ($response['status'] ?? 'unknown') . ") - $msg", 'error' );
             
             wc_add_notice( $msg, 'error' );
             return array(
                 'result'   => 'failure',
                 'messages' => $msg,
                 'message'  => $msg,
             );
        }
    }
}
