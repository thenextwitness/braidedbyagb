/* ============================================================
   BraidedbyAGB — Live Chat Widget
   FILE: /assets/js/chat-widget.js

   Flow:
   1. Customer opens widget → name form appears
   2. Name submitted → POST start → UUID stored in sessionStorage
   3. Customer types → POST send → message stored in DB → FCM push to admin
   4. Widget polls every 3 s → picks up admin replies in real time
   5. Admin closes session → widget shows farewell + disables input
   ============================================================ */

(function () {
  'use strict';

  const API       = '/api/livechat.php';
  const POLL_MS   = 3000;        // poll interval
  const SESS_KEY  = 'agb_live_session';

  // ── State ──────────────────────────────────────────────────
  let uuid      = null;
  let lastId    = 0;
  let pollTimer = null;
  let isOpen    = false;
  let isClosed  = false;
  let isSending = false;
  let hasOpened = false;

  // ── DOM ────────────────────────────────────────────────────
  let toggle, win, msgs, input, sendBtn, badge, statusEl;

  // ── Init ───────────────────────────────────────────────────
  function init() {
    inject();
    toggle   = document.getElementById('agb-toggle');
    win      = document.getElementById('agb-win');
    msgs     = document.getElementById('agb-msgs');
    input    = document.getElementById('agb-input');
    sendBtn  = document.getElementById('agb-send');
    badge    = document.getElementById('agb-badge');
    statusEl = document.getElementById('agb-status');

    // Restore existing session
    try {
      const s = JSON.parse(sessionStorage.getItem(SESS_KEY) || 'null');
      if (s && s.uuid) { uuid = s.uuid; lastId = s.lastId || 0; }
    } catch (_) {}

    bindEvents();
    setTimeout(() => { if (!hasOpened) badge.classList.remove('hidden'); }, 10000);
  }

  // ── HTML ───────────────────────────────────────────────────
  function inject() {
    const d = document.createElement('div');
    d.innerHTML = `
      <button class="agb-chat-toggle" id="agb-toggle" aria-label="Chat with us" aria-expanded="false">
        <span class="agb-chat-badge hidden" id="agb-badge">💬</span>
        <svg class="icon-open" viewBox="0 0 24 24" aria-hidden="true">
          <path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/>
        </svg>
        <svg class="icon-close" viewBox="0 0 24 24" aria-hidden="true">
          <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
        </svg>
      </button>

      <div class="agb-chat-window" id="agb-win" role="dialog" aria-label="Live chat with BraidedbyAGB">
        <div class="agb-chat-header">
          <div class="agb-chat-avatar" aria-hidden="true">✦</div>
          <div class="agb-chat-header-text">
            <div class="agb-chat-header-name">BraidedbyAGB</div>
            <div class="agb-chat-header-status" id="agb-status">Live chat · Ask us anything</div>
          </div>
          <button class="agb-chat-close-btn" id="agb-close" aria-label="Close chat">✕</button>
        </div>

        <div class="agb-chat-messages" id="agb-msgs" aria-live="polite" aria-label="Chat messages"></div>

        <div class="agb-chat-input-row">
          <textarea class="agb-chat-input" id="agb-input"
            placeholder="Type your message…" rows="1" maxlength="1000"
            aria-label="Type your message"></textarea>
          <button class="agb-chat-send" id="agb-send" aria-label="Send message" disabled>
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
            </svg>
          </button>
        </div>

        <div class="agb-chat-footer">Live chat with our team 💜</div>
      </div>`;
    document.body.appendChild(d);
  }

  // ── Events ─────────────────────────────────────────────────
  function bindEvents() {
    toggle.addEventListener('click', toggleChat);
    document.getElementById('agb-close').addEventListener('click', closeWidget);

    input.addEventListener('input', () => {
      sendBtn.disabled = !input.value.trim() || isSending || isClosed;
      input.style.height = 'auto';
      input.style.height = Math.min(input.scrollHeight, 100) + 'px';
    });
    input.addEventListener('keydown', e => {
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); if (!sendBtn.disabled) doSend(); }
    });
    sendBtn.addEventListener('click', e => { e.stopPropagation(); doSend(); });

    win.addEventListener('click', e => e.stopPropagation());
    document.addEventListener('click', e => {
      if (isOpen && !win.contains(e.target) && !toggle.contains(e.target)) closeWidget();
    });
  }

  function toggleChat() { isOpen ? closeWidget() : openWidget(); }

  function openWidget() {
    win.classList.add('open');
    toggle.classList.add('open');
    toggle.setAttribute('aria-expanded', 'true');
    badge.classList.add('hidden');
    isOpen    = true;
    hasOpened = true;

    if (msgs.children.length === 0) {
      uuid ? loadHistory() : showWelcome();
    }

    if (uuid && !isClosed && !pollTimer) startPolling();
    setTimeout(() => input.focus(), 300);
  }

  function closeWidget() {
    win.classList.remove('open');
    toggle.classList.remove('open');
    toggle.setAttribute('aria-expanded', 'false');
    isOpen = false;
    // Keep polling paused while widget is closed (saves server calls)
    stopPolling();
  }

  // ── Welcome & name form ────────────────────────────────────
  function showWelcome() {
    addAdminMsg('Hi there! 👋 Welcome to BraidedbyAGB.\n\nWhat\'s your name so we can connect you to our team?');
    showNameForm();
  }

  function showNameForm() {
    input.disabled = true;
    sendBtn.disabled = true;

    const form = document.createElement('div');
    form.id = 'agb-name-form';
    form.style.cssText = 'padding:8px 12px 12px;display:flex;gap:8px;align-items:center;border-top:1px solid rgba(0,0,0,0.08)';
    form.innerHTML = `
      <input id="agb-name-in" type="text" placeholder="Your name…" maxlength="60"
        style="flex:1;padding:9px 14px;border:1.5px solid #ddd;border-radius:22px;
               font-size:0.88rem;font-family:inherit;outline:none;transition:border .2s"
        onfocus="this.style.borderColor='#9B00D3'" onblur="this.style.borderColor='#ddd'">
      <button id="agb-name-go"
        style="background:linear-gradient(135deg,#6D0091,#9B00D3);color:#fff;border:none;
               border-radius:22px;padding:9px 18px;font-size:0.85rem;font-weight:700;
               cursor:pointer;white-space:nowrap;transition:opacity .2s">
        Start Chat
      </button>`;

    // Insert above input row
    document.querySelector('.agb-chat-input-row').before(form);

    const nameIn = document.getElementById('agb-name-in');
    const goBtn  = document.getElementById('agb-name-go');
    nameIn.focus();

    const submit = async () => {
      const name = nameIn.value.trim();
      if (!name) { nameIn.style.borderColor = '#e53e3e'; return; }
      goBtn.disabled    = true;
      goBtn.textContent = '…';
      await startSession(name);
      form.remove();
      input.disabled   = false;
      input.focus();
    };

    goBtn.addEventListener('click', submit);
    nameIn.addEventListener('keydown', e => { if (e.key === 'Enter') submit(); });
  }

  // ── Session ────────────────────────────────────────────────
  async function startSession(name) {
    try {
      const r = await post(`${API}?action=start`, { name });
      if (r.uuid) {
        uuid   = r.uuid;
        lastId = 0;
        saveSession();
        addAdminMsg(`Thanks ${name}! 💜\n\nYou're connected to our team. We'll reply shortly — feel free to ask your question!`);
        if (statusEl) statusEl.textContent = 'Connected · We\'ll reply soon';
        startPolling();
      }
    } catch (_) {
      addAdminMsg("Sorry, we couldn't start the chat. Please WhatsApp us on 07769 064 971 💬");
    }
  }

  function saveSession() {
    try { sessionStorage.setItem(SESS_KEY, JSON.stringify({ uuid, lastId })); } catch (_) {}
  }

  // ── Send ───────────────────────────────────────────────────
  async function doSend() {
    const text = input.value.trim();
    if (!text || isSending || isClosed || !uuid) return;

    input.value      = '';
    input.style.height = 'auto';
    sendBtn.disabled = true;
    isSending        = true;

    addUserMsg(text);

    try {
      const r = await post(`${API}?action=send&uuid=${encodeURIComponent(uuid)}`, { message: text });
      if (r.id) { lastId = Math.max(lastId, r.id - 1); saveSession(); }
    } catch (_) {
      addAdminMsg("⚠️ Message failed to send. Check your connection and try again.");
    }

    isSending        = false;
    sendBtn.disabled = !input.value.trim();
    input.focus();
  }

  // ── Polling ────────────────────────────────────────────────
  function startPolling() {
    stopPolling();
    doPoll(); // immediate
    pollTimer = setInterval(doPoll, POLL_MS);
  }

  function stopPolling() {
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
  }

  async function doPoll() {
    if (!uuid) return;
    try {
      const r = await get(`${API}?action=poll&uuid=${encodeURIComponent(uuid)}&after=${lastId}`);
      if (r.closed) { handleClosed(); return; }
      if (r.messages && r.messages.length) {
        r.messages.forEach(m => {
          if (m.sender === 'admin') addAdminMsg(m.body);
          lastId = Math.max(lastId, parseInt(m.id, 10));
        });
        saveSession();
      }
    } catch (_) { /* silent — network hiccup */ }
  }

  function handleClosed() {
    isClosed = true;
    stopPolling();
    addAdminMsg("This chat has been closed. Thank you for chatting with us! 💜\n\nRefresh the page to start a new conversation.");
    input.disabled   = true;
    sendBtn.disabled = true;
    if (statusEl) statusEl.textContent = 'Chat ended';
    sessionStorage.removeItem(SESS_KEY);
  }

  // ── History (resume existing session) ─────────────────────
  async function loadHistory() {
    try {
      const r = await get(`${API}?action=poll&uuid=${encodeURIComponent(uuid)}&after=0`);
      if (r.closed) { handleClosed(); return; }
      if (r.messages && r.messages.length) {
        r.messages.forEach(m => {
          m.sender === 'customer' ? addUserMsg(m.body) : addAdminMsg(m.body);
          lastId = Math.max(lastId, parseInt(m.id, 10));
        });
        saveSession();
        scrollBottom();
      } else {
        addAdminMsg("Welcome back! 👋 The team is here if you have more questions.");
      }
    } catch (_) {
      addAdminMsg("Couldn't load chat history. Please refresh if this continues.");
    }
    if (!isClosed) startPolling();
  }

  // ── Render ─────────────────────────────────────────────────
  function addAdminMsg(text) {
    const d = document.createElement('div');
    d.className = 'agb-msg bot';
    d.innerHTML = `
      <div class="agb-msg-avatar" aria-hidden="true">✦</div>
      <div class="agb-msg-bubble">${fmt(text)}</div>`;
    msgs.appendChild(d);
    scrollBottom();
  }

  function addUserMsg(text) {
    const d = document.createElement('div');
    d.className = 'agb-msg user';
    d.innerHTML = `
      <div class="agb-msg-bubble">${esc(text)}</div>
      <div class="agb-msg-avatar" aria-hidden="true" style="background:linear-gradient(135deg,#CC1A8A,#9400D3)">you</div>`;
    msgs.appendChild(d);
    scrollBottom();
  }

  function fmt(t) {
    return esc(t).replace(/\n\n/g, '</p><p style="margin:6px 0 0">').replace(/\n/g, '<br>');
  }
  function esc(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }
  function scrollBottom() {
    requestAnimationFrame(() => { msgs.scrollTop = msgs.scrollHeight; });
  }

  // ── HTTP helpers ───────────────────────────────────────────
  async function get(url) {
    const r = await fetch(url);
    if (!r.ok) throw new Error('HTTP ' + r.status);
    return r.json();
  }
  async function post(url, data) {
    const r = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data),
    });
    if (!r.ok) throw new Error('HTTP ' + r.status);
    return r.json();
  }

  // ── Boot ───────────────────────────────────────────────────
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();

})();
