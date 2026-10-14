<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * @since      8.0.0
 * @package    Moyasar
 * @subpackage Moyasar/includes
 */
class Moyasar_Run {

	/**
	 * The unique identifier of this plugin.
	 *
	 * @since    8.0.0
	 * @access   protected
	 * @var      string    $plugin_name    The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @since    8.0.0
	 * @access   protected
	 * @var      string    $version    The current version of the plugin.
	 */
	protected $version;

	/**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks for the admin area and
	 * the public-facing side of the site.
	 *
	 * @since    8.0.0
	 */
	public function __construct() {
		if ( defined( 'MOYASAR_WC_VERSION' ) ) {
			$this->version = MOYASAR_WC_VERSION;
		} else {
			$this->version = '8.0.3';
		}
		$this->plugin_name = 'moyasar-payments';

		$this->load_dependencies();
		$this->set_locale();
		$this->define_admin_hooks();
		$this->define_public_hooks();
		add_action( 'plugins_loaded', array( $this, 'migrate_settings_v7_to_v8' ), 5 );
		add_action( 'init', array( 'Moyasar_Helper', 'register_authorized_order_status' ) );
		add_filter( 'wc_order_statuses', array( 'Moyasar_Helper', 'add_authorized_to_order_statuses' ) );
		add_filter( 'woocommerce_valid_order_statuses_for_payment_complete', array( 'Moyasar_Helper', 'add_authorized_to_valid_payment_complete_statuses' ), 10, 2 );
	}

	/**
	 * Load the required dependencies for this plugin.
	 *
	 * Include the following files that make up the plugin:
	 *
	 * - Moyasar_Loader. Orchestrates the hooks of the plugin.
	 * - Moyasar_i18n. Defines internationalization functionality.
	 * - Moyasar_Admin. Defines all hooks for the admin area.
	 * - Moyasar_Public. Defines all hooks for the public side of the site.
	 *
	 * @since    8.0.0
	 * @access   private
	 */
	private function load_dependencies() {

		/**
		 * The class responsible for defining internationalization functionality.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-moyasar-i18n.php';

		/**
		 * The helper class.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-moyasar-helper.php';

		/**
		 * The API class.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-moyasar-api.php';

		        /**
		 * The class responsible for handling webhooks.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-moyasar-webhook.php';

		        /**
         * The class responsible for handling callbacks (3DS return).
         */
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-moyasar-callback.php';
        new Moyasar_Callback();

        /**
         * Traits
         */
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/traits/trait-moyasar-gateway.php';

        // Load Gateways
        $this->load_gateways();
        
        // Load Blocks Support hook
        add_action( 'woocommerce_blocks_loaded', array( $this, 'register_moyasar_blocks_support' ) );

	}

	private function load_gateways() {
		add_filter( 'woocommerce_payment_gateways', array( $this, 'add_moyasar_gateways' ) );
        
        // Force load gateways for AJAX requests to ensure hooks are registered
        if ( defined( 'DOING_AJAX' ) && DOING_AJAX && isset( $_REQUEST['action'] ) ) {
            $action = sanitize_text_field( $_REQUEST['action'] );
            if ( strpos( $action, 'moyasar_apple_pay_' ) === 0 ) {
                 add_action( 'init', function() {
                     if ( class_exists( 'WC_Payment_Gateway' ) ) {
                         require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/gateways/class-wc-gateway-moyasar-apple-pay.php';
                         if ( class_exists( 'WC_Gateway_Moyasar_Apple_Pay' ) ) {
                             new WC_Gateway_Moyasar_Apple_Pay();
                         }
                     }
                 }, 10 );
            } elseif ( strpos( $action, 'moyasar_stc_pay_' ) === 0 ) {
                 add_action( 'init', function() {
                     if ( class_exists( 'WC_Payment_Gateway' ) ) {
                         require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/gateways/class-wc-gateway-moyasar-stc-pay.php';
                         if ( class_exists( 'WC_Gateway_Moyasar_STC_Pay' ) ) {
                             new WC_Gateway_Moyasar_STC_Pay();
                         }
                     }
                 }, 10 );
            }
        }

        // Align sub-gateways checkout display order to match the parent 'moyasar' order rank
        add_filter( 'option_woocommerce_gateway_order', array( $this, 'align_moyasar_gateways_order' ) );
        add_filter( 'default_option_woocommerce_gateway_order', array( $this, 'align_moyasar_gateways_order' ) );

        // Bi-directional enabled option synchronization and sections cleanup
        if ( is_admin() ) {
            $sub_gateways = array( 'moyasar_cc', 'moyasar_apple_pay', 'moyasar_stc_pay', 'moyasar_samsung_pay', 'moyasar_invoice' );
            foreach ( $sub_gateways as $gateway_id ) {
                add_action( 'update_option_woocommerce_' . $gateway_id . '_settings', function( $old_value, $value ) use ( $gateway_id ) {
                    $enabled = ( is_array( $value ) && isset( $value['enabled'] ) ) ? $value['enabled'] : 'no';
                    $main_settings = get_option( 'woocommerce_moyasar_settings', array() );
                    if ( ! is_array( $main_settings ) ) {
                        $main_settings = array();
                    }
                    
                    $key = 'enable_method_' . $gateway_id;
                    if ( ! isset( $main_settings[ $key ] ) || $main_settings[ $key ] !== $enabled ) {
                        $main_settings[ $key ] = $enabled;
                        update_option( 'woocommerce_moyasar_settings', $main_settings );
                    }
                }, 10, 2 );
            }

            // Hide sub-gateways from settings page sections navigation submenu at the top
            add_filter( 'woocommerce_get_sections_checkout', function( $sections ) use ( $sub_gateways ) {
                if ( is_array( $sections ) ) {
                    foreach ( $sub_gateways as $gateway_id ) {
                        unset( $sections[ $gateway_id ] );
                    }
                }
                return $sections;
            }, 99 );
        }
	}

    public function add_moyasar_gateways( $gateways ) {
        // Include gateway files
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/gateways/class-wc-gateway-moyasar.php'; // Main Gateway
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/gateways/class-wc-gateway-moyasar-cc.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/gateways/class-wc-gateway-moyasar-apple-pay.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/gateways/class-wc-gateway-moyasar-stc-pay.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/gateways/class-wc-gateway-moyasar-samsung-pay.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/gateways/class-wc-gateway-moyasar-online-payment.php';

        // Always register the Main Gateway (General Settings)
        $gateways[] = 'WC_Gateway_Moyasar';
        
        // Hide sub-gateways from the admin payments list page to avoid clutter, keeping them active only on frontend checkout
        $is_settings_list = is_admin() && isset( $_GET['page'] ) && 'wc-settings' === $_GET['page'] && in_array( isset( $_GET['tab'] ) ? $_GET['tab'] : '', array( 'checkout', 'payments' ) ) && empty( $_GET['section'] );
        
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? urldecode( $_SERVER['REQUEST_URI'] ) : '';
        $is_rest_settings_list = ( defined( 'REST_REQUEST' ) && REST_REQUEST ) && (
            strpos( $request_uri, 'wc-admin/settings/payments' ) !== false ||
            ( isset( $_GET['rest_route'] ) && strpos( urldecode( $_GET['rest_route'] ), 'wc-admin/settings/payments' ) !== false )
        );

        if ( ! $is_settings_list && ! $is_rest_settings_list ) {
            $gateways[] = 'WC_Gateway_Moyasar_CC';
            $gateways[] = 'WC_Gateway_Moyasar_Apple_Pay';
            $gateways[] = 'WC_Gateway_Moyasar_STC_Pay';
            $gateways[] = 'WC_Gateway_Moyasar_Samsung_Pay';
            $gateways[] = 'WC_Gateway_Moyasar_Invoice';
        }
        
		return $gateways;
	}

    /**
     * Align Moyasar sub-gateways checkout display order to match the parent 'moyasar' order rank.
     * 
     * @param array|mixed $value Saved gateway order dictionary.
     * @return array|mixed
     */
    public function align_moyasar_gateways_order( $value ) {
        if ( ! is_array( $value ) || empty( $value ) ) {
            return $value;
        }

        if ( isset( $value['moyasar'] ) ) {
            $parent_order = (int) $value['moyasar'];
            
            // Adjust orders after the parent
            foreach ( $value as $gateway_id => $order ) {
                if ( (int) $order > $parent_order ) {
                    $value[ $gateway_id ] = (int) $order + 5;
                }
            }
            
            // Insert sub-gateways right next to the parent
            $value['moyasar_cc']          = $parent_order + 1;
            $value['moyasar_apple_pay']   = $parent_order + 2;
            $value['moyasar_stc_pay']     = $parent_order + 3;
            $value['moyasar_samsung_pay'] = $parent_order + 4;
            $value['moyasar_invoice']     = $parent_order + 5;
            
            asort( $value );
        }
        
        return $value;
    }

    public function register_moyasar_blocks_support() {
        if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
            Moyasar_Helper::log( 'Moyasar: Blocks AbstractPaymentMethodType class NOT found. Skipping blocks registration.', 'debug' );
            return;
        }

        $blocks_file = plugin_dir_path( __FILE__ ) . 'blocks/class-moyasar-blocks.php';

        if ( ! file_exists( $blocks_file ) ) {
            Moyasar_Helper::log( 'Moyasar: Blocks integration file NOT found at: ' . $blocks_file, 'debug' );
            return;
        }

        require_once $blocks_file;

        if ( ! class_exists( 'Moyasar_Blocks_Support' ) ) {
            Moyasar_Helper::log( 'Moyasar: Moyasar_Blocks_Support class NOT found after requiring file.', 'debug' );
            return;
        }

        add_action(
            'woocommerce_blocks_payment_method_type_registration',
            function( $payment_method_registry ) {
                $gateways = array(
                    'moyasar_cc',
                    'moyasar_apple_pay',
                    'moyasar_stc_pay',
                    'moyasar_samsung_pay',
                    'moyasar_invoice',
                );
                foreach ( $gateways as $gateway_id ) {
                    $payment_method_registry->register( new Moyasar_Blocks_Support( $gateway_id ) );
                }
            }
        );
    }

	/**
	 * Define the locale for this plugin for internationalization.
	 *
	 * Uses the Moyasar_i18n class in order to set the domain and to register the hook
	 * with WordPress.
	 *
	 * @since    8.0.0
	 * @access   private
	 */
	private function set_locale() {
		$plugin_i18n = new Moyasar_i18n();
		add_action( 'plugins_loaded', array( $plugin_i18n, 'load_plugin_textdomain' ) );
	}

	/**
	 * Register all of the hooks related to the admin area functionality
	 * of the plugin.
	 *
	 * @since    8.0.0
	 * @access   private
	 */
	private function define_admin_hooks() {
		// Register admin styles and scripts
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/admin/class-moyasar-admin.php';
        $plugin_admin = new Moyasar_Admin( $this->get_plugin_name(), $this->get_version() );
        
        add_action( 'admin_enqueue_scripts', array( $plugin_admin, 'enqueue_scripts' ) );
        add_filter( 'plugin_row_meta', array( $plugin_admin, 'plugin_row_meta' ), 10, 2 );

        // Manual Capture from Order Edit Page (Action Buttons next to Refund)
        add_action( 'woocommerce_order_item_add_action_buttons', array( $plugin_admin, 'render_order_capture_button' ) );
        add_action( 'wp_ajax_moyasar_capture_payment', array( $plugin_admin, 'ajax_capture_payment' ) );

        // Validate API Keys from Settings Page
        add_action( 'wp_ajax_moyasar_validate_keys', array( $plugin_admin, 'ajax_validate_keys' ) );

        // Order Actions dropdown integration
        add_filter( 'woocommerce_order_actions', array( $plugin_admin, 'add_order_capture_action' ), 10, 2 );
        add_action( 'woocommerce_order_action_moyasar_capture_payment', array( $plugin_admin, 'process_order_capture_action' ) );

        // Order status badge styling
        add_action( 'admin_head', array( $plugin_admin, 'output_order_status_badge_css' ) );
	}

	/**
	 * Register all of the hooks related to the public-facing functionality
	 * of the plugin.
	 *
	 * @since    8.0.0
	 * @access   private
	 */
	private function define_public_hooks() {
		// Register public styles and scripts
        add_action( 'wp_ajax_moyasar_verify_token', array( $this, 'ajax_verify_token' ) );
        add_action( 'wp_ajax_nopriv_moyasar_verify_token', array( $this, 'ajax_verify_token' ) );
        add_action( 'woocommerce_api_moyasar_cc_verify_callback', array( $this, 'api_verify_callback' ) );
        add_action( 'wp_ajax_moyasar_cc_verify_callback', array( $this, 'api_verify_callback' ) );
        add_action( 'woocommerce_api_moyasar_cc_verify_callback', array( $this, 'api_verify_callback' ) );
        add_action( 'wp_ajax_moyasar_cc_verify_callback', array( $this, 'api_verify_callback' ) );
        add_action( 'wp_ajax_nopriv_moyasar_cc_verify_callback', array( $this, 'api_verify_callback' ) );

        // Instant Checkout Hooks
        add_action( 'wp_ajax_moyasar_instant_checkout', array( $this, 'ajax_instant_checkout' ) );
        add_action( 'wp_ajax_nopriv_moyasar_instant_checkout', array( $this, 'ajax_instant_checkout' ) );

        // Render Instant Checkout Button (Explicit Hook)
        add_action( 'woocommerce_after_add_to_cart_button', array( $this, 'render_instant_checkout' ) );

        // Apple Pay Hooks
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_apple_pay_scripts' ) );
        add_action( 'woocommerce_after_add_to_cart_button', array( $this, 'render_apple_pay_on_product' ) );
        
        // Apple Pay Domain Verification
        add_action( 'init', array( $this, 'add_rewrite_rules' ) );
        add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
        add_action( 'init', array( $this, 'serve_apple_pay_domain_file' ) );

        // Show a checkout notice when Moyasar cannot load its payment methods (missing API keys).
        add_action( 'woocommerce_before_checkout_form', array( $this, 'maybe_render_checkout_config_notice' ), 5 );

        // Cloudflare Compatibility
        add_filter( 'script_loader_tag', array( $this, 'add_script_attributes' ), 10, 2 );
        add_filter( 'wp_inline_script_attributes', array( $this, 'add_inline_script_attributes' ), 10, 2 );

        // Raw Total Injection for JS Updates (Checkout & Cart)
        add_action( 'woocommerce_review_order_after_order_total', array( $this, 'print_hidden_total_input' ) );
        add_action( 'woocommerce_after_cart_totals', array( $this, 'print_hidden_total_input' ) );
    }

    /**
     * Inject raw total amount via hidden input for JS to read.
     */
    public function print_hidden_total_input() {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return;
        }

        // Get raw total
        $raw_total = WC()->cart->get_total( 'edit' );
        if ( ! $raw_total ) {
            $raw_total = WC()->cart->total;
        }
        
        // Output hidden input
        echo '<input type="hidden" class="moyasar-update-total" value="' . esc_attr( $raw_total ) . '" />';
    }

    /**
     * Add data-cfasync="false" to scripts to prevent Cloudflare Rocket Loader from modifying them.
     */
    public function add_script_attributes( $tag, $handle ) {
        $scripts_to_exclude = array( 'moyasar_cc', 'moyasar_apple_pay', 'moyasar_stc_pay', 'moyasar_samsung_pay' );

        if ( in_array( $handle, $scripts_to_exclude, true ) ) {
            return str_replace( ' src', ' data-cfasync="false" src', $tag );
        }

        return $tag;
    }

    /**
     * Add data-cfasync="false" to our inline (localized) scripts.
     *
     * add_script_attributes() only covers the external <script src> tags via
     * the `script_loader_tag` filter. The config injected by wp_localize_script
     * is printed as a SEPARATE inline "<handle>-js-extra" script, which must be
     * excluded from Cloudflare Rocket Loader too — otherwise Rocket Loader
     * defers it and the main script runs first, leaving moyasar_params /
     * moyasar_apple_params undefined at execution time.
     *
     * @param array  $attributes Inline script tag attributes (includes 'id').
     * @param string $javascript The inline script contents (unused).
     * @return array
     */
    public function add_inline_script_attributes( $attributes, $javascript = '' ) {
        if ( empty( $attributes['id'] ) ) {
            return $attributes;
        }

        $handles = array( 'moyasar_cc', 'moyasar_apple_pay', 'moyasar_stc_pay', 'moyasar_samsung_pay', 'moyasar-blocks-integration' );

        foreach ( $handles as $handle ) {
            // Localized/inline data is printed with an id like "<handle>-js-extra"
            // (also "-js-before" / "-js-after" for wp_add_inline_script).
            if ( 0 === strpos( $attributes['id'], $handle . '-js-' ) ) {
                $attributes['data-cfasync'] = 'false';
                break;
            }
        }

        return $attributes;
    }

    public function render_instant_checkout() {
        if ( ! class_exists( 'WC_Gateway_Moyasar_CC' ) ) {
            require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/gateways/class-wc-gateway-moyasar-cc.php';
        }
        
        // Check settings (Saved in Main Moyasar Gateway options now)
        $settings = get_option( 'woocommerce_moyasar_settings', array() );
        
        if ( ! isset( $settings['enable_instant_checkout'] ) || 'yes' !== $settings['enable_instant_checkout'] ) {
            return;
        }

        $gateway = new WC_Gateway_Moyasar_CC();
        $gateway->render_instant_checkout_button();
    }

    public function api_verify_callback() {
        if ( ! class_exists( 'WC_Gateway_Moyasar_CC' ) ) {
            require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/gateways/class-wc-gateway-moyasar-cc.php';
        }
        $gateway = new WC_Gateway_Moyasar_CC();
        $gateway->verify_callback();
    }
    
    /**
     * Enqueue Apple Pay Scripts Early
     */
    public function enqueue_apple_pay_scripts() {
        if ( ! class_exists( 'WC_Gateway_Moyasar_Apple_Pay' ) ) {
             require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/gateways/class-wc-gateway-moyasar-apple-pay.php';
        }
        $gateway = new WC_Gateway_Moyasar_Apple_Pay();
        // This will enqueue scripts if conditions met (is_product, etc)
        // We call it directly because the hook inside constructor might not have run if constructed now.
        // Actually, construction adds the hook. 
        // But wp_enqueue_scripts hook might be currently running or passed.
        // If we correspond to 'wp_enqueue_scripts', we are in the hook.
        // So just calling payment_scripts() works if it enqueues.
        $gateway->payment_scripts();
    }
    
    /**
     * Render Apple Pay Button on Product Page
     */
    public function render_apple_pay_on_product() {
        if ( ! class_exists( 'WC_Gateway_Moyasar_Apple_Pay' ) ) {
             require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/gateways/class-wc-gateway-moyasar-apple-pay.php';
        }
        $gateway = new WC_Gateway_Moyasar_Apple_Pay();
        $gateway->render_apple_pay_button();
    }

    public function ajax_verify_token() {
        // Ensure class is loaded
        if ( ! class_exists( 'WC_Gateway_Moyasar_CC' ) ) {
            require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/gateways/class-wc-gateway-moyasar-cc.php';
        }
        $gateway = new WC_Gateway_Moyasar_CC();
        $gateway->verify_token();
        wp_die();
    }

    public function ajax_instant_checkout() {
        // Ensure class is loaded
        if ( ! class_exists( 'WC_Gateway_Moyasar_CC' ) ) {
            require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/gateways/class-wc-gateway-moyasar-cc.php';
        }
        $gateway = new WC_Gateway_Moyasar_CC();
        $gateway->ajax_instant_checkout();
        wp_die();
    }

	/**
	 * Run the plugin (hooks are registered in constructor).
	 *
	 * @since    8.0.0
	 */
	public function run() {
		// Hooks are registered in __construct via load_dependencies, set_locale, define_admin_hooks, define_public_hooks.
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 *
	 * @since     8.0.0
	 * @return    string    The name of the plugin.
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @since     8.0.0
	 * @return    string    The version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}

    /**
     * Add rewrite rules for Apple Pay Domain Verification
     */
    public function add_rewrite_rules() {
        add_rewrite_rule(
            '^\\.well-known/apple-developer-merchantid-domain-association/?$',
            'index.php?apple_pay_domain_association=1',
            'top'
        );
    }

    /**
     * Add query vars
     */
    public function add_query_vars( $vars ) {
        $vars[] = 'apple_pay_domain_association';
        return $vars;
    }

    /**
     * Show an error notice on the checkout when Moyasar is enabled but cannot
     * load its payment methods because no API keys are configured.
     *
     * @since 8.2.0
     */
    public function maybe_render_checkout_config_notice() {
        // Only relevant when the merchant has turned Moyasar on but not added keys.
        if ( 'yes' !== Moyasar_Helper::get_moyasar_option( 'enabled' ) ) {
            return;
        }

        if ( Moyasar_Helper::has_api_keys() ) {
            return;
        }

        wc_print_notice(
            esc_html__( 'Online payments are currently unavailable. Please contact the store or try again later.', 'moyasar-payments' ),
            'error'
        );

        if ( current_user_can( 'manage_woocommerce' ) ) {
            wc_print_notice(
                sprintf(
                    /* translators: %s: URL to the Moyasar settings page */
                    wp_kses_post( __( 'Moyasar API keys are missing. Add your Test or Live API keys in <a href="%s">Moyasar settings</a> to enable payment methods.', 'moyasar-payments' ) ),
                    esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=moyasar' ) )
                ),
                'error'
            );
        }
    }

    /**
     * Serve Apple Pay Domain File
     */
    public function serve_apple_pay_domain_file() {
        // Check query var OR direct URI match
        $uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
        
        // Explicitly check $_GET for fallback URL
        $is_get_request = isset( $_GET['apple_pay_domain_association'] );

        // Check for the specific path .well-known/apple-developer-merchantid-domain-association
        if ( $is_get_request || strpos( $uri, 'apple-developer-merchantid-domain-association' ) !== false ) {

            // Preferred: content stored directly in settings (no filesystem dependency).
            $file_content = Moyasar_Helper::get_apple_pay_domain_file_content();
            if ( ! empty( $file_content ) ) {
                while ( ob_get_level() ) {
                    ob_end_clean();
                }
                header( 'Content-Type: text/plain' );
                echo $file_content;
                exit;
            }

            // Legacy fallback: file uploaded via the WordPress media library (stored as URL).
            $file_url = get_option( 'woocommerce_moyasar_apple_pay_domain_file_url' );
            if ( ! $file_url ) {
                 // Try getting from main settings array
                 $settings = get_option( 'woocommerce_moyasar_settings' );
                 $file_url = isset( $settings['apple_pay_domain_file_url'] ) ? $settings['apple_pay_domain_file_url'] : '';
            }


            if ( ! empty( $file_url ) ) {
                $upload_dir = wp_upload_dir();
                $base_dir   = $upload_dir['basedir'];
                $base_url   = $upload_dir['baseurl'];

                // Construct path
                $file_path = str_replace( $base_url, $base_dir, $file_url );

                // Fallback checks
                if ( ! file_exists( $file_path ) ) {
                    // Check by filename in base directory
                    $filename = basename( $file_url );
                    if ( file_exists( $base_dir . '/' . $filename ) ) {
                        $file_path = $base_dir . '/' . $filename;
                    } else {
                        // Check alternate protocol
                        $base_url_alt = ( strpos( $base_url, 'https://' ) === 0 ) ? str_replace( 'https://', 'http://', $base_url ) : str_replace( 'http://', 'https://', $base_url );
                        $file_path_alt = str_replace( $base_url_alt, $base_dir, $file_url );
                        if ( file_exists( $file_path_alt ) ) {
                            $file_path = $file_path_alt;
                        }
                    }
                }

                $base_dir_real = realpath( $base_dir );
                $file_path_real = $file_path && file_exists( $file_path ) ? realpath( $file_path ) : false;
                $path_under_base = $base_dir_real && $file_path_real && strpos( $file_path_real, $base_dir_real ) === 0;

                if ( $path_under_base && $file_path_real && is_file( $file_path_real ) ) {
                    header( 'Content-Type: text/plain' );
                    // Prevent WordPress Header issues
                    while ( ob_get_level() ) {
                        ob_end_clean();
                    }
                    readfile( $file_path_real );
                    exit;
                }

                Moyasar_Helper::log( 'Moyasar Apple Pay: Domain file not found or path outside uploads directory.', 'debug' );
            }
            
            // If we are here, we failed to serve.
            status_header( 404 );
            echo 'Apple Pay Domain Association File Not Found.';
            exit;
        }
    }

    /**
     * Migrate settings from v7 to v8.
     *
     * Maps legacy per-gateway options to the consolidated option array.
     */
    public static function migrate_settings_v7_to_v8() {
        if ( 'yes' === get_option( 'woocommerce_moyasar_v8_migrated' ) ) {
            return;
        }

        // Check if v8 settings already exist and are populated (test OR live keys)
        $v8_settings = get_option( 'woocommerce_moyasar_settings' );
        if ( is_array( $v8_settings ) && (
            ! empty( $v8_settings['test_secret_key'] ) ||
            ! empty( $v8_settings['live_secret_key'] ) ||
            ! empty( $v8_settings['test_publishable_key'] ) ||
            ! empty( $v8_settings['live_publishable_key'] )
        ) ) {
            // Ensure enable_method_* flags exist — may be missing on installs that already had API keys in v8 settings.
            $method_opts_map = array(
                'enable_method_moyasar_cc'          => 'woocommerce_moyasar_cc_settings',
                'enable_method_moyasar_apple_pay'   => 'woocommerce_moyasar_apple_pay_settings',
                'enable_method_moyasar_stc_pay'     => 'woocommerce_moyasar_stc_pay_settings',
                'enable_method_moyasar_samsung_pay' => 'woocommerce_moyasar_samsung_pay_settings',
            );
            $settings_updated = false;
            foreach ( $method_opts_map as $v8_key => $opts_key ) {
                if ( ! isset( $v8_settings[ $v8_key ] ) ) {
                    $sub_opts = get_option( $opts_key, array() );
                    $v8_settings[ $v8_key ] = ( is_array( $sub_opts ) && isset( $sub_opts['enabled'] ) && 'yes' === $sub_opts['enabled'] ) ? 'yes' : 'no';
                    $settings_updated = true;
                }
            }
            // Invoice may be enabled via either the invoice or the legacy online_payment settings.
            if ( ! isset( $v8_settings['enable_method_moyasar_invoice'] ) ) {
                $invoice_opts = get_option( 'woocommerce_moyasar_invoice_settings', array() );
                $online_opts  = get_option( 'woocommerce_moyasar_online_payment_settings', array() );
                $invoice_enabled = ( is_array( $invoice_opts ) && isset( $invoice_opts['enabled'] ) && 'yes' === $invoice_opts['enabled'] )
                    || ( is_array( $online_opts ) && isset( $online_opts['enabled'] ) && 'yes' === $online_opts['enabled'] );
                $v8_settings['enable_method_moyasar_invoice'] = $invoice_enabled ? 'yes' : 'no';
                $settings_updated = true;
            }
            if ( $settings_updated ) {
                update_option( 'woocommerce_moyasar_settings', $v8_settings );
            }
            update_option( 'woocommerce_moyasar_v8_migrated', 'yes' );
            return;
        }

        $cc_opts      = get_option( 'woocommerce_moyasar_cc_settings', array() );
        $apple_opts   = get_option( 'woocommerce_moyasar_apple_pay_settings', array() );
        $stc_opts     = get_option( 'woocommerce_moyasar_stc_pay_settings', array() );
        $samsung_opts = get_option( 'woocommerce_moyasar_samsung_pay_settings', array() );
        $invoice_opts = get_option( 'woocommerce_moyasar_invoice_settings', array() );
        $online_opts  = get_option( 'woocommerce_moyasar_online_payment_settings', array() );

        if ( empty( $cc_opts ) && empty( $apple_opts ) && empty( $stc_opts ) && empty( $samsung_opts ) && empty( $invoice_opts ) && empty( $online_opts ) ) {
            update_option( 'woocommerce_moyasar_v8_migrated', 'yes' );
            return;
        }

        $new_settings = is_array( $v8_settings ) ? $v8_settings : array();

        $global_enabled = 'no';
        foreach ( array( $cc_opts, $apple_opts, $stc_opts, $samsung_opts, $invoice_opts, $online_opts ) as $opts ) {
            if ( is_array( $opts ) && isset( $opts['enabled'] ) && 'yes' === $opts['enabled'] ) {
                $global_enabled = 'yes';
                break;
            }
        }
        $new_settings['enabled'] = $global_enabled;

        $primary_opts = ! empty( $cc_opts ) ? $cc_opts : ( ! empty( $online_opts ) ? $online_opts : array() );

        $key_map = array(
            'testmode'                  => array( 'testmode', 'test_mode' ),
            'test_secret_key'           => array( 'test_secret_key', 'test_secret' ),
            'test_publishable_key'      => array( 'test_publishable_key', 'test_public_key', 'test_publishable', 'test_public' ),
            'live_secret_key'           => array( 'live_secret_key', 'live_secret' ),
            'live_publishable_key'      => array( 'live_publishable_key', 'live_public_key', 'live_publishable', 'live_public' ),
            'webhook_secret'            => array( 'webhook_secret', 'secret' ),
            'debug_mode'                => array( 'debug_mode', 'debug' ),
            'enable_instant_checkout'   => array( 'enable_instant_checkout', 'instant_checkout' ),
            'quick_buy_shipping_enabled'=> array( 'quick_buy_shipping_enabled' ),
            'quick_buy_shipping_option' => array( 'quick_buy_shipping_option' ),
            'allowed_brands'            => array( 'allowed_brands', 'brands' ),
            'allowed_countries'         => array( 'allowed_countries', 'countries' ),
            'order_status_success'      => array( 'order_status_success', 'payment_success_status' ),
            'order_status_failed'       => array( 'order_status_failed', 'payment_failed_status' ),
            'order_status_refunded'     => array( 'order_status_refunded', 'payment_refunded_status' ),
            'order_status_voided'       => array( 'order_status_voided', 'payment_voided_status' ),
        );

        foreach ( $key_map as $new_key => $old_keys ) {
            // Never overwrite a key that is already set in v8 settings
            if ( isset( $new_settings[ $new_key ] ) && ! empty( $new_settings[ $new_key ] ) ) {
                continue;
            }
            foreach ( $old_keys as $old_key ) {
                if ( isset( $primary_opts[ $old_key ] ) && ! empty( $primary_opts[ $old_key ] ) ) {
                    $new_settings[ $new_key ] = $primary_opts[ $old_key ];
                    break;
                }
                foreach ( array( $apple_opts, $stc_opts, $samsung_opts, $invoice_opts, $online_opts ) as $fallback_opts ) {
                    if ( isset( $fallback_opts[ $old_key ] ) && ! empty( $fallback_opts[ $old_key ] ) ) {
                        $new_settings[ $new_key ] = $fallback_opts[ $old_key ];
                        break 2;
                    }
                }
            }
        }

        $new_settings['enable_method_moyasar_cc']          = ( isset( $cc_opts['enabled'] ) && 'yes' === $cc_opts['enabled'] ) ? 'yes' : 'no';
        $new_settings['enable_method_moyasar_apple_pay']   = ( isset( $apple_opts['enabled'] ) && 'yes' === $apple_opts['enabled'] ) ? 'yes' : 'no';
        $new_settings['enable_method_moyasar_stc_pay']     = ( isset( $stc_opts['enabled'] ) && 'yes' === $stc_opts['enabled'] ) ? 'yes' : 'no';
        $new_settings['enable_method_moyasar_samsung_pay'] = ( isset( $samsung_opts['enabled'] ) && 'yes' === $samsung_opts['enabled'] ) ? 'yes' : 'no';

        $invoice_enabled = 'no';
        if ( isset( $invoice_opts['enabled'] ) && 'yes' === $invoice_opts['enabled'] ) {
            $invoice_enabled = 'yes';
        } elseif ( isset( $online_opts['enabled'] ) && 'yes' === $online_opts['enabled'] ) {
            $invoice_enabled = 'yes';
        }
        $new_settings['enable_method_moyasar_invoice'] = $invoice_enabled;

        update_option( 'woocommerce_moyasar_settings', $new_settings );
        update_option( 'woocommerce_moyasar_v8_migrated', 'yes' );
        update_option( 'woocommerce_moyasar_v8_show_notice', 'yes' );
    }
}
