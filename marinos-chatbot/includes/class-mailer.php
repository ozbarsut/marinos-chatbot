<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Marinos_Chatbot_Mailer {

    /**
     * Kayıtlı e-posta adreslerini dizi olarak döndürür.
     * Virgül veya yeni satır ile ayrılmış adresleri destekler.
     */
    private function get_recipients() {
        $raw  = get_option( 'marinos_chatbot_email', get_option( 'admin_email' ) );
        $list = preg_split( '/[\r\n,]+/', $raw );
        $out  = [];
        foreach ( $list as $email ) {
            $email = sanitize_email( trim( $email ) );
            if ( is_email( $email ) ) {
                $out[] = $email;
            }
        }
        return ! empty( $out ) ? $out : [ get_option( 'admin_email' ) ];
    }

    /**
     * Konuşma özetini e-posta ve (varsa) WhatsApp olarak gönderir.
     * Aynı oturum için 5 dakika içinde tekrar gönderim yapılmaz (idempotent).
     */
    public function notify( $session_id, $page_url, $ip ) {
        if ( empty( $session_id ) ) return false;

        $logger = new Marinos_Chatbot_Logger();
        $logs   = $logger->get_session( $session_id );
        if ( ! is_array( $logs ) ) $logs = [];
        $count  = count( $logs );
        if ( $count === 0 ) return false;

        // Aynı konuşma + aynı son mesaj kombinasyonu için kilit.
        $last_ts  = isset( $logs[ $count - 1 ]->created_at ) ? (string) $logs[ $count - 1 ]->created_at : '';
        $lock_key = 'marinos_mail_sent_' . md5( $session_id );
        $already  = get_transient( $lock_key );
        if ( $already && $already === $last_ts ) {
            return false; // Bu tam içerik daha önce gönderildi → atla.
        }

        $subject = '[Marinos Chatbot] Yeni Konusma — ' . date_i18n( 'd.m.Y H:i' ) . ' (' . $count . ' mesaj)';
        $body    = $this->build_body( $logs, $page_url, $ip, $count );
        $headers = [ 'Content-Type: text/plain; charset=UTF-8' ];

        $to = $this->get_recipients();

        // wp_mail() basarisizliklarini yakalamak icin gecici hook bagla.
        $fail_reason = null;
        $capture = function( $wp_error ) use ( &$fail_reason ) {
            if ( $wp_error instanceof WP_Error ) {
                $fail_reason = $wp_error->get_error_message() . ' | data=' . wp_json_encode( $wp_error->get_error_data() );
            }
        };
        add_action( 'wp_mail_failed', $capture );

        $ok = wp_mail( $to, $subject, $body, $headers );

        remove_action( 'wp_mail_failed', $capture );

        // WhatsApp bildirimi (opsiyonel) — mail basarili olmasa da gonderelim.
        $this->maybe_send_whatsapp( $subject, $body );

        if ( $ok ) {
            // KILIDI YALNIZCA BASARILIYSA KOY. Aksi takdirde sonraki tetik bunu tekrar dener.
            set_transient( $lock_key, $last_ts, 30 * MINUTE_IN_SECONDS );
            update_option( 'marinos_chatbot_last_mail_sent', [
                'session_id' => $session_id,
                'at'         => current_time( 'mysql' ),
                'count'      => $count,
                'recipients' => $to,
            ], false );
        } else {
            error_log( '[Marinos Chatbot] wp_mail() basarisiz, oturum: ' . $session_id . ' | sebep: ' . ( $fail_reason ?: 'bilinmiyor' ) );
            // Basarisizlik sayacini artir (admin panelinde gosterilecek).
            $fails = (array) get_option( 'marinos_chatbot_mail_failures', [] );
            $fails[] = [
                'session_id' => $session_id,
                'at'         => current_time( 'mysql' ),
                'reason'     => $fail_reason ?: 'bilinmiyor',
            ];
            // Son 20 başarısızlığı tut.
            $fails = array_slice( $fails, -20 );
            update_option( 'marinos_chatbot_mail_failures', $fails, false );
        }

        return $ok;
    }

    public function notify_visitor_info( $session_id, $type, $value, $page_url ) {
        $to     = $this->get_recipients();
        $label  = $type === 'name' ? 'ISIM' : 'ILETISIM BILGISI';
        $subject = '[Marinos Chatbot] ' . $label . ': ' . $value . ' — ' . date_i18n( 'd.m.Y H:i' );

        $logger = new Marinos_Chatbot_Logger();
        $logs   = $logger->get_session( $session_id );

        $body  = "==========================================\n";
        $body .= "ZIYARETCI BILGISI ALINDI\n";
        $body .= "==========================================\n\n";
        $body .= $label . "  : " . $value . "\n";
        $body .= "Sayfa     : " . $page_url . "\n";
        $body .= "Tarih     : " . date_i18n( 'd.m.Y H:i' ) . "\n\n";
        $body .= $this->format_logs( $logs );
        $body .= "\nPanel: " . admin_url( 'admin.php?page=marinos-chatbot-logs&session_id=' . urlencode( $session_id ) ) . "\n";
        $body .= "==========================================\n";

        wp_mail( $to, $subject, $body, [ 'Content-Type: text/plain; charset=UTF-8' ] );
        $this->maybe_send_whatsapp( $subject, $body );
    }

    private function maybe_send_whatsapp( $subject, $body ) {
        if ( get_option( 'marinos_chatbot_wa_enabled', '0' ) !== '1' ) return;
        if ( ! class_exists( 'Marinos_Chatbot_Whatsapp' ) ) {
            $path = defined( 'MARINOS_CHATBOT_PATH' ) ? MARINOS_CHATBOT_PATH . 'includes/class-whatsapp.php' : __DIR__ . '/class-whatsapp.php';
            if ( file_exists( $path ) ) require_once $path;
        }
        if ( ! class_exists( 'Marinos_Chatbot_Whatsapp' ) ) return;

        $wa   = new Marinos_Chatbot_Whatsapp();
        $nums = $wa->get_recipients();
        if ( empty( $nums ) ) return;

        $message = $subject . "\n\n" . $body;
        if ( function_exists( 'mb_substr' ) ) {
            $message = mb_substr( $message, 0, 3800 );
        } else {
            $message = substr( $message, 0, 3800 );
        }
        foreach ( $nums as $n ) {
            $wa->send( $n, $message );
        }
    }

    private function build_body( $logs, $page_url, $ip, $count ) {
        $body  = "==========================================\n";
        $body .= "MARINOS CHATBOT - KONUSMA OZETI\n";
        $body .= "==========================================\n\n";
        $body .= "Tarih        : " . date_i18n( 'd.m.Y H:i' ) . "\n";
        $body .= "Sayfa        : " . $page_url . "\n";
        $body .= "Ziyaretci IP : " . $ip . "\n";
        $body .= "Mesaj Sayisi : " . $count . "\n\n";
        $body .= "------------------------------------------\n";
        $body .= "KONUSMA\n";
        $body .= "------------------------------------------\n\n";
        $body .= $this->format_logs( $logs );
        $body .= "\nTum konusmalari gormek icin:\n";
        $body .= admin_url( 'admin.php?page=marinos-chatbot-logs' ) . "\n";
        $body .= "==========================================\n";
        return $body;
    }

    private function format_logs( $logs ) {
        $out = '';
        foreach ( $logs as $row ) {
            if ( $row->role === 'user' ) {
                $out .= ">> ZIYARETCI:\n   " . $row->message . "\n\n";
            } elseif ( $row->role === 'visitor_info' ) {
                $out .= "** BILGI: " . $row->message . "\n\n";
            } else {
                $out .= "   BOT:\n   " . $row->message . "\n\n";
            }
        }
        return $out;
    }
}
