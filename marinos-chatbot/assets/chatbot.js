(function ($) {
    'use strict';

    var cfg            = window.marinosChatbot || {};
    var SK             = cfg.session_key || 'mc_session';  // localStorage anahtarı
    var AO_KEY         = SK + '_auto_open_dismissed';
    var SESSION_TTL_MS = 5 * 60 * 1000; // 5 dakika
    var isOpen         = false;
    var isWaiting      = false;
    var welcomed       = false;
    var inactivityTimer = null;
    var inactivityFired = false;
    var msgCount       = 0;

    // =============================================
    // localStorage'dan oturumu yükle (sayfa sürekliliği)
    // =============================================
    function loadSession() {
        try {
            var raw = localStorage.getItem(SK);
            if (!raw) return null;
            var data = JSON.parse(raw);
            // 5 dakikadan eski ise temizle
            if (!data.ts || (Date.now() - data.ts) > SESSION_TTL_MS) {
                localStorage.removeItem(SK);
                localStorage.removeItem(AO_KEY);
                return null;
            }
            return data;
        } catch(e) { return null; }
    }

    function saveSession(sessionId, history, messages) {
        try {
            localStorage.setItem(SK, JSON.stringify({
                sessionId: sessionId,
                history: history,
                messages: messages,
                ts: Date.now()
            }));
        } catch(e) {}
    }

    function clearSession() {
        try { localStorage.removeItem(SK); } catch(e) {}
    }

    // Oturumu yükle ya da yeni oluştur
    var saved     = loadSession();
    var sessionId = saved ? saved.sessionId : ('mc_' + Math.random().toString(36).substr(2,12) + '_' + Date.now());
    var history   = saved ? (saved.history || []) : [];
    var savedMsgs = saved ? (saved.messages || []) : [];
    var autoOpenDismissed = hasAutoOpenDismissed();

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

    // WhatsApp btn
    if (cfg.whatsapp && $('#mc-whatsapp-btn').length) {
        $('#mc-whatsapp-btn').attr('href', 'https://wa.me/' + cfg.whatsapp);
    }

    // CTA görünürlük başlangıç
    if ($ctaBar.length) {
        if ((cfg.cta_visibility || 'always') === 'always') {
            $ctaBar.show();
        } else {
            $ctaBar.hide();
        }
    }

    // =============================================
    // Önceki mesajları yeniden çiz
    // =============================================
    if (savedMsgs.length > 0) {
        welcomed = true;
        msgCount = savedMsgs.filter(function(m){ return m.role === 'user'; }).length;
        $.each(savedMsgs, function(i, m) {
            if (m.role === 'bot') {
                renderBotMsg(m.text);
            } else {
                renderUserMsg(m.text);
            }
        });
        // Önceki konuşma varsa chatı açık başlat (kullanıcı daha önce kapatmadıysa)
        if (!(cfg.auto_open_once_after_close === '1' && autoOpenDismissed)) {
            openChat(true); // silent=true, yazıyor animasyonu olmasın
        }
    } else if (cfg.auto_open === '1' && !(cfg.auto_open_once_after_close === '1' && autoOpenDismissed)) {
        // Önceki konuşma yok, ayara göre otomatik aç
        var delay = (parseInt(cfg.greeting_delay) || 1) * 1000;
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
                // Sessiz açılış — önceki konuşma var
            } else {
                var dur = parseInt(cfg.typing_duration) || 1200;
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
        if (cfg.auto_open_once_after_close === '1') {
            setAutoOpenDismissed(true);
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
        $.each(cfg.quick_replies, function(i, label) {
            if (!label) return;
            var $btn = $('<button class="mc-qr-btn"></button>').text(label);
            $btn.on('click', function() {
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
    // Input
    // =============================================
    $send.on('click', sendMessage);
    $input.on('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
    });
    $input.on('input', function() {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 100) + 'px';
        resetInactivityTimer();
    });

    // =============================================
    // Gönder
    // =============================================
    function sendMessage() {
        var text = $.trim($input.val());
        if (!text || isWaiting) return;

        msgCount++;
        $qrArea.slideUp(200);
        appendUserMessage(text);
        history.push({ role: 'user', text: text });
        $input.val('').css('height', 'auto');
        $send.prop('disabled', true);
        isWaiting = true;
        clearInactivityTimer();
        showTyping();

        if ($ctaBar.length && cfg.cta_visibility === 'after_first' && msgCount === 1) {
            $ctaBar.slideDown(300);
        }

        $.ajax({
            url: cfg.ajax_url,
            method: 'POST',
            data: {
                action:     'marinos_chat',
                nonce:      cfg.nonce,
                message:    text,
                session_id: sessionId,
                page_url:   cfg.page_url,
                history:    buildHistory(),
            },
            success: function(res) {
                removeTyping();
                if (res.success) {
                    var reply = res.data.reply;
                    appendBotMessage(reply);
                    history.push({ role: 'model', text: reply });
                    if (res.data.whatsapp === true && cfg.whatsapp) {
                        $waBar.hide().slideDown(200);
                    }
                } else {
                    appendBotMessage('Bir hata oluştu, lütfen tekrar deneyin.');
                }
            },
            error: function() {
                removeTyping();
                appendBotMessage('Bağlantı hatası oluştu.');
            },
            complete: function() {
                isWaiting = false;
                $send.prop('disabled', false);
                $input.focus();
                resetInactivityTimer();
            }
        });
    }

    // =============================================
    // Mesaj DOM + localStorage güncelleme
    // =============================================
    function appendBotMessage(text) {
        renderBotMsg(text);
        savedMsgs.push({ role: 'bot', text: text });
        saveSession(sessionId, history, savedMsgs);
        scrollBottom();
    }

    function appendUserMessage(text) {
        renderUserMsg(text);
        savedMsgs.push({ role: 'user', text: text });
        saveSession(sessionId, history, savedMsgs);
        scrollBottom();
    }

    function renderBotMsg(text) {
        var $msg = $('<div class="mc-msg bot"></div>');
        $msg.html(text.replace(/\n/g, '<br>'));
        $messages.append($msg);
    }

    function renderUserMsg(text) {
        var $msg = $('<div class="mc-msg user"></div>').text(text);
        $messages.append($msg);
    }

    function showTyping() {
        $messages.append('<div class="mc-typing" id="mc-typing-indicator"><span></span><span></span><span></span></div>');
        scrollBottom();
    }
    function removeTyping() { $('#mc-typing-indicator').remove(); }
    function scrollBottom() { $messages.scrollTop($messages[0].scrollHeight); }
    function buildHistory() {
        var recent = history.slice(-20);
        var normalized = [];

        $.each(recent, function(_, item) {
            if (!item || !item.text || !item.role) return;
            var text = $.trim(item.text);
            if (!text) return;

            var prev = normalized.length ? normalized[normalized.length - 1] : null;
            if (prev && prev.role === item.role && prev.text.toLowerCase() === text.toLowerCase()) {
                return;
            }
            normalized.push({ role: item.role, text: text });
        });

        return normalized;
    }

    function hasAutoOpenDismissed() {
        try {
            return localStorage.getItem(AO_KEY) === '1';
        } catch(e) {
            return false;
        }
    }

    function setAutoOpenDismissed(value) {
        try {
            if (value) {
                localStorage.setItem(AO_KEY, '1');
            } else {
                localStorage.removeItem(AO_KEY);
            }
        } catch(e) {}
    }

})(jQuery);
