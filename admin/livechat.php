<?php
// ============================================================
// BraidedbyAGB — Admin Live Chat Panel
// FILE: /admin/livechat.php
// ============================================================

// ── AJAX handlers (run before layout to avoid header issues) ─
if (isset($_GET['ajax'])) {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../includes/auth.php';
    requireAdmin();

    header('Content-Type: application/json; charset=utf-8');
    $db     = getDB();
    $action = $_GET['ajax'];
    $method = $_SERVER['REQUEST_METHOD'];
    $body   = ($method === 'POST')
            ? (json_decode(file_get_contents('php://input'), true) ?? [])
            : [];

    switch ($action) {

        case 'sessions':
            $status = in_array($_GET['status'] ?? 'active', ['active','closed'], true)
                    ? $_GET['status'] : 'active';
            $stmt = $db->prepare("
                SELECT id, customer_name, customer_email, status,
                       unread_admin, last_msg, last_msg_at, created_at
                FROM chat_sessions
                WHERE status=?
                ORDER BY COALESCE(last_msg_at, created_at) DESC
                LIMIT 100
            ");
            $stmt->execute([$status]);
            echo json_encode($stmt->fetchAll());
            break;

        case 'messages':
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) { echo json_encode(['error' => 'Missing id']); break; }

            $sess = $db->prepare("SELECT * FROM chat_sessions WHERE id=?");
            $sess->execute([$id]);
            $session = $sess->fetch();
            if (!$session) { echo json_encode(['error' => 'Not found']); break; }

            $msgs = $db->prepare("
                SELECT id, sender, body, created_at
                FROM chat_messages WHERE session_id=? ORDER BY id ASC
            ");
            $msgs->execute([$id]);

            $db->prepare("UPDATE chat_sessions SET unread_admin=0 WHERE id=?")
               ->execute([$id]);

            echo json_encode(['session' => $session, 'messages' => $msgs->fetchAll()]);
            break;

        case 'reply':
            if ($method !== 'POST') { echo json_encode(['error' => 'POST only']); break; }
            $id      = (int)($_GET['id'] ?? 0);
            $message = mb_substr(trim($body['message'] ?? ''), 0, 2000);
            if (!$id || !$message) { echo json_encode(['error' => 'Missing id or message']); break; }

            $check = $db->prepare("SELECT id FROM chat_sessions WHERE id=? AND status='active'");
            $check->execute([$id]);
            if (!$check->fetch()) { echo json_encode(['error' => 'Session closed or not found']); break; }

            $db->prepare("INSERT INTO chat_messages (session_id, sender, body) VALUES (?,?,?)")
               ->execute([$id, 'admin', $message]);
            $db->prepare("UPDATE chat_sessions SET last_msg=?, last_msg_at=NOW() WHERE id=?")
               ->execute([mb_substr($message, 0, 200), $id]);

            echo json_encode(['success' => true]);
            break;

        case 'close':
            if ($method !== 'POST') { echo json_encode(['error' => 'POST only']); break; }
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) { echo json_encode(['error' => 'Missing id']); break; }
            $db->prepare("UPDATE chat_sessions SET status='closed' WHERE id=?")->execute([$id]);
            echo json_encode(['success' => true]);
            break;

        default:
            echo json_encode(['error' => 'Unknown action']);
    }
    exit;
}

// ── Page render ───────────────────────────────────────────────
$pageTitle = 'Live Chat';
require_once __DIR__ . '/includes/layout.php';

// Active / closed session counts
try {
    $activeCount = (int)$db->query("SELECT COUNT(*) FROM chat_sessions WHERE status='active'")->fetchColumn();
    $unreadTotal = (int)$db->query("SELECT COALESCE(SUM(unread_admin),0) FROM chat_sessions WHERE status='active'")->fetchColumn();
} catch (Exception $e) {
    $activeCount = $unreadTotal = 0;
}
?>

<div class="page-header">
  <div>
    <h2 class="section-heading">Live Chat</h2>
    <p style="color:var(--admin-muted);font-size:0.8rem">
      <?= $activeCount ?> active <?= $activeCount === 1 ? 'conversation' : 'conversations' ?>
      <?php if ($unreadTotal > 0): ?>
        · <span style="color:#dc2626;font-weight:700"><?= $unreadTotal ?> unread</span>
      <?php endif; ?>
    </p>
  </div>
</div>

<!-- Chat panel layout -->
<div id="lc-wrap" style="display:flex;gap:16px;height:calc(100vh - 180px);min-height:500px">

  <!-- ── Session list (left) ── -->
  <div id="lc-sidebar" style="width:300px;min-width:220px;background:var(--admin-surface);border:1px solid var(--admin-border);border-radius:12px;display:flex;flex-direction:column;overflow:hidden;flex-shrink:0">
    <div style="padding:12px 14px;border-bottom:1px solid var(--admin-border);display:flex;align-items:center;gap:8px">
      <span style="font-size:0.75rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:var(--admin-muted)">Conversations</span>
      <span id="lc-active-tab" onclick="switchTab('active')"
        style="margin-left:auto;font-size:0.72rem;padding:2px 10px;border-radius:10px;cursor:pointer;font-weight:700;background:var(--admin-primary);color:#fff">Active</span>
      <span id="lc-closed-tab" onclick="switchTab('closed')"
        style="font-size:0.72rem;padding:2px 10px;border-radius:10px;cursor:pointer;font-weight:600;color:var(--admin-muted);background:var(--admin-bg)">Closed</span>
    </div>
    <div id="lc-sessions" style="flex:1;overflow-y:auto;padding:8px 0"></div>
  </div>

  <!-- ── Conversation (right) ── -->
  <div id="lc-convo" style="flex:1;background:var(--admin-surface);border:1px solid var(--admin-border);border-radius:12px;display:flex;flex-direction:column;overflow:hidden">

    <!-- Empty state -->
    <div id="lc-empty" style="flex:1;display:flex;align-items:center;justify-content:center;flex-direction:column;color:var(--admin-muted)">
      <div style="font-size:3rem;margin-bottom:12px">💬</div>
      <p style="font-weight:600;margin-bottom:4px">Select a conversation</p>
      <p style="font-size:0.82rem">Click a session on the left to view and reply</p>
    </div>

    <!-- Active conversation -->
    <div id="lc-chat" style="display:none;flex-direction:column;height:100%">
      <!-- Header -->
      <div id="lc-header" style="padding:14px 18px;border-bottom:1px solid var(--admin-border);display:flex;align-items:center;gap:12px">
        <div>
          <div id="lc-header-name" style="font-weight:700;font-size:1rem"></div>
          <div id="lc-header-sub" style="font-size:0.75rem;color:var(--admin-muted)"></div>
        </div>
        <div style="margin-left:auto;display:flex;gap:8px">
          <button onclick="closeSession()" id="lc-close-btn"
            style="font-size:0.78rem;padding:5px 14px;border-radius:8px;border:1px solid #dc2626;background:transparent;color:#dc2626;cursor:pointer;font-weight:700">
            End Chat
          </button>
        </div>
      </div>

      <!-- Messages -->
      <div id="lc-messages" style="flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:10px"></div>

      <!-- Reply form -->
      <div id="lc-reply-wrap" style="padding:12px 16px;border-top:1px solid var(--admin-border);display:flex;gap:10px;align-items:flex-end">
        <textarea id="lc-reply-input" placeholder="Type your reply…" rows="2"
          style="flex:1;resize:none;border:1.5px solid var(--admin-border);border-radius:10px;padding:10px 14px;font-family:inherit;font-size:0.9rem;background:var(--admin-bg);color:var(--admin-text);outline:none;transition:border .2s"
          onfocus="this.style.borderColor='var(--admin-primary)'"
          onblur="this.style.borderColor='var(--admin-border)'"
          maxlength="2000"></textarea>
        <button onclick="sendReply()"
          style="padding:10px 20px;background:var(--admin-primary);color:#fff;border:none;border-radius:10px;font-weight:700;cursor:pointer;font-size:0.9rem;white-space:nowrap">
          Send
        </button>
      </div>
    </div>

  </div><!-- /lc-convo -->
</div><!-- /lc-wrap -->

<style>
.lc-session-item {
  padding: 12px 14px;
  cursor: pointer;
  border-bottom: 1px solid var(--admin-border);
  transition: background .15s;
}
.lc-session-item:hover { background: var(--admin-bg); }
.lc-session-item.active { background: rgba(109,0,145,0.08); border-left: 3px solid var(--admin-primary); }
.lc-msg-admin, .lc-msg-customer {
  max-width: 72%;
  padding: 10px 14px;
  border-radius: 14px;
  font-size: 0.9rem;
  line-height: 1.5;
  white-space: pre-wrap;
  word-break: break-word;
}
.lc-msg-admin {
  background: var(--admin-primary);
  color: #fff;
  align-self: flex-end;
  border-bottom-right-radius: 4px;
}
.lc-msg-customer {
  background: var(--admin-bg);
  color: var(--admin-text);
  align-self: flex-start;
  border: 1px solid var(--admin-border);
  border-bottom-left-radius: 4px;
}
.lc-msg-label {
  font-size: 0.7rem;
  color: var(--admin-muted);
  margin-bottom: 3px;
}
.lc-msg-group { display: flex; flex-direction: column; }
.lc-msg-group.admin { align-items: flex-end; }
.lc-msg-group.customer { align-items: flex-start; }

@media (max-width: 640px) {
  #lc-wrap { flex-direction: column; height: auto; }
  #lc-sidebar { width: 100%; min-width: 0; max-height: 240px; }
}
</style>

<script>
// ── State ──────────────────────────────────────────────────
let currentId    = null;
let currentUuid  = null;
let currentStatus = 'active';
let lastMsgId    = 0;
let sessionTimer = null;
let msgTimer     = null;

// ── Tab switching ──────────────────────────────────────────
function switchTab(tab) {
  currentStatus = tab;
  const activeEl  = document.getElementById('lc-active-tab');
  const closedEl  = document.getElementById('lc-closed-tab');
  const isActive  = tab === 'active';
  activeEl.style.background  = isActive ? 'var(--admin-primary)' : 'var(--admin-bg)';
  activeEl.style.color       = isActive ? '#fff' : 'var(--admin-muted)';
  closedEl.style.background  = isActive ? 'var(--admin-bg)' : 'var(--admin-primary)';
  closedEl.style.color       = isActive ? 'var(--admin-muted)' : '#fff';
  clearConvo();
  loadSessions();
}

// ── Load session list ──────────────────────────────────────
async function loadSessions() {
  try {
    const data = await apiGet(`/admin/livechat?ajax=sessions&status=${currentStatus}`);
    renderSessions(data);
  } catch(e) { /* silent */ }
}

function renderSessions(sessions) {
  const el = document.getElementById('lc-sessions');
  if (!sessions || !sessions.length) {
    el.innerHTML = '<p style="padding:20px;text-align:center;color:var(--admin-muted);font-size:0.85rem">No ' + currentStatus + ' conversations</p>';
    return;
  }
  el.innerHTML = sessions.map(s => {
    const name    = esc(s.customer_name || 'Unknown');
    const email   = s.customer_email ? `<div style="font-size:0.72rem;color:var(--admin-muted)">${esc(s.customer_email)}</div>` : '';
    const preview = s.last_msg ? `<div style="font-size:0.78rem;color:var(--admin-muted);margin-top:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(s.last_msg.substring(0,60))}</div>` : '';
    const unread  = parseInt(s.unread_admin) > 0
                  ? `<span style="background:#dc2626;color:#fff;font-size:0.65rem;font-weight:700;border-radius:10px;padding:1px 7px;float:right">${s.unread_admin}</span>` : '';
    const time    = s.last_msg_at ? `<div style="font-size:0.68rem;color:var(--admin-muted);margin-top:2px">${fmtTime(s.last_msg_at)}</div>` : '';
    const active  = currentId === parseInt(s.id) ? 'active' : '';
    return `<div class="lc-session-item ${active}" onclick="openSession(${s.id},'${esc(s.uuid)}')">
      ${unread}
      <div style="font-weight:700;font-size:0.88rem">${name}</div>
      ${email}${preview}${time}
    </div>`;
  }).join('');
}

// ── Open a session ─────────────────────────────────────────
async function openSession(id, uuidVal) {
  currentId   = id;
  currentUuid = uuidVal;
  lastMsgId   = 0;
  stopMsgTimer();

  document.getElementById('lc-empty').style.display = 'none';
  const chat = document.getElementById('lc-chat');
  chat.style.display = 'flex';

  // Highlight in list
  document.querySelectorAll('.lc-session-item').forEach(el => el.classList.remove('active'));
  event && event.currentTarget && event.currentTarget.classList.add('active');

  await loadMessages();
  startMsgTimer();
}

async function loadMessages() {
  if (!currentId) return;
  try {
    const data = await apiGet(`/admin/livechat?ajax=messages&id=${currentId}`);
    if (data.error) return;

    const s = data.session;
    document.getElementById('lc-header-name').textContent = s.customer_name || 'Unknown';
    document.getElementById('lc-header-sub').textContent  =
      (s.customer_email || '') + (s.status === 'closed' ? ' · CLOSED' : ' · Active');

    const closed = s.status === 'closed';
    document.getElementById('lc-reply-wrap').style.display = closed ? 'none' : 'flex';
    document.getElementById('lc-close-btn').style.display  = closed ? 'none' : 'inline-block';

    renderMessages(data.messages || []);
  } catch(_) {}
}

function renderMessages(messages) {
  const el = document.getElementById('lc-messages');
  el.innerHTML = messages.map(m => {
    const isAdmin = m.sender === 'admin';
    const cls     = isAdmin ? 'admin' : 'customer';
    const label   = isAdmin ? 'You (admin)' : (document.getElementById('lc-header-name').textContent || 'Customer');
    return `
      <div class="lc-msg-group ${cls}">
        <div class="lc-msg-label">${esc(label)} · ${fmtTime(m.created_at)}</div>
        <div class="lc-msg-${cls}">${esc(m.body)}</div>
      </div>`;
  }).join('');

  if (messages.length) {
    lastMsgId = Math.max(...messages.map(m => parseInt(m.id)));
  }
  scrollBottom();
}

function scrollBottom() {
  const el = document.getElementById('lc-messages');
  requestAnimationFrame(() => { el.scrollTop = el.scrollHeight; });
}

// ── Reply ──────────────────────────────────────────────────
async function sendReply() {
  const inp     = document.getElementById('lc-reply-input');
  const message = inp.value.trim();
  if (!message || !currentId) return;
  inp.disabled = true;

  try {
    const r = await apiPost(`/admin/livechat?ajax=reply&id=${currentId}`, { message });
    if (r.success) {
      inp.value    = '';
      await loadMessages();
    } else {
      alert(r.error || 'Failed to send');
    }
  } catch(_) { alert('Network error — could not send reply'); }
  inp.disabled = false;
  inp.focus();
}

document.addEventListener('keydown', e => {
  const inp = document.getElementById('lc-reply-input');
  if (document.activeElement === inp && e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault();
    sendReply();
  }
});

// ── Close session ──────────────────────────────────────────
async function closeSession() {
  if (!currentId || !confirm('End this chat? The customer will see a goodbye message.')) return;
  try {
    await apiPost(`/admin/livechat?ajax=close&id=${currentId}`, {});
    clearConvo();
    loadSessions();
  } catch(_) { alert('Failed to close session'); }
}

function clearConvo() {
  stopMsgTimer();
  currentId   = null;
  currentUuid = null;
  document.getElementById('lc-empty').style.display = 'flex';
  document.getElementById('lc-chat').style.display  = 'none';
}

// ── Timers ─────────────────────────────────────────────────
function startMsgTimer() {
  stopMsgTimer();
  msgTimer = setInterval(loadMessages, 4000);
}
function stopMsgTimer() {
  if (msgTimer) { clearInterval(msgTimer); msgTimer = null; }
}

// Auto-refresh session list every 8s
setInterval(loadSessions, 8000);

// ── API helpers ────────────────────────────────────────────
async function apiGet(url) {
  const r = await fetch(url);
  if (!r.ok) throw new Error('HTTP ' + r.status);
  return r.json();
}
async function apiPost(url, data) {
  const r = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(data),
  });
  if (!r.ok) throw new Error('HTTP ' + r.status);
  return r.json();
}

function fmtTime(dt) {
  if (!dt) return '';
  const d = new Date(dt.replace(' ', 'T'));
  const now = new Date();
  const today = now.toDateString() === d.toDateString();
  const time = d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
  return today ? time : d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' }) + ' ' + time;
}

function esc(s) {
  if (!s) return '';
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Boot ───────────────────────────────────────────────────
loadSessions();
</script>

<?php require_once __DIR__ . '/includes/layout-end.php'; ?>
