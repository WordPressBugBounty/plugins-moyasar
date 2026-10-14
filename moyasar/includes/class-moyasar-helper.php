<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The Helper class.
 *
 * @since      8.0.0
 * @package    Moyasar
 * @subpackage Moyasar/includes
 */
class Moyasar_Helper {

    /**
     * Whether High-Performance Order Storage (HPOS / custom order tables) is active.
     *
     * @return bool
     */
    public static function is_hpos_enabled() {
        return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    }

    /**
     * Resolve the WooCommerce order that a Moyasar payment id belongs to.
     *
     * IMPORTANT: wc_get_orders() only honours `meta_query` on HPOS. On legacy
     * post-table (CPT) storage WooCommerce silently discards `meta_query`
     * (WC_Order_Data_Store_CPT flags it as an unsupported arg), so the query
     * degrades to "newest order, limit 1" and returns an unrelated order —
     * causing payments to be attributed to the wrong order. We therefore branch
     * on the active storage engine and query the order meta directly on legacy
     * storage.
     *
     * @param string $payment_id Moyasar payment id.
     * @return WC_Order|false The matching order, or false when none matches.
     */
    public static function get_order_from_payment_id( $payment_id ) {
        if ( empty( $payment_id ) ) {
            return false;
        }

        $order_id = 0;

        if ( self::is_hpos_enabled() ) {
            // HPOS: meta_query is fully supported.
            $orders = wc_get_orders( array(
                'limit'      => 1,
                'return'     => 'ids',
                'meta_query' => array(
                    'relation' => 'OR',
                    array(
                        'key'     => '_moyasar_payment_id', // Our custom meta
                        'value'   => $payment_id,
                        'compare' => '=',
                    ),
                    array(
                        'key'     => '_transaction_id',     // Standard WC transaction ID
                        'value'   => $payment_id,
                        'compare' => '=',
                    ),
                ),
            ) );

            $order_id = ! empty( $orders ) ? (int) reset( $orders ) : 0;
        } else {
            // Legacy CPT storage: query postmeta directly (status-agnostic) so
            // the payment id constraint is actually applied.
            global $wpdb;
            $order_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key IN ( '_moyasar_payment_id', '_transaction_id' )
                   AND meta_value = %s
                 ORDER BY post_id DESC
                 LIMIT 1",
                $payment_id
            ) );
        }

        if ( $order_id > 0 ) {
            $order = wc_get_order( $order_id );
            return $order ? $order : false;
        }

        return false;
    }
    
    public static function log( $message, $level = 'info' ) {
        if ( ! class_exists( 'WC_Logger' ) ) {
            return;
        }

        // Always log errors, warnings, critical, etc.
        if ( in_array( $level, array( 'error', 'warning', 'critical', 'emergency', 'alert' ) ) ) {
             // Log these regardless of debug setting
        } else {
            // For info, debug, notice: check debug_mode setting
            $debug_mode = self::get_moyasar_option( 'debug_mode', 'no' );
            if ( 'yes' !== $debug_mode ) {
                return;
            }
        }
        
        $logger = wc_get_logger();
        $context = array( 'source' => 'moyasar-payments' );
        
        $logger->log( $level, $message, $context );
    }

    /**
     * Normalize amount to minor units (halala/cents).
     */
    public static function to_minor_units( $amount, $currency = 'SAR' ) {
        return round( $amount * ( 10 ** self::fraction_for( $currency ) ), 0 );
    }

    /**
     * Convert from minor units (halala/cents) to major units.
     */
    public static function from_minor_units( $amount, $currency = 'SAR' ) {
        return floatval( $amount ) / ( 10 ** self::fraction_for( $currency ) );
    }

    /**
     * Get fraction digits for currency.
     */
    public static function fraction_for( $currency ) {
        $fractions = array(
            'BHD' => 3, 'BIF' => 0, 'BYR' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0,
            'HUF' => 0, 'IQD' => 0, 'ISK' => 0, 'JOD' => 3, 'JPY' => 0, 'KMF' => 0,
            'KRW' => 0, 'KWD' => 3, 'LYD' => 3, 'MGA' => 0, 'MRO' => 0, 'OMR' => 3,
            'PYG' => 0, 'RWF' => 0, 'TND' => 3, 'UGX' => 0, 'VND' => 0, 'VUV' => 0,
            'XAF' => 0, 'XOF' => 0, 'XPF' => 0,
        );
        $currency = strtoupper( $currency );
        return isset( $fractions[ $currency ] ) ? $fractions[ $currency ] : 2;
    }

    /**
     * Handle Moyasar Coupon/Promotion Discount.
     * Checks if paid amount is less than order total and adds a discount fee.
     * 
     * @param WC_Order $order
     * @param array    $payment Moyasar Payment Object
     */
    public static function handle_promotion_discount( $order, $payment ) {
        if ( ! $order || ! is_a( $order, 'WC_Order' ) || empty( $payment ) ) {
            return;
        }

        $amount_paid_minor = isset( $payment['amount'] ) ? intval( $payment['amount'] ) : 0;

        if ( $amount_paid_minor <= 0 ) {
            return;
        }

        // Only reconcile an amount gap when the payment actually carries a Moyasar
        // promotion/coupon signal. Without this gate, ANY payment where the paid
        // amount is below the order total (partial capture, rounding, or a payment
        // mis-attributed to the wrong order) would silently get a fabricated
        // negative fee to force the totals to match.
        // Sanitize and length-limit: this value comes from the external payment
        // payload and is persisted verbatim as an order item name below.
        $coupon_code = isset( $payment['metadata']['#coupon_code'] )
            ? substr( sanitize_text_field( (string) $payment['metadata']['#coupon_code'] ), 0, 255 )
            : '';
        if ( '' === $coupon_code ) {
            return;
        }

        // Get current order total in minor units WITH CURRENCY
        $currency = $order->get_currency();
        $order_total_minor = self::to_minor_units( $order->get_total(), $currency );

        // If paid amount < order total (e.g. 8500 < 10000)
        if ( $amount_paid_minor < $order_total_minor ) {
            
            $diff_minor = $order_total_minor - $amount_paid_minor;
            $diff_major = self::from_minor_units( $diff_minor, $currency );
            
            // Calculate Effective Tax Rate to reverse-calculate Net Fee
            // Rate = Total Tax / (Total Inc Tax - Total Tax)
            $total_tax = $order->get_total_tax();
            $total_inc_tax = $order->get_total();
            $tax_rate = 0;
            
            if ( $total_tax > 0 && $total_inc_tax > $total_tax ) {
                $tax_rate = $total_tax / ( $total_inc_tax - $total_tax );
            }
            
            // Calculate Net Fee Amount (Amount excluding tax)
            // Gross = Net * (1 + Rate)  =>  Net = Gross / (1 + Rate)
            $net_fee = $diff_major / ( 1 + $tax_rate );
            
            // Add Negative Fee (Taxable)
            $item_fee = new WC_Order_Item_Fee();
            $item_fee->set_name( $coupon_code );
            $item_fee->set_amount( -1 * $net_fee );
            $item_fee->set_total( -1 * $net_fee );
            
            if ( $tax_rate > 0 ) {
                $item_fee->set_tax_status( 'taxable' );
                $item_fee->set_tax_class( '' ); // Standard tax class, or infer from items? Standard is safest guess.
            } else {
                $item_fee->set_tax_status( 'none' );
            }
            
            $order->add_item( $item_fee );
            $order->calculate_totals();
            $order->save();
            
            $order->add_order_note( sprintf( __( 'Applied Moyasar Promotion Discount: %s', 'moyasar-payments' ), wc_price( $diff_major ) ) );
        }
    }

    /**
     * Get Shared Admin Fields for Gateways
     * 
     * @return array
     */
    public static function get_shared_settings_fields() {
        $fields = array(
             'testmode' => array(
                'title'       => __( 'Test Mode', 'moyasar-payments' ),
                'label'       => __( 'Enable Test Mode', 'moyasar-payments' ),
                'type'        => 'checkbox',
                'description' => __( 'Place the payment gateway in test mode using test API keys.', 'moyasar-payments' ),
                'default'     => 'yes',
                'desc_tip'    => true,
            ),
            'test_secret_key' => array(
                'title'       => __( 'Test Secret Key', 'moyasar-payments' ),
                'type'        => 'password',
                'description' => __( 'Moyasar Test Secret Key (sk_test_...).', 'moyasar-payments' ),
                'default'     => '',
            ),
            'test_publishable_key' => array(
                'title'       => __( 'Test Publishable Key', 'moyasar-payments' ),
                'type'        => 'text',
                'description' => __( 'Moyasar Test Publishable Key (pk_test_...).', 'moyasar-payments' ),
                'default'     => '',
            ),
            'live_secret_key' => array(
                'title'       => __( 'Live Secret Key', 'moyasar-payments' ),
                'type'        => 'password',
                'description' => __( 'Moyasar Live Secret Key (sk_live_...).', 'moyasar-payments' ),
                'default'     => '',
            ),
            'live_publishable_key' => array(
                'title'       => __( 'Live Publishable Key', 'moyasar-payments' ),
                'type'        => 'text',
                'description' => __( 'Moyasar Live Publishable Key (pk_live_...).', 'moyasar-payments' ),
                'default'     => '',
            )
        );

        // Status Map
        $statuses = wc_get_order_statuses();
        // Remove wc- prefix for saving
        $clean_statuses = array();
        foreach ( $statuses as $key => $label ) {
            $clean_statuses[ str_replace( 'wc-', '', $key ) ] = $label;
        }

        $fields['webhook_paid_status'] = array(
            'title'       => __( 'Paid Status', 'moyasar-payments' ),
            'type'        => 'select',
            'default'     => 'processing',
            'options'     => $clean_statuses,
        );
        $fields['webhook_failed_status'] = array(
            'title'       => __( 'Failed Status', 'moyasar-payments' ),
            'type'        => 'select',
            'default'     => 'failed',
            'options'     => $clean_statuses,
        );
        $fields['webhook_refunded_status'] = array(
            'title'       => __( 'Refunded Status', 'moyasar-payments' ),
            'type'        => 'select',
            'default'     => 'refunded',
            'options'     => $clean_statuses,
        );
         $fields['webhook_voided_status'] = array(
            'title'       => __( 'Voided Status', 'moyasar-payments' ),
            'type'        => 'select',
            'default'     => 'cancelled',
            'options'     => $clean_statuses,
        );

        return $fields;
    }

    /**
     * Retrieve shared webhook secret
     */
    public static function get_webhook_secret_fallback() {
        $main_settings = get_option( 'woocommerce_moyasar_settings', array() );

        $secret_exists = $main_settings['webhook_secret'] ?? null;
        if (!$secret_exists){
            $secret_exists = bin2hex(random_bytes(14));
            $main_settings['webhook_secret'] = $secret_exists;
            update_option('woocommerce_moyasar_settings', $main_settings);
        }
        return $secret_exists;
    }

    /**
     * Retrieve API Key from Order context.
     * 
     * @param WC_Order $order
     * @return string API Key
     */
    public static function get_api_key_from_order( $order ) {
        $payment_method = $order->get_payment_method();
        $settings = get_option( 'woocommerce_moyasar_settings' );

        if ( ! empty( $settings ) ) {
            $test_mode = isset( $settings['testmode'] ) ? $settings['testmode'] : 'yes';
            if ( 'yes' === $test_mode ) {
                return isset( $settings['test_secret_key'] ) ? $settings['test_secret_key'] : '';
            } else {
                return isset( $settings['live_secret_key'] ) ? $settings['live_secret_key'] : '';
            }
        }
        
        return '';
    }

    /**
     * Process Refund (Shared Logic)
     * Supports Voiding if eligible (full amount, < 2 hours), otherwise Refund.
     * 
     * @param int|WC_Order $order    Order ID or Object.
     * @param float        $amount   Refund amount.
     * @param string       $reason   Refund reason.
     * @param string       $api_key  API Key to use.
     * @return bool|WP_Error
     */
    public static function process_refund( $order, $amount = null, $reason = '', $api_key = null ) {
        if ( is_numeric( $order ) ) {
            $order = wc_get_order( $order );
        }

        if ( ! $order ) {
            self::log( "Refund Error: Order not found.", 'error' );
            return new WP_Error( 'moyasar_refund_error', __( 'Order not found', 'moyasar-payments' ) );
        }

        // Auto-detect API key if not provided (e.g. called from manual refund button outside gateway context?)
        if ( empty( $api_key ) ) {
             $api_key = self::get_api_key_from_order( $order );
        }

        $payment_id = $order->get_transaction_id();
        if ( empty( $payment_id ) ) {
             $payment_id = $order->get_meta( '_moyasar_payment_id' );
        }

        if ( empty( $payment_id ) ) {
            self::log( "Refund Error: No payment ID for Order " . $order->get_id(), 'error' );
            return new WP_Error( 'moyasar_refund_error', __( 'Transaction ID not found.', 'moyasar-payments' ) );
        }

        // 1. Fetch Payment Details from Moyasar
        $payment = Moyasar_API::fetch_payment( $payment_id, $api_key );
        
        if ( is_wp_error( $payment ) ) {
            self::log( "Refund API Fetch Error (Order {$order->get_id()}): " . $payment->get_error_message(), 'error' );
            return new WP_Error( 'moyasar_refund_error', __( 'Could not fetch payment details from Moyasar.', 'moyasar-payments' ) );
        }

        // 2. Determine Amount (Minor Units)
        $amount_minor = self::to_minor_units( $amount, $order->get_currency() );
        $payment_amount = isset( $payment['amount'] ) ? intval( $payment['amount'] ) : 0;
        $created_at_str = isset( $payment['created_at'] ) ? $payment['created_at'] : '';
        
        // 3. Check Eligibility for Void
        $can_void = false;
        if ( ! empty( $created_at_str ) && $payment_amount > 0 ) {
            try {
                $created_dt = new DateTime( $created_at_str );
                $cutoff = ( new DateTime() )->sub( new DateInterval( 'PT2H' ) ); 
                
                if ( $created_dt > $cutoff && intval( $amount_minor ) === $payment_amount ) {
                    $can_void = true; 
                }
            } catch ( Exception $e ) {
                self::log( "Refund Date Error: " . $e->getMessage(), 'error' );
            }
        }

        // 4. Attempt Void
        if ( $can_void ) {
            self::log( "Attempting Void for Order " . $order->get_id() );
            $void_res = Moyasar_API::void_payment( $payment_id, $api_key );

            if ( ! is_wp_error( $void_res ) && isset( $void_res['status'] ) && 'voided' === $void_res['status'] ) {
                self::log( "Void Success for Order " . $order->get_id() );
                $order->add_order_note( sprintf( __( 'Payment Voided via Moyasar API (Full Amount: %s).', 'moyasar-payments' ), wc_price( $amount ) ) );
                return true;
            } else {
                $err = is_wp_error( $void_res ) ? $void_res->get_error_message() : ( isset( $void_res['message'] ) ? $void_res['message'] : 'Unknown' );
                self::log( "Void Failed: $err. Fallback to Refund.", 'warning' );
            }
        }

        // 5. Fallback or Standard Refund
        $refund_res = Moyasar_API::refund_payment( $payment_id, $amount_minor, $api_key );

        if ( is_wp_error( $refund_res ) ) {
            $error_message = $refund_res->get_error_message();
            self::log( "Refund API Failed: " . $error_message, 'error' );
            return new WP_Error( 'moyasar_refund_error', sprintf( __( 'Moyasar Refund API Failed: %s', 'moyasar-payments' ), $error_message ) );
        }

        $new_status = isset( $refund_res['status'] ) ? $refund_res['status'] : 'unknown';
        
        self::log( "Refund Success for Order {$order->get_id()}. New Status: $new_status" );
        $order->add_order_note( sprintf( __( 'Refunded %s via Moyasar.', 'moyasar-payments' ), wc_price( $amount ) ) );

        return true;
    }
    /**
     * Get Card Icon URL.
     * 
     * @param string $brand Brand name (visa, mastercard, etc).
     * @return string URL to the icon image.
     */
    public static function get_card_icon_url( $brand ) {
        $brand = strtolower( $brand );
        $filename = $brand;
        
        // Handle mapped filenames
        if ( 'mastercard' === $brand ) {
            $filename = 'master-card';
        }
        
        // Return default logo if brand is 'logo' or 'default'
        if ( 'logo' === $brand || 'default' === $brand ) {
            $filename = 'logo';
        }

        if ( 'invoice' === $brand ) {
            $filename = 'logo';
        }

        return MOYASAR_WC_PLUGIN_URL . 'assets/images/' . $filename . '.svg';
    }

    /**
     * Get List of Moyasar Payment Methods
     */
    public static function get_payment_methods_list() {
        return array(
            'moyasar_cc' => array(
                'label'       => __( 'Credit Card', 'moyasar-payments' ),
                'description' => __( 'Accept payments via Credit Cards (Mada, Visa, MasterCard, Amex, UnionPay). The payment form is embedded directly in your checkout page.', 'moyasar-payments' ),
                'icons'       => array( 'mada', 'visa', 'mastercard', 'amex', 'unionpay' ),
                'features'    => array( 'subscriptions' )
            ),
            'moyasar_apple_pay' => array(
                'label'       => __( 'Apple Pay', 'moyasar-payments' ),
                'description' => __( 'Accept secure payments via Apple Pay. Customers can pay using Touch ID or Face ID on supported devices.', 'moyasar-payments' ),
                'icons'       => array( 'apple_pay' ),
                'features'    => array( 'subscriptions' )
            ),
            'moyasar_stc_pay' => array(
                'label'       => __( 'STC Pay', 'moyasar-payments' ),
                'description' => __( 'Accept payments via STC Pay digital wallet. Customers verify payment using their mobile number.', 'moyasar-payments' ),
                'icons'       => array( 'stc_bank' ),
                'features'    => array()
            ),
            'moyasar_samsung_pay' => array(
                'label'       => __( 'Samsung Pay', 'moyasar-payments' ),
                'description' => __( 'Accept secure payments via Samsung Pay on supported Samsung devices.', 'moyasar-payments' ),
                'icons'       => array( 'samsung_pay' ),
                'features'    => array()
            ),
            'moyasar_invoice' => array(
                'label'       => __( 'Invoice', 'moyasar-payments' ),
                'description' => __( 'Redirect customers to a secure Moyasar payment page. Supports cards, Apple Pay, and more. Works with all themes and as a fallback when embedded checkout is unavailable.', 'moyasar-payments' ),
                'icons'       => array( 'invoice' ),
                'features'    => array()
            ),
        );
    }

    /**
     * Get available card brands.
     * 
     * @return array
     */
    public static function get_card_brands() {
        return array(
            'mada'       => __( 'Mada', 'moyasar-payments' ),
            'visa'       => __( 'Visa', 'moyasar-payments' ),
            'mastercard' => __( 'MasterCard', 'moyasar-payments' ),
            'amex'       => __( 'American Express', 'moyasar-payments' ),
            'unionpay'   => __( 'UnionPay', 'moyasar-payments' )
        );
    }

    /**
     * Get available countries.
     * 
     * @return array
     */
    public static function get_countries() {
        if ( class_exists( 'WC_Countries' ) ) {
            return WC()->countries->get_countries();
        }
        return array();
    }

    /**
     * Save Payment Info to Order Meta and Note.
     * 
     * @param int|WC_Order $order 
     * @param array        $payment Moyasar Payment Object
     */
    public static function save_payment_info( $order, $payment ) {
        if ( is_numeric( $order ) ) {
            $order = wc_get_order( $order );
        }
        
        if ( ! $order || empty( $payment ) ) {
            return;
        }

        $source = isset( $payment['source'] ) ? $payment['source'] : array();
        $type   = isset( $source['type'] ) ? $source['type'] : 'unknown';
        
        $info_str = '';
        
        switch ( $type ) {
            case 'creditcard':
            case 'applepay':
            case 'samsungpay':
                $brand   = isset( $source['company'] ) ? $source['company'] : $type;
                $number  = isset( $source['number'] ) ? $source['number'] : '';
                $message = isset( $source['message'] ) ? $source['message'] : '';
                
                $info_str = sprintf( '%s: %s - %s', ucfirst( $type ), $brand, $number );
                
                $order->update_meta_data( '_moyasar_source_type', $type );
                $order->update_meta_data( '_moyasar_source_brand', $brand );
                $order->update_meta_data( '_moyasar_source_number', $number );
                if ( ! empty( $message ) ) {
                    $order->update_meta_data( '_moyasar_source_message', $message );
                }
                break;
                
            case 'stcpay':
                $mobile = isset( $source['mobile'] ) ? $source['mobile'] : '';
                $info_str = sprintf( 'STC Pay: %s', $mobile );
                
                $order->update_meta_data( '_moyasar_source_type', 'stcpay' );
                $order->update_meta_data( '_moyasar_source_mobile', $mobile );
                break;
                
            default:
                $info_str = sprintf( 'Payment Type: %s', $type );
                $order->update_meta_data( '_moyasar_source_type', $type );
                break;
        }

        if ( ! empty( $info_str ) ) {
            $order->update_meta_data( '_moyasar_payment_info', $info_str );
        }
        
        $order->save();
    }

    /**
     * Get Main Moyasar Option.
     * 
     * @param string $key Optional key to retrieve specific setting.
     * @param mixed  $default Default value if key not found.
     * @return mixed
     */
    public static function get_moyasar_option( $key = null, $default = null ) {
        $settings = get_option( 'woocommerce_moyasar_settings', array() );
        
        if ( is_null( $key ) ) {
            return $settings;
        }
        
        return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
    }

    /**
     * Get the API key based on settings.
     *
     * @return string
     */
    public static function get_api_secret_key() {
        $test_mode = Moyasar_Helper::get_moyasar_option('testmode');
        $key = ( 'yes' === $test_mode )
            ? Moyasar_Helper::get_moyasar_option('test_secret_key', '')
            : Moyasar_Helper::get_moyasar_option('live_secret_key', '');

        return trim( (string) $key );
    }

    /**
     * Whether the API keys for the currently active environment (Test or Live) are configured.
     *
     * @since 8.2.0
     * @return bool True if both the publishable and secret key for the active mode are set.
     */
    public static function has_api_keys() {
        $test_mode = 'yes' === self::get_moyasar_option( 'testmode' );
        $publishable_key = $test_mode
            ? self::get_moyasar_option( 'test_publishable_key', '' )
            : self::get_moyasar_option( 'live_publishable_key', '' );
        $secret_key = $test_mode
            ? self::get_moyasar_option( 'test_secret_key', '' )
            : self::get_moyasar_option( 'live_secret_key', '' );

        return '' !== trim( (string) $publishable_key ) && '' !== trim( (string) $secret_key );
    }

    /**
     * Check whether a Moyasar payment method is enabled from the main settings.
     *
     * @since 8.2.0
     * @param string $method_id Gateway id, e.g. 'moyasar_apple_pay'.
     * @return bool
     */
    public static function is_method_enabled( $method_id ) {
        return 'yes' === self::get_moyasar_option( 'enable_method_' . $method_id );
    }

    /**
     * Get the Apple Pay domain verification file content from settings.
     *
     * Checks (in order): the content stored directly in the main settings,
     * then the legacy per-gateway pasted content. The legacy media-library
     * URL is handled separately when serving the file.
     *
     * @since 8.2.0
     * @return string
     */
    public static function get_apple_pay_domain_file_content() {
        $content = trim( (string) self::get_moyasar_option( 'apple_pay_domain_file_content', '' ) );
        if ( '' !== $content ) {
            return $content;
        }

        // Legacy: content pasted in the Apple Pay gateway settings.
        $apple_settings = get_option( 'woocommerce_moyasar_apple_pay_settings', array() );
        if ( is_array( $apple_settings ) && ! empty( $apple_settings['domain_verification_file'] ) ) {
            return trim( (string) $apple_settings['domain_verification_file'] );
        }

        return '';
    }

    /**
     * Whether an Apple Pay domain verification file is available (uploaded content
     * or a legacy media-library URL).
     *
     * @since 8.2.0
     * @return bool
     */
    public static function has_apple_pay_domain_file() {
        if ( '' !== self::get_apple_pay_domain_file_content() ) {
            return true;
        }

        // Legacy: file uploaded via the WordPress media library.
        if ( '' !== trim( (string) self::get_moyasar_option( 'apple_pay_domain_file_url', '' ) ) ) {
            return true;
        }

        // Manually placed on disk at /.well-known/ (as the setup guidance suggests).
        if ( self::apple_pay_domain_file_exists_on_disk() ) {
            return true;
        }

        // Manually hosted and reachable over HTTP (e.g. served by the web server
        // outside of WordPress). Cached to avoid a request on every page load.
        if ( self::apple_pay_domain_file_reachable_remotely() ) {
            return true;
        }

        return false;
    }

    /**
     * Transient key used to cache the remote reachability check for the Apple
     * Pay domain association file.
     *
     * @since 8.2.4
     * @var string
     */
    const APPLE_PAY_DOMAIN_FILE_REMOTE_CACHE = 'moyasar_apple_pay_domain_file_remote';

    /**
     * Whether the Apple Pay domain association file is physically present on disk
     * at /.well-known/apple-developer-merchantid-domain-association.
     *
     * @since 8.2.4
     * @return bool
     */
    private static function apple_pay_domain_file_exists_on_disk() {
        $path = ABSPATH . '.well-known/apple-developer-merchantid-domain-association';

        return is_readable( $path ) && filesize( $path ) > 0;
    }

    /**
     * Whether the Apple Pay domain association file is reachable over HTTP at the
     * site's /.well-known/ URL. This covers files hosted manually by the web
     * server outside of WordPress (the exact case Apple itself verifies).
     *
     * The result is cached in a transient so the request runs at most once per
     * cache window rather than on every settings-page render. The cache is
     * cleared when the file is uploaded or removed via the settings screen.
     *
     * @since 8.2.4
     * @return bool
     */
    private static function apple_pay_domain_file_reachable_remotely() {
        $cached = get_transient( self::APPLE_PAY_DOMAIN_FILE_REMOTE_CACHE );
        if ( false !== $cached ) {
            return 'yes' === $cached;
        }

        $url      = self::get_canonical_apple_pay_domain_file_url();
        $response = wp_remote_get(
            $url,
            array(
                'timeout'     => 5,
                'redirection' => 3,
                'sslverify'   => true,
                'headers'     => array( 'Accept' => '*/*' ),
            )
        );

        $reachable = false;
        if ( ! is_wp_error( $response ) ) {
            $code = (int) wp_remote_retrieve_response_code( $response );
            $body = trim( (string) wp_remote_retrieve_body( $response ) );

            // A valid association file is a small text/JSON payload, not an HTML
            // page (guards against "soft 404" pages returned with a 200 status).
            $reachable = (
                200 === $code
                && '' !== $body
                && strlen( $body ) <= 65536
                && false === stripos( $body, '<html' )
            );
        }

        // Cache positive results longer than negative ones so a just-fixed setup
        // clears quickly, while a healthy setup avoids repeated requests.
        set_transient(
            self::APPLE_PAY_DOMAIN_FILE_REMOTE_CACHE,
            $reachable ? 'yes' : 'no',
            $reachable ? DAY_IN_SECONDS : ( 5 * MINUTE_IN_SECONDS )
        );

        return $reachable;
    }

    /**
     * Whether a Samsung Pay Service ID has been configured.
     *
     * @since 8.2.0
     * @return bool
     */
    public static function has_samsung_pay_service_id() {
        return '' !== trim( (string) self::get_moyasar_option( 'samsung_pay_service_id', '' ) );
    }

    /**
     * Build the list of configuration problems that should be surfaced to the
     * merchant (used for the settings-page alerts and checkout gating).
     *
     * @since 8.2.0
     * @return array Array of HTML-safe message strings.
     */
    public static function get_configuration_alerts() {
        $alerts = array();

        if ( ! self::has_api_keys() ) {
            $alerts[] = __( 'Moyasar API keys are missing. Add your Test or Live API keys in the <strong>Account Setup</strong> section before accepting payments.', 'moyasar-payments' );
        }

        if ( self::is_method_enabled( 'moyasar_apple_pay' ) && ! self::has_apple_pay_domain_file() ) {
            $alerts[] = sprintf(
                /* translators: %s: the .well-known domain association path */
                __( 'Apple Pay is enabled but no valid Domain Verification File was found. Upload it in the <strong>Apple Pay Web Registration</strong> section, or place it manually at <code>%s</code>.', 'moyasar-payments' ),
                esc_html( self::get_canonical_apple_pay_domain_file_url() )
            );
        }

        if ( self::is_method_enabled( 'moyasar_samsung_pay' ) && ! self::has_samsung_pay_service_id() ) {
            $alerts[] = __( 'Samsung Pay is enabled but the <strong>Samsung Pay Service ID</strong> is missing. Enter it in the Samsung Pay Setup section.', 'moyasar-payments' );
        }

        return $alerts;
    }

    /**
     * Handle Successful Payment Logic Centrally
     * 
     * @param WC_Order $order
     * @param array $payment
     * @param string $source (e.g., 'saved_card', 'instant_checkout', 'apple_pay', 'cc_form')
     */
    public static function handle_payment_success( $order, $payment, $source = 'unknown', $instant_checkout = false ) {
        if ( ! $order || empty( $payment ) ) {
            return;
        }

        $payment_id = isset( $payment['id'] ) ? $payment['id'] : '';
        $amount     = isset( $payment['amount'] ) ? $payment['amount'] : 0;
        $currency   = isset( $payment['currency'] ) ? $payment['currency'] : $order->get_currency();
        $status     = isset( $payment['status'] ) ? $payment['status'] : 'unknown';
        $message    = $payment['source']['message'] ?? $payment['message'] ?? 'Success';
        
        // Race Condition Lock: Check if order is currently being processed using an atomic option-based lock
        $lock_key = 'moyasar_lock_' . $order->get_id();
        $now = time();
        if ( ! add_option( $lock_key, $now, '', 'no' ) ) {
            $lock_time = (int) get_option( $lock_key );
            if ( $lock_time > 0 && ( $now - $lock_time ) < 15 ) {
                 self::log( "Race Condition Avoided: Order #{$order->get_id()} is currently locked (acquired " . ($now - $lock_time) . " seconds ago). Skipping.", 'debug' );
                 return;
            }
            update_option( $lock_key, $now );
        }

        try {
            // Idempotency: detailed check to prevent duplicate processing
            // If order is already paid with same ID, skip.
            $existing_payment_id = $order->get_meta( '_moyasar_payment_id' );
            if ( ! empty( $existing_payment_id ) && $existing_payment_id === $payment_id ) {
                // Get Configured Success Status (e.g., 'processing', 'completed', 'on-hold')
                $success_status = self::get_moyasar_option( 'order_status_success', 'processing' );
                $success_status = str_replace( 'wc-', '', $success_status );
    
                // Check if order is already in a "paid" state (Configured Success OR Processing OR Completed)
                $paid_statuses = array_unique( array( $success_status ) );
                
                if ( $order->has_status( $paid_statuses ) ) {
                    $order->add_order_note( "Duplicate Success Handling Skipped for Order #{$order->get_id()} (Source: $source). Payment ID $payment_id already verified." );
                     self::log( "Duplicate Success Handling Skipped for Order #{$order->get_id()} (Source: $source). Payment ID $payment_id already verified.", 'debug' );
                     delete_option( $lock_key );
                     return;
                }
            }
    
            // Extract Payment Details
            $source_data = isset( $payment['source'] ) ? $payment['source'] : array();
            $source_type = isset( $source_data['type'] ) ? $source_data['type'] : 'unknown';
            $company     = isset( $source_data['company'] ) ? $source_data['company'] : $source_type;
            $number      = isset( $source_data['number'] ) ? $source_data['number'] : '****';
    
            $display_source = ucfirst( str_replace( '_', ' ', $source ) );
            
            $log_message = "Moyasar Payment Success:\n" .
                           "Payment ID: {$payment_id}\n" .
                           "Status: {$status}\n" .
                           "Amount: " . self::from_minor_units( $amount, $currency ) . " " . $currency . "\n" .
                           "Source Type: " . ucfirst( $source_type ) . " (" . ucfirst( $company ) . ")\n" .
                           "Message: {$message}\n" .
                           "Origin: {$display_source}";
    
            $order->add_order_note( $log_message );
            self::log( "Payment Success Handled: Order #{$order->get_id()} (Source: {$source}, Payment ID: {$payment_id})", 'info' );
    
            // Save Metadata
            $order->update_meta_data( '_moyasar_payment_id', $payment_id );
            $order->update_meta_data( '_moyasar_payment_status', $status );
            $order->update_meta_data( '_moyasar_payment_source', $source );
            $order->update_meta_data( '_moyasar_source_company', $company );
            $order->update_meta_data( '_moyasar_source_number', $number );
            $order->update_meta_data( '_moyasar_instant_checkout', $instant_checkout );
            $order->save();

        } catch ( Exception $e ) {
            self::log( "Error in handle_payment_success: " . $e->getMessage(), 'error' );
        }
        
        // Lock will expire automatically. We don't delete it immediately to ensure any trailing requests are also blocked.

        // Save detailed payment info for display
        self::save_payment_info( $order, $payment );

        // Mark as Paid immediately to ensure it is captured
        $order->payment_complete( $payment_id );

        // Wrap optional post-processing in independent try/catch
        try {
            // Handle Coupon/Promotion
            self::handle_promotion_discount( $order, $payment );

            // Update Order Status based on Settings
            $target_status = self::get_moyasar_option( 'order_status_success', 'processing' );
            $target_status = str_replace( 'wc-', '', $target_status );

            if ( 'processing' !== $target_status && 'completed' !== $target_status ) {
                $order->update_status( $target_status, __( 'Status updated based on Moyasar settings.', 'moyasar-payments' ) );
            } elseif ( 'completed' === $target_status ) {
                $order->update_status( 'completed', __( 'Status updated based on Moyasar settings.', 'moyasar-payments' ) );
            }
        } catch ( Exception $e ) {
            self::log( "Error in post-completion processing: " . $e->getMessage(), 'error' );
        }

        // Clean up the option lock on completion
        delete_option( $lock_key );
    }

    /**
     * Handle Failed Payment Logic Centrally
     * 
     * @param WC_Order $order
     * @param mixed $payment_or_message
     * @param string $source
     */
    public static function handle_payment_failed( $order, $payment_or_message, $source = 'unknown' ) {
        if ( ! $order ) {
            return;
        }

        // Guard against overwriting already paid/completed/processing orders (BUG-3)
        $success_status = self::get_moyasar_option( 'order_status_success', 'processing' );
        $success_status = str_replace( 'wc-', '', $success_status );
        $protected_statuses = array_unique( array( 'processing', 'completed', $success_status ) );

        if ( $order->has_status( $protected_statuses ) ) {
            self::log( "handle_payment_failed: Order #{$order->get_id()} is already paid/completed. Skipping status update to failed.", 'debug' );
            return;
        }

        $message = 'Unknown Error';
        if ( is_array( $payment_or_message ) ) {
            if ( isset( $payment_or_message['source']['message'] ) && ! empty( $payment_or_message['source']['message'] ) ) {
                $message = $payment_or_message['source']['message'];
            } elseif ( isset( $payment_or_message['message'] ) && ! empty( $payment_or_message['message'] ) ) {
                $message = $payment_or_message['message'];
            }
        } elseif ( is_string( $payment_or_message ) ) {
            $message = $payment_or_message;
        }
        
        $payment_id = is_array( $payment_or_message ) && isset( $payment_or_message['id'] ) 
            ? $payment_or_message['id'] 
            : '';

        if ( ! empty( $payment_id ) ) {
            $failed_ids = $order->get_meta( '_moyasar_failed_payment_ids' );
            if ( ! is_array( $failed_ids ) ) {
                $failed_ids = ! empty( $failed_ids ) ? array( $failed_ids ) : array();
            }
            if ( in_array( $payment_id, $failed_ids, true ) ) {
                self::log( "handle_payment_failed: Duplicate failure check. Payment ID $payment_id already handled for failure on Order #{$order->get_id()} (Source: $source). Skipping.", 'debug' );
                return;
            }
            $failed_ids[] = $payment_id;
            $order->update_meta_data( '_moyasar_failed_payment_ids', $failed_ids );
            $order->save();
        }

        $display_source = ucfirst( str_replace( '_', ' ', $source ) );

        $log_message = "Moyasar Payment Failed:\n" .
                       "Message: {$message}\n" .
                       "Payment ID: {$payment_id}\n" .
                       "Origin: {$display_source}";

        $order->add_order_note( $log_message );
        
        // Update Order Status based on Settings
        $target_status = self::get_moyasar_option( 'order_status_failed', 'failed' );
        $target_status = str_replace( 'wc-', '', $target_status );

        $order->update_status( $target_status, __( 'Payment failed via Moyasar.', 'moyasar-payments' ) );
    }

    public static function is_subscription_order( $order ) {
        if ( ! $order ) {
            return false;
        }

        // 1. Official WooCommerce Subscriptions checks
        if ( function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $order ) ) {
            return true;
        }
        if ( function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( $order ) ) {
            return true;
        }

        // 2. Check for WP Swings Subscriptions (custom order type wps_subscriptions).
        //
        // wc_get_orders() only honours `meta_query` on HPOS. On legacy post-table
        // (CPT) storage WooCommerce 9.2.0+ rejects the `meta_query` arg with a
        // `_doing_it_wrong` notice and silently discards it, so the query would
        // degrade to "newest wps_subscriptions order, limit 1" and misdetect a
        // regular order as a subscription. Branch on the active storage engine and
        // query the post meta directly on legacy storage (see SDK-128).
        if ( self::is_hpos_enabled() ) {
            $swings_subs = wc_get_orders( array(
                'limit'      => 1,
                'return'     => 'ids',
                'type'       => 'wps_subscriptions',
                'meta_query' => array(
                    array(
                        'key'     => 'wps_parent_order',
                        'value'   => $order->get_id(),
                        'compare' => '=',
                    ),
                ),
            ) );

            if ( ! empty( $swings_subs ) ) {
                return true;
            }
        } else {
            global $wpdb;
            $swings_sub_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT pm.post_id
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = 'wps_parent_order'
                   AND pm.meta_value = %d
                   AND p.post_type = 'wps_subscriptions'
                 LIMIT 1",
                $order->get_id()
            ) );

            if ( $swings_sub_id > 0 ) {
                return true;
            }
        }

        // 2.5 Check for SpringDevs Subscriptions (custom post type subscrpt_order)
        if ( post_type_exists( 'subscrpt_order' ) ) {
            $args = array(
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'post_type'      => 'subscrpt_order',
                'meta_query'     => array(
                    array(
                        'key'     => '_subscrpt_order_id',
                        'value'   => $order->get_id(),
                        'compare' => '=',
                    ),
                ),
            );
            $sdevs_subs = get_posts( $args );
            if ( ! empty( $sdevs_subs ) ) {
                return true;
            }
        }

        // 3. Loop items to check types (contains 'subscription') or WP Swings/SpringDevs meta keys
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            if ( $product ) {
                $product_id   = $product->get_id();
                $parent_id    = $product->get_parent_id();
                $product_type = $product->get_type();

                if ( strpos( $product_type, 'subscription' ) !== false ) {
                    return true;
                }

                if ( function_exists( 'wps_sfw_check_product_is_subscription' ) && wps_sfw_check_product_is_subscription( $product ) ) {
                    return true;
                }

                if ( 'yes' === get_post_meta( $product_id, '_wps_sfw_product', true ) ) {
                    return true;
                }

                if ( $parent_id && 'yes' === get_post_meta( $parent_id, '_wps_sfw_product', true ) ) {
                    return true;
                }

                if ( $product->get_meta( '_subscrpt_enabled' ) || get_post_meta( $product_id, '_subscrpt_enabled', true ) ) {
                    return true;
                }

                if ( $parent_id && get_post_meta( $parent_id, '_subscrpt_enabled', true ) ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Safely parse a JSON token payload received from client-side wallet SDKs (Samsung Pay, Apple Pay, etc.)
     * without corrupting backslashes or internal quotes via over-sanitization.
     *
     * @param mixed $raw_data Raw POST token string, array, or object.
     * @return array|null Array token structure for Moyasar API source, or null if invalid/empty.
     */
    public static function parse_json_token( $raw_data ) {
        if ( empty( $raw_data ) ) {
            return null;
        }

        if ( is_array( $raw_data ) ) {
            return $raw_data;
        }

        if ( is_object( $raw_data ) ) {
            return (array) $raw_data;
        }

        if ( ! is_string( $raw_data ) ) {
            return null;
        }

        // Unslash POST data to strip slashes added by PHP/WordPress magic quotes.
        $unslashed = wp_unslash( trim( $raw_data ) );

        if ( '' === $unslashed ) {
            return null;
        }

        // Decode JSON without calling extra stripslashes() which corrupts backslashes inside payload strings.
        $decoded = json_decode( $unslashed, true );

        // Handle double-encoded JSON strings if the client stringified the payload twice.
        if ( is_string( $decoded ) ) {
            $decoded = json_decode( $decoded, true );
        }

        // Fallback: If unslashed decoding failed, attempt decoding raw string directly.
        if ( null === $decoded && $raw_data !== $unslashed ) {
            $decoded = json_decode( trim( $raw_data ), true );
            if ( is_string( $decoded ) ) {
                $decoded = json_decode( $decoded, true );
            }
        }

        return ( is_array( $decoded ) && ! empty( $decoded ) ) ? $decoded : null;
    }

    /**
     * Get the raw site home URL without WPML or translation language directories (e.g. /ar/).
     *
     * @param string $path Optional path to append.
     * @return string
     */
    public static function get_raw_home_url( $path = '' ) {
        $home = get_option( 'home' );
        if ( empty( $home ) ) {
            $home = get_option( 'siteurl' );
        }
        $home_url = untrailingslashit( $home );
        if ( ! empty( $path ) ) {
            $home_url .= '/' . ltrim( $path, '/' );
        }
        return $home_url;
    }

    /**
     * Get the canonical Webhook URL without language prefixes (WPML-safe).
     *
     * @return string
     */
    public static function get_canonical_webhook_url() {
        return self::get_raw_home_url( 'wc-api/moyasar_webhook/' );
    }

    /**
     * Get the canonical Apple Pay domain verification URL without language prefixes (WPML-safe).
     *
     * @return string
     */
    public static function get_canonical_apple_pay_domain_file_url() {
        return self::get_raw_home_url( '.well-known/apple-developer-merchantid-domain-association' );
    }

    /**
     * Safely parse and extract the encrypted Samsung Pay token payload string for Moyasar API.
     * Extracts the encrypted JWE string (e.g. 3DS.data or data) from the Samsung Pay SDK response wrapper.
     *
     * @param mixed $raw_token Raw POST token string, array, or object.
     * @return string|null Encrypted token payload string for Moyasar API source, or null if invalid/empty.
     */
    public static function parse_samsung_pay_token( $raw_token ) {
        if ( empty( $raw_token ) ) {
            return null;
        }

        $decoded = null;
        if ( is_array( $raw_token ) || is_object( $raw_token ) ) {
            $decoded = (array) $raw_token;
        } else if ( is_string( $raw_token ) ) {
            $unslashed = wp_unslash( trim( $raw_token ) );
            if ( '' === $unslashed ) {
                return null;
            }
            $parsed = json_decode( $unslashed, true );
            if ( is_string( $parsed ) ) {
                $parsed = json_decode( $parsed, true );
            }
            if ( is_array( $parsed ) ) {
                $decoded = $parsed;
            } else {
                return $unslashed;
            }
        }

        if ( is_array( $decoded ) ) {
            if ( isset( $decoded['3DS']['data'] ) && is_string( $decoded['3DS']['data'] ) && ! empty( $decoded['3DS']['data'] ) ) {
                return $decoded['3DS']['data'];
            }
            if ( isset( $decoded['3ds']['data'] ) && is_string( $decoded['3ds']['data'] ) && ! empty( $decoded['3ds']['data'] ) ) {
                return $decoded['3ds']['data'];
            }
            if ( isset( $decoded['data'] ) && is_string( $decoded['data'] ) && ! empty( $decoded['data'] ) ) {
                return $decoded['data'];
            }
            if ( isset( $decoded['token'] ) && is_string( $decoded['token'] ) && ! empty( $decoded['token'] ) ) {
                return $decoded['token'];
            }
        }

        return is_string( $raw_token ) ? wp_unslash( trim( $raw_token ) ) : null;
    }

    /**
     * Check if manual capture (authorization only) is enabled.
     *
     * @return bool
     */
    public static function is_manual() {
        return 'yes' === self::get_moyasar_option( 'manual', 'no' );
    }

    /**
     * Register custom 'wc-authorized' order status in WooCommerce.
     */
    public static function register_authorized_order_status() {
        register_post_status( 'wc-authorized', array(
            'label'                     => _x( 'Authorized', 'Order status', 'moyasar-payments' ),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop( 'Authorized <span class="count">(%s)</span>', 'Authorized <span class="count">(%s)</span>', 'moyasar-payments' ),
        ) );
    }

    /**
     * Add 'wc-authorized' to WooCommerce order statuses list.
     *
     * @param array $order_statuses
     * @return array
     */
    public static function add_authorized_to_order_statuses( $order_statuses ) {
        $new_order_statuses = array();
        foreach ( $order_statuses as $key => $status ) {
            $new_order_statuses[ $key ] = $status;
            if ( 'wc-on-hold' === $key ) {
                $new_order_statuses['wc-authorized'] = _x( 'Authorized', 'Order status', 'moyasar-payments' );
            }
        }
        if ( ! isset( $new_order_statuses['wc-authorized'] ) ) {
            $new_order_statuses['wc-authorized'] = _x( 'Authorized', 'Order status', 'moyasar-payments' );
        }
        return $new_order_statuses;
    }

    /**
     * Allow orders in 'authorized' status to be completed via $order->payment_complete().
     *
     * @param array    $statuses
     * @param WC_Order $order
     * @return array
     */
    public static function add_authorized_to_valid_payment_complete_statuses( $statuses, $order = null ) {
        if ( ! in_array( 'authorized', $statuses, true ) ) {
            $statuses[] = 'authorized';
        }
        return $statuses;
    }

    /**
     * Handle Authorized Payment Logic Centrally (Authorize Only / Manual Capture)
     *
     * @param WC_Order $order
     * @param array    $payment
     * @param string   $source
     */
    public static function handle_payment_authorized( $order, $payment, $source = 'unknown' ) {
        if ( ! $order || empty( $payment ) ) {
            return;
        }

        $payment_id = isset( $payment['id'] ) ? $payment['id'] : '';
        $amount     = isset( $payment['amount'] ) ? $payment['amount'] : 0;
        $currency   = isset( $payment['currency'] ) ? $payment['currency'] : $order->get_currency();
        $status     = isset( $payment['status'] ) ? $payment['status'] : 'authorized';
        $message    = $payment['source']['message'] ?? $payment['message'] ?? 'Authorized';

        // Race condition lock
        $lock_key = 'moyasar_lock_' . $order->get_id();
        $now = time();
        if ( ! add_option( $lock_key, $now, '', 'no' ) ) {
            $lock_time = (int) get_option( $lock_key );
            if ( $lock_time > 0 && ( $now - $lock_time ) < 15 ) {
                self::log( "Race Condition Avoided: Order #{$order->get_id()} is locked for authorization. Skipping.", 'debug' );
                return;
            }
            update_option( $lock_key, $now );
        }

        try {
            $existing_payment_id = $order->get_meta( '_moyasar_payment_id' );
            $existing_status     = $order->get_meta( '_moyasar_payment_status' );
            if ( ! empty( $existing_payment_id ) && $existing_payment_id === $payment_id && ( 'authorized' === $existing_status || 'captured' === $existing_status ) ) {
                if ( $order->has_status( array( 'authorized', 'processing', 'completed' ) ) ) {
                    self::log( "Duplicate Authorization Handling Skipped for Order #{$order->get_id()} (Source: $source). Payment ID $payment_id already verified.", 'debug' );
                    delete_option( $lock_key );
                    return;
                }
            }

            $source_data = isset( $payment['source'] ) ? $payment['source'] : array();
            $source_type = isset( $source_data['type'] ) ? $source_data['type'] : 'unknown';
            $company     = isset( $source_data['company'] ) ? $source_data['company'] : $source_type;
            $number      = isset( $source_data['number'] ) ? $source_data['number'] : '****';

            $display_source = ucfirst( str_replace( '_', ' ', $source ) );
            $log_message = "Moyasar Payment Authorized:\n" .
                           "Payment ID: {$payment_id}\n" .
                           "Status: {$status}\n" .
                           "Amount: " . self::from_minor_units( $amount, $currency ) . " " . $currency . "\n" .
                           "Source Type: " . ucfirst( $source_type ) . " (" . ucfirst( $company ) . ")\n" .
                           "Message: {$message}\n" .
                           "Origin: {$display_source}";

            $order->add_order_note( $log_message );
            self::log( "Payment Authorized Handled: Order #{$order->get_id()} (Source: {$source}, Payment ID: {$payment_id})", 'info' );

            $order->set_transaction_id( $payment_id );
            $order->update_meta_data( '_moyasar_payment_id', $payment_id );
            $order->update_meta_data( '_moyasar_payment_status', 'authorized' );
            $order->update_meta_data( '_moyasar_payment_source', $source );
            $order->update_meta_data( '_moyasar_source_company', $company );
            $order->update_meta_data( '_moyasar_source_number', $number );
            $order->save();

            self::save_payment_info( $order, $payment );

            // Reserve stock for authorized order
            wc_reduce_stock_levels( $order->get_id() );

            // Clear customer cart
            if ( function_exists( 'WC' ) && WC()->cart ) {
                WC()->cart->empty_cart();
            }

            // Update status to authorized
            $target_status = self::get_moyasar_option( 'order_status_authorized', 'wc-authorized' );
            $target_status = str_replace( 'wc-', '', $target_status );
            $order->update_status( $target_status, __( 'Payment authorized via Moyasar. Awaiting manual capture.', 'moyasar-payments' ) );

        } catch ( Exception $e ) {
            self::log( "Error in handle_payment_authorized: " . $e->getMessage(), 'error' );
        }

        delete_option( $lock_key );
    }

    /**
     * Handle Captured Payment Logic (from Admin Order Page or Webhook)
     *
     * @param WC_Order $order
     * @param array    $payment
     * @param string   $source
     */
    public static function handle_payment_captured( $order, $payment, $source = 'unknown' ) {
        if ( ! $order || empty( $payment ) ) {
            return;
        }

        $payment_id = isset( $payment['id'] ) ? $payment['id'] : '';
        $amount     = isset( $payment['amount'] ) ? $payment['amount'] : 0;
        $currency   = isset( $payment['currency'] ) ? $payment['currency'] : $order->get_currency();
        $status     = isset( $payment['status'] ) ? $payment['status'] : 'captured';

        // Idempotency: prevent duplicate capture processing
        $existing_status = $order->get_meta( '_moyasar_payment_status' );
        if ( 'captured' === $existing_status && $order->has_status( array( 'processing', 'completed' ) ) ) {
            self::log( "handle_payment_captured: Order #{$order->get_id()} is already captured and completed. Skipping.", 'debug' );
            return;
        }

        $lock_key = 'moyasar_lock_' . $order->get_id();
        $now = time();
        if ( ! add_option( $lock_key, $now, '', 'no' ) ) {
            $lock_time = (int) get_option( $lock_key );
            if ( $lock_time > 0 && ( $now - $lock_time ) < 15 ) {
                self::log( "Race Condition Avoided: Order #{$order->get_id()} is locked for capture. Skipping.", 'debug' );
                return;
            }
            update_option( $lock_key, $now );
        }

        try {
            $origin = 'webhook' === $source ? __( 'Moyasar Dashboard (Webhook)', 'moyasar-payments' ) : __( 'Order Admin', 'moyasar-payments' );
            $log_message = sprintf(
                "Moyasar Payment Captured via %s:\nPayment ID: %s\nStatus: %s\nAmount: %s %s",
                $origin,
                $payment_id,
                $status,
                self::from_minor_units( $amount, $currency ),
                $currency
            );

            $order->add_order_note( $log_message );
            self::log( "Payment Captured Handled: Order #{$order->get_id()} (Source: {$source}, Payment ID: {$payment_id})", 'info' );

            $order->update_meta_data( '_moyasar_payment_status', 'captured' );
            $order->update_meta_data( '_moyasar_captured_at', current_time( 'mysql' ) );
            $order->save();

            self::save_payment_info( $order, $payment );

            // Mark payment complete in WooCommerce
            $order->payment_complete( $payment_id );

            // Update status based on store success status setting
            $target_status = self::get_moyasar_option( 'order_status_success', 'processing' );
            $target_status = str_replace( 'wc-', '', $target_status );

            if ( ! $order->has_status( $target_status ) ) {
                $order->update_status( $target_status, __( 'Status updated based on Moyasar settings after capture.', 'moyasar-payments' ) );
            }
        } catch ( Exception $e ) {
            self::log( "Error in handle_payment_captured: " . $e->getMessage(), 'error' );
        }

        delete_option( $lock_key );
    }

    /**
     * Process Capture via Moyasar API
     *
     * @param int|WC_Order $order
     * @param float|null   $amount Optional capture amount (null for full capture)
     * @param string|null  $api_key
     * @return true|WP_Error
     */
    public static function process_capture( $order, $amount = null, $api_key = null ) {
        if ( is_numeric( $order ) ) {
            $order = wc_get_order( $order );
        }

        if ( ! $order ) {
            self::log( "Capture Error: Order not found.", 'error' );
            return new WP_Error( 'moyasar_capture_error', __( 'Order not found.', 'moyasar-payments' ) );
        }

        if ( empty( $api_key ) ) {
            $api_key = self::get_api_key_from_order( $order );
        }

        $payment_id = $order->get_transaction_id();
        if ( empty( $payment_id ) ) {
            $payment_id = $order->get_meta( '_moyasar_payment_id' );
        }

        if ( empty( $payment_id ) ) {
            self::log( "Capture Error: No payment ID for Order #" . $order->get_id(), 'error' );
            return new WP_Error( 'moyasar_capture_error', __( 'Payment ID not found for this order.', 'moyasar-payments' ) );
        }

        $amount_minor = null;
        if ( ! empty( $amount ) && is_numeric( $amount ) && $amount > 0 ) {
            $amount_minor = self::to_minor_units( $amount, $order->get_currency() );
        }

        self::log( "Attempting Capture for Order #" . $order->get_id() . " (Payment ID: $payment_id, Amount: " . ( $amount ? $amount : 'Full' ) . ")", 'info' );

        $response = Moyasar_API::capture_payment( $payment_id, $amount_minor, $api_key );

        if ( is_wp_error( $response ) ) {
            $error_message = $response->get_error_message();
            self::log( "Capture API Failed (Order #{$order->get_id()}): " . $error_message, 'error' );
            return $response;
        }

        if ( isset( $response['status'] ) && 'captured' === $response['status'] ) {
            self::log( "Capture Success for Order #" . $order->get_id(), 'info' );
            self::handle_payment_captured( $order, $response, 'admin_capture' );
            return true;
        } else {
            $msg = isset( $response['message'] ) ? $response['message'] : __( 'Capture failed on Moyasar.', 'moyasar-payments' );
            self::log( "Capture API returned non-captured status: " . ( $response['status'] ?? 'none' ) . " - $msg", 'error' );
            return new WP_Error( 'moyasar_capture_error', $msg );
        }
    }

    /**
     * Validate a single API key against Moyasar format requirements and live API.
     *
     * @since 8.4.1
     * @param string $key  The API key to test.
     * @param string $type 'publishable' or 'secret'.
     * @param string $mode 'test' or 'live'.
     * @return array Array with keys: 'status', 'valid', 'message'.
     */
    public static function validate_api_key( $key, $type = 'publishable', $mode = null ) {
        $key = trim( (string) $key );

        if ( empty( $key ) ) {
            return array(
                'status'  => 'empty',
                'valid'   => false,
                'message' => __( 'Key is not configured.', 'moyasar-payments' ),
            );
        }

        $expected_prefix = ( 'publishable' === $type ? 'pk_' : 'sk_' ) . ( 'live' === $mode ? 'live_' : ( 'test' === $mode ? 'test_' : '' ) );

        if ( ! empty( $expected_prefix ) && strpos( $key, $expected_prefix ) !== 0 ) {
            if ( 'publishable' === $type && strpos( $key, 'sk_' ) === 0 ) {
                $msg = sprintf( __( 'This is a Secret Key. Please enter a Publishable Key (starting with %s) here.', 'moyasar-payments' ), $expected_prefix );
            } elseif ( 'secret' === $type && strpos( $key, 'pk_' ) === 0 ) {
                $msg = sprintf( __( 'This is a Publishable Key. Please enter a Secret Key (starting with %s) here.', 'moyasar-payments' ), $expected_prefix );
            } elseif ( 'test' === $mode && strpos( $key, '_live_' ) !== false ) {
                $msg = sprintf( __( 'This is a Live key. For Test mode, the key must start with %s.', 'moyasar-payments' ), $expected_prefix );
            } elseif ( 'live' === $mode && strpos( $key, '_test_' ) !== false ) {
                $msg = sprintf( __( 'This is a Test key. For Live mode, the key must start with %s.', 'moyasar-payments' ), $expected_prefix );
            } else {
                $msg = sprintf( __( 'Key must start with "%s".', 'moyasar-payments' ), $expected_prefix );
            }

            return array(
                'status'  => 'format_error',
                'valid'   => false,
                'message' => $msg,
            );
        }

        // Test key with Moyasar API
        if ( 'secret' === $type ) {
            $response = wp_remote_get( 'https://api.moyasar.com/v1/payments?limit=1', array(
                'timeout' => 15,
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode( $key . ':' ),
                    'Content-Type'  => 'application/json',
                    'version'       => 'woo_' . ( defined( 'MOYASAR_WC_VERSION' ) ? MOYASAR_WC_VERSION : '8.4.0' ),
                ),
            ) );
        } else {
            // For Publishable Key: POST to /v1/tokens with empty payload
            $response = wp_remote_post( 'https://api.moyasar.com/v1/tokens', array(
                'timeout' => 15,
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode( $key . ':' ),
                    'Content-Type'  => 'application/json',
                    'version'       => 'woo_' . ( defined( 'MOYASAR_WC_VERSION' ) ? MOYASAR_WC_VERSION : '8.4.0' ),
                ),
                'body'    => wp_json_encode( array() ),
            ) );
        }

        if ( is_wp_error( $response ) ) {
            return array(
                'status'  => 'connection_error',
                'valid'   => false,
                'message' => sprintf( __( 'Connection to Moyasar failed: %s', 'moyasar-payments' ), $response->get_error_message() ),
            );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 'secret' === $type ) {
            if ( 200 === $code ) {
                return array(
                    'status'  => 'valid',
                    'valid'   => true,
                    'message' => __( 'Secret Key is valid and active on Moyasar.', 'moyasar-payments' ),
                );
            } elseif ( 401 === $code ) {
                return array(
                    'status'  => 'invalid',
                    'valid'   => false,
                    'message' => __( 'Invalid authorization credentials. Please verify your Secret Key against your Moyasar Dashboard.', 'moyasar-payments' ),
                );
            } else {
                $msg = isset( $body['message'] ) ? $body['message'] : sprintf( __( 'Moyasar API returned HTTP %d', 'moyasar-payments' ), $code );
                return array(
                    'status'  => 'error',
                    'valid'   => false,
                    'message' => $msg,
                );
            }
        } else {
            // Publishable Key: HTTP 400 with validation_error means credentials authenticated
            if ( 400 === $code && isset( $body['type'] ) && 'validation_error' === $body['type'] ) {
                return array(
                    'status'  => 'valid',
                    'valid'   => true,
                    'message' => __( 'Publishable Key is valid and active on Moyasar.', 'moyasar-payments' ),
                );
            } elseif ( 401 === $code ) {
                return array(
                    'status'  => 'invalid',
                    'valid'   => false,
                    'message' => __( 'Invalid authorization credentials. Please verify your Publishable Key against your Moyasar Dashboard.', 'moyasar-payments' ),
                );
            } else {
                $msg = isset( $body['message'] ) ? $body['message'] : sprintf( __( 'Moyasar API returned HTTP %d', 'moyasar-payments' ), $code );
                return array(
                    'status'  => 'error',
                    'valid'   => false,
                    'message' => $msg,
                );
            }
        }
    }

    /**
     * Validate all Moyasar API keys (Test and Live).
     *
     * @since 8.4.1
     * @param array     $keys      Optional associative array with key overrides.
     * @param bool|null $test_mode Optional whether store is currently in test mode.
     * @return array
     */
    public static function validate_all_keys( $keys = array(), $test_mode = null ) {
        if ( null === $test_mode ) {
            $test_mode = 'yes' === self::get_moyasar_option( 'testmode' );
        }

        $test_pk = isset( $keys['test_publishable_key'] ) ? $keys['test_publishable_key'] : self::get_moyasar_option( 'test_publishable_key', '' );
        $test_sk = isset( $keys['test_secret_key'] ) ? $keys['test_secret_key'] : self::get_moyasar_option( 'test_secret_key', '' );
        $live_pk = isset( $keys['live_publishable_key'] ) ? $keys['live_publishable_key'] : self::get_moyasar_option( 'live_publishable_key', '' );
        $live_sk = isset( $keys['live_secret_key'] ) ? $keys['live_secret_key'] : self::get_moyasar_option( 'live_secret_key', '' );

        $results = array(
            'test_mode' => $test_mode,
            'keys'      => array(
                'test_publishable_key' => self::validate_api_key( $test_pk, 'publishable', 'test' ),
                'test_secret_key'      => self::validate_api_key( $test_sk, 'secret', 'test' ),
                'live_publishable_key' => self::validate_api_key( $live_pk, 'publishable', 'live' ),
                'live_secret_key'      => self::validate_api_key( $live_sk, 'secret', 'live' ),
            ),
        );

        $active_pk_res = $test_mode ? $results['keys']['test_publishable_key'] : $results['keys']['live_publishable_key'];
        $active_sk_res = $test_mode ? $results['keys']['test_secret_key'] : $results['keys']['live_secret_key'];

        $active_valid = ( ! empty( $active_pk_res['valid'] ) && ! empty( $active_sk_res['valid'] ) );
        $active_empty = ( 'empty' === $active_pk_res['status'] && 'empty' === $active_sk_res['status'] );

        $results['active_environment'] = $test_mode ? 'test' : 'live';
        $results['active_valid']       = $active_valid;

        if ( $active_valid ) {
            $results['summary'] = sprintf(
                __( 'Active %s Mode keys are valid and ready to accept payments.', 'moyasar-payments' ),
                $test_mode ? __( 'Test', 'moyasar-payments' ) : __( 'Live', 'moyasar-payments' )
            );
        } elseif ( $active_empty ) {
            $results['summary'] = sprintf(
                __( 'API keys for %s Mode are not configured.', 'moyasar-payments' ),
                $test_mode ? __( 'Test', 'moyasar-payments' ) : __( 'Live', 'moyasar-payments' )
            );
        } else {
            $results['summary'] = sprintf(
                __( 'Issues detected with your %s Mode API keys. Please check the details below.', 'moyasar-payments' ),
                $test_mode ? __( 'Test', 'moyasar-payments' ) : __( 'Live', 'moyasar-payments' )
            );
        }

        return $results;
    }
}

