<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Moyasar Apple Pay Gateway.
 *
 * @class       WC_Gateway_Moyasar_Apple_Pay
 * @extends     WC_Payment_Gateway
 * @package     Moyasar/includes/gateways
 */
class WC_Gateway_Moyasar_Apple_Pay extends WC_Payment_Gateway {

    use Moyasar_Gateway_Trait;

    public function __construct() {
        $this->id                 = 'moyasar_apple_pay';
        $this->method_title       = __( 'Apple Pay', 'moyasar-payments' );
        $this->method_description = __( 'Pay with Apple Pay', 'moyasar-payments' );
        $this->has_fields         = true;
        $this->supports           = array(
            'products',
            'refunds',
            'tokenization',
            'block_checkout',
            'subscriptions',
            'subscription_cancellation',
            'subscription_suspension',
            'subscription_reactivation',
            'subscription_amount_changes',
            'subscription_date_changes',
            'multiple_subscriptions'
        );
        $this->moyasar_icons = array( 'apple_pay' );

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = Moyasar_Helper::get_moyasar_option( 'moyasar_apple_pay_title', __( 'Apple Pay', 'moyasar-payments' ) );
        $this->description = Moyasar_Helper::get_moyasar_option( 'moyasar_apple_pay_description', __( 'Pay with Apple Pay', 'moyasar-payments' ) );
        $this->enabled     = $this->get_option( 'enabled' );

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
        
        add_filter( 'woocommerce_gateway_icon', array( $this, 'filter_gateway_icon' ), 10, 2 );

        add_action( 'woocommerce_payment_complete', array( $this, 'add_token_to_user' ) );
        add_action( 'woocommerce_scheduled_subscription_payment_' . $this->id, array( $this, 'scheduled_subscription_payment' ), 10, 2 );
        add_action( 'wps_sfw_other_payment_gateway_renewal', array( $this, 'wps_sfw_process_other_gateway_renewal' ), 10, 3 );
        add_action( 'subscrpt_after_create_renew_order', array( $this, 'subscrpt_after_create_renew_order' ), 10, 3 );

        add_action( 'init', array( $this, 'add_rewrite_rules' ) );
        add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
        add_action( 'parse_request', array( $this, 'serve_verification_file' ) );

        // AJAX Hooks
        add_action( 'wp_ajax_moyasar_apple_pay_authorized', array( $this, 'ajax_authorize_payment' ) );
        add_action( 'wp_ajax_nopriv_moyasar_apple_pay_authorized', array( $this, 'ajax_authorize_payment' ) );
        
        add_action( 'wp_ajax_moyasar_apple_pay_get_shipping_methods', array( $this, 'ajax_get_shipping_methods' ) );
        add_action( 'wp_ajax_nopriv_moyasar_apple_pay_get_shipping_methods', array( $this, 'ajax_get_shipping_methods' ) );
    }

    public function ajax_get_shipping_methods() {
        check_ajax_referer( 'moyasar_apple_pay_nonce', 'nonce' );

        $product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
        $qty        = isset( $_POST['quantity'] ) ? absint( $_POST['quantity'] ) : 1;
        
        $country    = isset( $_POST['countryCode'] ) ? sanitize_text_field( $_POST['countryCode'] ) : '';
        $state      = isset( $_POST['administrativeArea'] ) ? sanitize_text_field( $_POST['administrativeArea'] ) : '';
        $city       = isset( $_POST['locality'] ) ? sanitize_text_field( $_POST['locality'] ) : '';
        $postcode   = isset( $_POST['postalCode'] ) ? sanitize_text_field( $_POST['postalCode'] ) : '';

        if ( $country ) {
            WC()->customer->set_billing_location( $country, $state, $postcode, $city );
            WC()->customer->set_shipping_location( $country, $state, $postcode, $city );
        }


        if ( $product_id > 0 ) {
            WC()->cart->empty_cart();
            WC()->cart->add_to_cart( $product_id, $qty );
        }

        WC()->cart->calculate_totals();

        $packages = WC()->cart->get_shipping_packages();
        $shipping_methods = [];
        
        // Loop through packages (usually just 1)
        foreach ( $packages as $i => $package ) {
            $chosen_method = isset( WC()->session->chosen_shipping_methods[ $i ] ) ? WC()->session->chosen_shipping_methods[ $i ] : '';
            $product_names = array();

             $package_rates = WC()->shipping()->calculate_shipping_for_package( $package );

             foreach ( $package_rates['rates'] as $rate ) {
                 $shipping_methods[] = array(
                     'identifier' => $rate->id,
                     'label'      => $rate->label,
                     'detail'     => '',
                     'amount'     => number_format( $rate->cost + array_sum( $rate->taxes ), 2, '.', '' )
                 );
             }
        }

        $product_name = __( 'Product', 'moyasar-payments' );
        if ( $product_id ) {
            $product_obj = wc_get_product( $product_id );
            if ( $product_obj ) {
                $product_name = $product_obj->get_name();
            }
        }

        $item_subtotal = WC()->cart->subtotal;
        $item_tax = WC()->cart->get_subtotal_tax();
        $item_total_inc = $item_subtotal + $item_tax;

        $line_items[] = array(
            'label' => $product_name,
            'amount' => number_format( $item_total_inc, 2, '.', '' ), 
            'type' => 'final'
        );

        $shipping_total = WC()->cart->get_shipping_total();
        $shipping_tax = WC()->cart->get_shipping_tax();
        $total_shipping = $shipping_total + $shipping_tax;

        if ( $total_shipping > 0 ) {
             $line_items[] = array(
                'label' => __( 'Shipping', 'moyasar-payments' ),
                'amount' => number_format( $total_shipping, 2, '.', '' ),
                'type' => 'final'
            );
        }
        
        // Tax (Included in Product/Shipping, so generic tax line removed to match "final price" request)

        // Total
        $totals = WC()->cart->get_totals();
        $grand_total = isset( $totals['total'] ) ? $totals['total'] : WC()->cart->total;
        
        $shipping_total_calc = WC()->cart->get_shipping_total() + WC()->cart->get_shipping_tax();
        
        $grand_total_float = (float) $grand_total;
        $shipping_float = (float) $shipping_total_calc;
        $subtotal_with_tax = $grand_total_float - $shipping_float;

        wp_send_json_success( array(
            'total' => number_format( $grand_total_float, 2, '.', '' ),
            'currency' => get_woocommerce_currency(),
            'lineItems' => $line_items,
            'shippingMethods' => $shipping_methods,
            'subtotal_with_tax' => number_format( $subtotal_with_tax, 2, '.', '' )
        ) );
    }

    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => __( 'Enable/Disable', 'moyasar-payments' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable Moyasar Apple Pay', 'moyasar-payments' ),
                'default' => 'no',
                'description' => sprintf( __( 'You can also manage this from the <a href="%s">Main Moyasar Settings</a>.', 'moyasar-payments' ), admin_url( 'admin.php?page=wc-settings&tab=checkout&section=moyasar_cc' ) ),
            ),
            'global_settings_info' => array(
                'title'       => __( 'API Configuration', 'moyasar-payments' ),
                'type'        => 'title',
                'description' => sprintf( __( 'API Keys and Webhook settings are now managed in the <a href="%s">Main Moyasar Settings</a>.', 'moyasar-payments' ), admin_url( 'admin.php?page=wc-settings&tab=checkout&section=moyasar_cc' ) ),
            ),
            'tokenize' => array(
                'title'       => __( 'Enable Saved Cards', 'moyasar-payments' ),
                'type'        => 'checkbox',
                'label'       => __( 'Enable Payment Method Saving', 'moyasar-payments' ),
                'default'     => 'no',
                'description' => __( 'If enabled, users can save their Apple Pay card to their account (if supported by Moyasar).', 'moyasar-payments' ),
            ),
            'domain_verification_file' => array(
                'title'       => __( 'Domain Verification File Content', 'moyasar-payments' ),
                'type'        => 'textarea',
                'description' => __( 'Paste the content of the apple-developer-merchantid-domain-association file here. You can download it from your Moyasar Dashboard > Apple Pay Settings.', 'moyasar-payments' ),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'express_settings_title' => array(
                'title'       => __( 'Button Style', 'moyasar-payments' ),
                'type'        => 'title',
                'description' => __( 'Customize the appearance of the Apple Pay button.', 'moyasar-payments' ),
            ),
            'express_btn_theme' => array(
                'title'       => __( 'Button Theme', 'moyasar-payments' ),
                'type'        => 'select',
                'default'     => 'dark',
                'options'     => array(
                    'dark'          => __( 'Dark (Black)', 'moyasar-payments' ),
                    'light'         => __( 'Light (White)', 'moyasar-payments' ),
                    'light-outline' => __( 'Light Outline', 'moyasar-payments' ),
                ),
            ),
            'express_btn_height' => array(
                'title'       => __( 'Button Height (px)', 'moyasar-payments' ),
                'type'        => 'number',
                'default'     => '40',
                'description' => __( 'Height of the Apple Pay button in pixels.', 'moyasar-payments' ),
                'desc_tip'    => true,
            ),
            'express_btn_label' => array(
                'title'       => __( 'Button Label', 'moyasar-payments' ),
                'type'        => 'select',
                'default'     => 'buy',
                'options'     => array(
                    'plain'     => __( 'Logo Only', 'moyasar-payments' ),
                    'buy'       => __( 'Buy with Apple Pay', 'moyasar-payments' ),
                    'donate'    => __( 'Donate with Apple Pay', 'moyasar-payments' ),
                    'check-out' => __( 'Check out with Apple Pay', 'moyasar-payments' ),
                    'book'      => __( 'Book with Apple Pay', 'moyasar-payments' ),
                    'subscribe' => __( 'Subscribe with Apple Pay', 'moyasar-payments' ),
                ),
                'description' => __( 'Text to display on the button.', 'moyasar-payments' ),
            ),
        );
    }



    /**
     * Add Rewrite Rule for Domain Verification
     */
    public function add_rewrite_rules() {
        add_rewrite_rule( '^.well-known/apple-developer-merchantid-domain-association/?', 'index.php?moyasar-apple-verify=1', 'top' );
    }

    /**
     * Add Query Var
     */
    public function add_query_vars( $vars ) {
        $vars[] = 'moyasar-apple-verify';
        return $vars;
    }

    /**
     * Serve Verification File
     */
    public function serve_verification_file( $wp ) {
        if ( array_key_exists( 'moyasar-apple-verify', $wp->query_vars ) ) {
            $content = $this->get_option( 'domain_verification_file' );
            if ( empty( $content ) ) {
                status_header( 404 );
                echo 'Domain verification file not configured in WooCommerce > Settings > Payments > Moyasar Apple Pay.';
                exit;
            }
            
            header( 'Content-Type: text/plain' );
            echo $content;
            exit;
        }
    }

    public function payment_scripts() {
        if ( 'no' === $this->enabled ) {
            return;
        }

        $enable_on_product = Moyasar_Helper::get_moyasar_option( 'enable_apple_pay_product_page', 'yes' );

        if ( ! is_checkout() && ! isset( $_GET['pay_for_order'] ) ) {
            // If on product/cart page, MUST be enabled
            if ( 'yes' !== $enable_on_product ) {
                return;
            }
        }
        
        if ( ! is_cart() && ! is_checkout() && ! isset( $_GET['pay_for_order'] ) && ! is_product() ) {
            return;
        }

        $allowed_countries = $this->get_option( 'countries' );
        if ( ! empty( $allowed_countries ) ) {
            if ( ! is_array( $allowed_countries ) ) {
                $allowed_countries = array( $allowed_countries );
            }
            
            $customer_country = WC()->customer ? WC()->customer->get_billing_country() : WC()->countries->get_base_country();
            
            if ( ! in_array( $customer_country, $allowed_countries ) ) {
                return;
            }
        }

        wp_enqueue_script( 'apple_pay_sdk', 'https://applepay.cdn-apple.com/jsapi/1.latest/apple-pay-sdk.js', array(), null, false );
        wp_register_script( 'moyasar_apple_pay', MOYASAR_WC_PLUGIN_URL . 'assets/js/moyasar-apple-pay.js', array( 'jquery', 'apple_pay_sdk' ), MOYASAR_WC_VERSION, true );
        
        $total = '0.00';
        $is_product = is_product();
        $product = null;
        if ( $is_product ) {
            global $product;
            
            // Ensure $product is a valid WC_Product object, as it might be overwritten by query vars (e.g., ?product=slug)
            if ( ! is_object( $product ) || ! is_callable( array( $product, 'get_price' ) ) ) {
                $product = wc_get_product( get_the_ID() );
            }

            if ( $product && is_callable( array( $product, 'get_price' ) ) ) {
                $total = $product->get_price();
            }
        } elseif ( is_cart() ) {
            $totals = WC()->cart->get_totals();
            $total = isset( $totals['total'] ) ? $totals['total'] : WC()->cart->total;
        } elseif ( is_checkout() ) {
            $totals = WC()->cart->get_totals();
            $total = isset( $totals['total'] ) ? $totals['total'] : WC()->cart->total;
        }

        $test_mode = Moyasar_Helper::get_moyasar_option( 'testmode', 'no' ) === 'yes';
        $publishable_key = $test_mode
            ? Moyasar_Helper::get_moyasar_option( 'test_publishable_key', '' )
            : Moyasar_Helper::get_moyasar_option( 'live_publishable_key', '' );
        $publishable_key = trim( (string) $publishable_key );



        wp_localize_script( 'moyasar_apple_pay', 'moyasar_apple_params', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'apple_pay_nonce' => wp_create_nonce( 'moyasar_apple_pay_nonce' ),
            'publishable_key' => $publishable_key,
            'country' => WC()->countries->get_base_country(),
            'currency' => get_woocommerce_currency(),
            'label' => $this->get_apple_pay_label(),
            'total' => $total,
            'brands' => Moyasar_Helper::get_moyasar_option( 'allowed_brands', array() ),
            'countries' => Moyasar_Helper::get_moyasar_option( 'allowed_countries', array( 'SA' ) ),
            'is_product' => $is_product,
            'is_checkout' => is_checkout() && ! is_wc_endpoint_url( 'order-pay' ), // True only for actual checkout page
            'is_cart' => is_cart(),
            'product_id' => $is_product && isset($product) ? $product->get_id() : 0,
            'debug' => $test_mode,
            'api_url' => Moyasar_API::get_base_url(),
            'button_theme' => $is_product
                ? Moyasar_Helper::get_moyasar_option( 'express_btn_theme', 'dark' )
                : Moyasar_Helper::get_moyasar_option( 'apple_pay_checkout_btn_theme', 'dark' ),
            'apple_pay_border_radius' => Moyasar_Helper::get_moyasar_option( 'apple_pay_border_radius', '5' ),
            'button_height' => (function() use ($is_product) {
                $height_setting = $is_product
                    ? Moyasar_Helper::get_moyasar_option( 'express_btn_height', 'medium' )
                    : Moyasar_Helper::get_moyasar_option( 'apple_pay_checkout_btn_height', 'medium' );

                switch ( $height_setting ) {
                    case 'small': return '32';
                    case 'large': return '55';
                    default: return '44'; // Medium/Default
                }
            })(),
            'button_label' => (function() use ($is_product, $product) {
                if ( $is_product && $product ) {
                    if ( $product->is_type( 'subscription' ) || $product->is_type( 'variable-subscription' ) ) {
                        return 'subscribe';
                    }
                    return Moyasar_Helper::get_moyasar_option( 'express_btn_label', 'buy' );
                }
                return Moyasar_Helper::get_moyasar_option( 'apple_pay_checkout_btn_label', 'plain' );
            })(),
            'strings' => array(
                'error_payment_failed' => __( 'Payment Failed', 'moyasar-payments' ),
                'error_connection' => __( 'Connection Error', 'moyasar-payments' ),
                'error_merchant_validation' => __( 'Apple Pay Merchant Validation Failed', 'moyasar-payments' ),
            )
        ) );

        wp_enqueue_script( 'moyasar_apple_pay' );
        wp_enqueue_style( 'moyasar_apple_pay_style', MOYASAR_WC_PLUGIN_URL . 'assets/css/moyasar-styles.css' ); // Ensure styles loaded
    }

    public function payment_fields() {
        if ( $this->description ) {
            echo wp_kses_post( wpautop( wptexturize( $this->description ) ) );
        }
        
        // Output the Apple Pay button container
        echo '<div id="moyasar-apple-pay-button-container" class="apple-pay-checkout-container"></div>';
    }

    /**
     * Render Button on Product Page
     */
    public function render_apple_pay_button() {
        if ( 'yes' !== $this->enabled ) return;
        
        $enable_on_product = Moyasar_Helper::get_moyasar_option( 'enable_apple_pay_product_page', 'yes' );
        
        if ( 'yes' !== $enable_on_product ) return;

        echo '<div id="moyasar-apple-pay-product-container" style="margin-top:10px; width:100%;"></div>';
        echo '<div id="moyasar-apple-pay-product-error" class="woocommerce-error" style="display:none; margin-top: 10px;"></div>';
    }

    /**
     * Validate Merchant for Apple Pay Session
     */


    /**
     * Authorize Payment (Instant Checkout)
     */
    public function ajax_authorize_payment() {
        if ( ! check_ajax_referer( 'moyasar_apple_pay_nonce', 'nonce', false ) ) {
            Moyasar_Helper::log( 'Apple Pay Error: Nonce verification failed.', 'error' );
            wp_send_json_error( array( 'message' => 'Security check failed (Nonce)' ) );
            wp_die();
        }

        $product_id        = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
        $qty               = isset( $_POST['quantity'] ) ? absint( $_POST['quantity'] ) : 1;
        $raw_token         = isset( $_POST['token'] ) ? $_POST['token'] : '';
        $token_data        = Moyasar_Helper::parse_json_token( $raw_token );
        $method_identifier = '';
        $line_items        = array();

        Moyasar_Helper::log( "Apple Pay: Authorize Payment started. Product ID: $product_id, Qty: $qty", 'debug' );

        if ( ! $product_id || empty( $token_data ) ) {
            Moyasar_Helper::log( 'Apple Pay Error: Invalid Data (Product or Token missing)', 'error' );
            wp_send_json_error( array( 'message' => 'Invalid Data' ) );
        }

        // Create Order
        Moyasar_Helper::log( "Apple Pay: Creating Order...", 'debug' );
        $order_args = array();
        if ( is_user_logged_in() ) {
            $order_args['customer_id'] = get_current_user_id();
        }
        $order = wc_create_order( $order_args );
        $order->add_product( wc_get_product( $product_id ), $qty );

        // Update Address (Optional)
        $address = array();
        if ( ! empty( $_POST['shipping_contact'] ) ) {
            $shipping = Moyasar_Helper::parse_json_token( $_POST['shipping_contact'] );
            if ( is_array( $shipping ) ) {
                $address = array(
                    'first_name' => isset( $shipping['givenName'] ) ? sanitize_text_field( $shipping['givenName'] ) : '',
                    'last_name'  => isset( $shipping['familyName'] ) ? sanitize_text_field( $shipping['familyName'] ) : '',
                    'address_1'  => isset( $shipping['addressLines'][0] ) ? sanitize_text_field( $shipping['addressLines'][0] ) : '',
                    'address_2'  => isset( $shipping['addressLines'][1] ) ? sanitize_text_field( $shipping['addressLines'][1] ) : '',
                    'city'       => isset( $shipping['locality'] ) ? sanitize_text_field( $shipping['locality'] ) : '',
                    'state'      => isset( $shipping['administrativeArea'] ) ? sanitize_text_field( $shipping['administrativeArea'] ) : '',
                    'postcode'   => isset( $shipping['postalCode'] ) ? sanitize_text_field( $shipping['postalCode'] ) : '',
                    'country'    => isset( $shipping['countryCode'] ) ? sanitize_text_field( $shipping['countryCode'] ) : '',
                    'email'      => isset( $shipping['emailAddress'] ) ? sanitize_email( $shipping['emailAddress'] ) : '',
                    'phone'      => isset( $shipping['phoneNumber'] ) ? wc_sanitize_phone_number( $shipping['phoneNumber'] ) : ''
                );
                $order->set_address( $address, 'shipping' );
                $order->set_address( $address, 'billing' );
            }
        }

        // Add Selected Shipping Method
        if ( ! empty( $_POST['shipping_method'] ) ) {
            $sm_data = Moyasar_Helper::parse_json_token( $_POST['shipping_method'] );
            $method_identifier = ( is_array( $sm_data ) && isset( $sm_data['identifier'] ) ) ? $sm_data['identifier'] : '';

            if ( $method_identifier ) {
                Moyasar_Helper::log( "Apple Pay: Processing Shipping ID via Cart: " . $method_identifier );

                // Use Cart to calculate packages correctly
                if ( ! WC()->cart ) {
                    wc_load_cart();
                }
                WC()->cart->empty_cart();
                
                // Add product to cart (same as order)
                if ( $product_id ) {
                     WC()->cart->add_to_cart( $product_id, $qty ); // $qty is defined in ajax_authorize_payment scope
                }
                
                // Set Customer Location from Address
                $country   = $address['country'] ?? '';
                $state     = $address['state'] ?? '';
                $postcode  = $address['postcode'] ?? '';
                $city      = $address['city'] ?? '';
                
                WC()->customer->set_billing_location( $country, $state, $postcode, $city );
                WC()->customer->set_shipping_location( $country, $state, $postcode, $city );
                
                // Get Packages
                $packages = WC()->cart->get_shipping_packages();
                
                $rate_found = false;
                
                foreach ( $packages as $i => $package ) {
                    $rates = WC()->shipping()->calculate_shipping_for_package( $package );
                    
                    if ( isset( $rates['rates'] ) ) {
                        foreach ( $rates['rates'] as $rate ) {
                            if ( $rate->id === $method_identifier ) {
                                $item = new WC_Order_Item_Shipping();
                                $item->set_method_title( $rate->label );
                                $item->set_method_id( $rate->id );
                                $item->set_instance_id( $rate->instance_id );
                                $item->set_total( $rate->cost );
                                $item->calculate_taxes( $rate->taxes );
                                $order->add_item( $item );
                                
                                // Explicitly set totals to ensure they are captured
                                $order->set_shipping_total( $rate->cost );
                                $order->set_shipping_tax( array_sum( $rate->taxes ) );
                                
                                $order->save();
                                $rate_found = true;
                                break 2;
                            }
                        }
                    }
                }
                
                if ( ! $rate_found ) {
                    Moyasar_Helper::log( "Apple Pay: Shipping Method ID $method_identifier not found in calculated rates." );
                }
                
                // Clean up cart
                WC()->cart->empty_cart();
            }
        }

        $order->calculate_totals();
        
        $amount = Moyasar_Helper::to_minor_units( $order->get_total(), $order->get_currency() );
        
        Moyasar_Helper::log( "Apple Pay Payment Authorization: Order #{$order->get_id()}, Amount: $amount" );

        $is_subscription = Moyasar_Helper::is_subscription_order( $order );

        $save_card = ( 'yes' === $this->get_option( 'tokenize' ) ) || $is_subscription;

        $source = array(
            'type' => 'applepay',
            'token' => $token_data
        );

        if ( Moyasar_Helper::is_manual() && ! $is_subscription ) {
            $source['manual'] = true;
        }

        if ( $save_card ) {
            $source['save_card'] = true;
            $order->update_meta_data( '_moyasar_save_card', 'yes' );
            $order->save();
        }

        $payment_data = array(
            'amount' => $amount,
            'currency' => $order->get_currency(),
            'description' => 'Order #' . $order->get_id(),
            'callback_url' => $this->get_return_url( $order ),
            'source' => $source,
            'metadata' => $this->get_payment_metadata( $order, array(
                'product_id'      => $product_id,
                'quantity'        => $qty,
                'shipping_method' => $method_identifier,
                'line_items'      => $line_items,
                'prices'          => array(
                    'total'    => $order->get_total(),
                    'shipping' => $order->get_shipping_total(),
                    'tax'      => $order->get_total_tax(),
                    'discount' => $order->get_total_discount(),
                ),
            ) )
        );

        $response = Moyasar_API::create_payment( $payment_data, $this->get_api_key() );

        if ( is_wp_error( $response ) ) {
            Moyasar_Helper::log( "Apple Pay Error: Payment Creation Failed (Order #{$order->get_id()}): " . $response->get_error_message(), 'error' );
            wp_send_json_error( array( 'message' => $response->get_error_message() ) );
        }

        if ( isset( $response['status'] ) ) {
             Moyasar_Helper::log( "Apple Pay: API Response Status: " . $response['status'] . " (ID: " . ($response['id'] ?? 'N/A') . ")", 'debug' );
             
             $order->add_meta_data( '_moyasar_payment_id', $response['id'] );
             // Save Order ID in Meta again for backup
             $order->update_meta_data( '_moyasar_apple_pay_id', $response['id'] );

             if ( 'paid' === $response['status'] || 'authorized' === $response['status'] ) {
                // Use Central Helper
                if ( 'authorized' === $response['status'] ) {
                    Moyasar_Helper::handle_payment_authorized( $order, $response, 'apple_pay' );
                } else {
                    Moyasar_Helper::handle_payment_success( $order, $response, 'apple_pay' );
                }

                // Remove cart
                WC()->cart->empty_cart();
                Moyasar_Helper::log( "Apple Pay: Payment Successful/Authorized. Redirecting...", 'debug' );
                wp_send_json_success( array( 'url' => $this->get_return_url( $order ) ) );
             } elseif ( 'initiated' === $response['status'] ) {
                 // Should not happen for Apple Pay often
                 $url = isset( $response['source']['transaction_url'] ) ? $response['source']['transaction_url'] : $this->get_return_url( $order );
                 Moyasar_Helper::log( "Apple Pay: Payment Initiated (3DS likely). URL: $url", 'debug' );
                 wp_send_json_success( array( 'url' => $url ) );
             } else {
                 // Use Central Helper
                 Moyasar_Helper::handle_payment_failed( $order, $response, 'apple_pay' );
                 
                 $error_message = 'Payment Failed';
                 if ( isset( $response['source']['message'] ) ) {
                     $error_message = $response['source']['message'];
                 } elseif ( isset( $response['message'] ) ) {
                      $error_message = $response['message'];
                 }
                 
                 Moyasar_Helper::log( "Apple Pay Error: Payment Failed with status " . $response['status'] . ". Msg: $error_message", 'error' );
                 wp_send_json_error( array( 'message' => $error_message ) );
             }
        } else {
             Moyasar_Helper::log( "Apple Pay Error: Invalid API Response (No Status)", 'error' );
             wp_send_json_error( array( 'message' => 'Invalid API Response' ) );
        }
        wp_die();
    }

    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );
        
        $raw_token       = isset( $_POST['moyasar_apple_pay_token'] ) ? $_POST['moyasar_apple_pay_token'] : '';
        $apple_pay_token = Moyasar_Helper::parse_json_token( $raw_token );
        
        if ( empty( $apple_pay_token ) ) {
            wc_add_notice( __( 'Apple Pay token is missing.', 'moyasar-payments' ), 'error' );
            return array(
                'result'   => 'failure',
                'messages' => __( 'Apple Pay token is missing.', 'moyasar-payments' ),
                'message'  => __( 'Apple Pay token is missing.', 'moyasar-payments' ),
            );
        }

        $amount = Moyasar_Helper::to_minor_units( $order->get_total(), $order->get_currency() );
        
        $is_subscription = Moyasar_Helper::is_subscription_order( $order );

        $save_card = ( 'yes' === $this->get_option( 'tokenize' ) ) || $is_subscription;

        $source = array(
            'type' => 'applepay',
            'token' => $apple_pay_token
        );

        if ( Moyasar_Helper::is_manual() && ! $is_subscription ) {
            $source['manual'] = true;
        }

        if ( $save_card ) {
            $source['save_card'] = true;
            $order->update_meta_data( '_moyasar_save_card', 'yes' );
            $order->save();
        }

        $payment_data = array(
            'amount' => $amount,
            'currency' => $order->get_currency(),
            'description' => 'Order #' . $order_id,
            'callback_url' => $this->get_return_url( $order ),
            'source' => $source,
            'metadata' => $this->get_payment_metadata( $order ),
        );

        Moyasar_Helper::log( "Apple Pay Fallback Process: Order #$order_id" );

        $response = Moyasar_API::create_payment( $payment_data, $this->get_api_key() );

        if ( is_wp_error( $response ) ) {
            Moyasar_Helper::log( "Apple Pay Fallback Failed: " . $response->get_error_message(), 'error' );
            wc_add_notice( $response->get_error_message(), 'error' );
            return array(
                'result'   => 'failure',
                'messages' => $response->get_error_message(),
                'message'  => $response->get_error_message(),
            );
        }

        if ( isset( $response['status'] ) && ( 'paid' === $response['status'] || 'authorized' === $response['status'] ) ) {
            $order->add_meta_data( '_moyasar_payment_id', $response['id'] );
            // Use Central Helper
            if ( 'authorized' === $response['status'] ) {
                Moyasar_Helper::handle_payment_authorized( $order, $response, 'apple_pay_fallback' );
            } else {
                Moyasar_Helper::handle_payment_success( $order, $response, 'apple_pay_fallback' );
            }

            WC()->cart->empty_cart();
            return array(
                'result' => 'success',
                'redirect' => $this->get_return_url( $order )
            );
        } else {
             // Use Central Helper
             Moyasar_Helper::handle_payment_failed( $order, $response, 'apple_pay_fallback' );

             $error_message = __( 'Payment failed.', 'moyasar-payments' );
             if ( isset( $response['source']['message'] ) ) {
                 $error_message .= ' ' . $response['source']['message'];
                 if ( isset( $response['source']['response_code'] ) ) {
                      $error_message .= ' (Code: ' . $response['source']['response_code'] . ')';
                 }
             } elseif ( isset( $response['message'] ) ) {
                  $error_message .= ' ' . $response['message'];
             }
             
             wc_add_notice( $error_message, 'error' );
             return array(
                 'result'   => 'failure',
                 'messages' => $error_message,
                 'message'  => $error_message,
             );
        }
    }

    private function get_apple_pay_label() {
        $name = $this->get_option( 'display_name', get_bloginfo( 'name' ) );

        if ( preg_match( '/\A\p{ASCII}+\z/u', $name ) ) {
            return $name;
        }

        $host   = (string) parse_url( get_site_url(), PHP_URL_HOST );
        $host   = preg_replace( '/^www\./i', '', $host );
        $domain = explode( '.', $host )[0];

        return 'WC-Store: ' . $domain;
    }

}
