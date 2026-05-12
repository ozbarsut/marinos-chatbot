<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Marinos_Chatbot_Widget {

    public function __construct() {
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
        add_action( 'wp_footer',          [ $this, 'render' ] );
    }

    public function enqueue() {
        wp_enqueue_script( 'marinos-chatbot', MARINOS_CHATBOT_URL . 'assets/chatbot.js', [ 'jquery' ], MARINOS_CHATBOT_VERSION, true );
        wp_enqueue_style(  'marinos-chatbot', MARINOS_CHATBOT_URL . 'assets/chatbot.css', [], MARINOS_CHATBOT_VERSION );

        $qr_raw   = get_option( 'marinos_chatbot_quick_replies', '' );
        $qr_lines = array_values( array_filter( array_map( 'trim', explode( "\n", $qr_raw ) ) ) );

        wp_localize_script( 'marinos-chatbot', 'marinosChatbot', [
            'ajax_url'        => admin_url( 'admin-ajax.php' ),
            'nonce'           => wp_create_nonce( 'marinos_chatbot_nonce' ),
            'bot_name'        => get_option( 'marinos_chatbot_bot_name', 'Marinos Asistan' ),
            'welcome'         => get_option( 'marinos_chatbot_welcome_message', 'Merhaba! Size nasıl yardımcı olabilirim?' ),
            'whatsapp'        => get_option( 'marinos_chatbot_whatsapp_number', '' ),
            'phone'           => get_option( 'marinos_chatbot_phone_number', '' ),
            'page_url'        => get_permalink() ?: home_url( $_SERVER['REQUEST_URI'] ),
            'auto_open'       => get_option( 'marinos_chatbot_auto_open', '1' ),
            'greeting_delay'  => (int) get_option( 'marinos_chatbot_greeting_delay', 1 ),
            'typing_duration' => (int) get_option( 'marinos_chatbot_typing_duration', 1200 ),
            'pulse_enabled'   => get_option( 'marinos_chatbot_pulse_enabled', '1' ),
            'quick_replies'   => $qr_lines,
            'cta_enabled'     => get_option( 'marinos_chatbot_cta_enabled', '1' ),
            'cta_style'       => get_option( 'marinos_chatbot_cta_style', 'icon_text' ),
            'cta_position'    => get_option( 'marinos_chatbot_cta_position', 'above_input' ),
            'cta_visibility'  => get_option( 'marinos_chatbot_cta_visibility', 'always' ),
            'site_locale'     => substr( (string) get_locale(), 0, 2 ),
            'i18n'            => $this->i18n_strings(),
            'session_key'     => 'mc_session_' . md5( home_url() ), // Siteye özgü localStorage anahtarı
        ] );
    }

    /**
     * Frontend icin cok dilli UI metinleri.
     * Tarayici dili otomatik secilir; eslesme yoksa "tr" varsayilir.
     */
    private function i18n_strings() {
        return [
            'tr' => [
                'placeholder'   => 'Mesajınızı yazın...',
                'send'          => 'Gönder',
                'online'        => 'Çevrimiçi',
                'wa_question'   => 'Daha fazla bilgi almak ister misiniz?',
                'wa_action'     => "WhatsApp'tan yazın",
                'error_generic' => 'Şu an cevap veremiyorum. Lütfen birkaç saniye sonra tekrar deneyin.',
                'error_network' => 'Bağlantı sorunu oluştu. İnternet bağlantınızı kontrol edip tekrar deneyin.',
                'retry'         => 'Yeniden gönder',
                'aria_close'    => 'Sohbeti kapat',
                'aria_open'     => 'Sohbeti aç',
            ],
            'en' => [
                'placeholder'   => 'Type your message...',
                'send'          => 'Send',
                'online'        => 'Online',
                'wa_question'   => 'Would you like more details?',
                'wa_action'     => 'Message us on WhatsApp',
                'error_generic' => "I can't reply right now. Please try again in a moment.",
                'error_network' => 'Connection issue. Please check your network and try again.',
                'retry'         => 'Retry',
                'aria_close'    => 'Close chat',
                'aria_open'     => 'Open chat',
            ],
            'de' => [
                'placeholder'   => 'Nachricht eingeben...',
                'send'          => 'Senden',
                'online'        => 'Online',
                'wa_question'   => 'Möchten Sie mehr Informationen?',
                'wa_action'     => 'Auf WhatsApp schreiben',
                'error_generic' => 'Im Moment keine Antwort möglich. Bitte gleich erneut versuchen.',
                'error_network' => 'Verbindungsproblem. Bitte erneut versuchen.',
                'retry'         => 'Erneut senden',
                'aria_close'    => 'Chat schließen',
                'aria_open'     => 'Chat öffnen',
            ],
            'ru' => [
                'placeholder'   => 'Введите сообщение...',
                'send'          => 'Отправить',
                'online'        => 'В сети',
                'wa_question'   => 'Хотите узнать больше?',
                'wa_action'     => 'Написать в WhatsApp',
                'error_generic' => 'Сейчас не могу ответить. Попробуйте ещё раз через минуту.',
                'error_network' => 'Проблема с подключением. Проверьте интернет и попробуйте снова.',
                'retry'         => 'Повторить',
                'aria_close'    => 'Закрыть чат',
                'aria_open'     => 'Открыть чат',
            ],
            'ar' => [
                'placeholder'   => 'اكتب رسالتك...',
                'send'          => 'إرسال',
                'online'        => 'متصل',
                'wa_question'   => 'هل تريد المزيد من المعلومات؟',
                'wa_action'     => 'مراسلتنا على واتساب',
                'error_generic' => 'لا أستطيع الرد الآن. حاول مرة أخرى بعد قليل.',
                'error_network' => 'مشكلة في الاتصال. حاول مرة أخرى.',
                'retry'         => 'إعادة المحاولة',
                'aria_close'    => 'إغلاق الدردشة',
                'aria_open'     => 'فتح الدردشة',
            ],
            'fr' => [
                'placeholder'   => 'Écrivez votre message...',
                'send'          => 'Envoyer',
                'online'        => 'En ligne',
                'wa_question'   => 'Souhaitez-vous plus d\'informations ?',
                'wa_action'     => 'Nous écrire sur WhatsApp',
                'error_generic' => "Je ne peux pas répondre pour le moment. Réessayez dans un instant.",
                'error_network' => 'Problème de connexion. Réessayez s\'il vous plaît.',
                'retry'         => 'Réessayer',
                'aria_close'    => 'Fermer la discussion',
                'aria_open'     => 'Ouvrir la discussion',
            ],
            'es' => [
                'placeholder'   => 'Escribe tu mensaje...',
                'send'          => 'Enviar',
                'online'        => 'En línea',
                'wa_question'   => '¿Quieres más información?',
                'wa_action'     => 'Escríbenos en WhatsApp',
                'error_generic' => 'No puedo responder ahora. Inténtalo de nuevo en un momento.',
                'error_network' => 'Problema de conexión. Inténtalo de nuevo.',
                'retry'         => 'Reintentar',
                'aria_close'    => 'Cerrar chat',
                'aria_open'     => 'Abrir chat',
            ],
        ];
    }

    public function render() {
        $primary    = get_option( 'marinos_chatbot_primary_color', '#1a73e8' );
        $header_c   = get_option( 'marinos_chatbot_header_color', '' ) ?: $primary;
        $send_c     = get_option( 'marinos_chatbot_send_color', '' ) ?: $primary;
        $user_bub   = get_option( 'marinos_chatbot_user_bubble_color', '' ) ?: $primary;
        $qr_c       = get_option( 'marinos_chatbot_qr_color', '' ) ?: $primary;
        $bg_c       = get_option( 'marinos_chatbot_bg_color', '#f8f9fb' );
        $desk_r     = get_option( 'marinos_chatbot_desktop_right', '24px' );
        $desk_b     = get_option( 'marinos_chatbot_desktop_bottom', '24px' );
        $mob_r      = get_option( 'marinos_chatbot_mobile_right', '16px' );
        $mob_b      = get_option( 'marinos_chatbot_mobile_bottom', '16px' );
        $zindex     = (int) get_option( 'marinos_chatbot_zindex', 99999 );
        $bot_name   = get_option( 'marinos_chatbot_bot_name', 'Marinos Asistan' );
        $cta_en     = get_option( 'marinos_chatbot_cta_enabled', '1' );
        $cta_pos    = get_option( 'marinos_chatbot_cta_position', 'above_input' );
        $whatsapp   = get_option( 'marinos_chatbot_whatsapp_number', '' );
        $phone      = get_option( 'marinos_chatbot_phone_number', '' );
        $cta_style  = get_option( 'marinos_chatbot_cta_style', 'icon_text' );
        $cta_vis    = get_option( 'marinos_chatbot_cta_visibility', 'always' );
        $avatar_img = get_option( 'marinos_chatbot_avatar_url', '' );

        // px olmadan girildiyse ekle
        $desk_r = $this->px($desk_r);
        $desk_b = $this->px($desk_b);
        $mob_r  = $this->px($mob_r);
        $mob_b  = $this->px($mob_b);
        ?>
        <style id="mc-dynamic-style">
        #marinos-chat-wrapper {
            --mc-color: <?php echo esc_attr($primary); ?>;
            --mc-header: <?php echo esc_attr($header_c); ?>;
            --mc-send: <?php echo esc_attr($send_c); ?>;
            --mc-user-bubble: <?php echo esc_attr($user_bub); ?>;
            --mc-qr: <?php echo esc_attr($qr_c); ?>;
            --mc-bg: <?php echo esc_attr($bg_c); ?>;
            position: fixed !important;
            right: <?php echo esc_attr($desk_r); ?> !important;
            bottom: <?php echo esc_attr($desk_b); ?> !important;
            z-index: <?php echo $zindex; ?> !important;
        }
        @media (max-width: 480px) {
            #marinos-chat-wrapper {
                right: <?php echo esc_attr($mob_r); ?> !important;
                bottom: <?php echo esc_attr($mob_b); ?> !important;
            }
            #marinos-chat-box {
                width: calc(100vw - <?php echo (int)$mob_r * 2 + 16; ?>px) !important;
                right: 0 !important;
            }
        }
        </style>

        <div id="marinos-chat-wrapper">

            <div id="mc-pulse-dot"></div>

            <!-- Toggle butonu -->
            <button id="marinos-chat-toggle" aria-label="Chatbot aç/kapat">
                <?php if ( $avatar_img ): ?>
                    <img id="mc-avatar-img-toggle" src="<?php echo esc_url($avatar_img); ?>" alt="<?php echo esc_attr($bot_name); ?>" width="40" height="40" style="border-radius:50%;object-fit:cover;display:block;">
                    <img id="mc-icon-close-img" src="<?php echo esc_url($avatar_img); ?>" alt="" width="40" height="40" style="border-radius:50%;object-fit:cover;display:none;">
                <?php else: ?>
                <svg id="mc-icon-open" xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 64 64" fill="white">
                    <line x1="32" y1="6" x2="32" y2="14" stroke="white" stroke-width="3" stroke-linecap="round"/>
                    <circle cx="32" cy="5" r="3" fill="white"/>
                    <rect x="14" y="14" width="36" height="26" rx="6" fill="white" opacity="0.95"/>
                    <circle cx="24" cy="26" r="4" fill="var(--mc-color,#1a73e8)"/>
                    <circle cx="40" cy="26" r="4" fill="var(--mc-color,#1a73e8)"/>
                    <circle cx="25.5" cy="24.5" r="1.2" fill="white"/>
                    <circle cx="41.5" cy="24.5" r="1.2" fill="white"/>
                    <rect x="22" y="33" width="20" height="3" rx="1.5" fill="var(--mc-color,#1a73e8)" opacity="0.7"/>
                    <rect x="9" y="20" width="5" height="10" rx="2" fill="white" opacity="0.8"/>
                    <rect x="50" y="20" width="5" height="10" rx="2" fill="white" opacity="0.8"/>
                    <rect x="18" y="43" width="28" height="16" rx="4" fill="white" opacity="0.85"/>
                    <rect x="24" y="48" width="6" height="6" rx="1" fill="var(--mc-color,#1a73e8)" opacity="0.6"/>
                    <rect x="34" y="48" width="6" height="6" rx="1" fill="var(--mc-color,#1a73e8)" opacity="0.6"/>
                </svg>
                <svg id="mc-icon-close" xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 24 24" fill="white" style="display:none">
                    <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
                </svg>
                <?php endif; ?>
            </button>

            <!-- Chat penceresi -->
            <div id="marinos-chat-box" class="mc-hidden">
                <div id="mc-header">
                    <div id="mc-avatar">
                        <?php if ( $avatar_img ): ?>
                            <img src="<?php echo esc_url($avatar_img); ?>" alt="<?php echo esc_attr($bot_name); ?>" width="36" height="36" style="border-radius:50%;object-fit:cover;">
                        <?php else: ?>
                        <svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 64 64" fill="white">
                            <line x1="32" y1="4" x2="32" y2="11" stroke="white" stroke-width="3" stroke-linecap="round"/>
                            <circle cx="32" cy="3" r="2.5" fill="white"/>
                            <rect x="14" y="11" width="36" height="26" rx="6" fill="white" opacity="0.9"/>
                            <circle cx="24" cy="23" r="4" fill="rgba(255,255,255,0.3)"/>
                            <circle cx="40" cy="23" r="4" fill="rgba(255,255,255,0.3)"/>
                            <circle cx="25" cy="22" r="1.5" fill="white"/>
                            <circle cx="41" cy="22" r="1.5" fill="white"/>
                            <rect x="22" y="30" width="20" height="3" rx="1.5" fill="rgba(255,255,255,0.5)"/>
                            <rect x="9" y="18" width="5" height="9" rx="2" fill="white" opacity="0.7"/>
                            <rect x="50" y="18" width="5" height="9" rx="2" fill="white" opacity="0.7"/>
                            <rect x="18" y="40" width="28" height="14" rx="4" fill="white" opacity="0.8"/>
                        </svg>
                        <?php endif; ?>
                    </div>
                    <div id="mc-title">
                        <strong><?php echo esc_html($bot_name); ?></strong>
                        <span id="mc-online-status">
                            <span id="mc-online-dot"></span><span id="mc-online-label">Online</span>
                        </span>
                    </div>
                    <button id="mc-minimize" aria-label="Küçült">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="white"><path d="M19 13H5v-2h14v2z"/></svg>
                    </button>
                </div>

                <?php if ( $cta_en === '1' && $cta_pos === 'below_messages' ): ?>
                    <?php echo $this->render_cta_bar($phone, $whatsapp, $cta_style, $cta_vis); ?>
                <?php endif; ?>

                <div id="mc-messages"></div>
                <div id="mc-quick-replies"></div>

                <div id="mc-whatsapp-bar" style="display:none;">
                    <span id="mc-wa-question">Daha fazla bilgi almak ister misiniz?</span>
                    <?php if ($whatsapp): ?>
                        <a id="mc-whatsapp-btn" href="https://wa.me/<?php echo esc_attr($whatsapp); ?>" target="_blank" rel="noopener">WhatsApp'tan Yazın</a>
                    <?php endif; ?>
                </div>

                <?php if ( $cta_en === '1' && $cta_pos === 'above_input' ): ?>
                    <?php echo $this->render_cta_bar($phone, $whatsapp, $cta_style, $cta_vis); ?>
                <?php endif; ?>

                <div id="mc-input-area">
                    <textarea id="mc-input" placeholder="Mesajınızı yazın..." rows="1" maxlength="4000"></textarea>
                    <button id="mc-send" aria-label="Gönder">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="white"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }

    private function px($val) {
        $val = trim($val);
        if ($val === '') return '24px';
        return is_numeric($val) ? $val . 'px' : $val;
    }

    private function render_cta_bar($phone, $whatsapp, $style, $visibility) {
        $show_text = ($style === 'icon_text');
        $html = '<div id="mc-cta-bar" data-visibility="' . esc_attr($visibility) . '">';
        if ($phone) {
            $html .= '<a href="tel:+' . esc_attr($phone) . '" class="mc-cta-btn mc-cta-phone" title="Telefonla Ara">';
            $html .= '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/></svg>';
            if ($show_text) $html .= '<span>Ara</span>';
            $html .= '</a>';
        }
        if ($whatsapp) {
            $html .= '<a href="https://wa.me/' . esc_attr($whatsapp) . '" class="mc-cta-btn mc-cta-whatsapp" target="_blank" rel="noopener" title="WhatsApp">';
            $html .= '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>';
            if ($show_text) $html .= '<span>WhatsApp</span>';
            $html .= '</a>';
        }
        $html .= '</div>';
        return $html;
    }
}
