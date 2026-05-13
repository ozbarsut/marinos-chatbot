<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * UltraMsg destekli WhatsApp bildirim göndericisi.
 * Ayarlar admin panelinden gelir:
 *  - marinos_chatbot_wa_enabled
 *  - marinos_chatbot_wa_instance
 *  - marinos_chatbot_wa_token
 *  - marinos_chatbot_wa_recipients
 */
class Marinos_Chatbot_Whatsapp {

    public function get_recipients() {
        $raw  = get_option( 'marinos_chatbot_wa_recipients', '' );
        $list = preg_split( '/[\r\n,]+/', $raw );
        $out  = [];
        foreach ( (array) $list as $num ) {
            $clean = preg_replace( '/[^0-9]/', '', (string) $num );
            if ( strlen( $clean ) >= 8 ) {
                $out[] = $clean;
            }
        }
        return array_values( array_unique( $out ) );
    }

    /**
     * @return bool true gönderildiyse, false aksi.
     */
    public function send( $to, $message ) {
        $instance = trim( (string) get_option( 'marinos_chatbot_wa_instance', '' ) );
        $token    = trim( (string) get_option( 'marinos_chatbot_wa_token', '' ) );
        if ( $instance === '' || $token === '' ) {
            error_log( '[Marinos Chatbot WA] Eksik instance/token.' );
            return false;
        }
        if ( empty( $to ) ) return false;

        $url = 'https://api.ultramsg.com/' . rawurlencode( $instance ) . '/messages/chat';

        $response = wp_remote_post( $url, [
            'timeout' => 20,
            'headers' => [ 'Content-Type' => 'application/x-www-form-urlencoded' ],
            'body'    => [
                'token'             => $token,
                'to'                => $to,
                'body'              => $message,
                'priority'          => 10,
                'referenceId'       => '',
                'msgId'             => '',
                'mentions'          => '',
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            error_log( '[Marinos Chatbot WA] HTTP error: ' . $response->get_error_message() );
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( $code >= 200 && $code < 300 && is_array( $data ) ) {
            if ( isset( $data['sent'] ) && ( $data['sent'] === true || $data['sent'] === 'true' ) ) {
                return true;
            }
            if ( isset( $data['id'] ) ) return true;
        }

        error_log( '[Marinos Chatbot WA] Basarisiz: HTTP ' . $code . ' body=' . substr( (string) $body, 0, 500 ) );
        return false;
    }
}
