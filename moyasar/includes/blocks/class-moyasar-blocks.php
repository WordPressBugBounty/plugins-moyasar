<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Moyasar Blocks Integration.
 *
 * A single, gateway-configurable payment method integration for the
 * WooCommerce Cart/Checkout blocks (Store API). One instance is registered
 * per Moyasar gateway (Credit Card, Apple Pay, STC Pay, Samsung Pay, Invoice),
 * each exposing its own settings to the shared front-end script.
 *
 * @since      8.3.0
 * @package    Moyasar
 * @subpackage Moyasar/includes/blocks
 */

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

if ( ! class_exists( 'Moyasar_Blocks_Support' ) ) {

    class Moyasar_Blocks_Support extends AbstractPaymentMethodType {

        /**
         * Shared front-end script handle. Registered once and reused by every
         * gateway instance so the bundle is only downloaded a single time.
         */
        const SCRIPT_HANDLE = 'moyasar-blocks-integration';

        /**
         * Payment method name (must match the WooCommerce gateway id so the
         * Store API routes process_payment() to the correct gateway).
         *
         * @var string
         */
        protected $name;

        public function __construct( $gateway_id = 'moyasar_invoice' ) {
            $this->name = $gateway_id;
        }

        public function initialize() {
            $this->settings = get_option( 'woocommerce_' . $this->name . '_settings', array() );
        }

        /**
         * Whether this payment method should be shown in block checkout.
         *
         * Mirrors the classic gateway availability rules: the global Moyasar
         * switch must be on, API keys must be configured, and the specific
         * method must be enabled.
         *
         * @return bool
         */
        public function is_active() {
            if ( 'yes' !== Moyasar_Helper::get_moyasar_option( 'enabled' ) ) {
                return false;
            }

            if ( ! Moyasar_Helper::has_api_keys() ) {
                return false;
            }

            $active = Moyasar_Helper::is_method_enabled( $this->name );

            return $active;
        }

        /**
         * Register (once) and return the shared front-end script handle.
         *
         * @return array
         */
        public function get_payment_method_script_handles() {
            self::register_shared_script();

            return array( self::SCRIPT_HANDLE );
        }

        /**
         * Register the shared blocks script and any wallet SDKs it depends on.
         * Safe to call multiple times — registration is guarded.
         */
        public static function register_shared_script() {
            if ( wp_script_is( self::SCRIPT_HANDLE, 'registered' ) ) {
                return;
            }

            $deps = array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n', 'jquery' );

            // Apple Pay SDK — only when Apple Pay is enabled.
            if ( Moyasar_Helper::is_method_enabled( 'moyasar_apple_pay' ) ) {
                wp_register_script( 'moyasar_apple_pay_sdk', 'https://applepay.cdn-apple.com/jsapi/1.latest/apple-pay-sdk.js', array(), null, true );
                $deps[] = 'moyasar_apple_pay_sdk';
            }

            // Samsung Pay Web SDK — only when Samsung Pay is enabled.
            if ( Moyasar_Helper::is_method_enabled( 'moyasar_samsung_pay' ) ) {
                wp_register_script( 'moyasar_samsung_pay_sdk', 'https://img.mpay.samsung.com/gsmpi/sdk/samsungpay_web_sdk.js', array(), null, true );
                $deps[] = 'moyasar_samsung_pay_sdk';
            }

            wp_register_script(
                self::SCRIPT_HANDLE,
                MOYASAR_WC_PLUGIN_URL . 'assets/js/moyasar-blocks.js',
                $deps,
                MOYASAR_WC_VERSION,
                true
            );

            wp_register_style(
                'moyasar_blocks_style',
                MOYASAR_WC_PLUGIN_URL . 'assets/css/moyasar-styles.css',
                array(),
                MOYASAR_WC_VERSION
            );
            wp_enqueue_style( 'moyasar_blocks_style' );
        }

        /**
         * Data exposed to the front-end for this gateway, available in JS via
         * wc.wcSettings.getSetting( '<name>_data' ).
         *
         * @return array
         */
        public function get_payment_method_data() {
            $test_mode       = 'yes' === Moyasar_Helper::get_moyasar_option( 'testmode' );
            $publishable_key = $test_mode
                ? Moyasar_Helper::get_moyasar_option( 'test_publishable_key', '' )
                : Moyasar_Helper::get_moyasar_option( 'live_publishable_key', '' );

            $data = array(
                'name'            => $this->name,
                'title'           => $this->get_title(),
                'description'     => $this->get_description(),
                'icons'           => $this->get_icons(),
                'icon_height'     => Moyasar_Helper::get_moyasar_option( 'gateway_icon_height', '' ),
                'supports'        => $this->get_supported_features(),
                'publishable_key' => trim( (string) $publishable_key ),
                'base_url'        => Moyasar_API::get_base_url(),
                'currency'        => get_woocommerce_currency(),
                'country'         => WC()->countries ? WC()->countries->get_base_country() : 'SA',
                'locale'          => substr( get_locale(), 0, 2 ),
                'plugin_version'  => 'woo_' . MOYASAR_WC_VERSION,
                'manual'          => Moyasar_Helper::is_manual(),
            );

            switch ( $this->name ) {
                case 'moyasar_cc':
                    $data['token_api_url']  = Moyasar_API::get_base_url() . 'tokens';
                    $data['tokenization']   = 'yes' === Moyasar_Helper::get_moyasar_option( 'cc_enable_tokenization', 'yes' );
                    $data['logged_in']      = is_user_logged_in();
                    $data['supported_brands'] = Moyasar_Helper::get_moyasar_option( 'allowed_brands', array() );
                    $data['strings']        = $this->cc_strings();
                    break;

                case 'moyasar_apple_pay':
                    $data['supported_networks'] = Moyasar_Helper::get_moyasar_option( 'allowed_brands', array() );
                    $data['supported_countries'] = Moyasar_Helper::get_moyasar_option( 'allowed_countries', array( 'SA' ) );
                    $data['store_name']         = $this->apple_pay_label();
                    $data['button_theme']       = Moyasar_Helper::get_moyasar_option( 'apple_pay_checkout_btn_theme', 'dark' );
                    $data['border_radius']      = Moyasar_Helper::get_moyasar_option( 'apple_pay_border_radius', '5' );
                    $data['strings']            = array(
                        'error_payment_failed'      => __( 'Payment Failed', 'moyasar-payments' ),
                        'error_connection'          => __( 'Connection Error', 'moyasar-payments' ),
                        'error_merchant_validation' => __( 'Apple Pay Merchant Validation Failed', 'moyasar-payments' ),
                    );
                    break;

                case 'moyasar_stc_pay':
                    $data['confirm_nonce'] = wp_create_nonce( 'moyasar_stc_confirm' );
                    $data['ajax_url']      = admin_url( 'admin-ajax.php' );
                    $data['strings']       = array(
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
                    );
                    break;

                case 'moyasar_samsung_pay':
                    $data['service_id']         = Moyasar_Helper::get_moyasar_option( 'samsung_pay_service_id', '' );
                    $data['environment']        = $test_mode ? 'STAGE' : 'PRODUCTION';
                    $data['supported_networks'] = Moyasar_Helper::get_moyasar_option( 'allowed_brands', array() );
                    $data['store_name']         = get_bloginfo( 'name', 'display' );
                    $data['strings']            = array(
                        'error_payment_failed' => __( 'Payment failed or cancelled.', 'moyasar-payments' ),
                    );
                    break;
            }

            return $data;
        }

        public function get_supported_features() {
            return array( 'products' );
        }

        /**
         * Title shown for the payment method in block checkout.
         *
         * @return string
         */
        private function get_title() {
            $map = array(
                'moyasar_cc'          => array( 'moyasar_cc_title', __( 'Card Payment', 'moyasar-payments' ) ),
                'moyasar_apple_pay'   => array( 'moyasar_apple_pay_title', __( 'Apple Pay', 'moyasar-payments' ) ),
                'moyasar_stc_pay'     => array( 'moyasar_stc_pay_title', __( 'STC Pay', 'moyasar-payments' ) ),
                'moyasar_samsung_pay' => array( 'moyasar_samsung_pay_title', __( 'Samsung Pay', 'moyasar-payments' ) ),
                'moyasar_invoice'     => array( 'moyasar_invoice_title', __( 'Invoice', 'moyasar-payments' ) ),
            );
            $conf = isset( $map[ $this->name ] ) ? $map[ $this->name ] : array( '', __( 'Moyasar', 'moyasar-payments' ) );
            return Moyasar_Helper::get_moyasar_option( $conf[0], $conf[1] );
        }

        /**
         * Description shown under the payment method title.
         *
         * @return string
         */
        private function get_description() {
            $map = array(
                'moyasar_cc'          => array( 'moyasar_cc_description', __( 'Pay with your Credit/Debit Card.', 'moyasar-payments' ) ),
                'moyasar_apple_pay'   => array( 'moyasar_apple_pay_description', __( 'Pay with Apple Pay', 'moyasar-payments' ) ),
                'moyasar_stc_pay'     => array( 'moyasar_stc_pay_description', __( 'Pay with STC Pay', 'moyasar-payments' ) ),
                'moyasar_samsung_pay' => array( 'moyasar_samsung_pay_description', __( 'Pay with Samsung Pay', 'moyasar-payments' ) ),
                'moyasar_invoice'     => array( 'moyasar_invoice_description', __( 'Pay via Invoice (Moyasar Payment Page).', 'moyasar-payments' ) ),
            );
            $conf = isset( $map[ $this->name ] ) ? $map[ $this->name ] : array( '', '' );
            return Moyasar_Helper::get_moyasar_option( $conf[0], $conf[1] );
        }

        /**
         * Icon URLs shown next to the payment method label.
         *
         * @return array
         */
        private function get_icons() {
            $icons = array();
            switch ( $this->name ) {
                case 'moyasar_cc':
                    $brands = Moyasar_Helper::get_moyasar_option( 'allowed_brands', array() );
                    if ( ! is_array( $brands ) || empty( $brands ) ) {
                        $brands = array( 'mada', 'visa', 'mastercard' );
                    }
                    foreach ( $brands as $brand ) {
                        $url = Moyasar_Helper::get_card_icon_url( $brand );
                        if ( $url ) {
                            $icons[] = $url;
                        }
                    }
                    break;
                case 'moyasar_apple_pay':
                    $icons[] = Moyasar_Helper::get_card_icon_url( 'apple_pay' );
                    break;
                case 'moyasar_stc_pay':
                    $icons[] = Moyasar_Helper::get_card_icon_url( 'stc_bank' );
                    break;
                case 'moyasar_samsung_pay':
                    $icons[] = Moyasar_Helper::get_card_icon_url( 'samsung_pay' );
                    break;
                case 'moyasar_invoice':
                    $icons[] = Moyasar_Helper::get_card_icon_url( 'invoice' );
                    break;
            }
            return array_values( array_filter( $icons ) );
        }

        private function cc_strings() {
            return array(
                'cardholder_name'             => __( 'Cardholder Name', 'moyasar-payments' ),
                'cardholder_name_placeholder' => __( 'Name on Card', 'moyasar-payments' ),
                'card_number'                 => __( 'Card Number', 'moyasar-payments' ),
                'expiry'                      => __( 'Expiry (MM/YY)', 'moyasar-payments' ),
                'cvc'                         => __( 'Card Code (CVC)', 'moyasar-payments' ),
                'save_to_account'             => __( 'Save to account', 'moyasar-payments' ),
                'error_incomplete_fields'     => __( 'Please complete all credit card fields.', 'moyasar-payments' ),
                'error_card_type_unsupported' => __( 'This card brand (%s) is not supported.', 'moyasar-payments' ),
                'error_connection'            => __( 'Connection error. Please try again.', 'moyasar-payments' ),
                'error_unknown'               => __( 'Unknown error occurred.', 'moyasar-payments' ),
            );
        }

        /**
         * Apple Pay merchant display name (ASCII-safe, mirrors the classic gateway).
         *
         * @return string
         */
        private function apple_pay_label() {
            $name = get_bloginfo( 'name' );
            if ( preg_match( '/\A\p{ASCII}+\z/u', $name ) ) {
                return $name;
            }
            $host   = (string) parse_url( get_site_url(), PHP_URL_HOST );
            $host   = preg_replace( '/^www\./i', '', $host );
            $domain = explode( '.', $host )[0];
            return 'WC-Store: ' . $domain;
        }
    }
}
