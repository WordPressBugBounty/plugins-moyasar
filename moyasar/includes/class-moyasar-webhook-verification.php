<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles Moyasar webhook verification codes.
 *
 * @since      8.4.2
 * @package    Moyasar
 * @subpackage Moyasar/includes
 */
class Moyasar_Webhook_Verification {

    const EVENT           = 'webhook_verification';
    const OPTION          = 'moyasar_webhook_verification';
    const CODE_TTL        = 1800;
    const STATUS_VERIFIED = 'verified';
    const STATUS_PENDING  = 'pending';
    const STATUS_RESENT   = 'resent';

    public static function handle_event( $data ) {
        $webhook_id = isset( $data['webhook_id'] ) && is_string( $data['webhook_id'] ) ? sanitize_text_field( $data['webhook_id'] ) : '';
        $code       = isset( $data['verification_code'] ) && is_string( $data['verification_code'] ) ? trim( $data['verification_code'] ) : '';

        if ( ! preg_match( '/^[A-Za-z0-9-]{1,64}$/', $webhook_id ) || ! preg_match( '/^\d{6}$/', $code ) ) {
            Moyasar_Helper::log( 'Webhook verification event received with invalid data', 'error' );
            status_header( 400 );
            exit( 'Invalid verification data' );
        }

        Moyasar_Helper::log( "Webhook verification code received for webhook $webhook_id", 'info' );

        $result = Moyasar_API::verify_webhook( $webhook_id, $code );

        if ( ! is_wp_error( $result ) && ! empty( $result['verified'] ) ) {
            self::save_state( array(
                'webhook_id' => $webhook_id,
                'status'     => self::STATUS_VERIFIED,
            ) );
            Moyasar_Helper::log( "Webhook $webhook_id verified automatically", 'info' );
            status_header( 200 );
            exit( 'Webhook verified' );
        }

        $error = is_wp_error( $result ) ? $result->get_error_message() : __( 'Unexpected response from Moyasar.', 'moyasar-payments' );
        self::save_state( array(
            'webhook_id' => $webhook_id,
            'status'     => self::STATUS_PENDING,
            'code'       => $code,
            'expires_at' => time() + self::CODE_TTL,
            'error'      => $error,
        ) );
        Moyasar_Helper::log( "Webhook $webhook_id automatic verification failed: $error. Code is shown in the plugin settings.", 'error' );
        status_header( 200 );
        exit( 'Verification code stored' );
    }

    public static function resend() {
        $state = self::get_state();

        if ( empty( $state['webhook_id'] ) ) {
            return new WP_Error( 'moyasar_webhook_unknown', __( 'No webhook verification request has been received yet. Add the webhook in your Moyasar Dashboard first.', 'moyasar-payments' ) );
        }

        $result = Moyasar_API::resend_webhook_verification( $state['webhook_id'] );

        if ( is_wp_error( $result ) ) {
            $error_data = $result->get_error_data();
            if ( is_array( $error_data ) && isset( $error_data['type'] ) && 'webhook_already_verified' === $error_data['type'] ) {
                self::save_state( array(
                    'webhook_id' => $state['webhook_id'],
                    'status'     => self::STATUS_VERIFIED,
                ) );
                return array( 'status' => self::STATUS_VERIFIED, 'message' => __( 'Webhook is already verified.', 'moyasar-payments' ) );
            }
            return $result;
        }

        self::save_state( array(
            'webhook_id' => $state['webhook_id'],
            'status'     => self::STATUS_RESENT,
        ) );

        return array( 'status' => self::STATUS_RESENT, 'message' => __( 'A new verification code was requested. Reload this page in a few seconds.', 'moyasar-payments' ) );
    }

    public static function get_state() {
        $state = get_option( self::OPTION, array() );
        return is_array( $state ) ? $state : array();
    }

    public static function get_active_code() {
        $state = self::get_state();

        if ( self::STATUS_PENDING !== ( $state['status'] ?? '' ) || empty( $state['code'] ) || (int) ( $state['expires_at'] ?? 0 ) <= time() ) {
            return null;
        }

        return $state;
    }

    private static function save_state( $state ) {
        $state['updated_at'] = time();
        update_option( self::OPTION, $state, false );
    }
}
