<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Marinos_Chatbot_Admin {

    public function __construct() {
        add_action( 'admin_menu',    [ $this, 'add_menu' ] );
        add_action( 'admin_post_marinos_test_email',      [ $this, 'send_test_email' ] );
        add_action( 'admin_post_marinos_test_whatsapp',   [ $this, 'send_test_whatsapp' ] );
        add_action( 'admin_post_marinos_delete_session',  [ $this, 'delete_session' ] );
        add_action( 'admin_init',    [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    public function delete_session() {
        $session_id = isset( $_GET['session_id'] ) ? sanitize_text_field( $_GET['session_id'] ) : '';
        check_admin_referer( 'marinos_delete_' . $session_id );
        if ( ! current_user_can( 'manage_options' ) || empty( $session_id ) ) wp_die( 'Yetersiz yetki.' );
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'marinos_chatbot_logs', [ 'session_id' => $session_id ], [ '%s' ] );
        wp_redirect( admin_url( 'admin.php?page=marinos-chatbot-logs&deleted=1' ) );
        exit;
    }

    public function send_test_email() {
        check_admin_referer( 'marinos_test_email' );
        $to   = get_option( 'marinos_chatbot_email', get_option( 'admin_email' ) );
        $sent = wp_mail( $to, '[Marinos Chatbot] Test E-postasi', "Bu bir test mesajidir.\n\nE-posta sistemi calisiyor!" );
        wp_redirect( admin_url( 'admin.php?page=marinos-chatbot&marinos_test=' . ( $sent ? 'ok' : 'fail' ) ) );
        exit;
    }

    public function send_test_whatsapp() {
        check_admin_referer( 'marinos_test_whatsapp' );
        $wa   = new Marinos_Chatbot_Whatsapp();
        $nums = $wa->get_recipients();
        if ( empty( $nums ) ) {
            wp_redirect( admin_url( 'admin.php?page=marinos-chatbot&marinos_wa_test=nonumber' ) );
            exit;
        }
        $ok   = 0;
        $fail = 0;
        foreach ( $nums as $n ) {
            $result = $wa->send( $n, "[Marinos Chatbot] Test mesaji — " . date_i18n( 'd.m.Y H:i' ) . "\nWhatsApp bildirimleri calisiyor!" );
            $result ? $ok++ : $fail++;
        }
        $status = ( $fail === 0 ) ? 'ok' : ( $ok === 0 ? 'fail' : 'partial' );
        wp_redirect( admin_url( 'admin.php?page=marinos-chatbot&marinos_wa_test=' . $status . '&ok=' . $ok . '&fail=' . $fail ) );
        exit;
    }

    public function add_menu() {
        add_menu_page( 'Marinos Chatbot', 'Marinos Chatbot', 'manage_options', 'marinos-chatbot', [ $this, 'render_settings_page' ], 'dashicons-format-chat', 80 );
        add_submenu_page( 'marinos-chatbot', 'Ayarlar', 'Ayarlar', 'manage_options', 'marinos-chatbot', [ $this, 'render_settings_page' ] );
        add_submenu_page( 'marinos-chatbot', 'Konuşma Geçmişi', 'Konuşma Geçmişi', 'manage_options', 'marinos-chatbot-logs', [ $this, 'render_logs_page' ] );
    }

    public function register_settings() {
        $settings = [
            // Genel
            'marinos_chatbot_api_key'          => 'sanitize_text_field',
            'marinos_chatbot_model'            => 'sanitize_text_field',
            'marinos_chatbot_bot_name'         => 'sanitize_text_field',
            'marinos_chatbot_welcome_message'  => 'sanitize_textarea_field',
            'marinos_chatbot_system_prompt'    => 'sanitize_textarea_field',
            'marinos_chatbot_email'            => 'sanitize_textarea_field',
            // CTA / İletişim
            'marinos_chatbot_whatsapp_number'  => 'sanitize_text_field',
            'marinos_chatbot_phone_number'     => 'sanitize_text_field',
            'marinos_chatbot_cta_enabled'      => 'sanitize_text_field',
            'marinos_chatbot_cta_style'        => 'sanitize_text_field',
            'marinos_chatbot_cta_position'     => 'sanitize_text_field',
            'marinos_chatbot_cta_visibility'   => 'sanitize_text_field',
            // Karşılama Akışı
            'marinos_chatbot_auto_open'        => 'sanitize_text_field',
            'marinos_chatbot_greeting_delay'   => 'absint',
            'marinos_chatbot_typing_duration'  => 'absint',
            'marinos_chatbot_pulse_enabled'    => 'sanitize_text_field',
            'marinos_chatbot_quick_replies'    => 'sanitize_textarea_field',
            // Görünüm — Renkler
            'marinos_chatbot_primary_color'    => 'sanitize_hex_color',
            'marinos_chatbot_header_color'     => 'sanitize_hex_color',
            'marinos_chatbot_button_color'     => 'sanitize_hex_color',
            'marinos_chatbot_send_color'       => 'sanitize_hex_color',
            'marinos_chatbot_bg_color'         => 'sanitize_hex_color',
            'marinos_chatbot_user_bubble_color'=> 'sanitize_hex_color',
            'marinos_chatbot_qr_color'         => 'sanitize_hex_color',
            'marinos_chatbot_avatar_url'        => 'esc_url_raw',
            // Pozisyon
            'marinos_chatbot_desktop_right'    => 'sanitize_text_field',
            'marinos_chatbot_desktop_bottom'   => 'sanitize_text_field',
            'marinos_chatbot_mobile_right'     => 'sanitize_text_field',
            'marinos_chatbot_mobile_bottom'    => 'sanitize_text_field',
            'marinos_chatbot_zindex'           => 'absint',
            // WhatsApp Bildirimleri
            'marinos_chatbot_wa_enabled'       => 'sanitize_text_field',
            'marinos_chatbot_wa_instance'      => 'sanitize_text_field',
            'marinos_chatbot_wa_token'         => 'sanitize_text_field',
            'marinos_chatbot_wa_recipients'    => 'sanitize_textarea_field',
        ];
        foreach ( $settings as $key => $cb ) {
            register_setting( 'marinos_chatbot_group', $key, [ 'sanitize_callback' => $cb ] );
        }
    }

    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'marinos-chatbot' ) === false ) return;
        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_script( 'wp-color-picker' );
    }

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        // Genel
        $api_key          = get_option( 'marinos_chatbot_api_key', '' );
        $model            = get_option( 'marinos_chatbot_model', 'gemini-3-flash' );
        $bot_name         = get_option( 'marinos_chatbot_bot_name', 'Marinos Asistan' );
        $welcome          = get_option( 'marinos_chatbot_welcome_message', 'Merhaba! Size nasıl yardımcı olabilirim?' );
        $system_prompt    = get_option( 'marinos_chatbot_system_prompt', $this->default_prompt() );
        $email            = get_option( 'marinos_chatbot_email', get_option( 'admin_email' ) );
        // CTA
        $whatsapp         = get_option( 'marinos_chatbot_whatsapp_number', '' );
        $phone            = get_option( 'marinos_chatbot_phone_number', '' );
        $cta_enabled      = get_option( 'marinos_chatbot_cta_enabled', '1' );
        $cta_style        = get_option( 'marinos_chatbot_cta_style', 'icon_text' );
        $cta_position     = get_option( 'marinos_chatbot_cta_position', 'above_input' );
        $cta_visibility   = get_option( 'marinos_chatbot_cta_visibility', 'always' );
        // Karşılama
        $auto_open        = get_option( 'marinos_chatbot_auto_open', '1' );
        $greeting_delay   = get_option( 'marinos_chatbot_greeting_delay', 1 );
        $typing_duration  = get_option( 'marinos_chatbot_typing_duration', 1200 );
        $pulse_enabled    = get_option( 'marinos_chatbot_pulse_enabled', '1' );
        $quick_replies    = get_option( 'marinos_chatbot_quick_replies', '' );
        // Renkler
        $primary_color    = get_option( 'marinos_chatbot_primary_color', '#1a73e8' );
        $header_color     = get_option( 'marinos_chatbot_header_color', '' );
        $button_color     = get_option( 'marinos_chatbot_button_color', '' );
        $send_color       = get_option( 'marinos_chatbot_send_color', '' );
        $bg_color         = get_option( 'marinos_chatbot_bg_color', '#f8f9fb' );
        $user_bubble      = get_option( 'marinos_chatbot_user_bubble_color', '' );
        $qr_color         = get_option( 'marinos_chatbot_qr_color', '' );
        // Pozisyon
        $desk_right       = get_option( 'marinos_chatbot_desktop_right', '24px' );
        $desk_bottom      = get_option( 'marinos_chatbot_desktop_bottom', '24px' );
        $mob_right        = get_option( 'marinos_chatbot_mobile_right', '16px' );
        $mob_bottom       = get_option( 'marinos_chatbot_mobile_bottom', '16px' );
        $zindex           = get_option( 'marinos_chatbot_zindex', 99999 );
        // WhatsApp Bildirimleri
        $wa_enabled       = get_option( 'marinos_chatbot_wa_enabled', '0' );
        $wa_instance      = get_option( 'marinos_chatbot_wa_instance', '' );
        $wa_token         = get_option( 'marinos_chatbot_wa_token', '' );
        $wa_recipients    = get_option( 'marinos_chatbot_wa_recipients', '' );
        ?>
        <div class="wrap">
            <h1 style="display:flex;align-items:center;gap:10px;">
                <span class="dashicons dashicons-format-chat" style="font-size:28px;color:#1a73e8;"></span>
                Marinos Chatbot V2 — Ayarlar
            </h1>

            <?php if ( isset( $_GET['marinos_test'] ) ): ?>
                <?php if ( $_GET['marinos_test'] === 'ok' ): ?>
                    <div class="notice notice-success is-dismissible"><p>Test e-postası gönderildi.</p></div>
                <?php else: ?>
                    <div class="notice notice-error is-dismissible"><p>E-posta gönderilemedi. WP Mail SMTP ayarlayın.</p></div>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ( isset( $_GET['marinos_wa_test'] ) ): ?>
                <?php $wt = $_GET['marinos_wa_test']; $ok_c = intval($_GET['ok'] ?? 0); $fail_c = intval($_GET['fail'] ?? 0); ?>
                <?php if ( $wt === 'ok' ): ?>
                    <div class="notice notice-success is-dismissible"><p>WhatsApp test mesajı gönderildi (<?php echo $ok_c; ?> numara).</p></div>
                <?php elseif ( $wt === 'partial' ): ?>
                    <div class="notice notice-warning is-dismissible"><p>WhatsApp: <?php echo $ok_c; ?> başarılı, <?php echo $fail_c; ?> başarısız. PHP error_log'u kontrol edin.</p></div>
                <?php elseif ( $wt === 'nonumber' ): ?>
                    <div class="notice notice-error is-dismissible"><p>Alıcı numarası tanımlanmamış. Aşağıdaki "WhatsApp Bildirimleri" bölümüne numara ekleyin.</p></div>
                <?php else: ?>
                    <div class="notice notice-error is-dismissible"><p>WhatsApp gönderilemedi. Instance ID ve Token değerlerini kontrol edin.</p></div>
                <?php endif; ?>
            <?php endif; ?>
            <?php settings_errors(); ?>

            <style>
            .mc-section { background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:20px 24px; margin-bottom:20px; }
            .mc-section h2 { margin:0 0 16px; font-size:14px; text-transform:uppercase; letter-spacing:.5px; color:#64748b; border-bottom:1px solid #f1f5f9; padding-bottom:8px; }
            .mc-grid2 { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
            .mc-grid3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:16px; }
            .mc-field label { display:block; font-size:13px; font-weight:600; color:#374151; margin-bottom:5px; }
            .mc-field input[type=text],.mc-field input[type=number],.mc-field input[type=email],.mc-field textarea,.mc-field select { width:100%; padding:8px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:13px; }
            .mc-field .desc { font-size:12px; color:#6b7280; margin-top:4px; }
            .mc-toggle { display:flex; align-items:center; gap:8px; font-size:13px; }
            @media(max-width:782px){.mc-grid2,.mc-grid3{grid-template-columns:1fr;}}
            </style>

            <form method="post" action="options.php">
                <?php settings_fields( 'marinos_chatbot_group' ); ?>

                <!-- 1. GENEL -->
                <div class="mc-section">
                    <h2>Genel</h2>
                    <div class="mc-grid2">
                        <div class="mc-field">
                            <label>Marinos API Anahtarı</label>
                            <input type="password" name="marinos_chatbot_api_key" value="<?php echo esc_attr($api_key); ?>" autocomplete="off">
                            <p class="desc">marinosajans.com.tr üzerinden temin edilir.</p>
                        </div>
                        <div class="mc-field">
                            <label>Model</label>
                            <select name="marinos_chatbot_model">
                                <option value="gemini-3-flash" <?php selected($model,'gemini-3-flash'); ?>>Gemini Flash 3 (Önerilen)</option>
                                <option value="gemini-2.5-flash" <?php selected($model,'gemini-2.5-flash'); ?>>Gemini Flash 2.5</option>
                            </select>
                            <p class="desc">Çok dilli kullanım ve rezervasyon akışları için Gemini Flash 3 önerilir.</p>
                        </div>
                    </div>
                    <div class="mc-grid2" style="margin-top:12px;">
                        <div class="mc-field">
                            <label>Bot Adı</label>
                            <input type="text" name="marinos_chatbot_bot_name" value="<?php echo esc_attr($bot_name); ?>">
                        </div>
                    </div>
                    <div class="mc-field" style="margin-top:12px;">
                        <label>Karşılama Mesajı</label>
                        <textarea name="marinos_chatbot_welcome_message" rows="2"><?php echo esc_textarea($welcome); ?></textarea>
                    </div>
                    <div class="mc-field" style="margin-top:12px;">
                        <label>Sistem Prompt</label>
                        <textarea name="marinos_chatbot_system_prompt" rows="14" style="font-family:monospace;font-size:12px;"><?php echo esc_textarea($system_prompt); ?></textarea>
                        <p class="desc">whatsapp_yonlendir tetikleyicisini WhatsApp yönlendirmesi için kullanın.</p>
                    </div>
                    <div class="mc-field" style="margin-top:12px;">
                        <label>Bildirim E-postaları</label>
                        <textarea name="marinos_chatbot_email" rows="3" placeholder="info@firma.com&#10;yonetici@firma.com"><?php echo esc_textarea($email); ?></textarea>
                        <p class="desc">Her satıra bir adres. Virgülle de ayrılabilir.</p>
                    </div>
                </div>

                <!-- 2. KARŞILAMA AKIŞI -->
                <div class="mc-section">
                    <h2>Karşılama Akışı</h2>
                    <div class="mc-grid2">
                        <div class="mc-field">
                            <label>Otomatik Aç</label>
                            <select name="marinos_chatbot_auto_open">
                                <option value="1" <?php selected($auto_open,'1'); ?>>Evet — Sayfa açılınca otomatik aç</option>
                                <option value="0" <?php selected($auto_open,'0'); ?>>Hayır — Ziyaretçi tıklayana kadar bekle</option>
                            </select>
                        </div>
                        <div class="mc-field">
                            <label>Pulse Efekti</label>
                            <select name="marinos_chatbot_pulse_enabled">
                                <option value="1" <?php selected($pulse_enabled,'1'); ?>>Aktif</option>
                                <option value="0" <?php selected($pulse_enabled,'0'); ?>>Pasif</option>
                            </select>
                        </div>
                        <div class="mc-field">
                            <label>Karşılama Gecikmesi (saniye)</label>
                            <input type="number" name="marinos_chatbot_greeting_delay" value="<?php echo esc_attr($greeting_delay); ?>" min="0" max="30" style="width:120px;">
                            <p class="desc">Sayfa yüklendikten kaç saniye sonra chatbot açılsın.</p>
                        </div>
                        <div class="mc-field">
                            <label>Yazıyor Animasyonu Süresi (ms)</label>
                            <input type="number" name="marinos_chatbot_typing_duration" value="<?php echo esc_attr($typing_duration); ?>" min="300" max="5000" step="100" style="width:120px;">
                            <p class="desc">Karşılama mesajından önce "yazıyor..." kaç ms görünsün.</p>
                        </div>
                    </div>
                    <div class="mc-field" style="margin-top:12px;">
                        <label>Hızlı Cevap Butonları</label>
                        <textarea name="marinos_chatbot_quick_replies" rows="4" placeholder="Her satıra bir buton yazın:&#10;Fiyatlar&#10;WhatsApp&#10;Ara&#10;Ekonomi"><?php echo esc_textarea($quick_replies); ?></textarea>
                        <p class="desc">Her satır bir buton olur. Boş bırakırsanız buton çıkmaz. Özel komutlar: <code>__whatsapp__</code> ve <code>__phone__</code></p>
                    </div>
                </div>

                <!-- 3. CTA / İLETİŞİM -->
                <div class="mc-section">
                    <h2>CTA / İletişim Butonları</h2>
                    <div class="mc-grid2">
                        <div class="mc-field">
                            <label>WhatsApp Numarası</label>
                            <input type="text" name="marinos_chatbot_whatsapp_number" value="<?php echo esc_attr($whatsapp); ?>" placeholder="905405710707">
                            <p class="desc">Ülke kodu ile, + olmadan.</p>
                        </div>
                        <div class="mc-field">
                            <label>Telefon Numarası</label>
                            <input type="text" name="marinos_chatbot_phone_number" value="<?php echo esc_attr($phone); ?>" placeholder="905405710707">
                        </div>
                        <div class="mc-field">
                            <label>CTA Çubuğu</label>
                            <select name="marinos_chatbot_cta_enabled">
                                <option value="1" <?php selected($cta_enabled,'1'); ?>>Aktif</option>
                                <option value="0" <?php selected($cta_enabled,'0'); ?>>Pasif</option>
                            </select>
                        </div>
                        <div class="mc-field">
                            <label>CTA Stil</label>
                            <select name="marinos_chatbot_cta_style">
                                <option value="icon_only"  <?php selected($cta_style,'icon_only'); ?>>Sadece İkon</option>
                                <option value="icon_text"  <?php selected($cta_style,'icon_text'); ?>>İkon + Yazı</option>
                            </select>
                        </div>
                        <div class="mc-field">
                            <label>CTA Pozisyon</label>
                            <select name="marinos_chatbot_cta_position">
                                <option value="above_input"    <?php selected($cta_position,'above_input'); ?>>Input Üstü</option>
                                <option value="below_messages" <?php selected($cta_position,'below_messages'); ?>>Mesajların Altı</option>
                            </select>
                        </div>
                        <div class="mc-field">
                            <label>CTA Görünürlük</label>
                            <select name="marinos_chatbot_cta_visibility">
                                <option value="always"         <?php selected($cta_visibility,'always'); ?>>Her Zaman</option>
                                <option value="after_first"    <?php selected($cta_visibility,'after_first'); ?>>İlk Mesajdan Sonra</option>
                                <option value="after_inactivity" <?php selected($cta_visibility,'after_inactivity'); ?>>İnaktivite Sonrası</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- 4. GÖRÜNÜM — RENKLER -->
                <div class="mc-section">
                    <h2>Görünüm — Renkler</h2>
                    <div class="mc-grid3">
                        <div class="mc-field">
                            <label>Ana Renk (Toggle Butonu)</label>
                            <input type="text" name="marinos_chatbot_primary_color" value="<?php echo esc_attr($primary_color); ?>" class="mc-color">
                        </div>
                        <div class="mc-field">
                            <label>Header Arka Planı</label>
                            <input type="text" name="marinos_chatbot_header_color" value="<?php echo esc_attr($header_color); ?>" class="mc-color">
                            <p class="desc">Boşsa Ana Renk kullanılır.</p>
                        </div>
                        <div class="mc-field">
                            <label>Gönder Butonu</label>
                            <input type="text" name="marinos_chatbot_send_color" value="<?php echo esc_attr($send_color); ?>" class="mc-color">
                            <p class="desc">Boşsa Ana Renk kullanılır.</p>
                        </div>
                        <div class="mc-field">
                            <label>Mesaj Alanı Arka Planı</label>
                            <input type="text" name="marinos_chatbot_bg_color" value="<?php echo esc_attr($bg_color); ?>" class="mc-color">
                        </div>
                        <div class="mc-field">
                            <label>Kullanıcı Mesaj Balonu</label>
                            <input type="text" name="marinos_chatbot_user_bubble_color" value="<?php echo esc_attr($user_bubble); ?>" class="mc-color">
                            <p class="desc">Boşsa Ana Renk kullanılır.</p>
                        </div>
                        <div class="mc-field" style="grid-column:1/-1;">
                            <label>Bot Avatar Görseli (URL)</label>
                            <input type="text" name="marinos_chatbot_avatar_url" value="<?php echo esc_attr(get_option('marinos_chatbot_avatar_url','')); ?>" placeholder="https://siteniz.com/wp-content/uploads/bot.png" style="width:100%;">
                            <p class="desc">Boş bırakırsanız robot ikonu kullanılır. Kare veya yuvarlak görsel önerilir (min 64x64px).</p>
                        </div>
                    <div class="mc-field">
                            <label>Hızlı Cevap Buton Rengi</label>
                            <input type="text" name="marinos_chatbot_qr_color" value="<?php echo esc_attr($qr_color); ?>" class="mc-color">
                            <p class="desc">Boşsa Ana Renk kullanılır.</p>
                        </div>
                    </div>
                </div>

                <!-- 5. POZİSYON -->
                <div class="mc-section">
                    <h2>Pozisyon</h2>
                    <div class="mc-grid2">
                        <div>
                            <p style="font-weight:600;font-size:13px;margin:0 0 10px;">Masaüstü</p>
                            <div class="mc-grid2">
                                <div class="mc-field">
                                    <label>Sağdan Mesafe</label>
                                    <input type="text" name="marinos_chatbot_desktop_right" value="<?php echo esc_attr($desk_right); ?>" placeholder="24px" style="width:100px;">
                                </div>
                                <div class="mc-field">
                                    <label>Alttan Mesafe</label>
                                    <input type="text" name="marinos_chatbot_desktop_bottom" value="<?php echo esc_attr($desk_bottom); ?>" placeholder="24px" style="width:100px;">
                                </div>
                            </div>
                        </div>
                        <div>
                            <p style="font-weight:600;font-size:13px;margin:0 0 10px;">Mobil</p>
                            <div class="mc-grid2">
                                <div class="mc-field">
                                    <label>Sağdan Mesafe</label>
                                    <input type="text" name="marinos_chatbot_mobile_right" value="<?php echo esc_attr($mob_right); ?>" placeholder="16px" style="width:100px;">
                                </div>
                                <div class="mc-field">
                                    <label>Alttan Mesafe</label>
                                    <input type="text" name="marinos_chatbot_mobile_bottom" value="<?php echo esc_attr($mob_bottom); ?>" placeholder="16px" style="width:100px;">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="mc-field" style="margin-top:12px;">
                        <label>Z-Index</label>
                        <input type="number" name="marinos_chatbot_zindex" value="<?php echo esc_attr($zindex); ?>" style="width:120px;">
                    </div>
                </div>

                <!-- WHATSAPP BİLDİRİMLERİ -->
                <div class="mc-section">
                    <h2>WhatsApp Bildirimleri (UltraMsg)</h2>
                    <div class="mc-grid2">
                        <div class="mc-field">
                            <label>Durum</label>
                            <select name="marinos_chatbot_wa_enabled">
                                <option value="0" <?php selected($wa_enabled,'0'); ?>>Pasif</option>
                                <option value="1" <?php selected($wa_enabled,'1'); ?>>Aktif</option>
                            </select>
                        </div>
                        <div class="mc-field">
                            <label>UltraMsg Instance ID</label>
                            <input type="text" name="marinos_chatbot_wa_instance" value="<?php echo esc_attr($wa_instance); ?>" placeholder="instance12345">
                            <p class="desc">UltraMsg panelindeki Instance ID (ör: instance12345).</p>
                        </div>
                        <div class="mc-field">
                            <label>UltraMsg Token</label>
                            <input type="text" name="marinos_chatbot_wa_token" value="<?php echo esc_attr($wa_token); ?>" placeholder="abcd1234efgh5678">
                            <p class="desc">UltraMsg panelindeki API Token.</p>
                        </div>
                    </div>
                    <div class="mc-field" style="margin-top:12px;">
                        <label>Alıcı Numaraları</label>
                        <textarea name="marinos_chatbot_wa_recipients" rows="3" placeholder="905405710707&#10;905321234567"><?php echo esc_textarea($wa_recipients); ?></textarea>
                        <p class="desc">Her satıra bir numara. Ülke koduyla birlikte, + olmadan (ör: 905405710707). Virgülle de ayrılabilir.</p>
                    </div>
                    <p class="desc" style="margin-top:8px;">
                        <strong>Nasıl çalışır:</strong> E-posta bildirimleriyle birebir aynı içerik — oturum özeti + ziyaretçi bilgisi — bu numaralara WhatsApp mesajı olarak iletilir.
                        <a href="https://ultramsg.com" target="_blank">UltraMsg hesabı</a> gerektirir (ücretsiz deneme mevcut).
                    </p>
                </div>

                <?php submit_button( 'Kaydet' ); ?>

                <?php
                if ( isset( $_GET['marinos_test'] ) && $_GET['marinos_test'] === 'ok' ) {
                    // already shown above
                }
                $test_url = wp_nonce_url( admin_url( 'admin-post.php?action=marinos_test_email' ), 'marinos_test_email' );
                ?>
                <a href="<?php echo esc_url( $test_url ); ?>" class="button" style="margin-left:10px;">Test E-postası Gönder</a>
                <?php
                $test_wa_url = wp_nonce_url( admin_url( 'admin-post.php?action=marinos_test_whatsapp' ), 'marinos_test_whatsapp' );
                ?>
                <a href="<?php echo esc_url( $test_wa_url ); ?>" class="button" style="margin-left:10px;background:#25D366;color:#fff;border-color:#1da851;">Test WhatsApp Gönder</a>
            </form>
        </div>
        <script>
        jQuery(document).ready(function($){
            $('.mc-color').wpColorPicker();
        });
        </script>
        <?php
    }

    public function render_logs_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        global $wpdb;
        $table    = $wpdb->prefix . 'marinos_chatbot_logs';
        $per_page = 20;
        $page     = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
        $offset   = ( $page - 1 ) * $per_page;
        $session_filter = isset( $_GET['session_id'] ) ? sanitize_text_field( $_GET['session_id'] ) : '';

        if ( $session_filter ) {
            $rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE session_id = %s ORDER BY created_at ASC", $session_filter ) );
        } else {
            $sessions = $wpdb->get_results( "SELECT session_id, visitor_ip, visitor_page, MIN(created_at) as started, COUNT(*) as msg_count FROM $table GROUP BY session_id ORDER BY started DESC LIMIT $per_page OFFSET $offset" );
            $total    = $wpdb->get_var( "SELECT COUNT(DISTINCT session_id) FROM $table" );
        }
        ?>
        <div class="wrap">
            <h1>Marinos Chatbot — Konuşma Geçmişi</h1>
            <?php if ( isset( $_GET['deleted'] ) ): ?>
                <div class="notice notice-success is-dismissible"><p>Konuşma silindi.</p></div>
            <?php endif; ?>
            <?php if ( $session_filter ): ?>
                <p>
                    <a href="<?php echo esc_url( admin_url('admin.php?page=marinos-chatbot-logs') ); ?>">&larr; Tüm konuşmalara dön</a>
                    &nbsp;&nbsp;
                    <a href="<?php echo esc_url( wp_nonce_url( admin_url('admin-post.php?action=marinos_delete_session&session_id='.urlencode($session_filter)), 'marinos_delete_'.$session_filter ) ); ?>"
                       onclick="return confirm('Silmek istediğinizden emin misiniz?');"
                       class="button button-secondary" style="color:#cc0000;border-color:#cc0000;">Bu Konuşmayı Sil</a>
                </p>
                <table class="widefat striped">
                    <thead><tr><th>Rol</th><th>Mesaj</th><th>Zaman</th></tr></thead>
                    <tbody>
                    <?php foreach ( $rows as $row ): ?>
                        <tr style="<?php echo $row->role === 'user' ? 'background:#f0f4ff;' : ''; ?>">
                            <td><strong><?php echo $row->role === 'user' ? 'Ziyaretçi' : 'Bot'; ?></strong></td>
                            <td><?php echo nl2br( esc_html( $row->message ) ); ?></td>
                            <td><?php echo esc_html( $row->created_at ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <table class="widefat striped">
                    <thead><tr><th>Tarih</th><th>IP</th><th>Sayfa</th><th>Mesaj</th><th>İşlem</th></tr></thead>
                    <tbody>
                    <?php if ( empty( $sessions ) ): ?>
                        <tr><td colspan="5">Henüz konuşma yok.</td></tr>
                    <?php else: ?>
                        <?php foreach ( $sessions as $s ): ?>
                        <tr>
                            <td><?php echo esc_html($s->started); ?></td>
                            <td><?php echo esc_html($s->visitor_ip); ?></td>
                            <td><?php echo esc_html($s->visitor_page); ?></td>
                            <td><?php echo intval($s->msg_count); ?></td>
                            <td style="white-space:nowrap;">
                                <a href="<?php echo esc_url( admin_url('admin.php?page=marinos-chatbot-logs&session_id='.urlencode($s->session_id)) ); ?>">Görüntüle</a>
                                &nbsp;|&nbsp;
                                <a href="<?php echo esc_url( wp_nonce_url( admin_url('admin-post.php?action=marinos_delete_session&session_id='.urlencode($s->session_id)), 'marinos_delete_'.$s->session_id ) ); ?>"
                                   onclick="return confirm('Silmek istediğinizden emin misiniz?');"
                                   style="color:#cc0000;">Sil</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
                <?php
                $total_pages = ceil( $total / $per_page );
                if ( $total_pages > 1 ) {
                    echo '<div class="tablenav"><div class="tablenav-pages">';
                    echo paginate_links([ 'base' => add_query_arg('paged','%#%'), 'format' => '', 'current' => $page, 'total' => $total_pages ]);
                    echo '</div></div>';
                }
                ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private function default_prompt() {
        return "Sen Marinos Ajans'ın dijital asistanısın. Kısa, net ve samimi yanıt ver.\n"
            . "Kullanıcının dilini algıla ve aynı dilde devam et.\n"
            . "Rezervasyon akışlarında daha önce verilen bilgileri tekrar sorma; eksik bilgileri tek tek topla ve kısa özetle ilerle.\n\n"
            . "Marinos Ajans — Antalya merkezli, 19+ yıllık dijital pazarlama ajansı. Google Partner.\n"
            . "İletişim: 0540 571 07 07 (Abdullah İnan)";
    }
}
