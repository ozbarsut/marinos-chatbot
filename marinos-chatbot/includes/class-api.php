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

        $client_key  = get_option( 'marinos_chatbot_api_key', '' );
        $model_name  = $this->get_model_name();
        $base_prompt = get_option( 'marinos_chatbot_system_prompt', '' );

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
        $history_clean = $this->normalize_history( $history_clean );
        $is_passenger_count_step = $this->is_passenger_count_step( $history_clean );
        $prepared_user_msg = $this->prepare_user_message( $user_msg, $is_passenger_count_step );
        $system_prompt = $this->build_runtime_prompt( $base_prompt, $is_passenger_count_step );

        // Marinos API proxy'sine istek at
        $response = wp_remote_post(
            'https://marinosajans.com.tr/marinos-api/v1/chat',
            [
                'headers' => [ 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode([
                    'client_key'    => $client_key,
                    'model'         => $model_name,
                    'model_name'    => $model_name,
                    'message'       => $prepared_user_msg,
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
        $clean_reply      = $this->repair_incomplete_reply( $clean_reply );

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

    private function get_model_name() {
        $model = sanitize_text_field( (string) get_option( 'marinos_chatbot_model', 'gemini-3-flash' ) );
        $allowed = [ 'gemini-3-flash', 'gemini-2.5-flash' ];
        return in_array( $model, $allowed, true ) ? $model : 'gemini-3-flash';
    }

    private function build_runtime_prompt( $base_prompt, $is_passenger_count_step = false ) {
        $base_prompt = trim( (string) $base_prompt );
        $guardrails = "Konusmanin dilini kullanicidan algila ve ayni dilde devam et.\n"
            . "Rezervasyon akisini yonetirken her mesajdan once eldeki bilgileri kontrol et; daha once verilen bilgiyi tekrar sorma.\n"
            . "Rezervasyon icin bu alanlari slot olarak takip et: ad soyad, iletisim, tarih, saat, kisi sayisi, hizmet.\n"
            . "Eksik alanlari tek tek, en fazla 1-2 soru ile iste; kullaniciyi uzun soru listesine bogma.\n"
            . "Her adimda kisa bir ozet ver: bilinenler + hala eksik olanlar.\n"
            . "Tum alanlar tamamlaninca net rezervasyon ozeti ver ve onay iste.\n"
            . "Ayni soruyu tekrar sormaktan kacin; zorunlu tekrar gerekirse nedenini tek cumlede acikla.\n"
            . "Yolcu sayisi adiminda 11,22,33,44,55,66,77,88,99 degerlerini otomatik olarak 1,2,3,4,5,6,7,8,9 olarak kabul et.\n"
            . "Yolcu sayisi 9'dan buyukse maksimum 9 kisilik arac oldugunu belirt.\n"
            . "Cevaplari asla yarim birakma; cumlenin ortasinda kesme ve eksik baglacla bitirme.";

        if ( $is_passenger_count_step ) {
            $guardrails .= "\nSu anda yolcu sayisi adimindasin; sadece bu adima uygun yanit ver.";
        }

        return $base_prompt === '' ? $guardrails : $base_prompt . "\n\n" . $guardrails;
    }

    private function normalize_history( $history ) {
        if ( empty( $history ) || ! is_array( $history ) ) {
            return [];
        }

        $normalized = [];
        foreach ( $history as $item ) {
            if ( empty( $item['text'] ) || empty( $item['role'] ) ) {
                continue;
            }

            $current_text = $this->normalize_text( $item['text'] );
            $last = end( $normalized );
            if ( $last && $last['role'] === $item['role'] && $this->normalize_text( $last['text'] ) === $current_text ) {
                continue;
            }

            $normalized[] = [
                'role' => $item['role'],
                'text' => $item['text'],
            ];
        }

        return array_slice( $normalized, -20 );
    }

    private function normalize_text( $text ) {
        $text = preg_replace( '/\s+/u', ' ', trim( (string) $text ) );
        if ( function_exists( 'mb_strtolower' ) ) {
            return mb_strtolower( $text, 'UTF-8' );
        }
        return strtolower( $text );
    }

    private function is_passenger_count_step( $history ) {
        if ( empty( $history ) || ! is_array( $history ) ) {
            return false;
        }

        $keywords = [
            'kac kisi',
            'kaç kişi',
            'yolcu sayisi',
            'yolcu sayısı',
            'how many passenger',
            'how many people',
            'passenger count',
            'how many guests',
            'wie viele personen',
            'anzahl der personen',
            'сколько пассажиров',
            'сколько человек',
        ];

        for ( $i = count( $history ) - 1; $i >= 0; $i-- ) {
            if ( $history[ $i ]['role'] !== 'model' || empty( $history[ $i ]['text'] ) ) {
                continue;
            }

            $text = $this->normalize_text( $history[ $i ]['text'] );
            foreach ( $keywords as $keyword ) {
                if ( strpos( $text, $keyword ) !== false ) {
                    return true;
                }
            }
            break;
        }

        return false;
    }

    private function prepare_user_message( $user_msg, $is_passenger_count_step ) {
        $user_msg = trim( (string) $user_msg );
        if ( ! $is_passenger_count_step || $user_msg === '' ) {
            return $user_msg;
        }

        $normalized = preg_replace_callback(
            '/\b([1-9])\1\b/u',
            function( $matches ) {
                return $matches[1];
            },
            $user_msg
        );

        return trim( (string) $normalized );
    }

    private function repair_incomplete_reply( $reply ) {
        $reply = trim( (string) $reply );
        if ( $reply === '' ) {
            return $reply;
        }

        if ( preg_match( '/\botel ad[ıi]n[ıi]\s+veya\s*$/iu', $reply ) ) {
            return 'Otel adini veya tam bolgesini yazar misiniz?';
        }
        if ( preg_match( '/\bhotel name or\s*$/iu', $reply ) ) {
            return 'Please share your hotel name or exact area.';
        }
        if ( preg_match( '/\bhotelname oder\s*$/iu', $reply ) ) {
            return 'Bitte teilen Sie den Hotelnamen oder die genaue Region mit.';
        }
        if ( preg_match( '/\bназвание отеля или\s*$/iu', $reply ) ) {
            return 'Pozhaluysta, ukazhite nazvanie otyelya ili tochnyy rayon.';
        }

        if ( preg_match( '/\b(veya|or|oder|или)\s*$/iu', $reply ) ) {
            $reply = trim( preg_replace( '/\b(veya|or|oder|или)\s*$/iu', '', $reply ) );
        }
        if ( $reply !== '' && ! preg_match( '/[.!?]\s*$/u', $reply ) ) {
            $reply .= '.';
        }

        return $reply;
    }
}
