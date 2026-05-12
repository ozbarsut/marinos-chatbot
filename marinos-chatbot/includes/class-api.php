<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Marinos_Chatbot_Api {

    public function __construct() {
        add_action( 'wp_ajax_marinos_chat',        [ $this, 'handle_chat' ] );
        add_action( 'wp_ajax_nopriv_marinos_chat', [ $this, 'handle_chat' ] );
    }

    public function handle_chat() {
        check_ajax_referer( 'marinos_chatbot_nonce', 'nonce' );

        $history    = isset( $_POST['history'] )    ? $_POST['history']                            : [];
        $user_msg   = isset( $_POST['message'] )    ? sanitize_textarea_field( $_POST['message'] ) : '';
        $session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( $_POST['session_id'] )  : '';
        $page_url   = isset( $_POST['page_url'] )   ? esc_url_raw( $_POST['page_url'] )            : '';

        if ( empty( $user_msg ) || empty( $session_id ) ) {
            wp_send_json_error( 'Geçersiz istek.' );
        }

        $client_key    = get_option( 'marinos_chatbot_api_key', '' );
        $system_prompt = get_option( 'marinos_chatbot_system_prompt', '' );

        if ( empty( $client_key ) ) {
            wp_send_json_error( 'API anahtarı tanımlanmamış.' );
        }

        $ip     = $this->get_ip();
        $logger = new Marinos_Chatbot_Logger();
        $logger->log( $session_id, 'user', $user_msg, $ip, $page_url );

        // Geçmiş mesajlar
        $history_clean = [];
        if ( ! empty( $history ) && is_array( $history ) ) {
            foreach ( $history as $item ) {
                $role = ( isset( $item['role'] ) && $item['role'] === 'model' ) ? 'model' : 'user';
                $text = isset( $item['text'] ) ? sanitize_textarea_field( $item['text'] ) : '';
                if ( $text ) {
                    $history_clean[] = [ 'role' => $role, 'text' => $text ];
                }
            }
        }

        // Marinos API proxy'sine istek at
        $response = wp_remote_post(
            'https://marinosajans.com.tr/marinos-api/v1/chat',
            [
                'headers' => [ 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode([
                    'client_key'    => $client_key,
                    'message'       => $user_msg,
                    'history'       => $history_clean,
                    'system_prompt' => $system_prompt,
                ]),
                'timeout' => 30,
            ]
        );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( 'Bağlantı hatası: ' . $response->get_error_message() );
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $data['success'] ) || empty( $data['reply'] ) ) {
            $err = isset( $data['error'] ) ? $data['error'] : 'Yanıt alınamadı.';
            wp_send_json_error( $err );
        }

        $reply = $data['reply'];
        $logger->log( $session_id, 'model', $reply, $ip, $page_url );

        // WhatsApp tetikleyici
        $whatsapp_trigger = ( strpos( strtolower( $reply ), 'whatsapp_yonlendir' ) !== false );
        $clean_reply      = trim( str_ireplace( 'whatsapp_yonlendir', '', $reply ) );

        // Debounce e-posta planla
        $this->schedule_email( $session_id, $page_url, $ip );

        wp_send_json_success([
            'reply'    => $clean_reply,
            'whatsapp' => $whatsapp_trigger,
        ]);
    }

    private function schedule_email( $session_id, $page_url, $ip ) {
        $hook = 'marinos_send_conversation_email';
        $args = [ $session_id, $page_url, $ip ];
        $ts   = wp_next_scheduled( $hook, $args );
        if ( $ts ) wp_unschedule_event( $ts, $hook, $args );
        wp_schedule_single_event( time() + 60, $hook, $args );
    }

    private function get_ip() {
        if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) )        return sanitize_text_field( $_SERVER['HTTP_CLIENT_IP'] );
        if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) )  return sanitize_text_field( explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] )[0] );
        return sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' );
    }
}
