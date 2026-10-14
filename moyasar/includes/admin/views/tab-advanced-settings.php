<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Advanced Settings tab content for the Moyasar admin settings screen.
 *
 * Expects $gateway (WC_Gateway_Moyasar) and $form_fields (array) to be
 * available in scope from the including file.
 *
 * @since 8.2.2
 * @package    Moyasar
 * @subpackage Moyasar/includes/admin/views
 */
?>
<!-- Tab 2: Settings (Global) -->
<div id="moyasar_tab_advanced_settings" class="moyasar-tab-content" style="display:none;">

    <!-- 2. Webhook Integration -->
    <div class="moyasar-split-layout">
        <div class="moyasar-layout-info">
            <h2><?php _e( 'Webhook Integration', 'moyasar-payments' ); ?></h2>
            <p>
                <strong><?php _e( 'Important:', 'moyasar-payments' ); ?></strong>
                <?php _e( 'In case of user connectivity issues, a webhook will be sent to your store to update the transaction status.', 'moyasar-payments' ); ?>
            </p>
            <p>
                <?php _e( 'Please check the documentation to configure the URL and Secret correctly. Keep the rest of the fields as is.', 'moyasar-payments' ); ?>
                <br><br>
                <a href="https://docs.moyasar.com/guides/dashboard/setting-up-webhooks?_highlight=webhook" target="_blank" style="text-decoration: none;">
                    <?php _e( 'View Documentation', 'moyasar-payments' ); ?> <span class="dashicons dashicons-external" style="font-size: 14px; vertical-align: middle;"></span>
                </a>
            </p>
        </div>
        <div class="moyasar-layout-card">
            <div style="padding: 20px;">
                <p style="margin-top: 0;"><strong><?php _e( 'Webhook URL:', 'moyasar-payments' ); ?></strong></p>
                <div style="display: flex; gap: 10px; margin-bottom: 20px;">
                    <input type="text" value="<?php echo esc_url( Moyasar_Helper::get_canonical_webhook_url() ); ?>" readonly style="width: 100%; background: #f9f9f9;" id="moyasar_webhook_url">
                    <button type="button" class="button" onclick="moyasarCopyInput('moyasar_webhook_url')"><?php _e( 'Copy', 'moyasar-payments' ); ?></button>
                </div>

                <p><strong><?php _e( 'Webhook Secret:', 'moyasar-payments' ); ?></strong></p>
                <div style="display: flex; gap: 10px; align-items: left; width: 100%;">
                    <?php
                        // Render secret field manually to add buttons wrapper
                        $moyasar_settings = get_option( 'woocommerce_moyasar_settings', array() );
                        $secret_val      = isset( $moyasar_settings['webhook_secret'] ) ? $moyasar_settings['webhook_secret'] : '';
                        $samsung_pay_fields = array(
                            'webhook_secret' => $form_fields['webhook_secret'],
                        );
                        $gateway->generate_settings_html( $samsung_pay_fields );
                    ?>
                    <button type="button" class="button" onclick="moyasarToggleSecret('<?php echo esc_attr( 'woocommerce_moyasar_webhook_secret' ); ?>')"><?php _e( 'Show', 'moyasar-payments' ); ?></button>
                    <button type="button" class="button" onclick="moyasarCopyInput('<?php echo esc_attr( 'woocommerce_moyasar_webhook_secret' ); ?>')"><?php _e( 'Copy', 'moyasar-payments' ); ?></button>
                </div>
                <p class="description"><?php _e( 'This is your Webhook Secret. Usage: Enter this in your Moyasar Dashboard > Webhooks settings.', 'moyasar-payments' ); ?></p>
            </div>
        </div>
    </div>

    <hr style="margin: 30px 0; border: 0; border-top: 1px solid #eee;">

    <!-- 3. Card Settings -->
    <div class="moyasar-split-layout">
        <div class="moyasar-layout-info">
            <h2><?php _e( 'Card Settings', 'moyasar-payments' ); ?></h2>
            <p><?php _e( 'Configure allowed card brands and countries.', 'moyasar-payments' ); ?></p>
        </div>
        <div class="moyasar-layout-card">
            <div style="padding: 20px;">
                <table class="form-table">
                    <?php
                        $card_keys = array( 'allowed_brands', 'allowed_countries' );
                        $card_fields = array();
                        foreach ( $card_keys as $key ) {
                            if ( isset( $form_fields[$key] ) ) $card_fields[$key] = $form_fields[$key];
                        }
                        $gateway->generate_settings_html( $card_fields );
                    ?>
                </table>
            </div>
        </div>
    </div>

    <hr style="margin: 30px 0; border: 0; border-top: 1px solid #eee;">

    <!-- 4. Payment Authorization & Capture -->
    <div class="moyasar-split-layout">
        <div class="moyasar-layout-info">
            <h2><?php _e( 'Payment Authorization & Capture', 'moyasar-payments' ); ?></h2>
            <p><?php _e( 'Choose whether payments are captured immediately or authorized for manual capture later.', 'moyasar-payments' ); ?></p>
        </div>
        <div class="moyasar-layout-card">
            <div style="padding: 20px;">
                <table class="form-table">
                    <?php
                        $manual_keys = array( 'manual' );
                        $manual_fields = array();
                        foreach ( $manual_keys as $key ) {
                            if ( isset( $form_fields[$key] ) ) $manual_fields[$key] = $form_fields[$key];
                        }
                        $gateway->generate_settings_html( $manual_fields );
                    ?>
                </table>
            </div>
        </div>
    </div>

    <hr style="margin: 30px 0; border: 0; border-top: 1px solid #eee;">

    <!-- 6. Order Status Configuration -->
    <div class="moyasar-split-layout">
        <div class="moyasar-layout-info">
            <h2><?php _e( 'Order Status Configuration', 'moyasar-payments' ); ?></h2>
            <p><?php _e( 'Configure the order status after specific payment events.', 'moyasar-payments' ); ?></p>
        </div>
        <div class="moyasar-layout-card">
            <div style="padding: 20px;">
                <table class="form-table">
                    <?php
                        $status_keys = array( 'order_status_success', 'order_status_authorized', 'order_status_failed', 'order_status_refunded', 'order_status_voided' );
                        $status_fields = array();
                        foreach ( $status_keys as $key ) {
                            if ( isset( $form_fields[$key] ) ) $status_fields[$key] = $form_fields[$key];
                        }
                        $gateway->generate_settings_html( $status_fields );
                    ?>
                </table>
            </div>
        </div>
    </div>

    <hr style="margin: 30px 0; border: 0; border-top: 1px solid #eee;">

    <!-- 7. More Settings -->
    <div class="moyasar-split-layout">
        <div class="moyasar-layout-info">
            <h2><?php _e( 'More Settings', 'moyasar-payments' ); ?></h2>
        </div>
        <div class="moyasar-layout-card">
            <div style="padding: 20px;">
                <table class="form-table">
                    <?php
                        $more_settings_keys = array( 'gateway_icon_height' );
                        $more_settings_fields = array();
                        foreach ( $more_settings_keys as $key ) {
                            if ( isset( $form_fields[$key] ) ) $more_settings_fields[$key] = $form_fields[$key];
                        }
                        $gateway->generate_settings_html( $more_settings_fields );
                    ?>
                </table>
            </div>
        </div>
    </div>

    <hr style="margin: 30px 0; border: 0; border-top: 1px solid #eee;">

    <!-- 8. Instant Checkout (Apple Pay & Quick Buy) -->
    <div class="moyasar-split-layout">
        <!-- Sidebar Information -->
        <div class="moyasar-layout-info">
            <h2><?php _e( 'Instant Checkout', 'moyasar-payments' ); ?></h2>
            <p><?php _e( 'Enable and customize Apple Pay and Quick Buy buttons on product pages.', 'moyasar-payments' ); ?></p>
        </div>

        <!-- Card Container: Apple Pay -->
        <div class="moyasar-layout-card" style="margin-bottom: 20px;">

            <!-- Apple Pay Section -->
            <div style="padding: 20px;">
                <div style="display: flex; align-items: center; margin-bottom: 15px;">
                    <img src="<?php echo esc_url( Moyasar_Helper::get_card_icon_url( 'apple_pay' ) ); ?>" style="margin-right: 10px;">
                    <h3 style="margin: 0; font-size: 15px; font-weight: 600;"><?php _e( 'Apple Pay Settings', 'moyasar-payments' ); ?></h3>
                </div>
                <table class="form-table">
                    <?php
                    $apple_keys = array( 'enable_apple_pay_product_page', 'express_btn_theme', 'express_btn_height', 'express_btn_label' );
                    $apple_fields = array();
                    foreach( $apple_keys as $key ) {
                        if ( isset( $form_fields[$key] ) ) $apple_fields[$key] = $form_fields[$key];
                    }
                    $gateway->generate_settings_html( $apple_fields );
                    ?>
                </table>
            </div>

            <!-- Preview Section inside Card (Apple Pay) -->
            <div style="padding: 20px; background: #fafafa; border-top: 1px solid #f0f0f1;">
                <h4 style="margin: 0 0 15px 0; font-size: 13px; text-transform: uppercase; color: #646970;"><?php _e( 'Button Preview', 'moyasar-payments' ); ?></h4>
                <div class="moyasar-btn-preview" style="padding: 30px; background: #fff; border: 1px solid #e5e5e5; text-align: center; border-radius: 4px;">

                    <!-- Official Apple Pay Button Element -->
                    <script src="https://applepay.cdn-apple.com/jsapi/v1/apple-pay-sdk.js"></script>
                    <style>
                        apple-pay-button {
                            display: inline-block;
                            -webkit-appearance: -apple-pay-button;
                        }
                    </style>
                    <apple-pay-button id="moyasar_apple_pay_preview_el" buttonstyle="black" type="buy" locale="<?php echo substr( get_locale(), 0, 2 ); ?>"></apple-pay-button>

                    <p class="description" style="margin-top: 15px; color: #777; font-style: italic;"><?php _e( 'This is a live preview of using the official Apple Pay element.', 'moyasar-payments' ); ?></p>
                </div>
            </div>

            <!-- Internal JS for Preview Logic -->
            <script type="text/javascript">
            jQuery(document).ready(function($) {
                function updateApplePayPreview() {
                    var theme = $('#woocommerce_moyasar_express_btn_theme').val() || 'dark';
                    var height = $('#woocommerce_moyasar_express_btn_height').val() || 'medium';
                    var label = $('#woocommerce_moyasar_express_btn_label').val() || 'buy';
                    var radius = $('#woocommerce_moyasar_apple_pay_border_radius').val() || '5';

                    var btn = document.querySelector('apple-pay-button');
                    if (!btn) return;

                    // Map Theme
                    var style = 'black';
                    if ( theme === 'light' ) style = 'white';
                    if ( theme === 'light-outline' ) style = 'white-outline';

                    btn.setAttribute('buttonstyle', style);
                    btn.style.setProperty('-apple-pay-button-style', style);

                    // Map Label to Type
                    var type = label;
                    if ( type === 'check-out' ) type = 'checkout';

                    btn.setAttribute('type', type);
                    btn.style.setProperty('-apple-pay-button-type', type);

                    // Height
                    var heightPx = '44px'; // medium
                    if ( height === 'small' ) heightPx = '32px';
                    if ( height === 'large' ) heightPx = '55px'; // Apple recommends ~44-60

                    // Set both inline height and CSS variable
                    btn.style.height = heightPx;
                    btn.style.setProperty('--apple-pay-button-height', heightPx);

                    // Extra styles for cleaner look
                    btn.style.minWidth = '150px';
                    btn.style.borderRadius = radius + 'px';
                    btn.style.setProperty('--apple-pay-button-border-radius', radius + 'px');
                }

                // Listen for changes
                $('#woocommerce_moyasar_express_btn_theme, #woocommerce_moyasar_express_btn_height, #woocommerce_moyasar_express_btn_label, #woocommerce_moyasar_apple_pay_border_radius').on('change keyup', updateApplePayPreview);

                // Initial call
                updateApplePayPreview();
            });
            </script>
        </div>
    </div>

    <hr style="margin: 30px 0; border: 0; border-top: 1px solid #eee;">

    <!-- Quick Buy Separate Section -->
    <div class="moyasar-split-layout">
        <!-- Sidebar Information -->
        <div class="moyasar-layout-info">
            <h2>
                <?php _e( 'Quick Buy', 'moyasar-payments' ); ?>
                <span style="background-color: #ffba00; color: #fff; padding: 2px 6px; border-radius: 4px; font-size: 11px; vertical-align: middle; text-transform: uppercase;">Beta</span>
            </h2>
            <p>
                <?php _e( 'Enable and customize the Quick Buy button. This allows users to pay directly from the product page.', 'moyasar-payments' ); ?>
                <br><br>
                <strong><?php _e( 'Note:', 'moyasar-payments' ); ?></strong>
                <?php _e( 'This button will ONLY be visible to logged-in users who have a saved credit card and a saved billing address.', 'moyasar-payments' ); ?>
            </p>
        </div>

        <!-- Card Container: Quick Buy -->
        <div class="moyasar-layout-card">
            <div style="padding: 20px;">
                <h3 style="margin: 0 0 15px 0; font-size: 15px; font-weight: 600;"><?php _e( 'Quick Buy Settings', 'moyasar-payments' ); ?></h3>
                <table class="form-table">
                    <?php
                    $quick_keys = array( 'enable_instant_checkout', 'quick_buy_shipping_enabled', 'quick_buy_shipping_option' );
                    $quick_fields = array();
                    foreach( $quick_keys as $key ) {
                        if ( isset( $form_fields[$key] ) ) $quick_fields[$key] = $form_fields[$key];
                    }
                    $gateway->generate_settings_html( $quick_fields );
                    ?>
                </table>
            </div>
        </div>
    </div>

</div>
