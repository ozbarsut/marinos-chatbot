<?php
/**
 * Plugin Name: Marinos Chatbot
 * Plugin URI:  https://marinosajans.com.tr
 * Description: Gemini API destekli, özelleştirilebilir yapay zeka chatbot. Marinos Ajans.
 * Version:     1.2.10
 * Author:      Marinos Ajans
 * Author URI:  https://marinosajans.com.tr
 * License:     GPL2
 * Text Domain: marinos-chatbot
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'MARINOS_CHATBOT_VERSION', '1.2.10' );
define( 'MARINOS_CHATBOT_PATH', plugin_dir_path( __FILE__ ) );
define( 'MARINOS_CHATBOT_URL',  plugin_dir_url( __FILE__ ) );

require_once MARINOS_CHATBOT_PATH . 'includes/class-admin.php';
require_once MARINOS_CHATBOT_PATH . 'includes/class-widget.php';
require_once MARINOS_CHATBOT_PATH . 'includes/class-api.php';
require_once MARINOS_CHATBOT_PATH . 'includes/class-logger.php';
require_once MARINOS_CHATBOT_PATH . 'includes/class-mailer.php';
require_once MARINOS_CHATBOT_PATH . 'includes/class-visitor.php';
require_once MARINOS_CHATBOT_PATH . 'includes/class-whatsapp.php';

function marinos_chatbot_init() {
    new Marinos_Chatbot_Admin();
    new Marinos_Chatbot_Widget();
    new Marinos_Chatbot_Api();
    new Marinos_Chatbot_Visitor();
}
add_action( 'plugins_loaded', 'marinos_chatbot_init' );

// Zamanlanmış e-posta — cron hook
add_action( 'marinos_send_conversation_email', 'marinos_chatbot_send_scheduled_email', 10, 3 );
function marinos_chatbot_send_scheduled_email( $session_id, $page_url, $ip ) {
    if ( ! class_exists( 'Marinos_Chatbot_Mailer' ) ) {
        require_once MARINOS_CHATBOT_PATH . 'includes/class-mailer.php';
        require_once MARINOS_CHATBOT_PATH . 'includes/class-logger.php';
    }
    $mailer = new Marinos_Chatbot_Mailer();
    $mailer->notify( $session_id, $page_url, $ip );

    // Pending kaydini temizle (idempotent).
    $pending = (array) get_option( 'marinos_chatbot_pending_emails', [] );
    if ( isset( $pending[ $session_id ] ) ) {
        unset( $pending[ $session_id ] );
        update_option( 'marinos_chatbot_pending_emails', $pending, false );
    }
}

/**
 * Watchdog: WP-Cron her hostta calismayabilir. Bekleyenleri her admin/sayfa yuklemesinde tara
 * (init kancasinda hafif kalmasi icin throttle ediyoruz).
 */
add_action( 'init', 'marinos_chatbot_watchdog' );
function marinos_chatbot_watchdog() {
    $last = (int) get_option( 'marinos_chatbot_watchdog_ts', 0 );
    if ( ( time() - $last ) < 30 ) return; // 30 sn de bir tara
    update_option( 'marinos_chatbot_watchdog_ts', time(), false );

    $pending = (array) get_option( 'marinos_chatbot_pending_emails', [] );
    if ( empty( $pending ) ) return;

    if ( ! class_exists( 'Marinos_Chatbot_Mailer' ) ) {
        require_once MARINOS_CHATBOT_PATH . 'includes/class-mailer.php';
        require_once MARINOS_CHATBOT_PATH . 'includes/class-logger.php';
    }

    $now     = time();
    $changed = false;
    foreach ( $pending as $sid => $info ) {
        $due = isset( $info['due_at'] ) ? (int) $info['due_at'] : 0;
        if ( $due > 0 && $due <= $now ) {
            $mailer = new Marinos_Chatbot_Mailer();
            $mailer->notify( $sid, $info['page_url'] ?? '', $info['ip'] ?? '' );
            unset( $pending[ $sid ] );
            $changed = true;
        }
    }
    if ( $changed ) update_option( 'marinos_chatbot_pending_emails', $pending, false );
}

register_activation_hook( __FILE__, 'marinos_chatbot_activate' );
function marinos_chatbot_activate() {
    global $wpdb;
    $table   = $wpdb->prefix . 'marinos_chatbot_logs';
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS $table (
        id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        session_id   VARCHAR(64)         NOT NULL,
        visitor_ip   VARCHAR(45)         NOT NULL DEFAULT '',
        visitor_page VARCHAR(255)        NOT NULL DEFAULT '',
        role         VARCHAR(16)         NOT NULL,
        message      LONGTEXT            NOT NULL,
        created_at   DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY session_id (session_id),
        KEY created_at (created_at)
    ) $charset;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

register_deactivation_hook( __FILE__, 'marinos_chatbot_deactivate' );
function marinos_chatbot_deactivate() {
    // Tum zamanlanmis e-postalari temizle (cron coplugu kalmasin).
    $crons = _get_cron_array();
    if ( ! is_array( $crons ) ) return;
    foreach ( $crons as $ts => $hooks ) {
        if ( isset( $hooks['marinos_send_conversation_email'] ) ) {
            foreach ( $hooks['marinos_send_conversation_email'] as $sig => $event ) {
                wp_unschedule_event( $ts, 'marinos_send_conversation_email', $event['args'] );
            }
        }
    }
}
