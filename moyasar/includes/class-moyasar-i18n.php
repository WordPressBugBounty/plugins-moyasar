<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Define the internationalization functionality.
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
 *
 * @since      8.0.0
 * @package    Moyasar
 * @subpackage Moyasar/includes
 */
class Moyasar_i18n {

	/**
	 * Load the plugin text domain for translation.
	 *
	 * @since    8.0.0
	 */
	public function load_plugin_textdomain() {

		add_filter( 'plugin_locale', array( $this, 'override_plugin_locale' ), 10, 2 );

		load_plugin_textdomain(
			'moyasar-payments',
			false,
			dirname( dirname( plugin_basename( __FILE__ ) ) ) . '/i18n/languages/'
		);
	}

	/**
	 * Override plugin locale to handle locale fallback.
	 *
	 * Ensures that unsupported locales fall back to English (en) or Arabic (ar) appropriately.
	 *
	 * @since    8.0.7
	 */
	public function override_plugin_locale( $locale, $domain ) {
		if ( 'moyasar-payments' !== $domain ) {
			return $locale;
		}

		$available_locales = array( 'en', 'en_US', 'ar', 'ar_SA' );

		if ( ! in_array( $locale, $available_locales, true ) ) {
			if ( strpos( $locale, 'ar' ) === 0 ) {
				return 'ar';
			}
			return 'en';
		}

		return $locale;
	}

}
