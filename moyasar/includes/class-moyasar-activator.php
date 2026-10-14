<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Fired during plugin activation
 *
 * @since      8.0.0
 * @package    Moyasar
 * @subpackage Moyasar/includes
 */
class Moyasar_Activator {

	/**
	 * Short Description. (use period)
	 *
	 * Long Description.
	 *
	 * @since    8.0.0
	 */
	public static function activate() {
        // Create custom database tables if needed.
        // Schedule CRON jobs if needed.
        // Flush rewrite rules if custom post types or endpoints are registered.
        flush_rewrite_rules();
        
        // Trigger settings migration if upgrading
        if ( class_exists( 'Moyasar_Run' ) ) {
            Moyasar_Run::migrate_settings_v7_to_v8();
        }
	}

}
