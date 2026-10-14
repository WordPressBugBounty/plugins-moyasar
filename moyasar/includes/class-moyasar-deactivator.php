<?php	

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Fired during plugin deactivation
 *
 * @since      8.0.0
 * @package    Moyasar
 * @subpackage Moyasar/includes
 */
class Moyasar_Deactivator {

	/**
	 * Short Description. (use period)
	 *
	 * Long Description.
	 *
	 * @since    8.0.0
	 */
	public static function deactivate() {
        // Unschedule CRON jobs.
        // Flush rewrite rules.
        flush_rewrite_rules();
	}

}
