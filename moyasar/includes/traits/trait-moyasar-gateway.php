<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Moyasar Gateway Trait.
 *
 * @since      8.0.0
 * @package    Moyasar/includes/traits
 */
trait Moyasar_Gateway_Trait {

    /**
     * Filter Gateway Icon.
     * Removes the icon from the checkout page if needed.
     *
     * @param string $icon Gateway Icon HTML.
     * @param string $id   Gateway ID.
     * @return string
     */
    public function filter_gateway_icon( $icon, $id ) {
        if ( $id === 'moyasar_cc' || $id === 'moyasar_invoice' ) {
            return '';
        }
        $dir = is_rtl() ? 'left' : 'right';
        if ( $id === $this->id && ( is_checkout() || is_cart() ) && ! is_admin() ) {
            if ( ! empty( $this->moyasar_icons ) ) {
                $height = Moyasar_Helper::get_moyasar_option( 'gateway_icon_height', '' );
                $icon_html = '<span style="float: ' . $dir . ';">';
                foreach ( $this->moyasar_icons as $icon_key ) {
                    $url = Moyasar_Helper::get_card_icon_url( $icon_key );
                    if ( $url ) {
                        if ( ! empty( $height ) ) {
                            $img_style = 'margin-right: 10px; max-height: ' . esc_attr( $height ) . 'px; height: ' . esc_attr( $height ) . 'px; display: inline;';
                        } else {
                            $img_style = 'margin-right: 10px; max-width: 45px; display: inline;';
                        }
                        $icon_html .= '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( $this->method_title ) . '" style="' . $img_style . '" />';
                    }
                }
                $icon_html .= '</span>';
                return $icon_html;
            }
        }
        return $icon;
    }

    /**
     * Get settings page link.
     * Redirects individual gateway manage buttons to the unified settings panel.
     * 
     * @return string
     */
    public function get_setting_link() {
        return admin_url( 'admin.php?page=wc-settings&tab=checkout&section=moyasar' );
    }

    /**
     * Process Refund.
     * Delegates to the shared helper method.
     *
     * @param int    $order_id Order ID.
     * @param float  $amount   Refund amount.
     * @param string $reason   Refund reason.
     * @return bool|WP_Error
     */
    public function process_refund( $order_id, $amount = null, $reason = '' ) {
        // Pass API Key from this gateway instance to the helper
        return Moyasar_Helper::process_refund( $order_id, $amount, $reason, $this->get_api_key() );
    }

    /**
     * Get HTML for "Save to Account" checkbox.
     *
     * @return string
     */
    public function get_save_payment_method_html() {
        $html = '<fieldset class="wc-save-payment-method-checkbox">
            <p class="form-row form-row-wide">
                <input type="checkbox" id="wc-' . esc_attr( $this->id ) . '-new-payment-method" name="wc-' . esc_attr( $this->id ) . '-new-payment-method" value="true" style="width:auto;" />
                <label for="wc-' . esc_attr( $this->id ) . '-new-payment-method" style="display:inline;">' . esc_html__( 'Save to account', 'moyasar-payments' ) . '</label>
            </p>
        </fieldset>';
        return $html;
    }

    /**
     * Get API Key based on current gateway settings.
     *
     * @return string
     */
    public function get_api_key() {
        // Fetch from New General Settings Gateway (moyasar)
        $test_mode = 'yes' === Moyasar_Helper::get_moyasar_option( 'testmode' );
        
        if ( $test_mode ) {
            return trim( (string) Moyasar_Helper::get_moyasar_option( 'test_secret_key', '' ) );
        }
        return trim( (string) Moyasar_Helper::get_moyasar_option( 'live_secret_key', '' ) );
    }

    /**
     * Get Publishable Key based on current gateway settings.
     *
     * @return string
     */
    public function get_publishable_key() {
        $test_mode = 'yes' === Moyasar_Helper::get_moyasar_option( 'testmode' );

        if ( $test_mode ) {
            return trim( (string) Moyasar_Helper::get_moyasar_option( 'test_publishable_key', '' ) );
        }
        return trim( (string) Moyasar_Helper::get_moyasar_option( 'live_publishable_key', '' ) );
    }

    /**
     * Check if the gateway is available for use.
     * Respects the Global "Enable Moyasar Payments" switch.
     *
     * @return bool
     */
    public function is_available() {
        // 1. Check Global Switch from Main Gateway Settings
        $global_enabled = Moyasar_Helper::get_moyasar_option( 'enabled' );
        if ( 'yes' !== $global_enabled ) {
            return false;
        }

        // 2. No API keys configured -> the gateway cannot process payments.
        if ( ! Moyasar_Helper::has_api_keys() ) {
            return false;
        }

        // 3. Standard Availability Check (Individual 'enabled' setting etc.)
        return parent::is_available();
    }

    /**
     * Process a scheduled subscription renewal payment.
     *
     * @param float    $amount_to_charge The amount to charge.
     * @param WC_Order $renewal_order    The renewal order.
     */
    public function scheduled_subscription_payment( $amount_to_charge, $renewal_order ) {
        Moyasar_Helper::log( "Scheduled Subscription Payment: Starting for Order #" . $renewal_order->get_id() . " (Amount: $amount_to_charge)", 'debug' );

        // Check if this renewal order is associated with a SpringDevs/ConversLabs subscription
        $springdevs_subscription_id = 0;
        if ( class_exists( 'SpringDevs\Subscription\Illuminate\Helper' ) ) {
            $springdevs_subs = \SpringDevs\Subscription\Illuminate\Helper::get_subscriptions_from_order( $renewal_order->get_id() );
            if ( ! empty( $springdevs_subs ) ) {
                $subscription = reset( $springdevs_subs );
                $springdevs_subscription_id = $subscription->subscription_id ?? 0;
            }
        }

        // 1. Retrieve the payment token
        $token = null;
        
        // Check tokens directly associated with the renewal order
        $tokens = $renewal_order->get_payment_tokens();
        if ( ! empty( $tokens ) ) {
            $token = WC_Payment_Tokens::get( reset( $tokens ) );
        }

        // Fallback: Check parent subscription for payment tokens
        if ( ! $token && function_exists( 'wcs_get_subscriptions_for_renewal_order' ) ) {
            $subscriptions = wcs_get_subscriptions_for_renewal_order( $renewal_order );
            foreach ( $subscriptions as $subscription ) {
                $subscription_tokens = $subscription->get_payment_tokens();
                if ( ! empty( $subscription_tokens ) ) {
                    $token = WC_Payment_Tokens::get( reset( $subscription_tokens ) );
                    break;
                }
            }
        }

        // Fallback: Check parent order (especially for WP Swings Subscriptions)
        if ( ! $token ) {
            $parent_order_id = $renewal_order->get_meta( 'wps_sfw_parent_order_id' );
            if ( $parent_order_id ) {
                $parent_order = wc_get_order( $parent_order_id );
                if ( $parent_order ) {
                    $parent_tokens = $parent_order->get_payment_tokens();
                    if ( ! empty( $parent_tokens ) ) {
                        $token = WC_Payment_Tokens::get( reset( $parent_tokens ) );
                    }
                }
            }
        }

        // Fallback: Check customer's default payment method
        if ( ! $token ) {
            $user_id = $renewal_order->get_customer_id();
            if ( $user_id ) {
                $token = WC_Payment_Tokens::get_customer_default_token( $user_id );
                
                // If no default is set, grab the first token saved under this user for this gateway
                if ( ! $token ) {
                    $customer_tokens = WC_Payment_Tokens::get_customer_tokens( $user_id, $this->id );
                    if ( ! empty( $customer_tokens ) ) {
                        $token = reset( $customer_tokens );
                    }
                }
            }
        }

        if ( ! $token ) {
            $msg = 'Moyasar Subscription Renewal Failed: No payment token found for Order #' . $renewal_order->get_id();
            Moyasar_Helper::log( $msg, 'error' );
            $renewal_order->update_status( 'failed', $msg );
            if ( $springdevs_subscription_id ) {
                do_action( 'subscrpt_subscription_payment_failed', $springdevs_subscription_id );
            }
            return;
        }

        // 2. Prepare payment payload
        $source_token = $token->get_token();
        $amount = Moyasar_Helper::to_minor_units( $amount_to_charge, $renewal_order->get_currency() );

        $callback_url = add_query_arg(
            array(
                'order_id' => $renewal_order->get_id(),
                'key'      => $renewal_order->get_order_key(),
            ),
            WC()->api_request_url( 'moyasar_callback' )
        );

        $payment_data = array(
            'amount'       => $amount,
            'currency'     => $renewal_order->get_currency(),
            'description'  => sprintf( __( 'Subscription Renewal Order #%d', 'moyasar-payments' ), $renewal_order->get_id() ),
            'callback_url' => $callback_url,
            'source'       => array(
                'type'  => 'token',
                'token' => $source_token,
            ),
            'metadata'     => $this->get_payment_metadata( $renewal_order, array( 'is_subscription' => 'true' ) )
        );

        Moyasar_Helper::log( "Scheduled Subscription Payment: Sending API request for Order #" . $renewal_order->get_id() . " using Gateway " . $this->id, 'debug' );

        // Add order note for payment attempt
        $renewal_order->add_order_note( sprintf( __( 'Moyasar: Attempting automatic renewal payment of %s using saved card.', 'moyasar-payments' ), wc_price( $amount_to_charge ) ) );

        // 3. Dispatch payment call to Moyasar API
        $response = Moyasar_API::create_payment( $payment_data, $this->get_api_key() );

        if ( is_wp_error( $response ) ) {
            $msg = 'Moyasar API Error: ' . $response->get_error_message();
            Moyasar_Helper::log( $msg, 'error' );
            $renewal_order->add_order_note( sprintf( __( 'Moyasar Subscription Renewal Failed: %s', 'moyasar-payments' ), $response->get_error_message() ) );
            $renewal_order->update_status( 'failed', $msg );
            if ( $springdevs_subscription_id ) {
                do_action( 'subscrpt_subscription_payment_failed', $springdevs_subscription_id );
            }
            return;
        }

        // 4. Handle Response Status
        if ( isset( $response['status'] ) && 'paid' === $response['status'] ) {
            $renewal_order->update_meta_data( '_moyasar_payment_id', $response['id'] ?? '' );
            if ( isset( $response['id'] ) ) {
                $renewal_order->set_transaction_id( $response['id'] );
            }
            $renewal_order->save();
            
            // Mark payment complete using centralized helper
            Moyasar_Helper::handle_payment_success( $renewal_order, $response, 'subscription_renewal' );

            // Call SpringDevs payment success handler to update subscription status and trigger hooks
            if ( $springdevs_subscription_id ) {
                \SpringDevs\Subscription\Illuminate\Helper::subscription_payment_complete( $springdevs_subscription_id, $response['id'] ?? '' );
            }
        } else {
            Moyasar_Helper::handle_payment_failed( $renewal_order, $response, 'subscription_renewal' );
            if ( $springdevs_subscription_id ) {
                do_action( 'subscrpt_subscription_payment_failed', $springdevs_subscription_id );
            }
        }
    }

    /**
     * Add token to customer account on successful completion of payment.
     *
     * @param int $order_id Order ID.
     */
    public function add_token_to_user( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_payment_method() !== $this->id ) {
            return;
        }
        
        // Save card if requested OR if the order contains a subscription (forces tokenization)
        $is_subscription = Moyasar_Helper::is_subscription_order( $order );

        if ( 'yes' !== $order->get_meta( '_moyasar_save_card' ) && ! $is_subscription ) {
            Moyasar_Helper::log( 'Moyasar Save Card: Not requested for order ' . $order_id, 'debug' );
            return;
        }
        
        // Check if token already added to avoid duplicates
        if ( 'yes' === $order->get_meta( '_moyasar_token_saved' ) ) {
            Moyasar_Helper::log( 'Moyasar Save Card: Token already saved for order ' . $order_id, 'debug' );
            return;
        }

        $payment_id = $order->get_transaction_id();
        if ( ! $payment_id ) {
             $payment_id = $order->get_meta( '_moyasar_payment_id' );
        }
        
        if ( ! $payment_id ) {
             Moyasar_Helper::log( 'Moyasar Save Card: No Payment ID found for order ' . $order_id, 'debug' );
             return;
        }
        
        // Fetch fresh payment data
        $payment = Moyasar_API::fetch_payment( $payment_id );
        if ( is_wp_error( $payment ) ) {
            Moyasar_Helper::log( 'Moyasar Save Card: Failed to fetch payment ' . $payment_id, 'debug' );
            return;
        }

        $payment_status = isset( $payment['status'] ) ? $payment['status'] : '';
        if ( 'paid' !== $payment_status ) {
            Moyasar_Helper::log( 'Moyasar Save Card: Payment ' . $payment_id . ' status is ' . $payment_status . ' (not paid). Skipping token saving.', 'debug' );
            return;
        }

        $source = isset( $payment['source'] ) ? $payment['source'] : array();
        $source_token = isset( $source['token'] ) ? $source['token'] : $order->get_meta( '_moyasar_source_token' );
        
        if ( empty( $source_token ) ) {
            Moyasar_Helper::log( 'Moyasar Save Card: No Source Token found for order ' . $order_id . ' and payment ' . $payment_id, 'debug' );
            return;
        }
        
        // If payment source has missing details (month/year), fetch Token directly
        $month = isset( $source['month'] ) ? $source['month'] : '';
        $year = isset( $source['year'] ) ? $source['year'] : '';
        $brand = isset( $source['company'] ) ? strtolower( $source['company'] ) : '';
        $last4 = isset( $source['number'] ) ? substr( $source['number'], -4 ) : '';

        if ( empty( $month ) || empty( $year ) ) {
             $token_obj = Moyasar_API::fetch_token( $source_token );
             if ( ! is_wp_error( $token_obj ) ) {
                  $month = isset( $token_obj['month'] ) ? $token_obj['month'] : '';
                  $year = isset( $token_obj['year'] ) ? $token_obj['year'] : '';
                  if ( empty( $brand ) ) $brand = isset( $token_obj['brand'] ) ? $token_obj['brand'] : ( isset( $token_obj['company'] ) ? $token_obj['company'] : '' );
                  if ( empty( $last4 ) ) $last4 = isset( $token_obj['last_four'] ) ? $token_obj['last_four'] : ( isset( $token_obj['number'] ) ? substr( $token_obj['number'], -4 ) : '' );
             }
        }

        if ( ! empty( $month ) && ! empty( $year ) ) {
            Moyasar_Helper::log( 'Moyasar Save Card: Processing token for user ' . $order->get_user_id(), 'debug' );
            
            $token = new WC_Payment_Token_CC();
            $brand = strtolower( $brand );

            // Check if token already exists for this card (same brand + last4 + expiration date)
            $saved_tokens = WC_Payment_Tokens::get_customer_tokens( $order->get_user_id(), $this->id );
            foreach ( $saved_tokens as $t ) {
                if ( $t->get_last4() === $last4 && 
                     strtolower( $t->get_card_type() ) === $brand && 
                     (int) $t->get_expiry_month() === (int) $month && 
                     (int) $t->get_expiry_year() === (int) $year ) {
                    $token = $t;
                    Moyasar_Helper::log( 'Moyasar Save Card: Updating existing token ID ' . $t->get_id(), 'debug' );
                    break;
                }
            }

            $token->set_token( $source_token );
            $token->set_gateway_id( $this->id );
            $token->set_card_type( $brand );
            $token->set_last4( $last4 );
            $token->set_expiry_month( $month );
            $token->set_expiry_year( $year );
            $token->set_user_id( $order->get_user_id() );
            $token->save();
            
            $order->add_payment_token( $token );
            $order->update_meta_data( '_moyasar_token_saved', 'yes' );
            $order->save();
            Moyasar_Helper::log( 'Moyasar Save Card: Token saved successfully (ID: ' . $token->get_id() . ')', 'debug' );

            // WooCommerce Subscriptions support: add token to subscription objects
            if ( function_exists( 'wcs_get_subscriptions_for_order' ) ) {
                $subscriptions = wcs_get_subscriptions_for_order( $order );
                if ( ! empty( $subscriptions ) ) {
                    foreach ( $subscriptions as $subscription ) {
                        $subscription->add_payment_token( $token );
                        $subscription->save();
                        Moyasar_Helper::log( 'Moyasar Save Card: Token added to WooCommerce Subscription #' . $subscription->get_id(), 'debug' );
                    }
                }
            }
        } else {
             Moyasar_Helper::log( 'Moyasar Save Card: Source not tokenizable or empty token.', 'debug' );
        }
    }

    /**
     * Process WP Swings Subscriptions renewal payment.
     *
     * @param WC_Order $renewal_order    The renewal order.
     * @param int      $subscription_id The subscription ID.
     * @param string   $payment_method   The selected payment method ID.
     */
    public function wps_sfw_process_other_gateway_renewal( $renewal_order, $subscription_id, $payment_method ) {
        if ( $payment_method !== $this->id ) {
            return;
        }

        Moyasar_Helper::log( "WP Swings Renewal: Triggered for Order #" . $renewal_order->get_id() . " using Gateway " . $this->id, 'debug' );

        // Call the central renewal payment processor
        $this->scheduled_subscription_payment( $renewal_order->get_total(), $renewal_order );
    }

    /**
     * Process SpringDevs Subscriptions (Subscription & Recurring Payment for WooCommerce) renewal payment.
     *
     * @param WC_Order $new_order       Renewal order.
     * @param WC_Order $old_order       Parent order.
     * @param int      $subscription_id Subscription ID.
     */
    public function subscrpt_after_create_renew_order( $new_order, $old_order, $subscription_id ) {
        // Only process if the parent order paid with this gateway
        if ( $old_order->get_payment_method() !== $this->id ) {
            return;
        }

        Moyasar_Helper::log( "SpringDevs Renewal: Triggered for Order #" . $new_order->get_id() . " using Gateway " . $this->id, 'debug' );

        // Copy payment tokens from old order to new renewal order to ensure scheduled_subscription_payment finds it
        $parent_tokens = $old_order->get_payment_tokens();
        if ( ! empty( $parent_tokens ) ) {
            foreach ( $parent_tokens as $token_id ) {
                $new_order->add_payment_token( WC_Payment_Tokens::get( $token_id ) );
            }
            $new_order->save();
        }

        // Call the central renewal payment processor
        $this->scheduled_subscription_payment( $new_order->get_total(), $new_order );
    }

    /**
     * Build rich payment metadata to attach to every Moyasar API payment request.
     * Mirrors the full metadata set from v7 (customer, product, environment).
     *
     * @param WC_Order $order
     * @param array    $extra  Optional additional scalar fields to merge in.
     * @return array
     */
    protected function get_payment_metadata( $order, array $extra = array() ) {
        $metadata = array();

        try {
            $order_id = is_object( $order ) && method_exists( $order, 'get_id' ) ? (string) $order->get_id() : '';

            // Core order fields
            $metadata['order_id']               = $order_id;
            $metadata['moyasar_payment_method'] = isset( $this->id ) ? $this->id : '';
            $metadata['wc_order_key']           = is_object( $order ) && method_exists( $order, 'get_order_key' ) ? $order->get_order_key() : '';
            $metadata['wc_customer_id']         = is_object( $order ) && method_exists( $order, 'get_customer_id' ) ? (string) $order->get_customer_id() : '';
            $metadata['customer_email']         = is_object( $order ) && method_exists( $order, 'get_billing_email' ) ? $order->get_billing_email() : '';

            // Environment fields
            $metadata['site_url']               = function_exists( 'site_url' ) ? site_url() : '';
            $metadata['moyasar_plugin_version'] = defined( 'MOYASAR_WC_VERSION' ) ? MOYASAR_WC_VERSION : '';
            $metadata['wp_version']             = isset( $GLOBALS['wp_version'] ) ? $GLOBALS['wp_version'] : '';
            $metadata['wc_version']             = defined( 'WC_VERSION' ) ? WC_VERSION : '';
            $metadata['wp_environment']         = function_exists( 'wp_get_environment_type' )
                ? wp_get_environment_type()
                : ( defined( 'WP_DEBUG' ) && WP_DEBUG ? 'development' : 'production' );

            // Address fields (JSON-encoded)
            if ( is_object( $order ) && method_exists( $order, 'has_shipping_address' ) && $order->has_shipping_address() ) {
                $metadata['shipping_address'] = $this->stringify_address_for_metadata( (array) $order->get_address( 'shipping' ) );
            }
            if ( is_object( $order ) && method_exists( $order, 'has_billing_address' ) && $order->has_billing_address() ) {
                $metadata['billing_address'] = $this->stringify_address_for_metadata( (array) $order->get_address( 'billing' ) );
            }

            // Product fields
            if ( is_object( $order ) && method_exists( $order, 'get_items' ) ) {
                $product_map = array();
                foreach ( (array) $order->get_items() as $item ) {
                    if ( ! is_object( $item ) ) continue;
                    $pid  = method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0;
                    $name = method_exists( $item, 'get_name' ) ? $item->get_name() : '';
                    if ( $pid > 0 && ! isset( $product_map[ $pid ] ) ) {
                        $product_map[ $pid ] = substr( (string) $name, 0, 255 );
                    }
                }
                if ( ! empty( $product_map ) ) {
                    $metadata['product_ids'] = implode( ',', array_keys( $product_map ) );
                    if ( count( $product_map ) <= 10 ) {
                        foreach ( $product_map as $pid => $name ) {
                            $metadata[ 'product_' . $pid ] = (string) $name;
                        }
                    }
                }
            }
        } catch ( Exception $e ) {
            Moyasar_Helper::log( 'Moyasar: Failed to build payment metadata. ' . $e->getMessage(), 'warning' );
        }

        // Merge caller-supplied extra fields (scalars only, nulls/empties dropped)
        foreach ( $extra as $key => $value ) {
            if ( ! is_string( $key ) || $key === '' || $value === null || $value === '' ) continue;
            if ( is_array( $value ) ) {
                $encoded = wp_json_encode( $value );
                if ( $encoded === false ) continue;
                $metadata[ $key ] = $encoded;
            } else {
                $metadata[ $key ] = (string) $value;
            }
        }

        // Drop empty strings so the API payload stays clean
        return array_filter( $metadata, function( $v ) { return $v !== '' && $v !== null; } );
    }

    private function stringify_address_for_metadata( array $address, $max_len = 500 ) {
        $clean = array();
        foreach ( $address as $k => $v ) {
            if ( ! is_string( $k ) || $k === '' || $v === null ) continue;
            $v = is_scalar( $v ) ? trim( preg_replace( '/\s+/', ' ', (string) $v ) ) : '';
            if ( $v !== '' ) $clean[ $k ] = $v;
        }
        if ( empty( $clean ) ) return '';
        $json = wp_json_encode( $clean );
        if ( ! is_string( $json ) ) return '';
        return strlen( $json ) >= $max_len ? substr( $json, 0, $max_len - 5 ) . '...' : $json;
    }
}
