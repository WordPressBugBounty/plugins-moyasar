<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Dashboard tab content for the Moyasar admin settings screen.
 *
 * Expects $gateway (WC_Gateway_Moyasar) and $form_fields (array) to be
 * available in scope from the including file.
 *
 * @since 8.2.2
 * @package    Moyasar
 * @subpackage Moyasar/includes/admin/views
 */
?>
<!-- Tab 2: Dashboard -->
<div id="moyasar_tab_dashboard" class="moyasar-tab-content" style="display: none;">

    <div style="background-color: #d63638; color: white; display: inline-block; padding: 4px 8px; border-radius: 4px; font-weight: bold; font-size: 12px; margin-bottom: 10px;">
        <?php _e('BETA', 'moyasar-payments'); ?>
    </div>

    <?php
    global $wpdb;



    $total_orders = 0;
    $total_revenue = 0;
    $instant_count = 0;
    $method_stats = array();

    // Check for HPOS
    $hpos_enabled = false;
    if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
        $hpos_enabled = true;
    }

    if ( $hpos_enabled ) {
         $orders_table = $wpdb->prefix . 'wc_orders';
         $meta_table   = $wpdb->prefix . 'wc_orders_meta';

         // 1. Total Stats
         // Column names in HPOS: status (e.g. wc-completed), payment_method, total_amount
         $query_total = "
            SELECT COUNT(id) as count, SUM(total_amount) as revenue
            FROM {$orders_table}
            WHERE type = 'shop_order'
            AND status IN ('wc-processing', 'wc-completed', 'wc-on-hold')
            AND payment_method LIKE 'moyasar_%'
         ";
         $total_stats = $wpdb->get_row( $query_total );
         $total_orders = $total_stats->count ?? 0;
         $total_revenue = $total_stats->revenue ?? 0;

         // 2. Instant Checkout (Meta Query)
         $query_instant = "
            SELECT COUNT(o.id)
            FROM {$orders_table} o
            JOIN {$meta_table} om ON o.id = om.order_id
            WHERE o.type = 'shop_order'
            AND o.status IN ('wc-processing', 'wc-completed', 'wc-on-hold')
            AND om.meta_key = '_moyasar_instant_checkout'
            AND om.meta_value = '1'
         ";
         $instant_count = $wpdb->get_var( $query_instant );

        // 3. Methods Breakdown
        $query_methods = "
            SELECT payment_method as method, COUNT(id) as count
            FROM {$orders_table}
            WHERE type = 'shop_order'
            AND status IN ('wc-processing', 'wc-completed', 'wc-on-hold')
            AND payment_method LIKE 'moyasar_%'
            GROUP BY payment_method
            ORDER BY count DESC
        ";
        $method_stats = $wpdb->get_results( $query_methods );

    } else {
        // Legacy Query (wp_posts)

        // 1. Total Stats
        $query_total = "
            SELECT COUNT(p.ID) as count, SUM(pm_total.meta_value) as revenue
            FROM {$wpdb->posts} p
            JOIN {$wpdb->postmeta} pm_method ON p.ID = pm_method.post_id
            JOIN {$wpdb->postmeta} pm_total ON p.ID = pm_total.post_id
            WHERE p.post_type = 'shop_order'
            AND p.post_status IN ('wc-processing', 'wc-completed', 'wc-on-hold')
            AND pm_method.meta_key = '_payment_method'
            AND pm_method.meta_value LIKE 'moyasar_%'
            AND pm_total.meta_key = '_order_total'
        ";
        $total_stats = $wpdb->get_row( $query_total );
        $total_orders = $total_stats->count ?? 0;
        $total_revenue = $total_stats->revenue ?? 0;

        // 2. Instant Checkout Count
        $query_instant = "
            SELECT COUNT(p.ID)
            FROM {$wpdb->posts} p
            JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = 'shop_order'
            AND p.post_status IN ('wc-processing', 'wc-completed', 'wc-on-hold')
            AND pm.meta_key = '_moyasar_instant_checkout'
            AND pm.meta_value = '1'
        ";
        $instant_count = $wpdb->get_var( $query_instant );

        // 3. Methods Breakdown
        $query_methods = "
            SELECT pm.meta_value as method, COUNT(p.ID) as count
            FROM {$wpdb->posts} p
            JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = 'shop_order'
            AND p.post_status IN ('wc-processing', 'wc-completed', 'wc-on-hold')
            AND pm.meta_key = '_payment_method'
            AND pm.meta_value LIKE 'moyasar_%'
            GROUP BY pm.meta_value
            ORDER BY count DESC
        ";
        $method_stats = $wpdb->get_results( $query_methods );
    }

    // Saved Cards Count (Always in same table)
    $query_cards = "SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_payment_tokens WHERE gateway_id = 'moyasar_cc'";
    $saved_cards = $wpdb->get_var( $query_cards );

    $labels = array(
        'moyasar_cc' => __('Credit Card', 'moyasar-payments'),
        'moyasar_apple_pay' => __('Apple Pay', 'moyasar-payments'),
        'moyasar_stc_pay' => __('STC Pay', 'moyasar-payments'),
        'moyasar_samsung_pay' => __('Samsung Pay', 'moyasar-payments'),
        'moyasar_invoice' => __('Invoice', 'moyasar-payments'),
    );
    ?>

    <div class="moyasar-dashboard-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-top: 20px;">

        <!-- Card 1: Total Orders -->
        <div class="moyasar-stat-card" style="background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #e5e5e5; display: flex; flex-direction: column;">
            <span class="dashicons dashicons-cart" style="font-size: 24px; color: #646970; margin-bottom: 10px; width: 24px; height: 24px;"></span>
            <h3 style="margin: 0; font-size: 28px; font-weight: 600;"><?php echo number_format_i18n( $total_orders ); ?></h3>
            <span style="color: #646970; font-size: 13px;"><?php _e( 'Total Moyasar Orders', 'moyasar-payments' ); ?></span>
            <div style="margin-top: 5px; font-size: 12px; color: #2271b1; font-weight: 600;">
                <?php echo wc_price( $total_revenue ); ?> <?php _e( 'Revenue', 'moyasar-payments' ); ?>
            </div>
        </div>

        <!-- Card 2: Saved Cards -->
        <div class="moyasar-stat-card" style="background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #e5e5e5; display: flex; flex-direction: column;">
            <span class="dashicons dashicons-id" style="font-size: 24px; color: #646970; margin-bottom: 10px; width: 24px; height: 24px;"></span>
            <h3 style="margin: 0; font-size: 28px; font-weight: 600;"><?php echo number_format_i18n( $saved_cards ); ?></h3>
            <span style="color: #646970; font-size: 13px;"><?php _e( 'Saved Cards', 'moyasar-payments' ); ?></span>
        </div>

        <!-- Card 3: Instant Checkout -->
        <div class="moyasar-stat-card" style="background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #e5e5e5; display: flex; flex-direction: column;">
             <span class="dashicons dashicons-lightning" style="font-size: 24px; color: #646970; margin-bottom: 10px; width: 24px; height: 24px;"></span>
            <h3 style="margin: 0; font-size: 28px; font-weight: 600;"><?php echo number_format_i18n( $instant_count ); ?></h3>
            <span style="color: #646970; font-size: 13px;"><?php _e( 'Instant Checkouts', 'moyasar-payments' ); ?></span>
        </div>
    </div>

    <div style="margin-top: 30px; background: #fff; border: 1px solid #e5e5e5; border-radius: 8px; overflow: hidden;">
        <div style="padding: 15px 20px; border-bottom: 1px solid #f0f0f1; background: #fafafa;">
            <h3 style="margin: 0; font-size: 14px; font-weight: 600;"><?php _e( 'Payment Methods Usage', 'moyasar-payments' ); ?></h3>
        </div>
        <table class="widefat striped" style="border: none; box-shadow: none;">
            <thead>
                <tr>
                    <th style="padding: 10px 20px;"><?php _e( 'Method', 'moyasar-payments' ); ?></th>
                    <th style="padding: 10px 20px; text-align: right;"><?php _e( 'Orders', 'moyasar-payments' ); ?></th>
                    <th style="padding: 10px 20px; text-align: right;"><?php _e( 'Percentage', 'moyasar-payments' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( ! empty( $method_stats ) ) : ?>
                    <?php foreach ( $method_stats as $stat ) :
                        $percentage = $total_orders > 0 ? round( ( $stat->count / $total_orders ) * 100, 1 ) : 0;
                        $label = isset( $labels[ $stat->method ] ) ? $labels[ $stat->method ] : $stat->method;
                    ?>
                    <tr>
                        <td style="padding: 10px 20px; font-weight: 500;">
                            <?php
                            // Icon
                            $icon = '';
                            if ( 'moyasar_cc' === $stat->method ) $icon = 'dashicons-credit-card';
                            elseif ( 'moyasar_apple_pay' === $stat->method ) $icon = 'dashicons-smartphone';
                            else $icon = 'dashicons-money';
                            ?>
                            <span class="dashicons <?php echo $icon; ?>" style="color: #aaa; margin-right: 5px; font-size: 16px; height: 16px; width: 16px; vertical-align: text-bottom;"></span>
                            <?php echo esc_html( $label ); ?>
                        </td>
                        <td style="padding: 10px 20px; text-align: right;"><?php echo number_format_i18n( $stat->count ); ?></td>
                        <td style="padding: 10px 20px; text-align: right;">
                            <div style="display: flex; align-items: center; justify-content: flex-end; gap: 10px;">
                                <span style="color: #646970; font-size: 12px;"><?php echo $percentage; ?>%</span>
                                <div style="width: 50px; height: 6px; background: #f0f0f1; border-radius: 3px; overflow: hidden;">
                                    <div style="width: <?php echo $percentage; ?>%; height: 100%; background: #2271b1;"></div>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr>
                        <td colspan="3" style="padding: 20px; text-align: center; color: #646970;">
                            <?php _e( 'No orders found yet.', 'moyasar-payments' ); ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php
        $moyasar_dashboard_test_mode = 'yes' === Moyasar_Helper::get_moyasar_option( 'testmode' );
        $moyasar_dashboard_api_key   = Moyasar_Helper::get_api_secret_key();

        $moyasar_payments_list        = array();
        $moyasar_payments_auth_error  = false;
        $moyasar_payments_error       = '';

        if ( '' === trim( (string) $moyasar_dashboard_api_key ) ) {
            $moyasar_payments_error = __( 'No API key is configured for the currently active environment.', 'moyasar-payments' );
        } else {
            $moyasar_payments_response = Moyasar_API::list_payments( array(), $moyasar_dashboard_api_key );

            if ( is_wp_error( $moyasar_payments_response ) ) {
                $moyasar_payments_error_data = $moyasar_payments_response->get_error_data();
                if ( is_array( $moyasar_payments_error_data ) && isset( $moyasar_payments_error_data['type'] ) && 'authentication_error' === $moyasar_payments_error_data['type'] ) {
                    $moyasar_payments_auth_error = true;
                } else {
                    $moyasar_payments_error = $moyasar_payments_response->get_error_message();
                }
            } elseif ( isset( $moyasar_payments_response['payments'] ) && is_array( $moyasar_payments_response['payments'] ) ) {
                $moyasar_payments_list = $moyasar_payments_response['payments'];
            }
        }
    ?>

    <div style="margin-top: 30px; background: #fff; border: 1px solid #e5e5e5; border-radius: 8px; overflow: hidden;">
        <div style="padding: 15px 20px; border-bottom: 1px solid #f0f0f1; background: #fafafa; display: flex; align-items: center; justify-content: space-between;">
            <h3 style="margin: 0; font-size: 14px; font-weight: 600;"><?php _e( 'Recent Payments', 'moyasar-payments' ); ?></h3>
            <span style="background-color: <?php echo $moyasar_dashboard_test_mode ? '#dba617' : '#00a32a'; ?>; color: #fff; font-size: 10px; font-weight: 700; padding: 3px 9px; border-radius: 10px; text-transform: uppercase; letter-spacing: 0.3px;">
                <?php echo $moyasar_dashboard_test_mode ? esc_html__( 'Test Mode', 'moyasar-payments' ) : esc_html__( 'Live Mode', 'moyasar-payments' ); ?>
            </span>
        </div>

        <?php if ( $moyasar_payments_auth_error ) : ?>
            <div class="notice notice-error inline" style="margin: 15px 20px; padding: 10px 12px;">
                <p style="margin: 0;">
                    <span class="dashicons dashicons-warning" style="color: #d63638; vertical-align: middle; margin-right: 4px;"></span>
                    <?php
                        printf(
                            /* translators: %s: link to the Moyasar dashboard */
                            wp_kses_post( __( 'Invalid API credentials. Please double-check your Secret Key against the value shown in your %s.', 'moyasar-payments' ) ),
                            '<a href="https://dashboard.moyasar.com/" target="_blank">' . esc_html__( 'Moyasar Dashboard', 'moyasar-payments' ) . '</a>'
                        );
                    ?>
                </p>
            </div>
        <?php elseif ( '' !== $moyasar_payments_error ) : ?>
            <div class="notice notice-error inline" style="margin: 15px 20px; padding: 10px 12px;">
                <p style="margin: 0;">
                    <span class="dashicons dashicons-warning" style="color: #d63638; vertical-align: middle; margin-right: 4px;"></span>
                    <?php echo esc_html( $moyasar_payments_error ); ?>
                </p>
            </div>
        <?php else : ?>
            <table class="widefat striped" style="border: none; box-shadow: none;">
                <thead>
                    <tr>
                        <th style="padding: 10px 20px;"><?php _e( 'Status', 'moyasar-payments' ); ?></th>
                        <th style="padding: 10px 20px; text-align: left;"><?php _e( 'Description', 'moyasar-payments' ); ?></th>
                        <th style="padding: 10px 20px; text-align: right;"><?php _e( 'Amount', 'moyasar-payments' ); ?></th>
                        <th style="padding: 10px 20px;"><?php _e( 'Payment Method', 'moyasar-payments' ); ?></th>
                        <th style="padding: 10px 20px;"><?php _e( 'Created At', 'moyasar-payments' ); ?></th>
                        <th style="padding: 10px 20px;"><?php _e( 'Updated At', 'moyasar-payments' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( ! empty( $moyasar_payments_list ) ) : ?>
                        <?php
                        $moyasar_status_colors = array(
                            'paid'       => array( '#f6ffed', '#135200', '#b7eb8f' ),
                            'captured'   => array( '#f6ffed', '#135200', '#b7eb8f' ),
                            'authorized' => array( '#f0f6fc', '#0a4b78', '#c5d9ed' ),
                            'initiated'  => array( '#f0f6fc', '#0a4b78', '#c5d9ed' ),
                            'failed'     => array( '#fef0f0', '#8a1f11', '#f5c6c6' ),
                            'refunded'   => array( '#fff7e6', '#8a5b00', '#ffe1a8' ),
                            'voided'     => array( '#f0f0f1', '#646970', '#dcdcde' ),
                        );
                        ?>
                        <?php foreach ( $moyasar_payments_list as $moyasar_payment ) :
                            $moyasar_status       = isset( $moyasar_payment['status'] ) ? $moyasar_payment['status'] : '';
                            $moyasar_status_color = isset( $moyasar_status_colors[ $moyasar_status ] ) ? $moyasar_status_colors[ $moyasar_status ] : array( '#f0f0f1', '#646970', '#dcdcde' );

                            $moyasar_created_display = '—';
                            if ( ! empty( $moyasar_payment['created_at'] ) ) {
                                $moyasar_created_gmt    = gmdate( 'Y-m-d H:i:s', strtotime( $moyasar_payment['created_at'] ) );
                                $moyasar_created_display = get_date_from_gmt( $moyasar_created_gmt, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
                            }

                            $moyasar_updated_display = '—';
                            if ( ! empty( $moyasar_payment['updated_at'] ) ) {
                                $moyasar_updated_gmt     = gmdate( 'Y-m-d H:i:s', strtotime( $moyasar_payment['updated_at'] ) );
                                $moyasar_updated_display = get_date_from_gmt( $moyasar_updated_gmt, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
                            }
                        ?>
                        <tr>
                            <td style="padding: 10px 20px;">
                                <span style="display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; text-transform: capitalize; background-color: <?php echo esc_attr( $moyasar_status_color[0] ); ?>; color: <?php echo esc_attr( $moyasar_status_color[1] ); ?>; border: 1px solid <?php echo esc_attr( $moyasar_status_color[2] ); ?>;">
                                    <?php echo esc_html( $moyasar_status ); ?>
                                </span>
                            </td>
                            <td style="padding: 10px 20px;"><?php echo esc_html( ! empty( $moyasar_payment['description'] ) ? $moyasar_payment['description'] : '—' ); ?></td>
                            <td style="padding: 10px 20px; text-align: right;"><?php echo esc_html( isset( $moyasar_payment['amount_format'] ) ? $moyasar_payment['amount_format'] : '—' ); ?></td>
                            <td style="padding: 10px 20px;"><?php echo esc_html( isset( $moyasar_payment['source']['type'] ) ? $moyasar_payment['source']['type'] : '—' ); ?></td>
                            <td style="padding: 10px 20px; white-space: nowrap;"><?php echo esc_html( $moyasar_created_display ); ?></td>
                            <td style="padding: 10px 20px; white-space: nowrap;"><?php echo esc_html( $moyasar_updated_display ); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="6" style="padding: 20px; text-align: center; color: #646970;">
                                <?php _e( 'No payments found yet.', 'moyasar-payments' ); ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
