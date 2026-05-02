<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Marinos_Chatbot_Logger {

    private $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'marinos_chatbot_logs';
    }

    public function log( $session_id, $role, $message, $ip = '', $page = '' ) {
        global $wpdb;
        $wpdb->insert( $this->table, [
            'session_id'   => $session_id,
            'visitor_ip'   => $ip,
            'visitor_page' => $page,
            'role'         => $role,
            'message'      => $message,
            'created_at'   => current_time( 'mysql' ),
        ] );
    }

    public function get_session( $session_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE session_id = %s ORDER BY created_at ASC",
            $session_id
        ) );
    }

    public function get_session_count( $session_id ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE session_id = %s",
            $session_id
        ) );
    }
}
