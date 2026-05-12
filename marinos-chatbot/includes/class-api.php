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

        // WordPress, $_POST verisine otomatik backslash ekler (magic quotes).
        // Sanitize ETMEDEN ÖNCE mutlaka wp_unslash() çağırmazsak: ' -> \' kalır,
        // bu metin geçmişe yazılır ve Gemini sonraki cevaplarında \' stilini taklit eder.
        $history    = isset( $_POST['history'] )    ? wp_unslash( $_POST['history'] )                            : [];
        $user_msg   = isset( $_POST['message'] )    ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
        $session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) )  : '';
        $page_url   = isset( $_POST['page_url'] )   ? esc_url_raw( wp_unslash( $_POST['page_url'] ) )            : '';
        $lang       = isset( $_POST['lang'] )       ? sanitize_text_field( wp_unslash( $_POST['lang'] ) )        : '';

        if ( empty( $user_msg ) || empty( $session_id ) ) {
            wp_send_json_error( 'Geçersiz istek.' );
        }

        if ( function_exists( 'mb_substr' ) && mb_strlen( $user_msg ) > self::MAX_MESSAGE_CHARS ) {
            $user_msg = mb_substr( $user_msg, 0, self::MAX_MESSAGE_CHARS );
        }

        $client_key    = get_option( 'marinos_chatbot_api_key', '' );
        $system_prompt = get_option( 'marinos_chatbot_system_prompt', '' );
        $model_name    = $this->get_model_name();

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
                // $history zaten wp_unslash'ten geçti; sanitize_textarea_field salt güvenli karakterler bırakır.
                $text = isset( $item['text'] ) ? sanitize_textarea_field( (string) $item['text'] ) : '';
                if ( $text === '' ) continue;
                if ( function_exists( 'mb_substr' ) ) $text = mb_substr( $text, 0, self::MAX_MESSAGE_CHARS );
                $history_clean[] = [ 'role' => $role, 'text' => $text ];
            }
        }
        $history_clean = $this->normalize_history( $history_clean );

        // "Sayfa kaç?" / "Kaç kişi?" / "How many days?" gibi bir SAYI ADIMINDAYSA
        // kullanıcının "77" / "33" gibi yanlislikla cift basilan rakamini tek basamaga indir.
        $is_count_step = $this->is_count_step( $history_clean );
        $prepared_msg  = $this->prepare_user_message( $user_msg, $is_count_step );

        // Sistem prompt'una sayi-adimi guardrail'i ekle.
        if ( $is_count_step ) {
            $system_prompt = trim( $system_prompt )
                . "\n\n[Sayı Adımı Kuralları]"
                . "\n- Kullanici 11, 22, 33, 44, 55, 66, 77, 88, 99 gibi tekrarli kisa cevap verirse bunu 1,2,3,4,5,6,7,8,9 olarak yorumla (yanlislikla cift basildi varsay)."
                . "\n- Mantikli ust limiti gecmiyorsa kullanicinin verdigi sayiyi oldugu gibi kullan."
                . "\n- Asla yarim cumle birakma; tum yaniti tamamla.";
        }

        $payload = [
            'client_key'    => $client_key,
            'model'         => $model_name,
            'model_name'    => $model_name,
            'message'       => $prepared_msg,
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

        $session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';
        $page_url   = isset( $_POST['page_url'] )   ? esc_url_raw( wp_unslash( $_POST['page_url'] ) )           : '';
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
            // Proxy bazi durumlarda metni cift-escape edebilir (TL\'lik gibi).
            // Klasik magic-quotes kalintilarini temizle:  \'  ->  '   ve  \"  ->  "
            $reply = (string) $data['reply'];
            $reply = preg_replace( "/\\\\'/", "'", $reply );
            $reply = preg_replace( '/\\\\"/', '"', $reply );
            return $reply;
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

    /**
     * Admin panelinde secilen modeli dondurur. Proxy bu degeri okuyarak hangi
     * Gemini modeline yonlendirecegini bilir. Onaylanan beyaz listede degilse
     * varsayilana duser.
     */
    public static function allowed_models() {
        return [
            'gemini-3-flash'   => 'Gemini Flash 3 (Önerilen — hızlı, çok dilli)',
            'gemini-3-pro'     => 'Gemini 3 Pro (Karmaşık akışlar)',
            'gemini-2.5-flash' => 'Gemini Flash 2.5 (Geri uyum)',
            'gemini-2.5-pro'   => 'Gemini 2.5 Pro',
        ];
    }

    private function get_model_name() {
        $model   = sanitize_text_field( (string) get_option( 'marinos_chatbot_model', 'gemini-3-flash' ) );
        $allowed = array_keys( self::allowed_models() );
        return in_array( $model, $allowed, true ) ? $model : 'gemini-3-flash';
    }

    /**
     * Geçmişi normalize eder:
     *  - Boş veya rolsüz öğeleri atar.
     *  - Birbirinin tıpkı tekrarı olan ardışık öğeleri birleştirir.
     *  - Son 20 mesajla sınırlandırır.
     */
    private function normalize_history( $history ) {
        if ( empty( $history ) || ! is_array( $history ) ) return [];
        $normalized = [];
        foreach ( $history as $item ) {
            if ( empty( $item['text'] ) || empty( $item['role'] ) ) continue;
            $current = $this->normalize_text( $item['text'] );
            $last    = end( $normalized );
            if ( $last && $last['role'] === $item['role'] && $this->normalize_text( $last['text'] ) === $current ) {
                continue;
            }
            $normalized[] = [ 'role' => $item['role'], 'text' => $item['text'] ];
        }
        return array_slice( $normalized, -20 );
    }

    private function normalize_text( $text ) {
        $text = preg_replace( '/\s+/u', ' ', trim( (string) $text ) );
        return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
    }

    /**
     * Son bot mesaji "kaç X" / "how many X" / "wie viele" / "сколько" tarzi
     * SAYI BEKLEYEN bir adim mi? Cevap "77" -> "7" gibi tek basamaga indirilebilsin diye.
     */
    private function is_count_step( $history ) {
        if ( empty( $history ) || ! is_array( $history ) ) return false;

        $keywords = [
            // Türkçe
            'kac kisi', 'kaç kişi', 'kac sayfa', 'kaç sayfa', 'kac gun', 'kaç gün',
            'kac adet', 'kaç adet', 'kac saat', 'kaç saat', 'kac metre', 'kaç metre',
            'yolcu sayisi', 'yolcu sayısı', 'sayfa sayisi', 'sayfa sayısı',
            'kac olmasini', 'kaç olmasını',
            // İngilizce
            'how many', 'how much', 'number of', 'count of',
            // Almanca
            'wie viele', 'wie viel', 'anzahl der', 'anzahl von',
            // Rusça
            'сколько', 'количество',
            // Arapça
            'كم', 'كم عدد',
            // Fransızca
            'combien', 'nombre de',
            // İspanyolca
            'cuántos', 'cuantos', 'cuántas', 'cuantas', 'número de',
        ];

        for ( $i = count( $history ) - 1; $i >= 0; $i-- ) {
            $row = $history[ $i ];
            if ( empty( $row['role'] ) || $row['role'] !== 'model' || empty( $row['text'] ) ) continue;
            $text = $this->normalize_text( $row['text'] );
            foreach ( $keywords as $kw ) {
                if ( strpos( $text, $kw ) !== false ) return true;
            }
            break; // Sadece en son model mesajina bak.
        }
        return false;
    }

    /**
     * Sayı-adımında çift basılan rakamı düzeltir.
     * - "77"   -> "7"
     * - "77 "  -> "7 "
     * - "7"    -> "7" (degisiklik yok)
     * - "77 sayfa" -> "77 sayfa" (kelime varsa dokunma, kullanici gercekten 77 demis)
     * - "11 33" -> "11 33" (birden fazla token, dokunma)
     */
    private function prepare_user_message( $user_msg, $is_count_step ) {
        $msg = trim( (string) $user_msg );
        if ( ! $is_count_step || $msg === '' ) return $msg;

        // Sadece tek bir "XX" tokeni varsa düzelt: "77" -> "7"
        if ( preg_match( '/^\s*([1-9])\1\s*$/u', $msg, $m ) ) {
            return $m[1];
        }
        // Içinde tek başına "XX" geçen ve etrafı boşluk/noktalama olan durumlarda da uygula.
        $normalized = preg_replace_callback(
            '/(^|\s)([1-9])\2(\s|[.,!?]|$)/u',
            function ( $matches ) { return $matches[1] . $matches[2] . $matches[3]; },
            $msg
        );
        return is_string( $normalized ) ? trim( $normalized ) : $msg;
    }
}
