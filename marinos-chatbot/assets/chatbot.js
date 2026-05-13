(function ($) {
    'use strict';

    var cfg            = window.marinosChatbot || {};
    var SK             = cfg.session_key || 'mc_session';
    // Konuşmanın localStorage'da kalma süresi (dk -> ms). Admin'den ayarlanır, varsayılan 30 dk.
    var SESSION_TTL_MS = Math.max(1, parseInt(cfg.session_ttl, 10) || 30) * 60 * 1000;
    var CLEAR_ON_CLOSE = cfg.clear_on_close === '1' || cfg.clear_on_close === 1;
    // Mail tetiklemesi için tarayıcı tarafı idle timer süresi (sn -> ms).
    var MAIL_IDLE_MS   = Math.max(30, parseInt(cfg.mail_idle_sec, 10) || 120) * 1000;
    var mailIdleTimer  = null;
    var mailIdleFired  = false;
    var I18N           = cfg.i18n || {};
    var LANG           = detectLang();
    var T              = I18N[LANG] || I18N.tr || {};
    var isOpen         = false;
    var isWaiting      = false;
    var welcomed       = false;
    var inactivityTimer = null;
    var inactivityFired = false;
    var msgCount       = 0;
    var lastFailedText = null;
    var currentXhr     = null;

    // =============================================
    // Dil tespiti — tarayıcı dilini i18n setine eşle
    // =============================================
    function detectLang() {
        var nav = (navigator.language || navigator.userLanguage || 'tr').toLowerCase().slice(0, 2);
        if (I18N[nav]) return nav;
        if (cfg.site_locale && I18N[cfg.site_locale]) return cfg.site_locale;
        return 'tr';
    }

    function escapeHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    // =============================================
    // localStorage'dan oturumu yükle (sayfa sürekliliği)
    // =============================================
    function loadSession() {
        try {
            var raw = localStorage.getItem(SK);
            if (!raw) return null;
            var data = JSON.parse(raw);
            if (!data.ts || (Date.now() - data.ts) > SESSION_TTL_MS) {
                localStorage.removeItem(SK);
                return null;
            }
            return data;
        } catch (e) { return null; }
    }

    function saveSession(sessionId, history, messages) {
        try {
            // history ve mesajlar son 50 ile sınırlı kalsın (performans)
            var hist = history.slice(-50);
            var msgs = messages.slice(-80);
            localStorage.setItem(SK, JSON.stringify({
                sessionId: sessionId,
                history: hist,
                messages: msgs,
                ts: Date.now()
            }));
        } catch (e) {}
    }

    function clearSession() {
        try { localStorage.removeItem(SK); } catch (e) {}
    }

    var saved     = loadSession();
    var sessionId = saved ? saved.sessionId : ('mc_' + Math.random().toString(36).substr(2, 12) + '_' + Date.now());
    var history   = saved ? (saved.history || []) : [];
    var savedMsgs = saved ? (saved.messages || []) : [];

    // =============================================
    // DOM
    // =============================================
    var $box      = $('#marinos-chat-box');
    var $messages = $('#mc-messages');
    var $input    = $('#mc-input');
    var $send     = $('#mc-send');
    var $waBar    = $('#mc-whatsapp-bar');
    var $minimize = $('#mc-minimize');
    var $pulseDot = $('#mc-pulse-dot');
    var $qrArea   = $('#mc-quick-replies');
    var $ctaBar   = $('#mc-cta-bar');

    applyI18nLabels();

    if (cfg.whatsapp && $('#mc-whatsapp-btn').length) {
        $('#mc-whatsapp-btn').attr('href', 'https://wa.me/' + cfg.whatsapp);
    }

    if ($ctaBar.length) {
        if ((cfg.cta_visibility || 'always') === 'always') {
            $ctaBar.show();
        } else {
            $ctaBar.hide();
        }
    }

    function applyI18nLabels() {
        if (T.placeholder) $input.attr('placeholder', T.placeholder);
        if (T.send) $send.attr('aria-label', T.send);
        if (T.online) $('#mc-online-label').text(T.online);
        if (T.wa_question) $('#mc-wa-question').text(T.wa_question);
        if (T.wa_action) $('#mc-whatsapp-btn').text(T.wa_action);
        if (T.aria_open) $('#marinos-chat-toggle').attr('aria-label', T.aria_open);
        if (T.aria_close) $minimize.attr('aria-label', T.aria_close);
    }

    // =============================================
    // Önceki mesajları yeniden çiz
    // =============================================
    if (savedMsgs.length > 0) {
        welcomed = true;
        msgCount = savedMsgs.filter(function (m) { return m.role === 'user'; }).length;
        $.each(savedMsgs, function (i, m) {
            if (m.role === 'bot') {
                renderBotMsg(m.text);
            } else {
                renderUserMsg(m.text);
            }
        });
        openChat(true);
    } else if (cfg.auto_open === '1') {
        var delay = (parseInt(cfg.greeting_delay, 10) || 1) * 1000;
        setTimeout(function () { openChat(false); }, delay);
    }

    // =============================================
    // Açma / Kapama
    // =============================================
    function openChat(silent) {
        if (isOpen) return;
        isOpen = true;
        $box.removeClass('mc-hidden').addClass('mc-visible');
        $('#mc-icon-open, #mc-avatar-img-toggle').hide();
        $('#mc-icon-close, #mc-icon-close-img').show();
        $pulseDot.hide();

        if (!welcomed) {
            welcomed = true;
            if (silent) {
                // Sessiz açılış
            } else {
                var dur = parseInt(cfg.typing_duration, 10) || 1200;
                showTyping();
                setTimeout(function () {
                    removeTyping();
                    var welcomeText = cfg.welcome || 'Merhaba! Size nasıl yardımcı olabilirim?';
                    appendBotMessage(welcomeText);
                    renderQuickReplies();
                    if ($ctaBar.length && cfg.cta_visibility === 'after_first') {
                        $ctaBar.slideDown(300);
                    }
                    resetInactivityTimer();
                }, dur);
            }
        } else if (savedMsgs.length > 0) {
            renderQuickReplies();
        }

        setTimeout(function () { $input.focus(); }, 80);
    }

    function closeChat() {
        isOpen = false;
        $box.removeClass('mc-visible').addClass('mc-hidden');
        $('#mc-icon-open, #mc-avatar-img-toggle').show();
        $('#mc-icon-close, #mc-icon-close-img').hide();
        clearInactivityTimer();
        clearMailIdleTimer();
        // Kapatınca da bekleyen e-postayı tetikle.
        flushBeacon();
        // Admin "kapatıldığında sil" demişse localStorage'ı temizle.
        if (CLEAR_ON_CLOSE) {
            clearSession();
            history = [];
            savedMsgs = [];
            welcomed = false;
            msgCount = 0;
            $messages.empty();
            $qrArea.empty().hide();
            $waBar.hide();
        }
    }

    $('#marinos-chat-toggle').on('click', function () {
        if (isOpen) closeChat(); else openChat(false);
    });
    $minimize.on('click', closeChat);

    // =============================================
    // Quick Replies
    // =============================================
    function renderQuickReplies() {
        if (!cfg.quick_replies || !cfg.quick_replies.length) return;
        $qrArea.empty();
        $.each(cfg.quick_replies, function (i, label) {
            if (!label) return;
            var displayLabel = label;
            if (label === '__whatsapp__') displayLabel = T.wa_action || 'WhatsApp';
            if (label === '__phone__') displayLabel = T.online ? 'Telefon' : 'Phone';
            var $btn = $('<button class="mc-qr-btn"></button>').text(displayLabel);
            $btn.on('click', function () {
                if (label === '__whatsapp__' && cfg.whatsapp) {
                    window.open('https://wa.me/' + cfg.whatsapp, '_blank'); return;
                }
                if (label === '__phone__' && cfg.phone) {
                    window.location.href = 'tel:+' + cfg.phone; return;
                }
                $input.val(label);
                sendMessage();
                $qrArea.slideUp(200);
            });
            $qrArea.append($btn);
        });
        $qrArea.show();
    }

    // =============================================
    // İnaktivite
    // =============================================
    function resetInactivityTimer() {
        clearInactivityTimer();
        if (inactivityFired || msgCount === 0) return;
        inactivityTimer = setTimeout(function () {
            if (!isWaiting && isOpen && !inactivityFired) {
                inactivityFired = true;
                if ($ctaBar.length && cfg.cta_visibility === 'after_inactivity') {
                    $ctaBar.slideDown(300);
                }
            }
        }, 30000);
    }
    function clearInactivityTimer() {
        if (inactivityTimer) { clearTimeout(inactivityTimer); inactivityTimer = null; }
    }

    // =============================================
    // Mail Idle Timer — sohbette X saniye yeni mesaj olmazsa
    // tarayıcı tarafından sunucuya "flush" tetikle.
    // Bu, WP-Cron veya sayfa kapanma beacon'ından bağımsız çalışır.
    // =============================================
    function resetMailIdleTimer() {
        if (mailIdleTimer) { clearTimeout(mailIdleTimer); mailIdleTimer = null; }
        if (msgCount === 0) return; // Hiç mesaj yoksa anlamı yok.
        mailIdleFired = false;
        mailIdleTimer = setTimeout(function () {
            if (isWaiting) {
                // Cevap beklenirken tetiklemeyelim, cevap gelince tekrar başlatılır.
                return;
            }
            mailIdleFired = true;
            triggerMailFlush();
        }, MAIL_IDLE_MS);
    }

    function clearMailIdleTimer() {
        if (mailIdleTimer) { clearTimeout(mailIdleTimer); mailIdleTimer = null; }
    }

    function triggerMailFlush() {
        if (!sessionId || msgCount === 0) return;
        try {
            $.ajax({
                url: cfg.ajax_url,
                method: 'POST',
                data: {
                    action:     'marinos_flush',
                    nonce:      cfg.nonce,
                    session_id: sessionId,
                    page_url:   cfg.page_url || ''
                }
            });
        } catch (e) {}
    }

    // =============================================
    // Input
    // =============================================
    var lastSendAt = 0;
    $send.on('click', function (e) {
        if (e && e.preventDefault) e.preventDefault();
        sendMessage();
    });
    $input.on('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            // Klavye repeat / hızlı çift Enter koruması — "7" yerine "77" gitmesini önler.
            if (e.repeat) return;
            sendMessage();
        }
    });
    $input.on('input', function () {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 100) + 'px';
        resetInactivityTimer();
        // Kullanıcı yazıyor → idle timer'ı sıfırla; aktif olduğu sürece mail gönderme.
        clearMailIdleTimer();
    });

    // =============================================
    // Gönder
    // =============================================
    function sendMessage() {
        var text = $.trim($input.val());
        if (!text || isWaiting) return;
        // 400 ms throttle: çift tıklama / Enter+Click yarış durumlarını engelle.
        var now = Date.now();
        if (now - lastSendAt < 400) return;
        lastSendAt = now;
        if (text.length > 4000) text = text.slice(0, 4000);

        // Önce kilitle, sonra DOM oynat — race ortadan kalkar.
        isWaiting = true;
        $send.prop('disabled', true);

        msgCount++;
        $qrArea.slideUp(200);
        appendUserMessage(text);
        history.push({ role: 'user', text: text });
        $input.val('').css('height', 'auto');
        clearInactivityTimer();
        showTyping();

        if ($ctaBar.length && cfg.cta_visibility === 'after_first' && msgCount === 1) {
            $ctaBar.slideDown(300);
        }

        doRequest(text, 0);
    }

    function doRequest(text, attempt) {
        if (currentXhr && currentXhr.abort) {
            try { currentXhr.abort(); } catch (e) {}
        }
        currentXhr = $.ajax({
            url: cfg.ajax_url,
            method: 'POST',
            timeout: 60000,
            data: {
                action:     'marinos_chat',
                nonce:      cfg.nonce,
                message:    text,
                session_id: sessionId,
                page_url:   cfg.page_url,
                lang:       LANG,
                history:    buildHistory()
            },
            success: function (res) {
                removeTyping();
                if (res && res.success) {
                    var reply = (res.data && res.data.reply) ? res.data.reply : '';
                    if (!reply) reply = T.error_generic || 'Bir hata oluştu.';
                    appendBotMessage(reply);
                    history.push({ role: 'model', text: reply });
                    if (res.data && res.data.whatsapp === true && cfg.whatsapp) {
                        $waBar.hide().slideDown(200);
                    }
                    lastFailedText = null;
                } else {
                    handleFailure(text, attempt, 'server');
                }
            },
            error: function (xhr, status) {
                removeTyping();
                // Tarayıcı navigasyonu/abort nedeniyle iptal → tekrar deneme.
                if (status === 'abort') return;
                handleFailure(text, attempt, status || 'network');
            },
            complete: function () {
                currentXhr = null;
                isWaiting = false;
                $send.prop('disabled', false);
                $input.focus();
                resetInactivityTimer();
            }
        });
    }

    function handleFailure(text, attempt, reason) {
        // İlk hatada otomatik tek bir retry (kısa back-off ile).
        if (attempt < 1) {
            showTyping();
            isWaiting = true;
            $send.prop('disabled', true);
            setTimeout(function () { doRequest(text, attempt + 1); }, 800);
            return;
        }
        lastFailedText = text;
        var msg = (reason === 'timeout' || reason === 'network')
            ? (T.error_network || 'Bağlantı sorunu oluştu.')
            : (T.error_generic || 'Bir hata oluştu.');
        appendBotMessage(msg);
        renderRetryButton();
    }

    function renderRetryButton() {
        $qrArea.empty();
        var $btn = $('<button class="mc-qr-btn"></button>').text(T.retry || 'Tekrar dene');
        $btn.on('click', function () {
            if (!lastFailedText) return;
            $qrArea.slideUp(150);
            $send.prop('disabled', true);
            isWaiting = true;
            showTyping();
            doRequest(lastFailedText, 0);
        });
        $qrArea.append($btn).show();
    }

    // =============================================
    // Beacon — sayfa kapatılırken e-postayı anında flush et
    // =============================================
    function flushBeacon() {
        if (!sessionId) return;
        try {
            if (navigator.sendBeacon) {
                var fd = new FormData();
                fd.append('action', 'marinos_flush');
                fd.append('nonce', cfg.nonce);
                fd.append('session_id', sessionId);
                fd.append('page_url', cfg.page_url || '');
                navigator.sendBeacon(cfg.ajax_url, fd);
            } else {
                $.ajax({
                    url: cfg.ajax_url, method: 'POST', async: false,
                    data: {
                        action: 'marinos_flush', nonce: cfg.nonce,
                        session_id: sessionId, page_url: cfg.page_url || ''
                    }
                });
            }
        } catch (e) {}
    }

    // Yalnızca konuşmada en az 1 mesaj olduysa flush gönder.
    function maybeFlush() {
        if (msgCount > 0) flushBeacon();
    }

    window.addEventListener('pagehide', maybeFlush);
    window.addEventListener('beforeunload', maybeFlush);
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') maybeFlush();
    });

    // =============================================
    // Mesaj DOM + localStorage güncelleme
    // =============================================
    function appendBotMessage(text) {
        renderBotMsg(text);
        savedMsgs.push({ role: 'bot', text: text });
        saveSession(sessionId, history, savedMsgs);
        scrollBottom();
        // Bot cevabı geldi → idle sayacı baştan başlasın.
        resetMailIdleTimer();
    }

    function appendUserMessage(text) {
        renderUserMsg(text);
        savedMsgs.push({ role: 'user', text: text });
        saveSession(sessionId, history, savedMsgs);
        scrollBottom();
        // Kullanıcı yazdı → bot cevabı gelene kadar tetiklenmesin diye timer'ı temizle.
        clearMailIdleTimer();
    }

    function renderBotMsg(text) {
        var $msg = $('<div class="mc-msg bot"></div>');
        // XSS koruması: önce escape, sonra newline → <br>
        $msg.html(escapeHtml(text).replace(/\n/g, '<br>'));
        $messages.append($msg);
    }

    function renderUserMsg(text) {
        var $msg = $('<div class="mc-msg user"></div>').text(text);
        $messages.append($msg);
    }

    function showTyping() {
        if ($('#mc-typing-indicator').length) return;
        $messages.append('<div class="mc-typing" id="mc-typing-indicator"><span></span><span></span><span></span></div>');
        scrollBottom();
    }
    function removeTyping() { $('#mc-typing-indicator').remove(); }
    function scrollBottom() {
        if ($messages.length && $messages[0]) {
            $messages.scrollTop($messages[0].scrollHeight);
        }
    }
    function buildHistory() {
        return history.slice(-10).map(function (i) { return { role: i.role, text: i.text }; });
    }

})(jQuery);
