<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Moyasar Credit Card Gateway.
 *
 * @class       WC_Gateway_Moyasar_CC
 * @extends     WC_Payment_Gateway
 * @package     Moyasar/includes/gateways
 */
class WC_Gateway_Moyasar_CC extends WC_Payment_Gateway {

    use Moyasar_Gateway_Trait;

    public function __construct() {
        $this->id                 = 'moyasar_cc';
        $this->method_title       = __( 'Moyasar', 'moyasar-payments' );
        $this->method_description = __( 'Accept payments via Moyasar (Credit Card, Apple Pay, STC Pay, etc).', 'moyasar-payments' );
        $this->has_fields         = true;
        $this->logger = wc_get_logger();
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
        $this->moyasar_icons = array( 'mada', 'visa', 'mastercard', 'amex', 'unionpay' );

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = Moyasar_Helper::get_moyasar_option( 'moyasar_cc_title', __( 'Card Payment', 'moyasar-payments' ) );
        $this->description = Moyasar_Helper::get_moyasar_option( 'moyasar_cc_description', __( 'Pay with your Credit/Debit Card.', 'moyasar-payments' ) );
        $this->enabled     = $this->get_option( 'enabled' );
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        
        add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );

        add_action( 'woocommerce_payment_complete', array( $this, 'add_token_to_user' ) );
        add_action( 'woocommerce_scheduled_subscription_payment_' . $this->id, array( $this, 'scheduled_subscription_payment' ), 10, 2 );
        add_action( 'wps_sfw_other_payment_gateway_renewal', array( $this, 'wps_sfw_process_other_gateway_renewal' ), 10, 3 );
        add_action( 'subscrpt_after_create_renew_order', array( $this, 'subscrpt_after_create_renew_order' ), 10, 3 );

        add_filter( 'woocommerce_gateway_icon', array( $this, 'filter_gateway_icon' ), 10, 2 );

        add_shortcode( 'moyasar_quick_buy', array( $this, 'shortcode_quick_buy' ) );

    }


    public function init_form_fields() {
        $this->form_fields = array();
    }

    /**
     * Payment Scripts
     */
    public function payment_scripts() {
        if ( 'no' === $this->enabled ) {
            return;
        }

        $is_product = is_product();
        
        // Load on Checkout, Cart, Order Pay, Add Payment Method, OR Product Page
        if ( ! is_cart() && ! is_checkout() && ! isset( $_GET['pay_for_order'] ) && ! is_wc_endpoint_url( 'add-payment-method' ) && ! $is_product ) {
            return;
        }

        wp_enqueue_style( 'moyasar_custom_css', MOYASAR_WC_PLUGIN_URL . 'assets/css/moyasar-styles.css', array(), MOYASAR_WC_VERSION );

        $test_mode = 'yes' === Moyasar_Helper::get_moyasar_option( 'testmode' );
        $publishable_key = $test_mode 
            ? Moyasar_Helper::get_moyasar_option( 'test_publishable_key', '' )
            : Moyasar_Helper::get_moyasar_option( 'live_publishable_key', '' );
        
        $publishable_key = trim( $publishable_key );
        
        wp_register_script( 'moyasar_cc', MOYASAR_WC_PLUGIN_URL . 'assets/js/moyasar-cc.js', array( 'jquery', 'wc-credit-card-form' ), MOYASAR_WC_VERSION, true );
        wp_localize_script( 'moyasar_cc', 'moyasar_params', array(
            'publishable_key' => $publishable_key,
            'currency' => get_woocommerce_currency(),
            'language' => substr( get_locale(), 0, 2 ),
            'token_api_url' => Moyasar_API::get_base_url() . 'tokens',
            'payment_api_url' => Moyasar_API::get_base_url() . 'payments',
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'verify_nonce' => wp_create_nonce( 'moyasar_verify_token' ),
            'plugin_version' => 'woo_' . MOYASAR_WC_VERSION,
            'manual' => Moyasar_Helper::is_manual(),
            'supported_brands' => Moyasar_Helper::get_moyasar_option( 'allowed_brands', array() ),
            'icons_url' => array(
                'visa' => Moyasar_Helper::get_card_icon_url( 'visa' ),
                'mastercard' => Moyasar_Helper::get_card_icon_url( 'mastercard' ),
                'mada' => Moyasar_Helper::get_card_icon_url( 'mada' ),
                'amex' => Moyasar_Helper::get_card_icon_url( 'amex' ),
                'unionpay' => Moyasar_Helper::get_card_icon_url( 'unionpay' ),
            ),
            'processing_text' => __( 'Processing...', 'moyasar-payments' ),
            'strings' => array(
                'error_incomplete_fields' => __( 'Please complete all credit card fields.', 'moyasar-payments' ),
                'error_card_type_unsupported' => __( 'This card brand (%s) is not supported.', 'moyasar-payments' ),
                'error_invalid_cvc' => __( 'Invalid CVC.', 'moyasar-payments' ),
                'error_invalid_expiry' => __( 'Invalid Expiry Date.', 'moyasar-payments' ),
                'error_payment_verification_cancelled' => __( 'Payment verification cancelled.', 'moyasar-payments' ),
                'error_connection' => __( 'Connection error. Please try again.', 'moyasar-payments' ),
                'error_unknown' => __( 'Unknown error occurred.', 'moyasar-payments' ),
                'error_verification_failed' => __( 'Verification failed', 'moyasar-payments' ),
                'error_verification_request_failed' => __( 'Verification request failed', 'moyasar-payments' ),
            )
        ) );
        wp_enqueue_script( 'moyasar_cc' );
    }

    public function payment_fields() {
        global $wp;
        $is_add_payment_method = isset( $wp->query_vars['add-payment-method'] ) || is_wc_endpoint_url( 'add-payment-method' );
        $main_settings = get_option( 'woocommerce_moyasar_settings', array() );

        // Hide description on Add Payment Method page to reduce clutter
        if ( $this->description && ! $is_add_payment_method ) {
            echo wp_kses_post( wpautop( wptexturize( $this->description ) ) );
        }

        $cc_tokenize = 'yes' === Moyasar_Helper::get_moyasar_option( 'cc_enable_tokenization', 'yes' );
        if ( $cc_tokenize && is_user_logged_in() && $this->supports( 'tokenization' ) ) {
            if ( ! $is_add_payment_method ) {
                $this->saved_payment_methods();
            }
        }

        ?>
        <fieldset id="wc-moyasar-cc-form" class="wc-credit-card-form wc-payment-form" style="background:transparent;">
        <div class="form-row form-row-wide">
            <label for="moyasar_cc_name"><?php esc_html_e( 'Cardholder Name', 'moyasar-payments' ); ?> <span class="required">*</span></label>
            <input id="moyasar_cc_name" type="text" class="input-text" autocomplete="cc-name" placeholder="<?php esc_attr_e( 'Name on Card', 'moyasar-payments' ); ?>" required>
        </div>    
        <div class="form-row form-row-wide">
                <label for="moyasar_cc_number"><?php esc_html_e( 'Card Number', 'moyasar-payments' ); ?> <span class="required">*</span></label>
                <div class="moyasar-cc-group" style="position:relative;">
                    <input id="moyasar_cc_number" type="text" class="input-text" autocomplete="cc-number" placeholder="1234 5678 9101 1121" required style="text-align: -webkit-match-parent; direction: ltr;">
                    <div class="moyasar-cc-logos" style="position:absolute; right:12px; top:50%; transform:translateY(-50%); display:flex; gap:5px; align-items: center;">
                        <img id="moyasar_card_brand_img" src="" style="height: 30px; width: auto; display:none;">
                        <span id="moyasar_card_brand_placeholder" style="opacity:0.3; font-size: 24px;">💳</span>
                    </div>
                </div>
            </div>
            <div class="moyasar-cc-expiry-cvc-group">
                <div class="form-row form-row-first">
                    <label for="moyasar_cc_expiry"><?php esc_html_e( 'Expiry (MM/YY)', 'moyasar-payments' ); ?> <span class="required">*</span></label>
                    <input id="moyasar_cc_expiry" type="text" class="input-text" autocomplete="cc-exp" placeholder="MM / YY" required style="text-align: -webkit-match-parent; direction: ltr;">
                </div>
                <div class="form-row form-row-last">
                    <label for="moyasar_cc_cvc"><?php esc_html_e( 'Card Code (CVC)', 'moyasar-payments' ); ?> <span class="required">*</span></label>
                    <input id="moyasar_cc_cvc" type="password" class="input-text" autocomplete="cc-csc" placeholder="123" required maxlength="4" style="text-align: -webkit-match-parent; direction: ltr;">
                </div>
            </div>
            <?php 
            $cc_tokenize = 'yes' === Moyasar_Helper::get_moyasar_option( 'cc_enable_tokenization', 'yes' );
            if ( $cc_tokenize && is_user_logged_in() && $this->supports( 'tokenization' ) && ! is_wc_endpoint_url( 'add-payment-method' ) ) : ?>
                <?php echo $this->get_save_payment_method_html(); ?>
            <?php endif; ?>
        </fieldset>

        <div class="moyasar-payment-footer" style="margin-top: 20px; border-top: 1px solid #eee; padding-top: 15px;">       
            <?php
            $brands = Moyasar_Helper::get_moyasar_option( 'allowed_brands', array() );
            if ( ! is_array( $brands ) ) {
                $brands = array();
            }
            if ( ! empty( $brands ) ) {
                echo '<div class="moyasar-supported-brands" style="display: flex; gap: 8px; flex-wrap: wrap;">';
                foreach ( $brands as $brand ) {
                    $icon_url = Moyasar_Helper::get_card_icon_url( $brand );
                    echo '<div style="
                        display: flex; 
                        align-items: center; 
                        justify-content: center; 
                        width: 60px; 
                        height: 40px; 
                        border-radius: 8px; 
                        border: 1px solid #eaeaea; 
                        background-color: #f8fafc;
                        overflow: hidden;
                    ">';
                    echo '<img src="' . esc_url( $icon_url ) . '" alt="' . esc_attr( $brand ) . '" style="height: 14px; width: auto; max-width: none;">';
                    echo '</div>';
                }
                echo '</div>';
            }
            ?>
             <a href="https://moyasar.com/?ref=<?php echo esc_attr( "woo_" . MOYASAR_WC_VERSION . "_" . parse_url( get_site_url(), PHP_URL_HOST ) ); ?>" target="_blank">
                <svg width="139" height="11" viewBox="0 0 139 11" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3.39482 2.7C3.80882 2.7 4.17482 2.781 4.49282 2.943C4.81082 3.099 5.05382 3.324 5.22182 3.618C5.39582 3.906 5.48282 4.245 5.48282 4.635C5.48282 5.025 5.39582 5.367 5.22182 5.661C5.04782 5.955 4.80182 6.183 4.48382 6.345C4.17182 6.501 3.80882 6.579 3.39482 6.579H1.75682V9H0.973816V2.7H3.39482ZM1.75682 5.859H3.32282C3.73682 5.859 4.06682 5.748 4.31282 5.526C4.55882 5.304 4.68182 5.007 4.68182 4.635C4.68182 4.263 4.55882 3.969 4.31282 3.753C4.07282 3.531 3.74582 3.42 3.33182 3.42H1.75682V5.859ZM8.08726 9.054C7.64326 9.054 7.24726 8.955 6.89926 8.757C6.55726 8.559 6.29026 8.283 6.09826 7.929C5.90626 7.575 5.81026 7.167 5.81026 6.705C5.81026 6.243 5.90626 5.835 6.09826 5.481C6.29026 5.127 6.55726 4.851 6.89926 4.653C7.24726 4.455 7.64326 4.356 8.08726 4.356C8.53126 4.356 8.92426 4.455 9.26626 4.653C9.61426 4.851 9.88426 5.127 10.0763 5.481C10.2683 5.835 10.3643 6.243 10.3643 6.705C10.3643 7.167 10.2683 7.575 10.0763 7.929C9.88426 8.283 9.61426 8.559 9.26626 8.757C8.92426 8.955 8.53126 9.054 8.08726 9.054ZM8.08726 8.397C8.38726 8.397 8.65126 8.325 8.87926 8.181C9.11326 8.037 9.29326 7.836 9.41926 7.578C9.54526 7.32 9.60826 7.029 9.60826 6.705C9.60826 6.381 9.54526 6.09 9.41926 5.832C9.29326 5.574 9.11326 5.373 8.87926 5.229C8.65126 5.085 8.38726 5.013 8.08726 5.013C7.78726 5.013 7.52026 5.085 7.28626 5.229C7.05826 5.373 6.88126 5.574 6.75526 5.832C6.62926 6.09 6.56626 6.381 6.56626 6.705C6.56626 7.029 6.62926 7.32 6.75526 7.578C6.88126 7.836 7.05826 8.037 7.28626 8.181C7.52026 8.325 7.78726 8.397 8.08726 8.397ZM10.6467 4.41H11.4207L12.5637 8.145L13.7697 4.41H14.4537L15.5967 8.145L16.7937 4.41H17.5407L16.0107 9H15.2007L14.0937 5.589L12.9417 9L12.1317 9.009L10.6467 4.41ZM17.8249 6.705C17.8249 6.237 17.9149 5.826 18.0949 5.472C18.2809 5.118 18.5389 4.845 18.8689 4.653C19.1989 4.455 19.5769 4.356 20.0029 4.356C20.4229 4.356 20.7949 4.443 21.1189 4.617C21.4429 4.791 21.6979 5.043 21.8839 5.373C22.0699 5.703 22.1689 6.09 22.1809 6.534C22.1809 6.6 22.1749 6.705 22.1629 6.849H18.5989V6.912C18.6109 7.362 18.7489 7.722 19.0129 7.992C19.2769 8.262 19.6219 8.397 20.0479 8.397C20.3779 8.397 20.6569 8.316 20.8849 8.154C21.1189 7.986 21.2749 7.755 21.3529 7.461H22.0999C22.0099 7.929 21.7849 8.313 21.4249 8.613C21.0649 8.907 20.6239 9.054 20.1019 9.054C19.6459 9.054 19.2469 8.958 18.9049 8.766C18.5629 8.568 18.2959 8.292 18.1039 7.938C17.9179 7.578 17.8249 7.167 17.8249 6.705ZM21.4159 6.237C21.3799 5.847 21.2329 5.544 20.9749 5.328C20.7229 5.112 20.4019 5.004 20.0119 5.004C19.6639 5.004 19.3579 5.118 19.0939 5.346C18.8299 5.574 18.6799 5.871 18.6439 6.237H21.4159ZM25.354 4.41V5.13H24.985C24.547 5.13 24.232 5.277 24.04 5.571C23.854 5.865 23.761 6.228 23.761 6.66V9H23.023V4.41H23.662L23.761 5.103C23.893 4.893 24.064 4.725 24.274 4.599C24.484 4.473 24.775 4.41 25.147 4.41H25.354ZM25.6032 6.705C25.6032 6.237 25.6932 5.826 25.8732 5.472C26.0592 5.118 26.3172 4.845 26.6472 4.653C26.9772 4.455 27.3552 4.356 27.7812 4.356C28.2012 4.356 28.5732 4.443 28.8972 4.617C29.2212 4.791 29.4762 5.043 29.6622 5.373C29.8482 5.703 29.9472 6.09 29.9592 6.534C29.9592 6.6 29.9532 6.705 29.9412 6.849H26.3772V6.912C26.3892 7.362 26.5272 7.722 26.7912 7.992C27.0552 8.262 27.4002 8.397 27.8262 8.397C28.1562 8.397 28.4352 8.316 28.6632 8.154C28.8972 7.986 29.0532 7.755 29.1312 7.461H29.8782C29.7882 7.929 29.5632 8.313 29.2032 8.613C28.8432 8.907 28.4022 9.054 27.8802 9.054C27.4242 9.054 27.0252 8.958 26.6832 8.766C26.3412 8.568 26.0742 8.292 25.8822 7.938C25.6962 7.578 25.6032 7.167 25.6032 6.705ZM29.1942 6.237C29.1582 5.847 29.0112 5.544 28.7532 5.328C28.5012 5.112 28.1802 5.004 27.7902 5.004C27.4422 5.004 27.1362 5.118 26.8722 5.346C26.6082 5.574 26.4582 5.871 26.4222 6.237H29.1942ZM35.1574 2.7V9H34.5184L34.4194 8.262C34.0354 8.79 33.5104 9.054 32.8444 9.054C32.4184 9.054 32.0374 8.961 31.7014 8.775C31.3714 8.589 31.1104 8.319 30.9184 7.965C30.7324 7.611 30.6394 7.191 30.6394 6.705C30.6394 6.243 30.7324 5.835 30.9184 5.481C31.1104 5.127 31.3744 4.851 31.7104 4.653C32.0464 4.455 32.4244 4.356 32.8444 4.356C33.2164 4.356 33.5344 4.425 33.7984 4.563C34.0624 4.695 34.2694 4.878 34.4194 5.112V2.7H35.1574ZM32.9164 8.397C33.2104 8.397 33.4714 8.328 33.6994 8.19C33.9334 8.046 34.1134 7.848 34.2394 7.596C34.3654 7.338 34.4284 7.047 34.4284 6.723C34.4284 6.393 34.3654 6.099 34.2394 5.841C34.1134 5.577 33.9334 5.373 33.6994 5.229C33.4714 5.085 33.2104 5.013 32.9164 5.013C32.4604 5.013 32.0914 5.172 31.8094 5.49C31.5334 5.802 31.3954 6.207 31.3954 6.705C31.3954 7.203 31.5334 7.611 31.8094 7.929C32.0914 8.241 32.4604 8.397 32.9164 8.397ZM40.8312 4.356C41.2572 4.356 41.6352 4.449 41.9652 4.635C42.3012 4.821 42.5622 5.091 42.7482 5.445C42.9402 5.799 43.0362 6.219 43.0362 6.705C43.0362 7.167 42.9402 7.575 42.7482 7.929C42.5622 8.283 42.3012 8.559 41.9652 8.757C41.6292 8.955 41.2512 9.054 40.8312 9.054C40.4592 9.054 40.1412 8.988 39.8772 8.856C39.6132 8.718 39.4062 8.532 39.2562 8.298L39.1572 9H38.5182V2.7H39.2562V5.148C39.6402 4.62 40.1652 4.356 40.8312 4.356ZM40.7592 8.397C41.2152 8.397 41.5812 8.241 41.8572 7.929C42.1392 7.611 42.2802 7.203 42.2802 6.705C42.2802 6.207 42.1392 5.802 41.8572 5.49C41.5812 5.172 41.2152 5.013 40.7592 5.013C40.4652 5.013 40.2012 5.085 39.9672 5.229C39.7392 5.367 39.5622 5.565 39.4362 5.823C39.3102 6.075 39.2472 6.363 39.2472 6.687C39.2472 7.017 39.3102 7.314 39.4362 7.578C39.5622 7.836 39.7392 8.037 39.9672 8.181C40.2012 8.325 40.4652 8.397 40.7592 8.397ZM44.0277 4.41L45.4407 8.154L46.8087 4.41H47.5917L45.4767 9.774C45.3687 10.05 45.2727 10.257 45.1887 10.395C45.1047 10.533 44.9997 10.635 44.8737 10.701C44.7477 10.767 44.5797 10.8 44.3697 10.8H43.4877V10.125H44.1447C44.2887 10.125 44.3967 10.11 44.4687 10.08C44.5407 10.05 44.5977 9.999 44.6397 9.927C44.6877 9.861 44.7447 9.747 44.8107 9.585L45.0447 9.036L43.2447 4.41H44.0277Z" fill="#A4A4A4"></path><path d="M55.3554 1.05888C56.1662 0.846989 56.977 1.21884 57.3724 1.98333L58.1501 3.48632L57.4595 4.82209L56.3003 2.58058C56.1031 2.19937 55.7548 2.25754 55.6527 2.28351C55.5506 2.31052 55.2172 2.431 55.2172 2.8631V8.13657C55.2172 8.56867 55.5506 8.68916 55.6527 8.71617C55.7548 8.74317 56.1031 8.8003 56.3003 8.4191L59.6276 1.98333C60.023 1.21884 60.8338 0.846989 61.6446 1.05888C62.4555 1.27078 63 1.99579 63 2.8631V8.13657C63 9.00389 62.4555 9.7289 61.6446 9.94079C61.4925 9.98026 61.3413 10 61.1922 10C60.5445 10 59.9489 9.63853 59.6276 9.01635L58.8438 7.49985L59.5345 6.16409L60.7007 8.41806C60.8979 8.79926 61.2462 8.74214 61.3483 8.71513C61.4505 8.68812 61.7838 8.56763 61.7838 8.13554V2.86206C61.7838 2.42997 61.4505 2.30948 61.3483 2.28247C61.3163 2.27416 61.2603 2.26273 61.1922 2.26273C61.043 2.26273 60.8358 2.31779 60.7007 2.57954L57.3724 9.01428C57.0511 9.63542 56.4555 9.99792 55.8078 9.99792C55.6587 9.99792 55.5075 9.97923 55.3554 9.93872C54.5445 9.72682 54 9.00181 54 8.1345V2.86206C54 1.99475 54.5445 1.26974 55.3554 1.05888Z" fill="#A4A4A4"></path><path d="M85.489 2.15213C87.259 2.15213 88.6989 3.56714 88.6989 5.30669C88.6989 7.04623 87.259 8.46124 85.489 8.46124C83.7189 8.46124 82.2791 7.04623 82.2791 5.30669C82.2791 3.56714 83.7189 2.15213 85.489 2.15213ZM85.489 0.873352C82.9974 0.873352 80.9778 2.85812 80.9778 5.30669C80.9778 7.75525 82.9974 9.74002 85.489 9.74002C87.9805 9.74002 90.0001 7.75525 90.0001 5.30669C90.0001 2.85812 87.9805 0.873352 85.489 0.873352Z" fill="#A4A4A4"></path><path d="M115.089 9.73941C114.627 9.73941 114.208 9.68774 113.845 9.58621C113.48 9.48347 113.157 9.33696 112.886 9.15033C112.616 8.96369 112.391 8.75274 112.218 8.52355C112.044 8.29254 111.914 8.04328 111.831 7.78248L111.778 7.6153L113.051 7.1703L113.108 7.34052C113.211 7.64752 113.425 7.92048 113.746 8.1515C114.066 8.38312 114.467 8.50045 114.938 8.50045C115.526 8.50045 115.988 8.38494 116.311 8.15636C116.62 7.93933 116.77 7.6609 116.77 7.30465C116.77 6.97819 116.638 6.71921 116.365 6.51434C116.068 6.29123 115.667 6.11311 115.173 5.98605L114.278 5.75078C113.9 5.6529 113.546 5.50639 113.227 5.31428C112.898 5.11671 112.632 4.86016 112.436 4.55194C112.237 4.23825 112.135 3.85951 112.135 3.42666C112.135 2.63209 112.396 1.99924 112.91 1.54633C113.418 1.10011 114.151 0.873352 115.088 0.873352C115.645 0.873352 116.135 0.967581 116.543 1.15361C116.95 1.33903 117.288 1.57855 117.548 1.8661C117.807 2.15365 117.994 2.47039 118.102 2.80718L118.156 2.97375L116.9 3.41815L116.838 3.25522C116.704 2.89776 116.484 2.6248 116.166 2.42054C115.848 2.21688 115.454 2.11353 114.995 2.11353C114.536 2.11353 114.142 2.22843 113.871 2.45458C113.606 2.67526 113.477 2.9701 113.477 3.35614C113.477 3.67895 113.573 3.91604 113.772 4.0814C113.99 4.26317 114.283 4.39935 114.642 4.48628L115.537 4.71C116.354 4.90454 116.995 5.23525 117.439 5.69242C117.892 6.15992 118.123 6.67483 118.123 7.22258C118.123 7.68461 118.004 8.1138 117.771 8.49741C117.537 8.88162 117.19 9.18923 116.739 9.41052C116.295 9.62938 115.739 9.74002 115.088 9.74002L115.089 9.73941Z" fill="#A4A4A4"></path><path d="M94.9779 4.64911L92.828 1.02881H91.4001L94.2639 5.85103L94.9779 4.64911Z" fill="#A4A4A4"></path><path d="M98.3294 1.02881L94.9778 6.77068V9.73992H96.3812V7.15359L99.9556 1.02881H98.3294Z" fill="#A4A4A4"></path><path d="M105.011 2.28654C105.066 2.28654 105.081 2.32122 105.088 2.33764L108.236 9.74002H109.755L106.37 1.77857C106.132 1.22011 105.611 0.873352 105.011 0.873352C104.411 0.873352 103.89 1.22011 103.652 1.77857L100.267 9.74002H101.786L102.871 7.18861H105.977L105.457 5.96826H103.39L104.934 2.33764C104.941 2.32122 104.956 2.28654 105.011 2.28654Z" fill="#A4A4A4"></path><path d="M124.689 2.28654C124.744 2.28654 124.76 2.32122 124.766 2.33764L127.967 9.74002H129.511L126.07 1.77857C125.828 1.22011 125.299 0.873352 124.689 0.873352C124.079 0.873352 123.549 1.22011 123.308 1.77857L119.867 9.74002H121.411L122.514 7.18861H125.67L125.143 5.96826H123.041L124.611 2.33764C124.618 2.32122 124.633 2.28654 124.689 2.28654Z" fill="#A4A4A4"></path><path d="M139 9.73143L136.202 6.73067H136.883C137.921 6.26239 138.645 5.22028 138.645 4.01197C138.645 2.36693 137.304 1.02881 135.656 1.02881H132.467V9.73992H133.83V2.38937H135.656C136.552 2.38937 137.282 3.11727 137.282 4.01258C137.282 4.90789 136.553 5.63579 135.656 5.63579H134.721V6.73128L137.122 9.73203H138.999L139 9.73143Z" fill="#A4A4A4"></path><path d="M73.0444 4.19945L71.4781 1.52862C71.1671 1.00552 70.5664 0.761613 69.9833 0.922192C69.3954 1.08459 69 1.60769 69 2.22324V9.74002H70.4109V2.49817L72.2221 5.58687L73.0444 4.19945Z" fill="#A4A4A4"></path><path d="M77.1901 0.92216C76.605 0.761581 76.0017 1.00549 75.6862 1.53224L72.7334 6.51141C73.0147 6.93171 73.9341 7.25165 74.8138 5.8034L75.0292 5.43784L76.7606 2.49815V9.74002H78.1778V2.22322C78.1778 1.60705 77.7807 1.08456 77.1901 0.92216Z" fill="#A4A4A4"></path></svg></a>
        </div>

            <input type="hidden" id="moyasar_token" name="moyasar_token" />
            <div class="clear"></div>
        </fieldset>
        <?php
    }

    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );

        Moyasar_Helper::log( "CC Process Payment: Starting for Order #$order_id", 'debug' );

        // Block (Store API) checkout: the card was tokenized client-side and the
        // token id was passed through paymentMethodData. Charge it server-side.
        if ( isset( $_POST['mysr_form'] ) && 'blocks' === sanitize_text_field( wp_unslash( $_POST['mysr_form'] ) ) ) {
            return $this->process_block_payment( $order );
        }

        // Handle saved payment token
        if ( isset( $_POST['wc-moyasar_cc-payment-token'] ) && 'new' !== $_POST['wc-moyasar_cc-payment-token'] ) {
            $token_id = wc_clean( $_POST['wc-moyasar_cc-payment-token'] );
            $token = WC_Payment_Tokens::get( $token_id );
            
            if ( ! $token || $token->get_user_id() !== get_current_user_id() ) {
                Moyasar_Helper::log( "CC Process Payment: Invalid Saved Token ID $token_id", 'error' );
                wc_add_notice( 'Invalid token', 'error' );
                return;
            }
            $source = array(
                'type' => 'token',
                'token' => $token->get_token() 
            );
            if ( Moyasar_Helper::is_manual() && ! $is_subscription ) {
                $source['manual'] = true;
            }
            Moyasar_Helper::log( "CC Process Payment: Using Saved Token Source (ID: $token_id)", 'debug' );
            
            $amount = Moyasar_Helper::to_minor_units( $order->get_total(), $order->get_currency() );
            $is_subscription = Moyasar_Helper::is_subscription_order( $order );
            $save_card = ( isset( $_POST['wc-moyasar_cc-new-payment-method'] ) && 'true' === $_POST['wc-moyasar_cc-new-payment-method'] ) || $is_subscription;

            if ( $save_card ) {
                $source['save_card'] = true;
                $order->update_meta_data( '_moyasar_save_card', 'yes' );
            } else {
                $order->update_meta_data( '_moyasar_save_card', 'no' );
            }
            
            $callback_url = add_query_arg(
                array(
                    'order_id' => $order_id,
                    'key'      => $order->get_order_key(),
                    'mode'     => 'modal',
                ),
                WC()->api_request_url( 'moyasar_callback' )
            );

            $payment_data = array(
                'amount' => $amount,
                'currency' => $order->get_currency(),
                'description' => 'Order #' . $order_id,
                'callback_url' => $callback_url,
                'source' => $source,
                'metadata' => $this->get_payment_metadata( $order ),
            );

            Moyasar_Helper::log( "CC Process Payment: Sending API Request for Saved Token...", 'debug' );
            $response = Moyasar_API::create_payment( $payment_data );

            if ( is_wp_error( $response ) ) {
                wc_add_notice( $response->get_error_message(), 'error' );
                return;
            }
            
            $order->update_meta_data( '_moyasar_payment_id', $response['id'] ?? '' );
            if ( isset( $response['id'] ) ) {
                $order->set_transaction_id( $response['id'] );
                $order->add_order_note( sprintf( __( 'Moyasar Payment Initiated. ID: %s', 'moyasar-payments' ), $response['id'] ) );
            }
            $order->save();

            if ( isset( $response['status'] ) && 'initiated' === $response['status'] ) {
                 if ( isset( $response['source']['transaction_url'] ) ) {
                      return array(
                          'result' => 'success',
                          'redirect' => '#moyasar-verify:' . $response['source']['transaction_url']
                      );
                 } else {
                      Moyasar_Helper::log( "CC Process Payment: 'initiated' status returned but transaction_url is missing.", 'error' );
                      wc_add_notice( __( 'Payment initiation failed: Transaction URL is missing.', 'moyasar-payments' ), 'error' );
                      return;
                 }
            } else if ( isset( $response['status'] ) && ( 'paid' === $response['status'] || 'authorized' === $response['status'] ) ) {
                if ( 'authorized' === $response['status'] ) {
                    Moyasar_Helper::handle_payment_authorized( $order, $response, 'credit_card_form' );
                } else {
                    Moyasar_Helper::handle_payment_success( $order, $response, 'credit_card_form' );
                }
                return array(
                    'result'   => 'success',
                    'redirect' => $this->get_return_url( $order )
                );
            } else {
                Moyasar_Helper::handle_payment_failed( $order, $response, 'credit_card_form' );
                wc_add_notice( isset( $response['message'] ) ? $response['message'] : __( 'Payment failed.', 'moyasar-payments' ), 'error' );
                return;
            }
        }

        // For new cards, process directly from frontend JS
        Moyasar_Helper::log( "CC Process Payment: New Card. Preparing frontend direct payment hash.", 'debug' );

        $amount = Moyasar_Helper::to_minor_units( $order->get_total(), $order->get_currency() );
        $is_subscription = Moyasar_Helper::is_subscription_order( $order );
        
        $save_card = ( isset( $_POST['wc-moyasar_cc-new-payment-method'] ) && ( 'true' === $_POST['wc-moyasar_cc-new-payment-method'] || '1' === $_POST['wc-moyasar_cc-new-payment-method'] ) ) || $is_subscription;
        
        if ( $save_card ) {
            $order->update_meta_data( '_moyasar_save_card', 'yes' );
        } else {
            $order->update_meta_data( '_moyasar_save_card', 'no' );
        }
        
        $order->update_status( 'pending', __( 'Awaiting direct credit card payment.', 'moyasar-payments' ) );
        
        if ( isset( WC()->session ) ) {
            WC()->session->set( 'order_awaiting_payment', $order_id );
        }
        
        $order->save();

        $callback_url = add_query_arg(
            array(
                'order_id' => $order_id,
                'key'      => $order->get_order_key(),
                'mode'     => 'modal',
            ),
            WC()->api_request_url( 'moyasar_callback' )
        );

        $metadata = $this->get_payment_metadata( $order );
        $metadata_json = wp_json_encode( $metadata );

        return array(
            'result'   => 'success',
            'redirect' => '#moyasar-pay-direct:' . $order_id . ':' . $order->get_order_key() . ':' . $amount . ':' . rawurlencode( $callback_url ) . ':' . rawurlencode( $metadata_json )
        );
    }
    


    /**
     * Process a Credit Card payment initiated from the WooCommerce Blocks
     * (Store API) checkout.
     *
     * The React block tokenizes the card in the browser (Moyasar /tokens) and
     * sends only the token id. Here we create the payment server-side using the
     * token source with 3-D Secure enabled, then redirect the shopper: to the
     * 3DS transaction URL when required, or straight to the thank-you page.
     *
     * @since 8.3.0
     * @param WC_Order $order
     * @return array
     */
    protected function process_block_payment( $order ) {
        $order_id = $order->get_id();
        $token    = isset( $_POST['moyasar_token'] ) ? sanitize_text_field( wp_unslash( $_POST['moyasar_token'] ) ) : '';

        if ( empty( $token ) ) {
            Moyasar_Helper::log( "CC Block Payment: Missing token for Order #$order_id", 'error' );
            wc_add_notice( __( 'Card verification failed. Please try again.', 'moyasar-payments' ), 'error' );
            return array(
                'result'   => 'failure',
                'messages' => __( 'Card verification failed. Please try again.', 'moyasar-payments' ),
                'message'  => __( 'Card verification failed. Please try again.', 'moyasar-payments' ),
            );
        }

        $amount          = Moyasar_Helper::to_minor_units( $order->get_total(), $order->get_currency() );
        $is_subscription = Moyasar_Helper::is_subscription_order( $order );
        $save_card       = ( isset( $_POST['moyasar_save_card'] ) && 'true' === sanitize_text_field( wp_unslash( $_POST['moyasar_save_card'] ) ) ) || $is_subscription;

        $source = array(
            'type'   => 'token',
            'token'  => $token,
            '3ds'    => true,
            'manual' => ( Moyasar_Helper::is_manual() && ! $is_subscription ),
        );

        if ( $save_card ) {
            $source['save_card'] = true;
            $order->update_meta_data( '_moyasar_save_card', 'yes' );
        } else {
            $order->update_meta_data( '_moyasar_save_card', 'no' );
        }

        // Top-level redirect flow (mode=redirect): the callback wp_redirect()s
        // rather than emitting the modal-only completion script.
        $callback_url = add_query_arg(
            array(
                'order_id' => $order_id,
                'key'      => $order->get_order_key(),
                'mode'     => 'redirect',
            ),
            WC()->api_request_url( 'moyasar_callback' )
        );

        $payment_data = array(
            'amount'       => $amount,
            'currency'     => $order->get_currency(),
            'description'  => 'Order #' . $order_id,
            'callback_url' => $callback_url,
            'source'       => $source,
            'metadata'     => $this->get_payment_metadata( $order ),
        );

        $order->update_status( 'pending', __( 'Awaiting Moyasar block checkout payment.', 'moyasar-payments' ) );
        $order->save();

        Moyasar_Helper::log( "CC Block Payment: Sending API request for Order #$order_id", 'debug' );
        $response = Moyasar_API::create_payment( $payment_data, $this->get_api_key() );

        if ( is_wp_error( $response ) ) {
            Moyasar_Helper::log( 'CC Block Payment: API error: ' . $response->get_error_message(), 'error' );
            wc_add_notice( $response->get_error_message(), 'error' );
            return array(
                'result'   => 'failure',
                'messages' => $response->get_error_message(),
                'message'  => $response->get_error_message(),
            );
        }

        $order->update_meta_data( '_moyasar_payment_id', $response['id'] ?? '' );
        if ( isset( $response['id'] ) ) {
            $order->set_transaction_id( $response['id'] );
            $order->add_order_note( sprintf( __( 'Moyasar Payment Initiated. ID: %s', 'moyasar-payments' ), $response['id'] ) );
        }
        $order->save();

        $status = isset( $response['status'] ) ? $response['status'] : '';

        if ( 'initiated' === $status && isset( $response['source']['transaction_url'] ) ) {
            // 3-D Secure required: send the shopper to the transaction URL. Moyasar
            // redirects back to our callback (mode=redirect) once completed.
            return array(
                'result'   => 'success',
                'redirect' => $response['source']['transaction_url'],
            );
        }

        if ( 'paid' === $status || 'authorized' === $status ) {
            if ( 'authorized' === $status ) {
                Moyasar_Helper::handle_payment_authorized( $order, $response, 'credit_card_form_blocks' );
            } else {
                Moyasar_Helper::handle_payment_success( $order, $response, 'credit_card_form_blocks' );
            }
            return array(
                'result'   => 'success',
                'redirect' => $this->get_return_url( $order ),
            );
        }

        Moyasar_Helper::handle_payment_failed( $order, $response, 'credit_card_form_blocks' );
        $message = isset( $response['source']['message'] ) ? $response['source']['message'] : ( isset( $response['message'] ) ? $response['message'] : __( 'Payment failed.', 'moyasar-payments' ) );
        wc_add_notice( $message, 'error' );
        return array(
            'result'   => 'failure',
            'messages' => $message,
            'message'  => $message,
        );
    }

    public function add_payment_method() {
        if ( ! is_user_logged_in() ) {
            return;
        }
        // Reuse payment fields
        $this->payment_fields();
    }


    public function verify_token() {
        if ( ! check_ajax_referer( 'moyasar_verify_token', 'nonce', false ) ) {
            $this->logger->error( 'Moyasar Verify Token: Nonce check failed.', array( 'source' => 'moyasar_cc' ) );
            wp_send_json_error( array( 'message' => 'Security check failed' ) );
        }

        $token = isset( $_POST['token'] ) ? wc_clean( $_POST['token'] ) : '';
        $name = isset( $_POST['name'] ) ? wc_clean( $_POST['name'] ) : '';

        if ( empty( $token ) ) {
            wp_send_json_error( array( 'message' => 'Token missing' ) );
        }

        $user = wp_get_current_user();
        if ( ! $user->ID ) {
             wp_send_json_error( array( 'message' => 'User not logged in' ) );
        }

        $this->logger->debug( 'Moyasar Verify Token: Fetching token ' . $token, array( 'source' => 'moyasar_cc' ) );
        
        $token_info = Moyasar_API::fetch_token( $token, $this->get_api_key() );
        if ( is_wp_error( $token_info ) ) {
            $this->logger->debug( 'Moyasar Verify Token Failed: ' . $token_info->get_error_message(), array( 'source' => 'moyasar_cc' ) );
            wp_send_json_error( array( 'message' => $token_info->get_error_message() ) );
        }

        if ( 'yes' === Moyasar_Helper::get_moyasar_option( 'testmode' ) ) {
            $this->logger->debug( 'Moyasar Verify Token Response: ' . print_r( $token_info, true ), array( 'source' => 'moyasar_cc' ) );
        }

        // Check token status
        $status = isset( $token_info['status'] ) ? $token_info['status'] : '';
        $this->logger->debug( 'Moyasar Verify Token Status: ' . $status, array( 'source' => 'moyasar_cc' ) );

        if ( 'valid' === $status || 'active' === $status || 'succeeded' === $status  ) {
            try {
                // Map fields strictly based on Moyasar Token API response
                $brand = isset( $token_info['brand'] ) ? $token_info['brand'] : '';
                $last4 = isset( $token_info['last_four'] ) ? $token_info['last_four'] : '';
                $month = isset( $token_info['month'] ) ? $token_info['month'] : '';
                $year  = isset( $token_info['year'] ) ? $token_info['year'] : '';

                // Validate required fields
                if ( empty( $brand ) || empty( $last4 ) || empty( $month ) || empty( $year ) ) {
                     throw new Exception( 'Missing token details (brand, last_four, month, or year).' );
                }

                // Check for existing token for this user with same Last4 and Expiry to update
                $existing_tokens = WC_Payment_Tokens::get_customer_tokens( $user->ID, $this->id );
                $wc_token = null;

                foreach ( $existing_tokens as $t ) {
                    if ( $t->get_last4() === $last4 && $t->get_card_type() === strtolower( $brand ) ) {
                        $wc_token = $t;
                        break;
                    }
            }
            
            if ( ! $wc_token ) {
                $wc_token = new WC_Payment_Token_CC();
                $wc_token->set_gateway_id( $this->id );
                $wc_token->set_card_type( strtolower( $brand ) );
                $wc_token->set_last4( $last4 );
                $wc_token->set_user_id( $user->ID );
            }

            $wc_token->set_token( $token_info['id'] );
            $wc_token->set_expiry_month( $month );
            $wc_token->set_expiry_year( $year );
            
            $wc_token->save();
            
            $this->logger->debug( 'Moyasar Verify Token: Token Saved/Updated (ID: ' . $wc_token->get_id() . ')', array( 'source' => 'moyasar_cc' ) );
            
             $redirect_url = wc_get_endpoint_url( 'payment-methods' );
             if ( empty( $redirect_url ) || strpos( $redirect_url, 'admin-ajax.php' ) !== false ) {
                  $redirect_url = get_permalink( get_option( 'woocommerce_myaccount_page_id' ) );
                  $redirect_url = wc_get_endpoint_url( 'payment-methods', '', $redirect_url );
             }

            wp_send_json_success( array( 
                'message' => 'Token Verified',
                'redirect_url' => $redirect_url
            ) );
            } catch ( Exception $e ) {
                $this->logger->error( 'Moyasar Verify Token Error: ' . $e->getMessage(), array( 'source' => 'moyasar_cc' ) );
                wp_send_json_error( array( 'message' => 'Failed to save token: ' . $e->getMessage() ) );
            }
        } else {
            $this->logger->debug( 'Moyasar Verify Token: Token Status Invalid', array( 'source' => 'moyasar_cc' ) );
            wp_send_json_error( array( 'message' => 'Token not valid: ' . $status ) );
        }
    }

    public function verify_callback() {
        // Redirection only
        $redirect_url = wc_get_endpoint_url( 'payment-methods' );
        if ( empty( $redirect_url ) || strpos( $redirect_url, 'admin-ajax.php' ) !== false ) {
             $redirect_url = get_permalink( get_option( 'woocommerce_myaccount_page_id' ) );
             $redirect_url = wc_get_endpoint_url( 'payment-methods', '', $redirect_url );
        }
        wp_redirect( $redirect_url );
        exit;
    }
    /**
     * Shortcode to render Quick Buy Button
     */
    public function shortcode_quick_buy( $atts ) {
        ob_start();
        $this->render_instant_checkout_button();
        return ob_get_clean();
    }

    /**
     * Render Instant Checkout Button on Product Page
     */
    public function render_instant_checkout_button() {
        if ( ! is_user_logged_in() ) {
            // Moyasar_Helper::log( 'Quick Buy: User not logged in.', 'debug' ); 
            return;
        }

        $user_id = get_current_user_id();
        $tokens = WC_Payment_Tokens::get_customer_tokens( $user_id, $this->id );

        if ( empty( $tokens ) ) {
            Moyasar_Helper::log( 'Quick Buy Check: User ' . $user_id . ' has no saved cards.', 'debug' );
            return;
        }

        // Get the last used token (or default)
        $token = reset( $tokens ); // Taking the first one for simplicity, or we could sort by last used
        foreach ( $tokens as $t ) {
            if ( $t->is_default() ) {
                $token = $t;
                break;
            }
        }

        $brand = $token->get_card_type();
        $last4 = $token->get_last4();
        
        global $product;
        // Fallback if global product not set (e.g. in shortcode context outside loop)
        if ( ! $product && get_the_ID() ) {
            $product = wc_get_product( get_the_ID() );
        }

        if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
            Moyasar_Helper::log( 'Quick Buy Check: Product invalid/unavailable.', 'debug' );
            return;
        }
        
        $brand_display = ucfirst( $brand );
        $btn_text = sprintf( __( 'Quick Buy with %s ending in %s', 'moyasar-payments' ), $brand_display, $last4 );

        // Security Nonce
        $nonce = wp_create_nonce( 'moyasar_instant_checkout' );

        // Log Success
        Moyasar_Helper::log( 'Quick Buy: Rendering button for User ' . $user_id . ' (Shortcode or Hook)', 'debug' );

        ?>
        <button 
            type="button" 
            id="moyasar-instant-checkout-btn" 
            class="button alt moyasar-quick-buy-btn"
            data-security="<?php echo esc_attr( $nonce ); ?>"
            data-product_id="<?php echo esc_attr( $product->get_id() ); ?>"
            data-token_id="<?php echo esc_attr( $token->get_id() ); ?>"
        >
            <span class="dashicons dashicons-lightning" style="margin-top: 2px;"></span> <?php echo esc_html( $btn_text ); ?>
        </button>
        <div id="moyasar-instant-checkout-error" class="woocommerce-error" style="display:none; margin-top: 10px;"></div>
        <?php
    }

    /**
     * Handle AJAX Instant Checkout
     */
    public function ajax_instant_checkout() {
        check_ajax_referer( 'moyasar_instant_checkout', 'security' );

        if ( ! is_user_logged_in() ) {
             wp_send_json_error( array( 'message' => 'Please login.' ) );
        }

        $user_id = get_current_user_id();
        $product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
        $quantity   = isset( $_POST['quantity'] ) ? absint( $_POST['quantity'] ) : 1;
        $token_id   = isset( $_POST['token_id'] ) ? absint( $_POST['token_id'] ) : 0;

        if ( ! $product_id || ! $token_id ) {
             wp_send_json_error( array( 'message' => 'Invalid parameters.' ) );
        }

        // Validate Token
        $token = WC_Payment_Tokens::get( $token_id );
        if ( ! $token || $token->get_user_id() !== $user_id || $token->get_gateway_id() !== $this->id ) {
             wp_send_json_error( array( 'message' => 'Invalid payment token.' ) );
        }

        // Create Order
        try {
            $order = wc_create_order( array(
                'customer_id' => $user_id
            ) );

            // Add Product
            $product = wc_get_product( $product_id );
            $order->add_product( $product, $quantity );

            // Set Addresses (Use Customer Defaults)
            $customer = new WC_Customer( $user_id );
            $billing_address = $customer->get_billing();
            $shipping_address = $customer->get_shipping();

            // Validate Address
            if ( empty( $billing_address['first_name'] ) || empty( $billing_address['address_1'] ) ) {
                 // Try to fallback or error
                 wp_send_json_error( array( 'message' => 'Please set your default billing address in My Account first.' ) );
            }

            $order->set_address( $billing_address, 'billing' );
            if ( $product->needs_shipping() ) {
                if ( empty( $shipping_address['first_name'] ) ) $shipping_address = $billing_address;
                 $order->set_address( $shipping_address, 'shipping' );
                 
                 // Quick Buy Flat Shipping Logic (Beta)
                 // Use main settings as configured in Moyasar Admin
                 $enable_shipping = Moyasar_Helper::get_moyasar_option( 'quick_buy_shipping_enabled', 'no' );
                 $shipping_instance_id = Moyasar_Helper::get_moyasar_option( 'quick_buy_shipping_option', '' );

                 if ( 'yes' === $enable_shipping && ! empty( $shipping_instance_id ) ) {
                     // Simulate Shipping Package for Calculation (Preserves User Cart)
                     $package = array(
                        'contents'        => array(),
                        'contents_cost'   => 0,
                        'applied_coupons' => array(),
                        'user'            => array( 'ID' => $user_id ),
                        'destination'     => array(
                            'country'   => $shipping_address['country'],
                            'state'     => $shipping_address['state'],
                            'postcode'  => $shipping_address['postcode'],
                            'city'      => $shipping_address['city'],
                            'address'   => $shipping_address['address_1'],
                            'address_2' => $shipping_address['address_2'],
                        ),
                    );

                    foreach ( $order->get_items() as $item_id => $item ) {
                        if ( $item->is_type( 'line_item' ) ) {
                            $product = $item->get_product();
                            if ( $product && $product->needs_shipping() ) {
                                $package['contents'][ $item_id ] = array(
                                    'data'               => $product,
                                    'quantity'           => $item->get_quantity(),
                                    'price'              => $product->get_price(),
                                    'line_total'         => $item->get_total(),
                                    'line_tax'           => $item->get_total_tax(),
                                    'line_subtotal'      => $item->get_subtotal(),
                                    'line_subtotal_tax'  => $item->get_subtotal_tax(),
                                );
                                $package['contents_cost'] += $item->get_total();
                            }
                        }
                    }

                    if ( ! empty( $package['contents'] ) ) {
                        $rates = WC()->shipping()->calculate_shipping_for_package( $package );
                        if ( isset( $rates['rates'] ) ) {
                             foreach ( $rates['rates'] as $rate ) {
                                 if ( isset( $rate->instance_id ) && (string)$rate->instance_id === (string)$shipping_instance_id ) {
                                     // Found Rate
                                     $item = new WC_Order_Item_Shipping();
                                     $item->set_method_title( $rate->label );
                                     $item->set_method_id( $rate->id );
                                     $item->set_instance_id( $rate->instance_id );
                                     $item->set_total( $rate->cost );
                                     $item->calculate_taxes( $rate->taxes );
                                     $order->add_item( $item );
                                     $order->save();
                                     break;
                                 }
                             }
                        }
                    }
                 }
            }

            $order->calculate_totals();
            
            // Set Payment Method explicitly so WC knows which gateway handles this order (enables Refunds)
            $order->set_payment_method( $this );
            $order->set_payment_method_title( $this->method_title );
            
            $order->update_status( 'pending', 'Instant Checkout Initiated.' );
            $order->update_meta_data( '_moyasar_instant_checkout', true );
            $order->save(); // Save to get ID

            // Process Payment with Token
            // We can't use process_payment($order_id) because it looks at $_POST.
            // We must call API manually using token.
            
            $source_token = $token->get_token();
            $amount = Moyasar_Helper::to_minor_units( $order->get_total(), $order->get_currency() );

            // Correct Callback URL for Order Processing
            $callback_url = add_query_arg(
                array(
                    'order_id' => $order->get_id(),
                    'key'      => $order->get_order_key(),
                ),
                WC()->api_request_url( 'moyasar_callback' )
            );

            $payment_data = array(
                'amount'    => $amount,
                'currency'  => $order->get_currency(),
                'description' => 'Order #' . $order->get_id(),
                'callback_url' => $callback_url,
                'source'    => array(
                    'type' => 'token',
                    'token' => $source_token,
                    '3ds' => true,
                    'manual' => Moyasar_Helper::is_manual(),
                ),
                'metadata' => $this->get_payment_metadata( $order ),
            );

            $response = Moyasar_API::create_payment( $payment_data, $this->get_api_key() );

            if ( is_wp_error( $response ) ) {
                 $order->update_status( 'failed', 'Moyasar API Error: ' . $response->get_error_message() );
                 wp_send_json_error( array( 'message' => $response->get_error_message() ) );
            }

            if ( isset( $response['status'] ) && 'initiated' === $response['status'] ) {
                 // 3DS Required
                 $url = $response['source']['transaction_url'];
                 $order->add_order_note( 'Moyasar: 3DS Verification Required. Redirecting...' );
                 $order->update_meta_data( '_moyasar_payment_id', $response['id'] );
                 $order->save();
                 wp_send_json_success( array( 'redirect' => $url ) );
            } elseif ( isset( $response['status'] ) && ( 'paid' === $response['status'] || 'authorized' === $response['status'] ) ) {
                 // Use Central Helper
                 if ( 'authorized' === $response['status'] ) {
                     Moyasar_Helper::handle_payment_authorized( $order, $response, 'instant_checkout', true );
                 } else {
                     Moyasar_Helper::handle_payment_success( $order, $response, 'instant_checkout', true );
                 }
                 
                 wp_send_json_success( array( 'redirect' => $this->get_return_url( $order ) ) );
            } else {
                 // Use Central Helper
                 Moyasar_Helper::handle_payment_failed( $order, $response, 'instant_checkout' );

                 $msg = isset( $response['message'] ) ? $response['message'] : 'Payment failed.';
                 wp_send_json_error( array( 'message' => $msg ) );
            }

        } catch ( Exception $e ) {
            wp_send_json_error( array( 'message' => $e->getMessage() ) );
        }
    }
}
