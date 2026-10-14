<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The API class.
 *
 * @since      8.0.0
 * @package    Moyasar
 * @subpackage Moyasar/includes
 */
class Moyasar_API {

    const BASE_URL = 'https://api.moyasar.com/';
    const VERSION  = 'v1';

    public static function get_base_url() {
        return self::BASE_URL . self::VERSION . '/';
    }

    /**
     * Send a request to Moyasar API.
     *
     * @param string $endpoint The API endpoint.
     * @param string $method   The HTTP method (GET, POST, etc.).
     * @param array  $data     The data to send.
     * @param string $api_key  Optional API Key.
     * @return array|WP_Error The response or error.
     */
    public static function request( $endpoint, $method = 'GET', $data = array(), $api_key = null ) {
        // Fallback (Deprecating) - Ideally $api_key should always be passed
        if ( empty( $api_key ) ) {
             $api_key = Moyasar_Helper::get_api_secret_key();
        }
        
        if ( empty( $api_key ) ) {
            Moyasar_Helper::log('Moyasar API Error: API key is missing.', 'error');
            return new WP_Error( 'moyasar_api_key_missing', __( 'Moyasar API key is missing.', 'moyasar-payments' ) );
        }

        $api_key  = trim( (string) $api_key );
        $endpoint = trim( $endpoint, '/' );
        
        // Check if endpoint is full URL or relative
        if ( strpos( $endpoint, 'http' ) === 0 ) {
             $url = $endpoint;
        } else {
             $url = self::get_base_url() . $endpoint;
        }

        $args = array(
            'method'  => $method,
            'timeout' => 45,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode( $api_key . ':' ),
                'Content-Type'  => 'application/json',
                'version'       => 'woo_' . MOYASAR_WC_VERSION
            ),
        );

        // Mask critical data in logs
        $log_data = $data;
        if ( isset( $log_data['source'] ) ) {
            if ( isset( $log_data['source']['number'] ) ) $log_data['source']['number'] = '****' . substr( $log_data['source']['number'], -4 );
            if ( isset( $log_data['source']['cvc'] ) ) $log_data['source']['cvc'] = '***';
        }

        if ( ! empty( $data ) && in_array( $method, array( 'POST', 'PUT' ) ) ) {
            $args['body'] = wp_json_encode( $data );
        }
        
        $pretty_request = wp_json_encode( $log_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        Moyasar_Helper::log("API Request [$method]: $url. Data: \n" . $pretty_request, 'debug');

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            Moyasar_Helper::log("API Connection Error: " . $response->get_error_message(), 'error');
            return $response;
        }

        $body = wp_remote_retrieve_body( $response );
        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( $body, true );
        
        $pretty_body = $body;
        if ( is_array( $data ) ) {
            $pretty_body = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        }

        Moyasar_Helper::log("API Response Code: $code", 'debug');
        if ( $code >= 400 ) {
            Moyasar_Helper::log("API Response Body (Error): \n" . $pretty_body, 'error');
        } else {
            Moyasar_Helper::log("API Response Body: \n" . $pretty_body, 'debug');
        }

        if ( $code >= 200 && $code < 300 ) {
            return $data;
        } else {
            return new WP_Error( 'moyasar_api_error', isset( $data['message'] ) ? $data['message'] : __( 'Unknown API Error', 'moyasar-payments' ), $data );
        }
    }

    public static function create_payment( $data, $api_key = null ) {
        return self::request( 'payments', 'POST', $data, $api_key );
    }

    public static function fetch_payment( $id, $api_key = null ) {
        return self::request( 'payments/' . $id, 'GET', array(), $api_key );
    }

    /**
     * List payments.
     *
     * @param array  $params  Optional query params (e.g. 'page').
     * @param string $api_key Optional API Key.
     * @return array|WP_Error The decoded response (with a 'payments' key) or a WP_Error.
     */
    public static function list_payments( $params = array(), $api_key = null ) {
        $endpoint = 'payments';
        if ( ! empty( $params ) ) {
            $endpoint .= '?' . http_build_query( $params );
        }
        return self::request( $endpoint, 'GET', array(), $api_key );
    }

    public static function fetch_token( $id, $api_key = null ) {
        return self::request( 'tokens/' . $id, 'GET', array(), $api_key );
    }

    public static function update_payment( $id, $data, $api_key = null ) {
       return self::request( 'payments/' . $id, 'PUT', $data, $api_key );
    }

    public static function refund_payment( $id, $amount = null, $api_key = null ) {
        $data = array();
        if ( $amount ) {
            $data['amount'] = $amount;
        }
        return self::request( 'payments/' . $id . '/refund', 'POST', $data, $api_key );
    }

    public static function void_payment( $id, $api_key = null ) {
        return self::request( 'payments/' . $id . '/void', 'POST', array(), $api_key );
    }

    public static function capture_payment( $id, $amount = null, $api_key = null ) {
        $data = array();
        if ( $amount ) {
            $data['amount'] = $amount;
        }
        return self::request( 'payments/' . $id . '/capture', 'POST', $data, $api_key );
    }

    public static function create_invoice( $data, $api_key = null ) {
        return self::request( 'invoices', 'POST', $data, $api_key );
    }

    public static function fetch_invoice( $id, $api_key = null ) {
        return self::request( 'invoices/' . $id, 'GET', array(), $api_key );
    }
}
