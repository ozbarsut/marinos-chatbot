<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Marinos_Chatbot_Visitor {

    public function __construct() {
        add_action( 'wp_ajax_marinos_save_visitor',        [ $this, 'save' ] );
        add_action( 'wp_ajax_nopriv_marinos_save_visitor', [ $this, 'save' ] );
    }

    public function save() {
        check_ajax_referer( 'marinos_chatbot_nonce', 'nonce' );

        $session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';
        $type       = isset( $_POST['type'] )       ? sanitize_text_field( wp_unslash( $_POST['type'] ) )       : '';
        $value      = isset( $_POST['value'] )      ? sanitize_text_field( wp_unslash( $_POST['value'] ) )      : '';
        $page_url   = isset( $_POST['page_url'] )   ? esc_url_raw( wp_unslash( $_POST['page_url'] ) )           : '';

        if ( ! $session_id || ! $type || ! $value ) {
            wp_send_json_error();
            return;
        }

        $logger = new Marinos_Chatbot_Logger();
        $logger->log( $session_id, 'visitor_info', '[' . $type . '] ' . $value, '', $page_url );

        $mailer = new Marinos_Chatbot_Mailer();
        $mailer->notify_visitor_info( $session_id, $type, $value, $page_url );

        wp_send_json_success();
    }
}
