(function () {
    'use strict';

    var config = window.clielo || {};
    var POLL_INTERVAL = 5000;
    var lastMessageId = 0;
    var pollTimer = null;
    var hasLoaded = false;
    var unreadCount = 0;

    var $container = document.getElementById('clielo-container');
    var $messages  = document.getElementById('clielo-messages');
    var $form      = document.getElementById('clielo-form');
    var $input     = document.getElementById('clielo-input');
    var $send      = document.getElementById('clielo-send');
    var $badge     = document.getElementById('clielo-badge');

    if (!$container || !$messages) return;

    // Observer quand le popup s'ouvre pour charger les messages
    var observer = new MutationObserver(function () {
        if ($container.style.display === 'flex') {
            unreadCount = 0;
            updateBadge();
            if (!hasLoaded) {
                hasLoaded = true;
                loadMessages();
            } else {
                scrollToBottom();
            }
            if ($input) $input.focus();
        }
    });
    observer.observe($container, { attributes: true, attributeFilter: ['style'] });

    // Form
    if ($form && $input && $send) {
        $form.addEventListener('submit', handleSend);
        $input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                $form.dispatchEvent(new Event('submit'));
            }
        });
        $input.addEventListener('input', function () {
            $input.style.height = 'auto';
            $input.style.height = Math.min($input.scrollHeight, 120) + 'px';
        });
    }

    startPolling();

    // ─── Load ───────────────────────────────────────────────
    function loadMessages() {
        fetch(config.ajax_url + '?' + new URLSearchParams({
            action: 'clielo_load',
            post_id: config.post_id,
            nonce: config.nonce
        }))
        .then(function (r) { return r.json(); })
        .then(function (res) {
            $messages.innerHTML = '';
            if (!res.success || !res.data || res.data.length === 0) {
                $messages.innerHTML = '<div class="clielo-empty">' + escapeHtml(config.i18n.empty) + '</div>';
                return;
            }
            res.data.forEach(function (msg) { appendMessage(msg); });
            scrollToBottom();
        })
        .catch(function () {
            $messages.innerHTML = '<div class="clielo-empty">' + escapeHtml(config.i18n.error) + '</div>';
        });
    }

    // ─── Send ───────────────────────────────────────────────
    function handleSend(e) {
        e.preventDefault();
        var message = $input.value.trim();
        if (!message) return;
        $send.disabled = true;

        var fd = new FormData();
        fd.append('action', 'clielo_send');
        fd.append('post_id', config.post_id);
        fd.append('nonce', config.nonce);
        fd.append('message', message);

        fetch(config.ajax_url, { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res.success) {
                removeEmpty();
                res.data.is_mine = true;
                appendMessage(res.data);
                scrollToBottom();
                $input.value = '';
                $input.style.height = 'auto';
            }
        })
        .finally(function () {
            $send.disabled = false;
            $input.focus();
        });
    }

    // ─── Polling ────────────────────────────────────────────
    function startPolling() {
        if (pollTimer) clearInterval(pollTimer);
        pollTimer = setInterval(pollNewMessages, POLL_INTERVAL);
    }

    function pollNewMessages() {
        fetch(config.ajax_url + '?' + new URLSearchParams({
            action: 'clielo_poll',
            post_id: config.post_id,
            last_id: lastMessageId,
            nonce: config.nonce
        }))
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res.success || !res.data) return;
            var pollMsgs = res.data.messages || res.data;
            if (!pollMsgs || pollMsgs.length === 0) return;

            var chatOpen = $container.style.display === 'flex';

            if (!hasLoaded) {
                pollMsgs.forEach(function (msg) {
                    lastMessageId = Math.max(lastMessageId, parseInt(msg.id));
                    if (parseInt(msg.user_id) !== parseInt(config.user_id)) unreadCount++;
                });
                updateBadge();
                return;
            }

            removeEmpty();
            pollMsgs.forEach(function (msg) {
                if (!document.querySelector('[data-msg-id="' + msg.id + '"]')) {
                    appendMessage(msg);
                    if (parseInt(msg.user_id) !== parseInt(config.user_id) && !chatOpen) unreadCount++;
                }
            });
            updateBadge();
            if (chatOpen) scrollToBottom();
        });
    }

    // ─── Message ────────────────────────────────────────────
    function appendMessage(msg) {
        var isMine = msg.is_mine || (parseInt(msg.user_id) === parseInt(config.user_id));
        var side = isMine ? 'mine' : 'other';

        var el = document.createElement('div');
        el.className = 'clielo-message clielo-message--' + side;
        el.setAttribute('data-msg-id', msg.id);

        el.innerHTML =
            '<div class="clielo-avatar"><img src="' + escapeHtml(msg.avatar) + '" alt="' + escapeHtml(msg.display_name) + '"></div>' +
            '<div class="clielo-bubble-wrap">' +
                (!isMine ? '<div class="clielo-username">' + escapeHtml(msg.display_name) + '</div>' : '') +
                '<div class="clielo-bubble">' + escapeHtml(msg.message) + '</div>' +
                '<div class="clielo-time">' + formatTime(msg.created_at) + '</div>' +
            '</div>';

        $messages.appendChild(el);
        lastMessageId = Math.max(lastMessageId, parseInt(msg.id));
    }

    // ─── Badge ──────────────────────────────────────────────
    function updateBadge() {
        if (!$badge) return;
        var chatOpen = $container.style.display === 'flex';
        if (unreadCount > 0 && !chatOpen) {
            $badge.textContent = unreadCount > 9 ? '9+' : unreadCount;
            $badge.style.display = 'flex';
        } else {
            $badge.style.display = 'none';
            unreadCount = 0;
        }
    }

    // ─── Helpers ────────────────────────────────────────────
    function scrollToBottom() { $messages.scrollTop = $messages.scrollHeight; }
    function removeEmpty() { var el = $messages.querySelector('.clielo-empty'); if (el) el.remove(); }

    function formatTime(s) {
        if (!s) return '';
        var d = new Date(s.replace(' ', 'T'));
        return d.getHours().toString().padStart(2, '0') + ':' + d.getMinutes().toString().padStart(2, '0');
    }

    function escapeHtml(s) {
        if (!s) return '';
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }
})();
