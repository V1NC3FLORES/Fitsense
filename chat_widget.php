<?php
// chat_widget.php — Reusable chat panel for all pages
// Usage: <?php include 'chat_widget.php'; ?>
if (!isset($userId)) {
    $userId = (int)($_SESSION['user_id'] ?? 0);
}
?>

<!-- ============================================
     CHAT OVERLAY + PANEL
     ============================================ -->
<div class="chat-overlay" id="chatOverlay" onclick="closeChatPanel()"></div>

<div class="chat-panel" id="chatPanel">
    <div class="chat-panel-header">
        <h3><i class="bi bi-chat-dots-fill"></i> Chat</h3>
        <div class="chat-search">
            <i class="bi bi-search" style="font-size: 14px;"></i>
            <input type="text" id="chatSearchInput" placeholder="Search conversations..." oninput="filterContacts(this.value)">
        </div>
        <button class="chat-close-btn" onclick="closeChatPanel()">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>

    <div class="chat-panel-body">
        <div class="chat-sidebar">
            <div class="chat-filter-tabs">
                <div class="chat-filter-tab active" onclick="selectChatFilter(this, 'all')" data-filter="all">
                    <i class="bi bi-collection"></i> All
                </div>
                <div class="chat-filter-tab" onclick="selectChatFilter(this, 'friends')" data-filter="friends">
                    <i class="bi bi-people"></i> Members
                </div>
                <div class="chat-filter-tab" onclick="selectChatFilter(this, 'admin')" data-filter="admin">
                    <i class="bi bi-shield-lock"></i> Admin
                </div>
            </div>

            <div class="chat-divider"></div>

            <div class="chat-contacts-list" id="chatContactsList">
                <div class="chat-contact-item active"
                     onclick="selectChatContact(this, 'community', 'Gym Community')"
                     data-type="community"
                     data-name="gym community">
                    <span class="contact-avatar">
                        <span style="width: 32px; height: 32px; border-radius: 50%; background: linear-gradient(135deg, #005b9f, #0084d1); color: white; display: inline-flex; align-items: center; justify-content: center; font-size: 14px;">
                            <i class="bi bi-people-fill"></i>
                        </span>
                    </span>
                    <span class="contact-name">Gym Community</span>
                </div>

                <div id="liveContactsLoader" style="padding: 12px; text-align: center; color: #9ca3af; font-size: 12px;">
                    <i class="bi bi-arrow-clockwise" style="display:inline-block; animation: spin 1s linear infinite;"></i> Loading...
                </div>
            </div>
        </div>

        <div class="chat-main">
            <div class="chat-main-header">
                <h4 id="chatRoomTitle">Gym Community</h4>
                <p id="chatRoomSubtitle">Public chat room - All members</p>
            </div>

            <div class="chat-messages" id="chatMessages">
                <div style="text-align: center; padding: 30px; color: #9ca3af; font-size: 13px;">
                    <i class="bi bi-arrow-clockwise" style="font-size: 24px; display: block; margin-bottom: 8px; animation: spin 1s linear infinite;"></i>
                    Loading messages...
                </div>
            </div>

            <div class="chat-input-area">
                <button class="emoji-btn" type="button" title="Insert emoji" onclick="insertEmoji('😊')">
                    <i class="bi bi-emoji-smile" style="color: var(--text-muted); font-size: 20px;"></i>
                </button>
                <input type="text" id="chatMessageInput" placeholder="Type a message..."
                       maxlength="2000"
                       onkeypress="if(event.key==='Enter' && !event.shiftKey) { event.preventDefault(); sendChatMessage(); }">
                <button class="chat-send-btn" onclick="sendChatMessage()" id="chatSendBtn">
                    <i class="bi bi-send-fill"></i>
                </button>
            </div>
        </div>
    </div>
</div>

<style>
    /* ============================================
       CHAT PANEL STYLES (self-contained)
       ============================================ */
    @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

    .chat-overlay {
        position: fixed; inset: 0;
        background: rgba(0,0,0,0.5);
        opacity: 0; visibility: hidden;
        transition: all 0.3s ease;
        z-index: 1999;
    }
    .chat-overlay.open { opacity: 1; visibility: visible; }

    .chat-panel {
        position: fixed; top: 0; right: 0;
        width: 900px; max-width: 95vw; height: 100vh;
        background: #fff;
        box-shadow: -10px 0 40px rgba(0,0,0,0.15);
        transform: translateX(100%);
        transition: transform 0.35s cubic-bezier(0.4, 0, 0.2, 1);
        z-index: 2000;
        display: flex; flex-direction: column;
    }
    .chat-panel.open { transform: translateX(0); }

    .chat-panel-header {
        background: linear-gradient(135deg, #005b9f 0%, #0084d1 100%);
        color: white; padding: 18px 22px;
        display: flex; justify-content: space-between; align-items: center;
        flex-shrink: 0;
    }
    .chat-panel-header h3 {
        margin: 0; font-size: 18px; font-weight: 700;
        display: flex; align-items: center; gap: 10px;
    }
    .chat-search {
        display: flex; align-items: center; gap: 8px;
        background: rgba(255,255,255,0.15);
        padding: 8px 14px; border-radius: 20px;
        margin-left: 20px; flex: 1; max-width: 300px;
    }
    .chat-search input {
        background: none; border: none; color: white;
        outline: none; width: 100%; font-size: 13px;
    }
    .chat-search input::placeholder { color: rgba(255,255,255,0.7); }

    .chat-close-btn {
        background: rgba(255,255,255,0.15); border: none; color: white;
        width: 36px; height: 36px; border-radius: 50%;
        cursor: pointer; display: flex; align-items: center;
        justify-content: center; font-size: 18px; transition: 0.2s;
    }
    .chat-close-btn:hover { background: rgba(255,255,255,0.3); transform: rotate(90deg); }

    .chat-panel-body { display: flex; flex: 1; overflow: hidden; }

    .chat-sidebar {
        width: 240px; background: #f9fafb;
        border-right: 1px solid #e5e7eb;
        overflow-y: auto; flex-shrink: 0;
    }

    .chat-filter-tabs { padding: 15px 15px 5px; }
    .chat-filter-tab {
        display: flex; align-items: center; gap: 10px;
        padding: 9px 12px; border-radius: 8px;
        cursor: pointer; font-size: 13px; font-weight: 600;
        color: #6b7280; margin-bottom: 2px; transition: 0.2s;
    }
    .chat-filter-tab:hover { background: #e5e7eb; color: #333; }
    .chat-filter-tab.active { background: rgba(0,91,159,0.1); color: #005b9f; }

    .chat-divider { height: 1px; background: #e5e7eb; margin: 12px 15px; }
    .chat-contacts-list { padding: 0 8px 15px; }

    .chat-contact-item {
        display: flex; align-items: center; gap: 10px;
        padding: 10px 12px; border-radius: 8px;
        cursor: pointer; font-size: 13px; transition: 0.2s;
        margin-bottom: 2px;
    }
    .chat-contact-item:hover { background: #e5e7eb; }
    .chat-contact-item.active {
        background: rgba(0,91,159,0.1);
        border-left: 3px solid #005b9f;
    }
    .chat-contact-item .contact-avatar { font-size: 16px; flex-shrink: 0; }
    .chat-contact-item .contact-name {
        font-weight: 600; color: #333; flex: 1;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .chat-contact-item .unread-tag {
        background: #005b9f; color: white;
        font-size: 10px; font-weight: 700;
        padding: 2px 7px; border-radius: 10px; flex-shrink: 0;
    }
    .chat-contact-item .contact-avatar img {
        width: 32px; height: 32px; border-radius: 50%; object-fit: cover;
    }
    .chat-contact-item .contact-avatar .initials {
        width: 32px; height: 32px; border-radius: 50%;
        display: inline-flex; align-items: center; justify-content: center;
        font-size: 12px; font-weight: 800; color: white;
        background: linear-gradient(135deg, #005b9f, #0084d1);
    }
    .chat-contact-item .contact-avatar .initials.admin {
        background: linear-gradient(135deg, #ffce00, #ffb800); color: #333;
    }
    .chat-contact-item .online-dot {
        width: 8px; height: 8px; background: #4ade80;
        border-radius: 50%; display: inline-block; margin-left: 4px;
    }

    .chat-main {
        flex: 1; display: flex; flex-direction: column;
        background: #fff; min-width: 0;
    }
    .chat-main-header {
        padding: 15px 22px; border-bottom: 1px solid #e5e7eb;
        background: #fff; flex-shrink: 0;
    }
    .chat-main-header h4 { margin: 0; font-size: 15px; color: #005b9f; font-weight: 700; }
    .chat-main-header p { margin: 2px 0 0; font-size: 12px; color: #6b7280; }

    .chat-messages {
        flex: 1; padding: 20px 22px;
        overflow-y: auto; background: #fafbfc;
        display: flex; flex-direction: column; gap: 14px;
    }

    .chat-message-item { display: flex; flex-direction: column; gap: 4px; }
    .chat-message-sender {
        font-size: 12px; font-weight: 700; color: #005b9f;
        display: flex; align-items: center; gap: 6px;
    }
    .chat-message-text {
        background: #fff; padding: 10px 14px;
        border-radius: 12px; border: 1px solid #e5e7eb;
        font-size: 14px; line-height: 1.5; max-width: 85%;
        box-shadow: 0 1px 3px rgba(0,0,0,0.03);
        word-wrap: break-word;
    }
    .chat-message-item.self { align-items: flex-end; }
    .chat-message-item.self .chat-message-sender { color: #2e7d32; flex-direction: row-reverse; }
    .chat-message-item.self .chat-message-text {
        background: linear-gradient(135deg, #005b9f, #0084d1);
        color: white; border-color: transparent;
    }
    .chat-message-time { font-size: 10px; color: #6b7280; margin-top: 2px; }

    .chat-message-item .msg-avatar {
        width: 28px; height: 28px; border-radius: 50%;
        object-fit: cover; margin-right: 6px;
    }
    .chat-message-item .msg-avatar-placeholder {
        width: 28px; height: 28px; border-radius: 50%;
        background: linear-gradient(135deg, #005b9f, #0084d1);
        color: white; display: inline-flex; align-items: center;
        justify-content: center; font-size: 11px; font-weight: 800;
        margin-right: 6px;
    }
    .chat-message-item .msg-avatar-placeholder.admin {
        background: linear-gradient(135deg, #ffce00, #ffb800); color: #333;
    }
    .chat-message-item.self .msg-avatar-placeholder {
        background: linear-gradient(135deg, #2e7d32, #4ade80);
    }
    .chat-message-item.self .chat-message-sender { flex-direction: row-reverse; }

    .chat-date-separator { text-align: center; margin: 12px 0; position: relative; }
    .chat-date-separator span {
        background: #f0f2f6; padding: 3px 12px;
        border-radius: 12px; font-size: 11px; color: #6b7280;
        font-weight: 700; position: relative; z-index: 1;
    }
    .chat-date-separator::before {
        content: ''; position: absolute; left: 0; right: 0;
        top: 50%; height: 1px; background: #e5e7eb; z-index: 0;
    }

    .chat-input-area {
        padding: 15px 22px; border-top: 1px solid #e5e7eb;
        background: #fff; display: flex; align-items: center;
        gap: 12px; flex-shrink: 0;
    }
    .emoji-btn {
        background: none; border: none; font-size: 20px;
        cursor: pointer; padding: 5px; border-radius: 6px; transition: 0.2s;
    }
    .emoji-btn:hover { background: #f3f4f6; }
    .chat-input-area input {
        flex: 1; padding: 11px 16px;
        border: 1px solid #e5e7eb; border-radius: 22px;
        outline: none; font-size: 14px; transition: 0.2s;
    }
    .chat-input-area input:focus {
        border-color: #005b9f;
        box-shadow: 0 0 0 3px rgba(0,91,159,0.1);
    }
    .chat-send-btn {
        background: linear-gradient(135deg, #005b9f, #0084d1);
        color: white; border: none; width: 42px; height: 42px;
        border-radius: 50%; cursor: pointer;
        display: flex; align-items: center; justify-content: center;
        font-size: 16px; transition: 0.2s; flex-shrink: 0;
    }
    .chat-send-btn:hover { transform: scale(1.08); }
    .chat-send-btn:disabled { opacity: 0.6; cursor: wait; }

    @media (max-width: 768px) {
        .chat-sidebar { width: 200px; }
        .chat-panel { width: 100%; }
    }
    @media (max-width: 576px) {
        .chat-sidebar { display: none; }
    }
</style>

<script>
// ============================================
// CHAT WIDGET — Shared across all pages
// ============================================
(function() {
    if (window.__chatWidgetLoaded) return;
    window.__chatWidgetLoaded = true;

    window.chatState = {
        currentRoom: 'community',
        currentReceiverId: 0,
        currentReceiverName: 'Gym Community',
        lastMessageId: 0,
        pollTimer: null,
        contacts: [],
        filter: 'all',
    };

    window.openChatPanel = function() {
        document.getElementById('chatPanel').classList.add('open');
        document.getElementById('chatOverlay').classList.add('open');
        document.body.style.overflow = 'hidden';

        loadContacts();
        loadMessages(true);
        startPolling();

        setTimeout(() => {
            const input = document.getElementById('chatMessageInput');
            if (input) input.focus();
        }, 400);
    };

    window.closeChatPanel = function() {
        document.getElementById('chatPanel').classList.remove('open');
        document.getElementById('chatOverlay').classList.remove('open');
        document.body.style.overflow = '';
        stopPolling();
    };

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') window.closeChatPanel();
    });

    function startPolling() {
        stopPolling();
        window.chatState.pollTimer = setInterval(() => loadMessages(false), 3000);
    }

    function stopPolling() {
        if (window.chatState.pollTimer) {
            clearInterval(window.chatState.pollTimer);
            window.chatState.pollTimer = null;
        }
    }

    async function loadContacts() {
        try {
            const res = await fetch('chat_api.php?action=get_contacts');
            const data = await res.json();
            if (!data.success) return;
            window.chatState.contacts = data.contacts;
            renderContacts();
        } catch (err) { console.error('Load contacts error:', err); }
    }

    function renderContacts() {
        const list = document.getElementById('chatContactsList');
        if (!list) return;
        const loader = document.getElementById('liveContactsLoader');
        list.querySelectorAll('.live-contact').forEach(el => el.remove());

        if (window.chatState.contacts.length === 0) {
            if (loader) loader.innerHTML = '<div style="padding: 12px; color: #9ca3af; font-size: 12px;">No other members yet</div>';
            return;
        }
        if (loader) loader.remove();

        let html = '';
        window.chatState.contacts.forEach(c => {
            let avatarHtml = '';
            if (c.avatar) {
                avatarHtml = `<img src="${c.avatar}" alt="${escapeHtml(c.name)}">`;
            } else {
                avatarHtml = `<span class="initials ${c.is_admin ? 'admin' : ''}">${escapeHtml(c.initials)}</span>`;
            }
            const onlineDot = c.is_online ? '<span class="online-dot"></span>' : '';
            const unreadBadge = c.unread_count > 0 ? `<span class="unread-tag">${c.unread_count}</span>` : '';
            const adminBadge = c.is_admin ? '<i class="bi bi-patch-check-fill" style="color: #ffce00; font-size: 11px; margin-left: 4px;"></i>' : '';

            html += `
                <div class="chat-contact-item live-contact"
                     onclick="selectChatContact(this, 'private', '${escapeHtml(c.name)}', ${c.id})"
                     data-type="private"
                     data-user-id="${c.id}"
                     data-name="${escapeHtml(c.name.toLowerCase())}"
                     data-is-admin="${c.is_admin ? '1' : '0'}">
                    <span class="contact-avatar">${avatarHtml}</span>
                    <span class="contact-name">${escapeHtml(c.name)}${adminBadge}</span>
                    ${onlineDot}
                    ${unreadBadge}
                </div>
            `;
        });
        list.insertAdjacentHTML('beforeend', html);
        applyFilter();
    }

    window.selectChatFilter = function(el, filter) {
        document.querySelectorAll('.chat-filter-tab').forEach(t => t.classList.remove('active'));
        el.classList.add('active');
        window.chatState.filter = filter;
        applyFilter();
    };

    function applyFilter() {
        document.querySelectorAll('.live-contact').forEach(el => {
            const isAdmin = el.dataset.isAdmin === '1';
            let show = true;
            if (window.chatState.filter === 'admin' && !isAdmin) show = false;
            if (window.chatState.filter === 'friends' && isAdmin) show = false;
            el.style.display = show ? '' : 'none';
        });
    }

    window.filterContacts = function(query) {
        query = query.toLowerCase().trim();
        document.querySelectorAll('.chat-contact-item').forEach(el => {
            const name = el.dataset.name || '';
            el.style.display = (!query || name.includes(query)) ? '' : 'none';
        });
    };

    window.selectChatContact = function(el, type, name, userId = 0) {
        document.querySelectorAll('.chat-contact-item').forEach(c => c.classList.remove('active'));
        el.classList.add('active');

        window.chatState.currentRoom = (type === 'community') ? 'community' : 'private';
        window.chatState.currentReceiverId = userId || 0;
        window.chatState.currentReceiverName = name || 'Chat';
        window.chatState.lastMessageId = 0;

        document.getElementById('chatRoomTitle').textContent = name;
        document.getElementById('chatRoomSubtitle').textContent =
            (type === 'community') ? 'Public chat room - All members' : 'Private conversation';

        loadMessages(true);
        setTimeout(() => document.getElementById('chatMessageInput').focus(), 200);
    };

    async function loadMessages(reset = false) {
        const container = document.getElementById('chatMessages');
        if (!container) return;

        if (reset) {
            window.chatState.lastMessageId = 0;
            container.innerHTML = '<div style="text-align: center; padding: 30px; color: #9ca3af; font-size: 13px;"><i class="bi bi-arrow-clockwise" style="font-size: 24px; display: block; margin-bottom: 8px; animation: spin 1s linear infinite;"></i>Loading messages...</div>';
        }

        try {
            const params = new URLSearchParams({
                action: 'get_messages',
                room: window.chatState.currentRoom,
                last_id: window.chatState.lastMessageId
            });
            if (window.chatState.currentRoom === 'private') {
                params.set('receiver_id', window.chatState.currentReceiverId);
            }

            const res = await fetch('chat_api.php?' + params.toString());
            const data = await res.json();
            if (!data.success) return;

            if (reset) container.innerHTML = '';

            if (data.messages.length === 0 && reset) {
                container.innerHTML = '<div style="text-align: center; padding: 40px 20px; color: #9ca3af; font-size: 13px;"><i class="bi bi-chat-dots" style="font-size: 40px; opacity: 0.4; display: block; margin-bottom: 10px;"></i><p style="margin: 0; font-weight: 600;">No messages yet</p><p style="margin: 6px 0 0; font-size: 12px;">Be the first to say something!</p></div>';
                return;
            }

            let lastDate = '';
            data.messages.forEach(msg => {
                if (msg.date !== lastDate) {
                    lastDate = msg.date;
                    const sep = document.createElement('div');
                    sep.className = 'chat-date-separator';
                    sep.innerHTML = `<span>${escapeHtml(msg.date)}</span>`;
                    container.appendChild(sep);
                }
                if (window.chatState.lastMessageId >= msg.id) return;
                container.appendChild(buildMessageElement(msg));
                window.chatState.lastMessageId = Math.max(window.chatState.lastMessageId, msg.id);
            });

            container.scrollTop = container.scrollHeight;
        } catch (err) { console.error('Load messages error:', err); }
    }

    function buildMessageElement(msg) {
        const el = document.createElement('div');
        el.className = 'chat-message-item' + (msg.is_self ? ' self' : '');
        el.dataset.msgId = msg.id;

        let avatarHtml = '';
        if (msg.is_self) {
            avatarHtml = '<i class="bi bi-person-fill" style="font-size: 14px;"></i>';
        } else if (msg.sender_avatar) {
            avatarHtml = `<img src="${msg.sender_avatar}" class="msg-avatar" alt="">`;
        } else {
            avatarHtml = `<span class="msg-avatar-placeholder ${msg.is_admin ? 'admin' : ''}">${escapeHtml(msg.sender_initials)}</span>`;
        }

        const adminIcon = msg.is_admin && !msg.is_self ? '<i class="bi bi-patch-check-fill" style="color: #ffce00;"></i>' : '';

        el.innerHTML = `
            <div class="chat-message-sender">${avatarHtml}${adminIcon}${escapeHtml(msg.is_self ? 'You' : msg.sender)}</div>
            <div class="chat-message-text">${escapeHtml(msg.message)}</div>
            <div class="chat-message-time">${escapeHtml(msg.time)}</div>
        `;
        return el;
    }

    window.sendChatMessage = async function() {
        const input = document.getElementById('chatMessageInput');
        const btn = document.getElementById('chatSendBtn');
        const message = input.value.trim();
        if (!message) return;

        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-hourglass-split"></i>';

        try {
            const res = await fetch('chat_api.php?action=send_message', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    message: message,
                    room: window.chatState.currentRoom,
                    receiver_id: window.chatState.currentReceiverId
                })
            });
            const data = await res.json();
            if (data.success) {
                input.value = '';
                await loadMessages(false);
            } else {
                alert('Failed: ' + (data.error || 'Unknown'));
            }
        } catch (err) {
            console.error('Send error:', err);
            alert('Network error');
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-send-fill"></i>';
            input.focus();
        }
    };

    window.insertEmoji = function(emoji) {
        const input = document.getElementById('chatMessageInput');
        input.value += emoji;
        input.focus();
    };

    function escapeHtml(text) {
        if (text === null || text === undefined) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
})();
</script>