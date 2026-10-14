<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * General Settings tab content for the Moyasar admin settings screen.
 *
 * Expects $gateway (WC_Gateway_Moyasar) and $form_fields (array) to be
 * available in scope from the including file.
 *
 * @since 8.2.2
 * @package    Moyasar
 * @subpackage Moyasar/includes/admin/views
 */
?>
<!-- Tab 1: Payment Methods & Express Checkout -->
<div id="moyasar_tab_general_settings" class="moyasar-tab-content" style="padding: 20px;">

    <!-- 1. Account Setup -->
    <div class="moyasar-split-layout">
        <div class="moyasar-layout-info">
            <h2><?php _e( 'Account Setup', 'moyasar-payments' ); ?></h2>
            <p>
                <?php _e( 'Manage your API keys for Test and Live modes.', 'moyasar-payments' ); ?>
                <br><br>
                <a href="https://dashboard.moyasar.com/" target="_blank" style="text-decoration: none;">
                    <?php _e( 'Go to Dashboard', 'moyasar-payments' ); ?> <span class="dashicons dashicons-external" style="font-size: 14px; vertical-align: middle;"></span>
                </a>
            </p>
        </div>
        <div class="moyasar-layout-card">
            <div style="padding: 20px;">
                <table class="form-table">
                    <?php
                        $account_keys = array( 'testmode', 'debug_mode', 'test_publishable_key', 'test_secret_key', 'live_publishable_key', 'live_secret_key' );
                        $account_fields = array();
                        foreach ( $account_keys as $key ) {
                            if ( isset( $form_fields[$key] ) ) $account_fields[$key] = $form_fields[$key];
                        }
                        $gateway->generate_settings_html( $account_fields );
                    ?>
                </table>

                <div class="moyasar-key-validator-section" style="margin-top: 20px; padding-top: 18px; border-top: 1px solid #f0f0f1;">
                    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                        <div>
                            <button type="button" class="button button-secondary" id="moyasar-validate-keys-btn">
                                <span class="dashicons dashicons-shield" style="font-size: 16px; vertical-align: text-bottom; margin-right: 4px;"></span>
                                <?php esc_html_e( 'Validate API Keys', 'moyasar-payments' ); ?>
                            </button>
                            <span class="spinner" id="moyasar-validate-spinner" style="float: none; margin: 0 8px;"></span>
                            <span id="moyasar-validate-summary" style="font-size: 13px; font-weight: 500;"></span>
                        </div>
                        <div style="font-size: 12px; color: #646970;">
                            <?php esc_html_e( 'Tests both Publishable and Secret keys against Moyasar API.', 'moyasar-payments' ); ?>
                        </div>
                    </div>
                    <div id="moyasar-key-validation-results" style="display: none; margin-top: 15px;"></div>
                </div>
            </div>
        </div>
    </div>

    <hr style="margin: 30px 0; border: 0; border-top: 1px solid #eee;">

    <div class="moyasar-split-layout">
        <!-- Sidebar Information -->
        <div class="moyasar-layout-info">
            <h2><?php _e( 'Payments accepted on checkout', 'moyasar-payments' ); ?></h2>
            <p><?php _e( 'Select payments available to customers at checkout. Based on their device type, location, and purchase history, your customers will only see the most relevant payment methods.', 'moyasar-payments' ); ?></p>
        </div>

        <!-- Card Container -->
        <div class="moyasar-layout-card">
            <div style="padding: 16px 20px; border-bottom: 1px solid #f0f0f1; display: flex; justify-content: space-between; align-items: center;">
                <h3 style="margin: 0; font-size: 15px; font-weight: 600;"><?php _e( 'Payment methods', 'moyasar-payments' ); ?></h3>
            </div>

            <!-- Section A: Methods List -->
            <table class="wc_gateways widefat" cellspacing="0" style="border: none; box-shadow: none;">
                <thead>
                    <tr style="border-bottom: 1px solid #f0f0f0; background: #fafafa;">
                        <th class="status" style="padding: 12px 20px; width: 60px;">Enabled</th> <!-- Checkbox column -->
                        <th class="name" style="padding: 12px 20px; text-align: left; width: 120px;"><?php _e( 'Method Details', 'moyasar-payments' ); ?></th>
                        <th class="description" style="padding: 12px 20px; text-align: left;"></th>
                        <th class="actions" style="padding: 12px 20px; text-align: right; width: 120px;"><?php _e( 'Actions', 'moyasar-payments' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $methods_list = array();
                $methods_list = Moyasar_Helper::get_payment_methods_list();

                foreach ( $methods_list as $mid => $m ) {
                    $key = 'enable_method_' . $mid;
                    $is_checked = 'yes' === $gateway->get_option( $key );

                    ?>
                    <tr style="border-bottom: 1px solid #f9f9f9;">
                        <td class="status" style="padding: 15px 0;">
                            <label class="switch">
                                <input type="checkbox" name="<?php echo esc_attr( $gateway->get_field_key( $key ) ); ?>" value="yes" <?php checked( $is_checked, true ); ?>>
                                <span class="slider round"></span>
                            </label>
                        </td>
                        <td class="logo" style="padding: 15px;">
                            <?php
                            $icons = array();
                            if ( ! empty( $m['icons'] ) ) {
                                $icons = $m['icons'];
                            } elseif ( ! empty( $m['icon'] ) ) {
                                $icons = array( $m['icon'] );
                            }

                            if ( ! empty( $icons ) ) {
                                // Check if it's CC to use Grid
                                $icon_container_class = ( 'moyasar_cc' === $mid ) ? 'moyasar-icons-grid' : 'moyasar-icons-row moyasar-icon-fixed';

                                echo '<div class="' . $icon_container_class . '">';
                                foreach ( $icons as $icon_key ) {
                                    $icon_url = Moyasar_Helper::get_card_icon_url( $icon_key );
                                    if ( $icon_url ) {
                                        echo '<img src="' . esc_url( $icon_url ) . '" alt="' . esc_attr( $icon_key ) . '">';
                                    }
                                }
                                echo '</div>';
                            }
                            ?>
                        </td>
                        <td class="details" style="padding: 15px;">
                            <div class="moyasar-method-label-row" style="display: flex; align-items: center; gap: 8px;">
                                <div class="moyasar-method-label"><?php echo esc_html( $m['label'] ); ?></div>
                                <?php
                                if ( ! empty( $m['features'] ) && in_array( 'subscriptions', $m['features'] ) ) {
                                    echo '<img class="woocommerce-list__item-recurring-payments-icon" src="' . esc_url( WC()->plugin_url() . '/assets/images/icons/recurring-payments.svg' ) . '" alt="' . esc_attr__( 'Supports recurring payments', 'moyasar-payments' ) . '" title="' . esc_attr__( 'Supports recurring payments', 'moyasar-payments' ) . '" style="width: 16px; height: 16px; margin-left: 5px;">';
                                }
                                ?>
                            </div>
                            <div class="moyasar-method-desc"><?php echo esc_html( $m['description'] ); ?></div>
                        </td>
                        <td class="actions" style="padding: 15px; text-align: right; vertical-align: middle;">
                            <button type="button" class="button moyasar-toggle-method-settings" data-method="<?php echo esc_attr( $mid ); ?>" style="display: inline-flex; align-items: center; gap: 4px;">
                                <span class="dashicons dashicons-admin-generic" style="font-size: 16px; width: 16px; height: 16px; line-height: 16px; margin: 0;"></span>
                                <?php _e( 'Customize', 'moyasar-payments' ); ?>
                            </button>
                        </td>
                    </tr>
                    <tr id="moyasar_method_settings_<?php echo esc_attr( $mid ); ?>" class="moyasar-method-settings-row" style="display: none; background: #fafafa; border-top: 1px solid #f0f0f1; border-bottom: 1px solid #f0f0f1;">
                        <td colspan="4" style="padding: 20px 40px; border-top: 1px solid #f0f0f1; border-bottom: 1px solid #f0f0f1;">
                            <h4 style="margin: 0 0 15px 0; font-size: 14px; font-weight: 600; color: #1d2327;">
                                <?php printf( esc_html__( '%s Customization', 'moyasar-payments' ), esc_html( $m['label'] ) ); ?>
                            </h4>
                            <table class="form-table" style="margin: 0; width: 100%;">
                                <?php
                                $method_fields = array();
                                $method_keys = array( $mid . '_title', $mid . '_description' );
                                if ( 'moyasar_cc' === $mid ) {
                                    $method_keys[] = 'cc_enable_tokenization';
                                } elseif ( 'moyasar_apple_pay' === $mid ) {
                                    $method_keys[] = 'apple_pay_checkout_btn_theme';
                                    $method_keys[] = 'apple_pay_checkout_btn_height';
                                    $method_keys[] = 'apple_pay_checkout_btn_label';
                                    $method_keys[] = 'apple_pay_border_radius';
                                } elseif ( 'moyasar_samsung_pay' === $mid ) {
                                    $method_keys[] = 'samsung_pay_checkout_btn_height';
                                    $method_keys[] = 'samsung_pay_checkout_btn_border_radius';
                                }
                                foreach ( $method_keys as $key ) {
                                    if ( isset( $form_fields[ $key ] ) ) {
                                        $method_fields[ $key ] = $form_fields[ $key ];
                                    }
                                }
                                $gateway->generate_settings_html( $method_fields );
                                ?>
                            </table>
                        </td>
                    </tr>
                    <?php
                }
                ?>
                </tbody>
            </table>
        </div> <!-- End Card -->
    </div> <!-- End Split Layout -->

    <hr style="margin: 30px 0; border: 0; border-top: 1px solid #eee;">

    <!-- 4. Apple Pay Setup -->
    <div class="moyasar-split-layout">
        <div class="moyasar-layout-info">
            <h2><?php _e( 'Apple Pay Web Registration', 'moyasar-payments' ); ?></h2>
            <p>
                <?php _e( 'Web Registration is a feature provided by Moyasar that allows merchants to enable Apple Pay on the web without the need for an Apple Developer account.', 'moyasar-payments' ); ?>
            </p>
            <p>
                <?php _e( 'You can upload the Verification File here.', 'moyasar-payments' ); ?>
                <br><br>
                <a href="https://docs.moyasar.com/guides/apple-pay/web-registration" target="_blank" style="text-decoration: none;">
                    <?php _e( 'View Documentation', 'moyasar-payments' ); ?> <span class="dashicons dashicons-external" style="font-size: 14px; vertical-align: middle;"></span>
                </a>
            </p>
        </div>
        <div class="moyasar-layout-card">
            <div style="padding: 20px;">

                <?php
                    $has_file        = Moyasar_Helper::has_apple_pay_domain_file();
                    $domain_file_url = Moyasar_Helper::get_canonical_apple_pay_domain_file_url();
                ?>

                <div style="margin-bottom: 20px;">
                    <strong><?php _e( 'Domain Verification File', 'moyasar-payments' ); ?></strong>
                    <p style="margin: 5px 0 10px; font-size: 13px; color: #666;"><?php _e( 'Upload the apple-developer-merchantid-domain-association file provided by Apple/Moyasar. It will be served automatically at your domain.', 'moyasar-payments' ); ?></p>

                    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                        <input type="file" name="moyasar_apple_pay_domain_file" id="moyasar_apple_pay_domain_file" style="flex-grow: 1;">
                        <?php if ( $has_file ) : ?>
                            <label style="display: inline-flex; align-items: center; gap: 5px; font-size: 13px; color: #d63638; white-space: nowrap;">
                                <input type="checkbox" name="moyasar_remove_apple_pay_domain_file" value="1">
                                <?php _e( 'Remove current file', 'moyasar-payments' ); ?>
                            </label>
                        <?php endif; ?>
                    </div>
                    <p style="margin: 8px 0 0; font-size: 12px; color: #787c82;"><?php _e( 'Select the file, then click "Save changes" below.', 'moyasar-payments' ); ?></p>
                </div>

                <div style="display: flex; align-items: center; justify-content: space-between; background: #fafafa; padding: 10px; border-radius: 4px;">
                    <div>
                        <strong><?php _e( 'Verification Status', 'moyasar-payments' ); ?></strong>
                    </div>
                    <div>
                        <?php if ( $has_file ) : ?>
                            <span class="moyasar-badge moyasar-badge-green">
                                <span class="dashicons dashicons-yes"></span>
                                <?php _e( 'File Uploaded', 'moyasar-payments' ); ?>
                            </span>
                        <?php else : ?>
                            <span class="moyasar-badge moyasar-badge-red">
                                <span class="dashicons dashicons-warning"></span>
                                <?php _e( 'File Not Uploaded', 'moyasar-payments' ); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ( $has_file ) : ?>
                    <p style="margin-top: 10px; font-size: 13px;">
                        <a href="<?php echo esc_url( $domain_file_url ); ?>" target="_blank"><?php _e( 'View Served File', 'moyasar-payments' ); ?></a>
                    </p>
                <?php endif; ?>

                <!-- Manual placement helper -->
                <div style="margin-top: 15px; padding: 12px 14px; background: #f0f6fc; border: 1px solid #c5d9ed; border-radius: 4px; font-size: 13px; color: #1d2327;">
                    <p style="margin: 0 0 6px;"><strong><?php _e( 'Having trouble uploading the file?', 'moyasar-payments' ); ?></strong></p>
                    <p style="margin: 0 0 8px;"><?php _e( 'If the file cannot be uploaded through this form, you can place it manually on your server so it is served at:', 'moyasar-payments' ); ?></p>
                    <code style="display: block; padding: 8px; background: #fff; border: 1px solid #dcdcde; border-radius: 3px; word-break: break-all;"><?php echo esc_html( $domain_file_url ); ?></code>
                    <p style="margin: 8px 0 0;">
                        <a href="https://docs.moyasar.com/guides/apple-pay/web-registration" target="_blank" style="text-decoration: none;">
                            <?php _e( 'Apple Pay domain verification instructions', 'moyasar-payments' ); ?> <span class="dashicons dashicons-external" style="font-size: 13px; vertical-align: middle;"></span>
                        </a>
                    </p>
                </div>

            </div>
        </div>
    </div>

    <hr style="margin: 30px 0; border: 0; border-top: 1px solid #eee;">

    <!-- 5. Samsung Pay -->
    <div class="moyasar-split-layout">
        <div class="moyasar-layout-info">
            <h2><?php _e( 'Samsung Pay Setup', 'moyasar-payments' ); ?></h2>
            <p>
                <?php _e( 'Configure Samsung Pay Integration. You need to setup your Service ID.', 'moyasar-payments' ); ?>
                <br><br>
                <a href="https://docs.moyasar.com/guides/samsung-pay/samsung-pay-account" target="_blank" style="text-decoration: none;">
                    <?php _e( 'View Documentation', 'moyasar-payments' ); ?> <span class="dashicons dashicons-external" style="font-size: 14px; vertical-align: middle;"></span>
                </a>
            </p>
        </div>
        <div class="moyasar-layout-card">
            <div style="padding: 20px;">
                <table class="form-table">
                    <?php
                        $samsung_pay_fields = array(
                            'samsung_pay_service_id' => $form_fields['samsung_pay_service_id'],
                        );
                        $gateway->generate_settings_html( $samsung_pay_fields );
                    ?>
                </table>
            </div>
        </div>
    </div>

</div> <!-- End Tab 1 (General) -->
