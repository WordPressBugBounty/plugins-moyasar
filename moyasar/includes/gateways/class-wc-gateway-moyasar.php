<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Moyasar General Settings Gateway.
 * This class handles the main API keys, Webhook settings, and global enablement.
 * It is NOT a payment method itself, but acts as the parent configuration.
 *
 * @class       WC_Gateway_Moyasar
 * @extends     WC_Payment_Gateway
 * @package     Moyasar/includes/gateways
 */
class WC_Gateway_Moyasar extends WC_Payment_Gateway {

    public function __construct() {
        $this->id                 = 'moyasar'; // New ID for General Settings
        $this->method_title       = __( 'Moyasar', 'moyasar-payments' );
        $this->method_description = __( 'Accept Credit/Debit Cards, Apple Pay, and more.', 'moyasar-payments' );
        
        // This gateway process payment
        $this->has_fields         = false;
        
        // Load the form fields
        $this->init_form_fields();
        $this->init_settings();

        // Get settings
        $this->title       = $this->get_option( 'title' );
        $this->icon        = Moyasar_Helper::get_card_icon_url( 'logo' );
        $this->description = $this->get_option( 'description' );
        $this->enabled     = $this->get_option( 'enabled' );

        // Save settings hook
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        
        // Auto-generate Webhook Secret if missing
        if ( empty( $this->settings['webhook_secret'] ) ) {
            $this->settings['webhook_secret'] = wp_generate_password( 32, false );
            update_option( $this->get_option_key(), $this->settings );
        }
    }

    /**
     * Define the fields that will appear in the settings.
     */
    public function init_form_fields() {
        if ( class_exists( 'Moyasar_Admin' ) ) {
            $this->form_fields = Moyasar_Admin::get_form_fields();
        } else {
            $this->form_fields = array();
        }
    }
    
    /**
     * Admin Options UI
     */
    public function admin_options() {
        if ( class_exists( 'Moyasar_Admin' ) ) {
             Moyasar_Admin::render_admin_options( $this );
        } else {
             parent::admin_options();
        }
    }

    /**
     * Process Admin Options
     */
    public function process_admin_options() {
        $result = parent::process_admin_options();

        if ( class_exists( 'Moyasar_Admin' ) ) {
            Moyasar_Admin::process_settings( $this );
        }

        return $result;
    }

    /**
     * Validate Test Publishable Key
     *
     * @param string $key Field key.
     * @param string $value Field value.
     * @return string
     */
    public function validate_test_publishable_key_field( $key, $value ) {
        $value = trim( stripslashes( (string) $value ) );
        if ( ! empty( $value ) && strpos( $value, 'pk_test_' ) !== 0 ) {
            WC_Admin_Settings::add_error( __( 'Test Publishable Key must start with "pk_test_". The entered value was not saved.', 'moyasar-payments' ) );
            return $this->get_option( $key );
        }
        return $value;
    }

    /**
     * Validate Test Secret Key
     *
     * @param string $key Field key.
     * @param string $value Field value.
     * @return string
     */
    public function validate_test_secret_key_field( $key, $value ) {
        $value = trim( stripslashes( (string) $value ) );
        if ( ! empty( $value ) && strpos( $value, 'sk_test_' ) !== 0 ) {
            WC_Admin_Settings::add_error( __( 'Test Secret Key must start with "sk_test_". The entered value was not saved.', 'moyasar-payments' ) );
            return $this->get_option( $key );
        }
        return $value;
    }

    /**
     * Validate Live Publishable Key
     *
     * @param string $key Field key.
     * @param string $value Field value.
     * @return string
     */
    public function validate_live_publishable_key_field( $key, $value ) {
        $value = trim( stripslashes( (string) $value ) );
        if ( ! empty( $value ) && strpos( $value, 'pk_live_' ) !== 0 ) {
            WC_Admin_Settings::add_error( __( 'Live Publishable Key must start with "pk_live_". The entered value was not saved.', 'moyasar-payments' ) );
            return $this->get_option( $key );
        }
        return $value;
    }

    /**
     * Validate Live Secret Key
     *
     * @param string $key Field key.
     * @param string $value Field value.
     * @return string
     */
    public function validate_live_secret_key_field( $key, $value ) {
        $value = trim( stripslashes( (string) $value ) );
        if ( ! empty( $value ) && strpos( $value, 'sk_live_' ) !== 0 ) {
            WC_Admin_Settings::add_error( __( 'Live Secret Key must start with "sk_live_". The entered value was not saved.', 'moyasar-payments' ) );
            return $this->get_option( $key );
        }
        return $value;
    }
    
    // We don't process payments here
    public function process_payment( $order_id ) {
        return array();
    }
    
    // This gateway is never "available" for checkout selection
    public function is_available() {
        return false;
    }
}
