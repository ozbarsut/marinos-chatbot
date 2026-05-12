<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Marinos_Chatbot_Api {

    const PENDING_OPT       = 'marinos_chatbot_pending_emails';
    const DEBOUNCE_SECONDS  = 60;
    const REQUEST_TIMEOUT   = 45;
    const REQUEST_RETRIES   = 2;
    const HISTORY_LIMIT     = 12;
    const MAX_MESSAGE_CHARS = 4000;

    public function __construct() {
        add_action( 'wp_ajax_marinos_chat',         [ $this, 'handle_chat' ] );
        add_action( 'wp_ajax_nopriv_marinos_chat',  [ $this, 'handle_chat' ] );
        add_action( 'wp_ajax_marinos_flush',        [ $this, 'handle_flush' ] );
        add_action( 'wp_ajax_nopriv_marinos_flush', [ $this, 'handle_flush' ] );
    }

    /**
     * ZIYARETCI -> BOT mesaj akisini isleyen ana endpoint.
     */
    public function handle_chat() {
        check_ajax_referer( 'marinos_chatbot_nonce', 'nonce' );

        $history    = isset( $_POST['history'] )    ? $_POST['history']                            : [];
        $user_msg   = isset( $_POST['message'] )    ? sanitize_textarea_field( $_POST['message'] ) : '';
        $session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( $_POST['session_id'] )  : '';
        $page_url   = isset( $_POST['page_url'] )   ? esc_url_raw( $_POST['page_url'] )            : '';
        $lang       = isset( $_POST['lang'] )       ? sanitize_text_field( $_POST['lang'] )        : '';

        if ( empty( $user_msg ) || empty( $session_id ) ) {
            wp_send_json_error( 'Geçersiz istek.' );
        }

        if ( function_exists( 'mb_substr' ) && mb_strlen( $user_msg ) > self::MAX_MESSAGE_CHARS ) {
            $user_msg = mb_substr( $user_msg, 0, self::MAX_MESSAGE_CHARS );
        }

        $client_key    = get_option( 'marinos_chatbot_api_key', '' );
        $system_prompt = get_option( 'marinos_chatbot_system_prompt', '' );

        if ( empty( $client_key ) ) {
            wp_send_json_error( 'API anahtarı tanımlanmamış.' );
        }

        // Dil bilgisini sistem prompt'una iliştir (cok dilli destegi).
        if ( $lang ) {
            $system_prompt = trim( $system_prompt );
            $system_prompt .= "\n\n[Language Hint] The visitor's browser language is '" . $lang . "'. "
                . "Reply in the same language the visitor uses in their last message. "
                . "Detect the visitor's language from their message and match it (Turkish, English, German, Russian, Arabic, etc.). "
                . "If the message is in Turkish reply in Turkish; if in English reply in English; and so on.";
        }

        $ip     = $this->get_ip();
        $logger = new Marinos_Chatbot_Logger();
        $logger->log( $session_id, 'user', $user_msg, $ip, $page_url );

        // Geçmiş mesajlar — kotali, temiz.
        $history_clean = [];
        if ( ! empty( $history ) && is_array( $history ) ) {
            $slice = array_slice( $history, -1 * self::HISTORY_LIMIT );
            foreach ( $slice as $item ) {
                $role = ( isset( $item['role'] ) && $item['role'] === 'model' ) ? 'model' : 'user';
                $text = isset( $item['text'] ) ? sanitize_textarea_field( $item['text'] ) : '';
                if ( $text === '' ) continue;
                if ( function_exists( 'mb_substr' ) ) $text = mb_substr( $text, 0, self::MAX_MESSAGE_CHARS );
                $history_clean[] = [ 'role' => $role, 'text' => $text ];
            }
        }

        $payload = [
            'client_key'    => $client_key,
            'message'       => $user_msg,
            'history'       => $history_clean,
            'system_prompt' => $system_prompt,
            'lang'          => $lang,
        ];

        $reply = $this->call_provider_with_retry( $payload );

        if ( is_wp_error( $reply ) ) {
            error_log( '[Marinos Chatbot] Provider hatasi: ' . $reply->get_error_message() );
            // Kullaniciya kibar fallback ver, ama hata olarak isaretle.
            $fallback = $this->fallback_message( $lang );
            $logger->log( $session_id, 'model', $fallback, $ip, $page_url );
            $this->schedule_email( $session_id, $page_url, $ip );
            wp_send_json_success([
                'reply'      => $fallback,
                'whatsapp'   => false,
                'degraded'   => true,
                'error_code' => $reply->get_error_code(),
            ]);
        }

        $logger->log( $session_id, 'model', $reply, $ip, $page_url );

        // WhatsApp tetikleyici
        $whatsapp_trigger = ( stripos( $reply, 'whatsapp_yonlendir' ) !== false );
        $clean_reply      = trim( str_ireplace( 'whatsapp_yonlendir', '', $reply ) );

        // Debounce e-posta planla + bekleyenleri tara.
        $this->schedule_email( $session_id, $page_url, $ip );
        $this->flush_stale_pending();

        wp_send_json_success([
            'reply'    => $clean_reply,
            'whatsapp' => $whatsapp_trigger,
        ]);
    }

    /**
     * Ziyaretci sayfayi kapatirken cagirilan flush endpoint'i (sendBeacon).
     * Bekleyen e-postayi anlik tetikler ve cron'u temizler.
     */
    public function handle_flush() {
        // sendBeacon nonce yi guvenilir gondermez; once kontrol et, basarisizsa da
        // session_id li flush mantigi ile devam et (mail kilitleri zaten idempotent).
        $valid_nonce = isset( $_POST['nonce'] ) && wp_verify_nonce( $_POST['nonce'], 'marinos_chatbot_nonce' );

        $session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( $_POST['session_id'] ) : '';
        $page_url   = isset( $_POST['page_url'] )   ? esc_url_raw( $_POST['page_url'] )           : '';
        $ip         = $this->get_ip();

        if ( ! $session_id ) {
            wp_send_json_error( 'no_session' );
        }

        $this->cancel_scheduled_email( $session_id, $page_url, $ip );
        $this->remove_pending( $session_id );

        if ( ! class_exists( 'Marinos_Chatbot_Mailer' ) ) {
            require_once dirname( __FILE__ ) . '/class-mailer.php';
        }
        $mailer = new Marinos_Chatbot_Mailer();
        $sent   = $mailer->notify( $session_id, $page_url, $ip );

        wp_send_json_success( [ 'sent' => (bool) $sent, 'nonce_ok' => $valid_nonce ] );
    }

    /**
     * Marinos proxy'sine yeniden denemeli istek.
     * @return string|WP_Error
     */
    private function call_provider_with_retry( $payload ) {
        $url   = apply_filters( 'marinos_chatbot_proxy_url', 'https://marinosajans.com.tr/marinos-api/v1/chat' );
        $tries = self::REQUEST_RETRIES + 1;
        $last_err = null;

        for ( $i = 0; $i < $tries; $i++ ) {
            $response = wp_remote_post( $url, [
                'headers' => [ 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode( $payload ),
                'timeout' => self::REQUEST_TIMEOUT,
            ] );

            if ( is_wp_error( $response ) ) {
                $last_err = new WP_Error( 'http_error', $response->get_error_message() );
                usleep( 350000 * ( $i + 1 ) );
                continue;
            }

            $code = wp_remote_retrieve_response_code( $response );
            $body = wp_remote_retrieve_body( $response );

            if ( $code >= 500 ) {
                $last_err = new WP_Error( 'server_' . $code, 'Proxy 5xx donduruyor.' );
                usleep( 350000 * ( $i + 1 ) );
                continue;
            }

            $data = json_decode( $body, true );
            if ( ! is_array( $data ) ) {
                $last_err = new WP_Error( 'bad_json', 'Gecersiz JSON yanit: ' . substr( (string) $body, 0, 200 ) );
                continue; // tekrar dene
            }

            if ( empty( $data['success'] ) || empty( $data['reply'] ) ) {
                $err = isset( $data['error'] ) ? (string) $data['error'] : 'Yanit alinamadi.';
                return new WP_Error( 'provider_error', $err );
            }
            return (string) $data['reply'];
        }

        return $last_err instanceof WP_Error ? $last_err : new WP_Error( 'unknown', 'Bilinmeyen hata' );
    }

    private function fallback_message( $lang ) {
        $lang = strtolower( substr( (string) $lang, 0, 2 ) );
        $map  = [
            'tr' => 'Şu an küçük bir aksaklık yaşıyoruz — sorunuzu birazdan tekrar denerseniz veya WhatsApp üzerinden iletirseniz hemen geri döneriz.',
            'en' => 'We are having a brief hiccup. Please try again in a moment, or message us on WhatsApp and we will get right back to you.',
            'de' => 'Es gibt gerade eine kurze Störung. Bitte versuchen Sie es gleich noch einmal oder schreiben Sie uns über WhatsApp.',
            'ru' => 'У нас небольшой сбой. Попробуйте, пожалуйста, снова через минуту или напишите нам в WhatsApp.',
            'ar' => 'يوجد لدينا انقطاع بسيط الآن. يرجى المحاولة مرة أخرى بعد قليل أو مراسلتنا على واتساب.',
            'fr' => 'Nous avons un petit souci technique. Merci de réessayer dans un instant ou de nous écrire sur WhatsApp.',
            'es' => 'Tenemos un pequeño problema técnico. Inténtelo de nuevo en un momento o escríbanos por WhatsApp.',
        ];
        return $map[ $lang ] ?? $map['tr'];
    }

    /**
     * E-postayi WP-Cron araciligiyla 60 sn sonra atmak uzere zamanlar.
     * Ayrica "pending" listesine yazar (cron calismazsa watchdog isin yapar).
     */
    private function schedule_email( $session_id, $page_url, $ip ) {
        $hook = 'marinos_send_conversation_email';
        $args = [ $session_id, $page_url, $ip ];
        $ts   = wp_next_scheduled( $hook, $args );
        if ( $ts ) wp_unschedule_event( $ts, $hook, $args );
        wp_schedule_single_event( time() + self::DEBOUNCE_SECONDS, $hook, $args );

        $pending = (array) get_option( self::PENDING_OPT, [] );
        $pending[ $session_id ] = [
            'page_url' => $page_url,
            'ip'       => $ip,
            'due_at'   => time() + self::DEBOUNCE_SECONDS,
        ];
        update_option( self::PENDING_OPT, $pending, false );
    }

    private function cancel_scheduled_email( $session_id, $page_url, $ip ) {
        $hook = 'marinos_send_conversation_email';
        $args = [ $session_id, $page_url, $ip ];
        $ts   = wp_next_scheduled( $hook, $args );
        if ( $ts ) wp_unschedule_event( $ts, $hook, $args );
    }

    private function remove_pending( $session_id ) {
        $pending = (array) get_option( self::PENDING_OPT, [] );
        if ( isset( $pending[ $session_id ] ) ) {
            unset( $pending[ $session_id ] );
            update_option( self::PENDING_OPT, $pending, false );
        }
    }

    /**
     * WP-Cron her hostta guvenilir calismadigi icin watchdog:
     * Sure dolmus bekleyen oturumlari anlik tetikler.
     */
    public function flush_stale_pending() {
        $pending = (array) get_option( self::PENDING_OPT, [] );
        if ( empty( $pending ) ) return;

        $now     = time();
        $changed = false;

        if ( ! class_exists( 'Marinos_Chatbot_Mailer' ) ) {
            require_once dirname( __FILE__ ) . '/class-mailer.php';
        }

        foreach ( $pending as $sid => $info ) {
            $due = isset( $info['due_at'] ) ? (int) $info['due_at'] : 0;
            if ( $due > 0 && $due <= $now ) {
                $mailer = new Marinos_Chatbot_Mailer();
                $mailer->notify( $sid, $info['page_url'] ?? '', $info['ip'] ?? '' );
                unset( $pending[ $sid ] );
                $changed = true;
            }
        }

        if ( $changed ) {
            update_option( self::PENDING_OPT, $pending, false );
        }
    }

    private function get_ip() {
        if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) )        return sanitize_text_field( $_SERVER['HTTP_CLIENT_IP'] );
        if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) )  return sanitize_text_field( explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] )[0] );
        return sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' );
    }
}
