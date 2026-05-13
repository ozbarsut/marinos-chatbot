<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Marinos_Chatbot_Api {

    const PENDING_OPT       = 'marinos_chatbot_pending_emails';
    const DEBOUNCE_DEFAULT  = 60;    // 1 dk — kullanicinin istegi: "1 dk sessizlik = mail tetigi".
    const REQUEST_TIMEOUT   = 45;
    const REQUEST_RETRIES   = 2;
    const HISTORY_LIMIT     = 12;
    const MAX_MESSAGE_CHARS = 4000;
    const MAX_OUTPUT_TOKENS = 4096; // Daha geniş tavan — yarım kalma riski az.
    const WATCHDOG_BATCH    = 3;     // Tek calistirmada max kac oturum gonderelim.
    const SCAN_WINDOW_HOURS = 6;     // DB taramasi kac saat geriye baksin.

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
                . "\n\n[Sayı Adımı Kuralları — ÇOK ÖNEMLİ]"
                . "\n- Kullanıcının yazdığı sayıyı OLDUĞU GİBİ KULLAN. ASLA binlik/milyonluk çarpanla genişletme."
                . "\n- '400' yazıldıysa anlamı 400'dür (dört yüz). 400.000 (dört yüz bin) DEĞİLDİR."
                . "\n- '50' yazıldıysa 50'dir, 50.000 DEĞİLDİR."
                . "\n- Türkçe locale'de '.' bazen binlik ayraçtır (örn: 30.000 TL = otuz bin lira). Ama kullanıcı SADECE rakam yazdıysa (örn: 400) bu çarpansız ham sayıdır."
                . "\n- 11, 22, 33, 44, 55, 66, 77, 88, 99 gibi tek-token tekrarlı kısa cevapları 1..9 olarak yorumla (yanlışlıkla çift basıldı varsay)."
                . "\n- Mantıklı üst limiti geçmiyorsa kullanıcının verdiği sayıyı olduğu gibi kullan."
                . "\n- Yanıtında sayıyı tekrar yazarken kullanıcının formatına SAYGI duy: '400' dediğinde sen de '400' yaz, '400.000' yazma.";
        }

        // Her zaman geçerli yarım-cevap + stil guardrail'i:
        $system_prompt = trim( $system_prompt )
            . "\n\n[Tamamlık ve Stil Kuralı]"
            . "\n- Yanıtını ASLA yarıda bırakma. Her yanıt bir noktalama (.!?) ile bitmelidir."
            . "\n- Cümle ortasında, sayıdan sonra, 've/veya/or/and/oder' gibi bağlaçlardan sonra durma."
            . "\n- KISA cümleler kullan: tercihen 1-2 cümle, en fazla 3 cümle."
            . "\n- Birden fazla bilgi varsa madde işareti (•) veya numara ile listele, uzun paragraf yazma."
            . "\n- Reklam/satış jargonundan kaçın; sade ve net konuş."
            . "\n- Her yanıtın sonunda BİR sonraki adımı belirten KISA bir soru sor (kullanıcıyı akışta tut).";

        $payload = [
            'client_key'        => $client_key,
            'model'             => $model_name,
            'model_name'        => $model_name,
            'message'           => $prepared_msg,
            'history'           => $history_clean,
            'system_prompt'     => $system_prompt,
            'lang'              => $lang,
            // Proxy hangi parametre adını okursa okusun diye birden fazla alias gönderiyoruz.
            'max_tokens'        => self::MAX_OUTPUT_TOKENS,
            'max_output_tokens' => self::MAX_OUTPUT_TOKENS,
            'output_tokens'     => self::MAX_OUTPUT_TOKENS,
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

        // Yanıt yarım kaldıysa Gemini'a otomatik "kaldığın yerden devam et" isteği at,
        // iki cevabı birleştir. Kullanıcı bu adımı fark etmez, tek tam cevap görür.
        if ( $this->is_truncated_reply( $reply ) ) {
            $continuation = $this->request_continuation( $payload, $reply );
            if ( is_string( $continuation ) && $continuation !== '' ) {
                $reply = $this->stitch_reply( $reply, $continuation );
            }
        }

        // Yine de bilinen bitiş kalıpları (örn. "veya", "or", "oder") ile bitiyorsa kibarca onar.
        $reply = $this->repair_incomplete_reply( $reply );

        $logger->log( $session_id, 'model', $reply, $ip, $page_url );

        // WhatsApp tetikleyici
        $whatsapp_trigger = ( stripos( $reply, 'whatsapp_yonlendir' ) !== false );
        $clean_reply      = trim( str_ireplace( 'whatsapp_yonlendir', '', $reply ) );

        // Debounce e-posta planla.
        $this->schedule_email( $session_id, $page_url, $ip );

        // Yanit dondukten sonra arka planda bekleyenleri kontrol etmek icin shutdown hook.
        // Kullanici cevabini hemen alir, mail isi response'tan sonra calisir.
        add_action( 'shutdown', [ $this, 'flush_stale_pending' ], 99 );

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
    public static function debounce_seconds() {
        $min = (int) get_option( 'marinos_chatbot_mail_debounce_min', 1 );
        $min = max( 1, min( 120, $min ) );
        return $min * 60;
    }

    private function schedule_email( $session_id, $page_url, $ip ) {
        $debounce = self::debounce_seconds();
        $hook = 'marinos_send_conversation_email';
        $args = [ $session_id, $page_url, $ip ];
        $ts   = wp_next_scheduled( $hook, $args );
        if ( $ts ) wp_unschedule_event( $ts, $hook, $args );
        wp_schedule_single_event( time() + $debounce, $hook, $args );

        $pending = (array) get_option( self::PENDING_OPT, [] );
        $pending[ $session_id ] = [
            'page_url' => $page_url,
            'ip'       => $ip,
            'due_at'   => time() + $debounce,
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
     * WP-Cron her hostta guvenilir calismadigi icin iki katmanli watchdog:
     *  1) Pending option'undaki vadesi gecmis oturumlari gonder.
     *  2) DB'de son mesaji >= debounce süresi kadar eski OLAN ve henüz mail kilidi
     *     dusmemis oturumlari da tara — pending option kaybolsa bile mail gider.
     */
    public function flush_stale_pending() {
        if ( ! class_exists( 'Marinos_Chatbot_Mailer' ) ) {
            require_once dirname( __FILE__ ) . '/class-mailer.php';
        }

        $debounce = self::debounce_seconds();
        $sent_count = 0;
        $sent_sessions = [];

        // --- (1) Pending option ---
        $pending = (array) get_option( self::PENDING_OPT, [] );
        $now     = time();
        $changed = false;

        foreach ( $pending as $sid => $info ) {
            if ( $sent_count >= self::WATCHDOG_BATCH ) break;
            $due = isset( $info['due_at'] ) ? (int) $info['due_at'] : 0;
            if ( $due > 0 && $due <= $now ) {
                $mailer = new Marinos_Chatbot_Mailer();
                $result = $mailer->notify( $sid, $info['page_url'] ?? '', $info['ip'] ?? '' );
                unset( $pending[ $sid ] );
                $sent_sessions[ $sid ] = true;
                $changed = true;
                if ( $result !== false ) $sent_count++; // skipped (transient lock) ise sayma
            }
        }
        if ( $changed ) update_option( self::PENDING_OPT, $pending, false );

        if ( $sent_count >= self::WATCHDOG_BATCH ) return;

        // --- (2) DB tarama: son aktivitesi debounce kadar eski oturumlar ---
        global $wpdb;
        $table   = $wpdb->prefix . 'marinos_chatbot_logs';
        $cutoff  = date( 'Y-m-d H:i:s', $now - $debounce );
        $window  = date( 'Y-m-d H:i:s', $now - ( self::SCAN_WINDOW_HOURS * HOUR_IN_SECONDS ) );
        $limit   = self::WATCHDOG_BATCH - $sent_count;
        $rows    = $wpdb->get_results( $wpdb->prepare(
            "SELECT session_id, MAX(visitor_page) AS page_url, MAX(visitor_ip) AS ip, MAX(created_at) AS last_at
             FROM $table
             WHERE created_at >= %s
             GROUP BY session_id
             HAVING MAX(created_at) <= %s
             ORDER BY last_at ASC
             LIMIT %d",
            $window, $cutoff, $limit
        ) );

        if ( ! is_array( $rows ) ) return;

        foreach ( $rows as $row ) {
            $sid = $row->session_id;
            if ( empty( $sid ) || isset( $sent_sessions[ $sid ] ) ) continue;
            // Mailer'in idempotency mantigi mukerrer gonderimi engeller; bizim icin guvenli.
            $mailer = new Marinos_Chatbot_Mailer();
            $mailer->notify( $sid, (string) $row->page_url, (string) $row->ip );
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
            'gemini-3-pro'     => 'Gemini 3 Pro (En güçlü — karmaşık akışlar, satış sohbeti)',
            'gemini-3-flash'   => 'Gemini 3 Flash (Önerilen — hızlı, çok dilli)',
            'gemini-2.5-pro'   => 'Gemini 2.5 Pro (Yedek)',
            'gemini-2.5-flash' => 'Gemini 2.5 Flash (Geri uyum)',
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
     * Yanıt yarım kaldı mı? Heuristic'ler:
     *  - Noktalama (.!?…؟。!？) ile bitmiyorsa
     *  - "veya/or/oder/и/و" gibi bir bağlaçla bitiyorsa
     *  - Son kelime tek harfli ya da kelimenin ortası gibi gözüküyorsa (örn. "bütç")
     */
    private function is_truncated_reply( $reply ) {
        $reply = trim( (string) $reply );
        if ( $reply === '' ) return false;
        // 1) Noktalama kontrolü
        if ( ! preg_match( '/[\.!\?…؟。！？\)\]\}]\s*$/u', $reply ) ) return true;
        // 2) "ve/veya/or/and/oder/и/و" bağlacıyla bitenler (noktalı olsa da çoğunlukla yarım kalır)
        if ( preg_match( '/\b(ve|veya|or|and|oder|und|и|или|или)\s*[\.!\?…]?\s*$/iu', $reply ) ) return true;
        return false;
    }

    /**
     * Yarım kalan yanıtın devamını Gemini'dan istemek için ikinci istek.
     */
    private function request_continuation( $original_payload, $partial_reply ) {
        $payload = $original_payload;
        // Mevcut payload üstüne yarım cevabı history'ye ekleyip devam talimatı verelim.
        $history = isset( $payload['history'] ) && is_array( $payload['history'] ) ? $payload['history'] : [];
        $history[] = [ 'role' => 'model', 'text' => $partial_reply ];
        $payload['history']       = array_slice( $history, -20 );
        $payload['message']       = "[SYSTEM] Az önceki yanıtın yarım kaldı. KISA biçimde, BAŞTAN TEKRARLAMADAN, sadece kalan kısmı tamamla. Mutlaka noktalama ile bitir.";
        $payload['system_prompt'] = ( $payload['system_prompt'] ?? '' )
            . "\n\n[Devam Talimatı]"
            . "\n- Önceki yanıtın yarım kaldı. Sadece eksik kalan kısmı yaz."
            . "\n- Bütünü TEKRARLAMA, yalnızca yarıda kalan cümleyi tamamlayacak ek metni üret."
            . "\n- Yeni metin mutlaka noktalama ile bitsin.";

        $continuation = $this->call_provider_with_retry( $payload );
        if ( is_wp_error( $continuation ) ) return '';
        return (string) $continuation;
    }

    /**
     * Yarım yanıt + devam yanıtını mantıklıca birleştirir.
     */
    private function stitch_reply( $partial, $continuation ) {
        $partial      = rtrim( (string) $partial );
        $continuation = ltrim( (string) $continuation );
        if ( $continuation === '' ) return $partial;
        // Devam metni partial'la başlıyorsa tekrarı kes (Gemini bazen başa dönebilir).
        if ( function_exists( 'mb_substr' ) ) {
            $head_len = min( 60, mb_strlen( $partial ) );
            if ( $head_len > 10 ) {
                $head = mb_substr( $partial, -$head_len );
                $pos  = mb_strpos( $continuation, $head );
                if ( $pos !== false ) {
                    $continuation = mb_substr( $continuation, $pos + mb_strlen( $head ) );
                    $continuation = ltrim( $continuation );
                }
            }
        }
        // Aralarına uygun boşluk koy.
        $glue = '';
        if ( $partial !== '' && $continuation !== '' ) {
            $last  = function_exists( 'mb_substr' ) ? mb_substr( $partial, -1 ) : substr( $partial, -1 );
            $first = function_exists( 'mb_substr' ) ? mb_substr( $continuation, 0, 1 ) : substr( $continuation, 0, 1 );
            // Kelime ortasında kesildiyse boşluksuz yapıştır ("bütç" + "e ile..." = "bütçe ile...")
            if ( preg_match( '/[a-zA-Z\p{L}]/u', $last ) && preg_match( '/[a-zA-Z\p{L}]/u', $first ) ) {
                $glue = '';
            } else {
                $glue = ' ';
            }
        }
        return trim( $partial . $glue . $continuation );
    }

    /**
     * Devam isteği bile gelmediyse, çok bilinen yarım kalma kalıplarını lokal olarak onar.
     */
    private function repair_incomplete_reply( $reply ) {
        $reply = trim( (string) $reply );
        if ( $reply === '' ) return $reply;

        // "veya/or/oder/и/und" bağlacıyla biten kuyruğu temizle.
        $reply = preg_replace( '/\s*\b(ve|veya|or|and|oder|und|и|или)\b\s*[\.!\?…]?\s*$/iu', '', $reply );
        $reply = trim( $reply );

        // Hiçbir noktalama yoksa "." ekle.
        if ( $reply !== '' && ! preg_match( '/[\.!\?…؟。！？\)\]\}]\s*$/u', $reply ) ) {
            $reply .= '.';
        }
        return $reply;
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
        $msg = is_string( $normalized ) ? trim( $normalized ) : $msg;

        // Mesaj sadece tek bir tam sayı içeriyorsa, Gemini'nin binlik çarpanla
        // genişletmesini (400 -> 400.000) önlemek için açık disambiguation ekle.
        if ( preg_match( '/^\s*(\d{1,9})\s*$/u', $msg, $m2 ) ) {
            $n = (int) $m2[1];
            // 1..999 arasındaki sayılar en sık karıştırılan aralık.
            if ( $n >= 1 && $n <= 999999 ) {
                $msg = $n . " (ziyaretci tam olarak " . $n . " yazdi; binlik veya milyonluk carpan UYGULAMA, sayiyi oldugu gibi kullan)";
            }
        }
        return $msg;
    }
}
