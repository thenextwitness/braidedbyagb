<?php
// ============================================================
// BraidedbyAGB — Admin: batch messages to clients
// FILE: /admin/messages.php   (?view=compose | batch&id= | history)
// Approved design: docs/mockups/2026-11-batch-messages.png. Logic: includes/messaging.php.
// Reached from Clients → "Haven't booked lately" (tick clients → Message selected).
// ============================================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$db = getDB();

function msgJson(array $a, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($a);
    exit;
}

$ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));

// ── Actions (JSON) ──────────────────────────────────────────
$action = (string)($_POST['action'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== '') {
    try {
        $channel = ($_POST['channel'] ?? '') === 'whatsapp' ? 'whatsapp' : 'email';
        $subject = trim(strip_tags((string)($_POST['subject'] ?? '')));
        $body    = trim(strip_tags((string)($_POST['body'] ?? '')));
        $book    = !empty($_POST['book_button']);
        if (in_array($action, ['preview', 'test', 'create'], true)) {
            if ($body === '') msgJson(['error' => 'Write a message first.'], 400);
            if ($channel === 'email' && $subject === '') msgJson(['error' => 'Add a subject for the email.'], 400);
            if (!$ids) msgJson(['error' => 'No clients selected.'], 400);
        }
        if ($action === 'preview') {
            $client = messageClient($db, $ids[0]);
            if (!$client) msgJson(['error' => 'Client not found.'], 404);
            if ($channel === 'whatsapp') msgJson(['name' => $client['first'], 'text' => buildBatchWhatsApp($client, $body, $book)]);
            $mail = buildBatchEmail($db, $client, $subject, $body, $book);
            msgJson(['name' => $client['first'], 'subject' => $mail['subject'], 'html' => waApplyTokens($mail['html'])]);
        }
        if ($action === 'test') {
            $client = messageClient($db, $ids[0]);
            $mail   = buildBatchEmail($db, $client, '[TEST] ' . $subject, $body, $book);
            $to     = defined('SITE_EMAIL') ? SITE_EMAIL : '';
            if ($to === '') msgJson(['error' => 'No admin email is set up to receive the test.'], 500);
            $ok = sendMail($to, $mail['subject'], $mail['html']);
            msgJson($ok ? ['ok' => true, 'to' => $to] : ['error' => 'The test email could not be sent.'], $ok ? 200 : 502);
        }
        if ($action === 'create') {
            $template = array_key_exists($_POST['template'] ?? '', MESSAGE_TEMPLATES) ? $_POST['template'] : 'blank';
            $audience = mb_substr(trim(strip_tags((string)($_POST['audience'] ?? ''))), 0, 100);
            $bid = createMessageBatch($db, $channel, $ids, $template, $subject, $body, $book, $audience);
            msgJson(['ok' => true, 'url' => '/admin/messages.php?view=batch&id=' . $bid]);
        }
        $bid = (int)($_POST['batch_id'] ?? 0);
        if ($action === 'send_chunk') {
            $n = sendBatchChunk($db, $bid, 6);
            msgJson(['ok' => true, 'counts' => $n]);
        }
        if ($action === 'wa_open' || $action === 'wa_skip') {
            $rid = (int)($_POST['recipient_id'] ?? 0);
            $db->prepare("UPDATE message_batch_recipients SET status = ?, sent_at = NOW() WHERE id = ? AND batch_id = ? AND status = 'pending'")
               ->execute([$action === 'wa_open' ? 'opened' : 'skipped', $rid, $bid]);
            msgJson(['ok' => true, 'counts' => refreshBatchCounts($db, $bid)]);
        }
        msgJson(['error' => 'Unknown action.'], 400);
    } catch (Throwable $e) {
        error_log('admin/messages ' . $action . ': ' . $e->getMessage());
        msgJson(['error' => 'Something went wrong. Nothing more was sent.'], 500);
    }
}

$view = in_array($_GET['view'] ?? '', ['compose', 'batch', 'history'], true) ? $_GET['view'] : 'history';
if ($view === 'compose' && !$ids) { header('Location: /admin/customers?view=lapsed'); exit; }

$pageTitle = 'Messages';
require_once __DIR__ . '/includes/layout.php';
$money = null;
?>
<style>
  .seg{display:inline-flex;border:1px solid var(--admin-border);border-radius:9px;overflow:hidden;background:#fff}
  .seg label{padding:7px 14px;font-size:0.8rem;font-weight:700;color:var(--admin-muted);cursor:pointer}
  .seg input{display:none}.seg input:checked + span{color:#fff}
  .seg label:has(input:checked){background:var(--admin-primary);color:#fff}
  .chip{display:inline-block;background:#F9EEF9;border:1px solid #E8D8EE;color:var(--admin-primary);border-radius:12px;padding:2px 9px;font-size:0.72rem;font-weight:700;margin:4px 4px 0 0;cursor:pointer}
  .prog{height:12px;background:#f1ecf6;border-radius:8px;overflow:hidden}.prog i{display:block;height:100%;width:0;background:linear-gradient(90deg,#800080,#D4AF37);transition:width .4s}
  .q{display:flex;align-items:center;gap:12px;border:1px solid var(--admin-border);border-radius:10px;padding:10px 12px;margin-top:8px;font-size:0.85rem;background:#fff}
  .q.done{opacity:.55}.q.cur{border:2px solid var(--admin-primary)}
  .q .n{width:26px;height:26px;border-radius:50%;background:#F9EEF9;color:var(--admin-primary);font-weight:800;display:grid;place-items:center;font-size:0.75rem;flex:none}
</style>

<div class="page-header"><div>
  <h2 class="section-heading">Messages</h2>
  <p style="color:var(--admin-muted);font-size:0.8rem">Send one message to many clients — by email, or WhatsApp one tap at a time.</p>
</div></div>
<div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap">
  <a href="/admin/customers?view=lapsed" class="btn-admin btn-admin-outline">💤 Haven't booked lately</a>
  <a href="/admin/messages.php?view=history" class="btn-admin <?= $view === 'history' ? 'btn-admin-primary' : 'btn-admin-outline' ?>">✉️ Message history</a>
</div>

<?php if ($view === 'compose'):
  $in = implode(',', $ids);
  $emailable = (int)$db->query("SELECT COUNT(*) FROM customers WHERE id IN ($in) AND email_optin = 1 AND email IS NOT NULL AND email <> '' AND COALESCE(is_blocked,0) = 0")->fetchColumn();
  $audience  = trim(strip_tags((string)($_POST['audience'] ?? '')));
?>
<form id="composeForm" onsubmit="return false" style="display:grid;grid-template-columns:minmax(320px,1fr) minmax(320px,560px);gap:20px;align-items:start">
  <?php foreach ($ids as $i): ?><input type="hidden" name="ids[]" value="<?= $i ?>"><?php endforeach; ?>
  <input type="hidden" name="audience" value="<?= htmlspecialchars($audience) ?>">
  <div class="admin-form-card" style="padding:18px">
    <h3 style="font-family:Montserrat,sans-serif;color:var(--admin-primary-dark);margin:0 0 2px">Message <?= count($ids) ?> client<?= count($ids) === 1 ? '' : 's' ?></h3>
    <p style="color:var(--admin-muted);font-size:0.78rem;margin:0 0 12px"><?= htmlspecialchars($audience) ?></p>
    <label class="admin-label">Send by</label>
    <div class="seg">
      <label><input type="radio" name="channel" value="email" checked><span>✉️ Email (<?= $emailable ?>)</span></label>
      <label><input type="radio" name="channel" value="whatsapp"><span>💬 WhatsApp (<?= count($ids) ?>)</span></label>
    </div>
    <label class="admin-label" style="margin-top:12px">Start from</label>
    <select class="admin-input" name="template" id="tpl">
      <?php foreach (MESSAGE_TEMPLATES as $k => $t): ?><option value="<?= $k ?>"><?= htmlspecialchars($t['label']) ?></option><?php endforeach; ?>
    </select>
    <div id="subjWrap"><label class="admin-label" style="margin-top:12px">Subject</label>
      <input class="admin-input" name="subject" id="subject" maxlength="200"></div>
    <label class="admin-label" style="margin-top:12px">Message</label>
    <textarea class="admin-input admin-textarea" name="body" id="body" rows="9"></textarea>
    <div><span class="chip" data-ins="{first name}">+ {first name}</span><span class="chip" data-ins="{last style}">+ {last style}</span></div>
    <label style="display:flex;gap:8px;align-items:center;font-size:0.82rem;margin-top:10px"><input type="checkbox" name="book_button" value="1" checked style="accent-color:var(--admin-primary)"> Add a "See available times" booking button / link</label>
    <div style="display:flex;gap:8px;margin-top:14px;align-items:center;flex-wrap:wrap">
      <button type="button" class="btn-admin btn-admin-outline" id="testBtn">Send me a test first</button>
      <button type="button" class="btn-admin btn-admin-primary" id="sendBtn" style="margin-left:auto">Send</button>
    </div>
    <p id="note" style="color:var(--admin-muted);font-size:0.75rem;margin:8px 0 0"></p>
  </div>
  <div>
    <p style="font-size:0.78rem;color:var(--admin-muted);margin:0 0 6px" id="pvHead">Preview</p>
    <iframe id="pvMail" style="width:100%;height:640px;border:0;border-radius:10px;background:#F9EEF9"></iframe>
    <div id="pvWa" style="display:none;background:#e5ddd5;border-radius:10px;padding:16px;min-height:200px">
      <div style="background:#dcf8c6;border-radius:8px;padding:10px 12px;max-width:85%;margin-left:auto;white-space:pre-wrap;font-size:0.88rem" id="pvWaText"></div>
    </div>
  </div>
</form>
<script>
(function () {
  const T = <?= json_encode(MESSAGE_TEMPLATES) ?>, EMAILABLE = <?= $emailable ?>, ALL = <?= count($ids) ?>;
  const f = document.getElementById('composeForm'), $ = id => document.getElementById(id);
  const ch = () => f.querySelector('input[name=channel]:checked').value;
  function fill() { const t = T[$('tpl').value]; $('subject').value = t.subject; $('body').value = t.body; preview(); }
  function post(action, extra) {
    const fd = new FormData(f); fd.append('action', action);
    for (const k in (extra || {})) fd.append(k, extra[k]);
    return fetch('/admin/messages.php', { method: 'POST', body: fd }).then(r => r.json());
  }
  let pvT = null;
  function preview() {
    clearTimeout(pvT);
    pvT = setTimeout(() => post('preview').then(d => {
      if (d.error) { $('pvHead').textContent = d.error; return; }
      const wa = ch() === 'whatsapp';
      $('pvMail').style.display = wa ? 'none' : 'block'; $('pvWa').style.display = wa ? 'block' : 'none';
      $('pvHead').innerHTML = '<b>Preview for ' + d.name.replace(/</g, '&lt;') + '</b>' + (d.subject ? ' · Subject: ' + d.subject.replace(/</g, '&lt;') : '');
      if (wa) $('pvWaText').textContent = d.text; else $('pvMail').srcdoc = d.html;
    }), 350);
  }
  function mode() {
    const wa = ch() === 'whatsapp';
    $('subjWrap').style.display = wa ? 'none' : 'block';
    $('testBtn').style.display = wa ? 'none' : '';
    $('sendBtn').textContent = wa ? 'Start WhatsApp queue (' + ALL + ')' : 'Send to ' + EMAILABLE + ' client' + (EMAILABLE === 1 ? '' : 's');
    $('sendBtn').disabled = !wa && EMAILABLE === 0;
    $('note').textContent = wa ? 'Each chat opens with the message typed in; you press send in WhatsApp, then tap Next.'
      : 'You\'ll be asked to confirm. ' + (ALL - EMAILABLE ? (ALL - EMAILABLE) + ' client(s) who have not agreed to offers by email (turned off or not asked) are left out.' : '') ;
    preview();
  }
  document.querySelectorAll('.chip').forEach(c => c.addEventListener('click', () => {
    const b = $('body'), s = b.selectionStart || b.value.length; b.value = b.value.slice(0, s) + c.dataset.ins + b.value.slice(s); b.focus(); preview();
  }));
  f.querySelectorAll('input[name=channel]').forEach(r => r.addEventListener('change', mode));
  $('tpl').addEventListener('change', fill);
  ['subject', 'body'].forEach(id => $(id).addEventListener('input', preview));
  f.querySelector('input[name=book_button]').addEventListener('change', preview);
  $('testBtn').addEventListener('click', () => {
    $('testBtn').disabled = true;
    post('test').then(d => { alert(d.error ? 'Not sent: ' + d.error : 'Test sent to ' + d.to + '. Check your inbox.'); $('testBtn').disabled = false; });
  });
  $('sendBtn').addEventListener('click', () => {
    const wa = ch() === 'whatsapp';
    const msg = wa ? 'Start a WhatsApp queue for ' + ALL + ' clients?' : 'Send this email to ' + EMAILABLE + ' clients now? This can\'t be undone.';
    if (!confirm(msg)) return;
    $('sendBtn').disabled = true;
    post('create').then(d => { if (d.error) { alert('Not sent: ' + d.error); $('sendBtn').disabled = false; } else location.href = d.url; });
  });
  fill(); mode();
})();
</script>

<?php elseif ($view === 'batch'):
  $bid = (int)($_GET['id'] ?? 0);
  $bq = $db->prepare("SELECT * FROM message_batches WHERE id = ?"); $bq->execute([$bid]); $batch = $bq->fetch();
  if (!$batch): ?><div class="table-empty"><p>That batch wasn't found.</p></div><?php else:
  $counts = refreshBatchCounts($db, $bid);
  $title  = MESSAGE_TEMPLATES[$batch['template']]['label'] ?? 'Message';
  if ($batch['subject']) $title = $batch['subject'];
?>
<?php if ($batch['channel'] === 'email'): ?>
<div class="admin-form-card" style="padding:18px;max-width:720px">
  <h3 style="font-family:Montserrat,sans-serif;color:var(--admin-primary-dark);margin:0">✉️ <?= htmlspecialchars($title) ?></h3>
  <p style="color:var(--admin-muted);font-size:0.78rem;margin:4px 0 14px">Started <?= date('j M, g:i A', strtotime($batch['created_at'])) ?> · <?= htmlspecialchars((string)$batch['audience']) ?></p>
  <div class="prog"><i id="bar"></i></div>
  <div style="display:flex;justify-content:space-between;margin-top:8px;font-size:0.85rem"><strong id="done"></strong><span id="rest" style="color:var(--admin-muted)"></span></div>
  <p style="color:var(--admin-muted);font-size:0.75rem;margin-top:12px">You can leave this page: anything left is sent automatically within 30 minutes.</p>
  <div id="fails"></div>
</div>
<script>
(function () {
  const BID = <?= $bid ?>, total = <?= (int)$counts['pending'] + (int)$counts['sending'] + (int)$counts['sent'] + (int)$counts['failed'] ?>, skipped = <?= (int)$counts['skipped'] ?>;
  function show(c) {
    const sent = c.sent + c.opened, left = c.pending + c.sending, all = sent + c.failed + left;
    document.getElementById('bar').style.width = (all ? Math.round(100 * (sent + c.failed) / all) : 100) + '%';
    document.getElementById('done').textContent = left ? sent + ' of ' + all + ' sent' : '✓ ' + sent + ' sent';
    document.getElementById('rest').textContent = c.failed + ' failed' + (skipped ? ' · ' + skipped + ' skipped (no agreement to offers by email)' : '') + (left ? ' · sending…' : '');
    return left;
  }
  function step() {
    const fd = new FormData(); fd.append('action', 'send_chunk'); fd.append('batch_id', BID);
    fetch('/admin/messages.php', { method: 'POST', body: fd }).then(r => r.json()).then(d => {
      if (d.error) { document.getElementById('rest').textContent = d.error; return; }
      if (show(d.counts) > 0) setTimeout(step, 300); else if (d.counts.failed) location.reload();
    }).catch(() => setTimeout(step, 3000));
  }
  if (show(<?= json_encode($counts) ?>) > 0) step();
})();
</script>
<?php if ((int)$counts['failed'] > 0):
  $fq = $db->prepare("SELECT c.name, c.phone, r.error FROM message_batch_recipients r JOIN customers c ON c.id = r.customer_id WHERE r.batch_id = ? AND r.status = 'failed'");
  $fq->execute([$bid]); ?>
  <div class="admin-form-card" style="padding:18px;max-width:720px;margin-top:14px">
    <h3 style="font-size:0.85rem;margin:0 0 8px">Not delivered — WhatsApp these clients instead</h3>
    <?php foreach ($fq->fetchAll() as $f): $wa = waClientLink($f['phone'], buildBatchWhatsApp(['first' => trim(explode(' ', $f['name'])[0]), 'last_style' => 'last appointment'], (string)$batch['body'], (int)$batch['book_button'] === 1)); ?>
      <div class="q"><span style="flex:1"><strong><?= htmlspecialchars($f['name']) ?></strong><div style="color:var(--admin-muted);font-size:0.75rem"><?= htmlspecialchars((string)$f['error']) ?></div></span>
        <?php if ($wa): ?><a class="btn-admin btn-admin-sm" style="background:#25D366;border-color:#25D366;color:#fff" target="_blank" href="<?= htmlspecialchars($wa) ?>">WhatsApp</a><?php endif; ?></div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php else: /* WhatsApp queue */
  $rq = $db->prepare("SELECT r.id, r.status, r.sent_at, r.error, r.customer_id FROM message_batch_recipients r WHERE r.batch_id = ? ORDER BY r.id");
  $rq->execute([$bid]); $rows = $rq->fetchAll();
  $doneN = count(array_filter($rows, fn($r) => in_array($r['status'], ['opened', 'skipped', 'sent'], true)));
?>
<div class="admin-form-card" style="padding:18px;max-width:760px">
  <h3 style="font-family:Montserrat,sans-serif;color:var(--admin-primary-dark);margin:0">💬 WhatsApp queue — <?= count($rows) ?> clients</h3>
  <p style="color:var(--admin-muted);font-size:0.78rem;margin:4px 0 6px"><span id="qdone"><?= $doneN ?></span> done · tap <strong>Open WhatsApp</strong>, press send in WhatsApp, come back for the next one.</p>
  <?php $cur = true; foreach ($rows as $i => $r):
    $client = messageClient($db, (int)$r['customer_id']); if (!$client) continue;
    $text = buildBatchWhatsApp($client, (string)$batch['body'], (int)$batch['book_button'] === 1);
    $wa   = waClientLink($client['phone'], $text);
    $isDone = $r['status'] !== 'pending';
    $isCur  = !$isDone && $cur; if ($isCur) $cur = false; ?>
    <div class="q <?= $isDone ? 'done' : '' ?> <?= $isCur ? 'cur' : '' ?>" data-rid="<?= (int)$r['id'] ?>">
      <span class="n"><?= $isDone ? '✓' : $i + 1 ?></span>
      <span style="flex:1"><strong><?= htmlspecialchars($client['name']) ?></strong>
        <div style="color:var(--admin-muted);font-size:0.75rem"><?php
          if ($r['status'] === 'opened') echo 'Opened ' . date('g:i A', strtotime($r['sent_at']));
          elseif ($r['status'] === 'skipped') echo htmlspecialchars($r['error'] ?: 'Skipped');
          else echo htmlspecialchars(mb_strimwidth($text, 0, 70, '…')); ?></div></span>
      <?php if (!$isDone && $wa): ?>
        <a class="btn-admin btn-admin-sm qopen" style="background:#25D366;border-color:#25D366;color:#fff" target="_blank" href="<?= htmlspecialchars($wa) ?>">Open WhatsApp →</a>
        <button type="button" class="btn-admin btn-admin-outline btn-admin-sm qskip">Skip</button>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
<script>
(function () {
  const BID = <?= $bid ?>;
  function mark(row, action) {
    const fd = new FormData(); fd.append('action', action); fd.append('batch_id', BID); fd.append('recipient_id', row.dataset.rid);
    fetch('/admin/messages.php', { method: 'POST', body: fd }).then(r => r.json()).then(d => {
      row.classList.add('done'); row.classList.remove('cur');
      row.querySelector('.n').textContent = '✓';
      row.querySelectorAll('.qopen,.qskip').forEach(b => b.remove());
      const next = document.querySelector('.q:not(.done)'); if (next) { next.classList.add('cur'); next.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
      if (d.counts) document.getElementById('qdone').textContent = d.counts.opened + d.counts.skipped + d.counts.sent;
    });
  }
  document.querySelectorAll('.qopen').forEach(a => a.addEventListener('click', () => mark(a.closest('.q'), 'wa_open')));
  document.querySelectorAll('.qskip').forEach(b => b.addEventListener('click', () => mark(b.closest('.q'), 'wa_skip')));
})();
</script>
<?php endif; endif; ?>

<?php else: /* history */
  $hist = $db->query("SELECT * FROM message_batches ORDER BY id DESC LIMIT 100")->fetchAll();
?>
<div class="admin-table-wrap">
<?php if (!$hist): ?>
  <div class="table-empty"><div class="table-empty-icon">✉️</div><p>No batch messages yet. Go to <a href="/admin/customers?view=lapsed">Haven't booked lately</a>, tick clients and press "Message selected".</p></div>
<?php else: ?>
  <table class="admin-table">
    <thead><tr><th>Sent</th><th>Message</th><th>By</th><th>To</th><th>Result</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($hist as $h):
      $c = refreshBatchCounts($db, (int)$h['id']);
      $label = $h['subject'] ?: (MESSAGE_TEMPLATES[$h['template']]['label'] ?? 'Message'); ?>
      <tr>
        <td style="font-size:0.82rem"><?= date('j M Y, g:i A', strtotime($h['created_at'])) ?></td>
        <td><strong><?= htmlspecialchars($label) ?></strong></td>
        <td><?= $h['channel'] === 'email' ? 'Email' : 'WhatsApp' ?></td>
        <td style="font-size:0.82rem"><?= (int)$h['total'] ?> clients<?= $h['audience'] ? '<div class="td-muted">' . htmlspecialchars($h['audience']) . '</div>' : '' ?></td>
        <td style="font-size:0.8rem">
          <?php if ($h['channel'] === 'email'): ?>
            <span class="status-badge status-confirmed"><?= $c['sent'] ?> sent</span>
            <?php if ($c['failed']): ?><span class="status-badge status-cancelled"><?= $c['failed'] ?> failed</span><?php endif; ?>
            <?php if ($c['pending'] + $c['sending']): ?><span class="status-badge status-pending">sending…</span><?php endif; ?>
          <?php else: ?>
            <span class="status-badge status-pending"><?= $c['opened'] ?> of <?= (int)$h['total'] - $c['skipped'] ?> opened</span>
          <?php endif; ?>
          <?php if ($c['skipped']): ?><span class="td-muted"><?= $c['skipped'] ?> skipped</span><?php endif; ?>
        </td>
        <td><a href="/admin/messages.php?view=batch&amp;id=<?= (int)$h['id'] ?>" class="btn-admin btn-admin-outline btn-admin-sm">Open</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/layout-end.php'; ?>
