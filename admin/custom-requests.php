<?php
// ============================================================
// BraidedbyAGB — Admin Custom Requests
// FILE: /admin/custom-requests.php
// Route: /admin/custom-requests (add to admin .htaccess)
// ============================================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$db = getDB();

// ── Handle POST BEFORE any output ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = sanitize($_POST['action'] ?? '');
    $id     = (int)($_POST['request_id'] ?? 0);

    try {
        if ($id) {
            if ($action === 'mark_viewed') {
                // Only advance from 'new' to 'viewed'
                $db->prepare("UPDATE custom_requests SET status='viewed' WHERE id=? AND status='new'")
                   ->execute([$id]);
                header('Location: /admin/custom-requests?msg=Marked+as+viewed.&open=' . $id);
                exit;

            } elseif ($action === 'reply') {
                $reply  = trim($_POST['admin_reply'] ?? '');
                $status = sanitize($_POST['reply_status'] ?? 'replied');
                if (!in_array($status, ['replied','accepted','declined'])) $status = 'replied';

                if (!$reply) {
                    header('Location: /admin/custom-requests?error=Reply+cannot+be+empty.&open=' . $id);
                    exit;
                }

                $db->prepare("
                    UPDATE custom_requests
                    SET admin_reply=?, status=?, replied_at=NOW()
                    WHERE id=?
                ")->execute([$reply, $status, $id]);

                // Fetch request to send email
                $req = $db->query("SELECT * FROM custom_requests WHERE id=$id")->fetch();
                if ($req) {
                    try {
                        require_once __DIR__ . '/../includes/mailer.php';
                        emailCustomRequestReply($req, $reply, $status);
                    } catch (Throwable $e) {
                        error_log('Custom request reply email error: ' . $e->getMessage());
                    }
                }

                $label = match($status) {
                    'accepted' => 'Request+accepted+and+reply+sent.',
                    'declined' => 'Request+declined+and+reply+sent.',
                    default    => 'Reply+sent+successfully.',
                };
                header('Location: /admin/custom-requests?msg=' . $label . '&open=' . $id);
                exit;

            } elseif ($action === 'delete') {
                $req = $db->query("SELECT inspiration_url FROM custom_requests WHERE id=$id")->fetch();
                // Delete uploaded image if exists
                if ($req && $req['inspiration_url']) {
                    $filepath = __DIR__ . '/..' . $req['inspiration_url'];
                    if (file_exists($filepath)) @unlink($filepath);
                }
                $db->prepare("DELETE FROM custom_requests WHERE id=?")->execute([$id]);
                header('Location: /admin/custom-requests?msg=Request+deleted.');
                exit;
            }
        }
    } catch (Throwable $e) {
        error_log('Custom requests admin error: ' . $e->getMessage());
        header('Location: /admin/custom-requests?error=An+error+occurred.');
        exit;
    }
    header('Location: /admin/custom-requests');
    exit;
}

// ── Load data ─────────────────────────────────────────────
$statusFilter = sanitize($_GET['status'] ?? '');
$search       = sanitize($_GET['q'] ?? '');
$openId       = (int)($_GET['open'] ?? 0);   // auto-open a specific row
$msg          = htmlspecialchars($_GET['msg'] ?? '');
$errorMsg     = htmlspecialchars($_GET['error'] ?? '');

$where  = ['1=1'];
$params = [];
if ($statusFilter && in_array($statusFilter, ['new','viewed','replied','accepted','declined'])) {
    $where[] = 'status = ?'; $params[] = $statusFilter;
}
if ($search) {
    $where[] = '(name LIKE ? OR email LIKE ? OR ref LIKE ? OR style_desc LIKE ?)';
    $like = '%' . $search . '%';
    $params = array_merge($params, [$like,$like,$like,$like]);
}
$whereClause = implode(' AND ', $where);

$requests = $db->prepare("
    SELECT * FROM custom_requests
    WHERE $whereClause
    ORDER BY FIELD(status,'new','viewed','replied','accepted','declined'), created_at DESC
");
$requests->execute($params);
$requests = $requests->fetchAll();

// Badge counts for tabs
$counts = [];
foreach (['new','viewed','replied','accepted','declined'] as $s) {
    $counts[$s] = (int)$db->query("SELECT COUNT(*) FROM custom_requests WHERE status='$s'")->fetchColumn();
}
$totalNew = $counts['new'] + $counts['viewed']; // "needs attention"

$pageTitle = 'Custom Requests';
require_once __DIR__ . '/includes/layout.php';
?>

<?php if ($msg): ?>
<div class="alert-success">✓ <?= $msg ?></div>
<?php endif; ?>
<?php if ($errorMsg): ?>
<div class="alert-error">⚠ <?= $errorMsg ?></div>
<?php endif; ?>

<!-- Page header -->
<div class="page-header" style="margin-bottom:20px">
  <div>
    <h2 class="section-heading">Custom Style Requests</h2>
    <p style="color:var(--admin-muted);font-size:0.8rem">
      <?= array_sum($counts) ?> total ·
      <?php if ($totalNew > 0): ?>
        <span style="color:var(--admin-warning);font-weight:700"><?= $totalNew ?> awaiting response</span>
      <?php else: ?>
        <span style="color:var(--admin-success)">all up to date</span>
      <?php endif; ?>
    </p>
  </div>
  <!-- Search -->
  <form method="GET" action="/admin/custom-requests" style="display:flex;gap:8px;align-items:center">
    <input class="admin-search" type="text" name="q" placeholder="Search name, email, ref…"
           value="<?= htmlspecialchars($search) ?>" style="max-width:220px">
    <?php if ($statusFilter): ?>
      <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
    <?php endif; ?>
    <button type="submit" class="btn-admin btn-admin-primary btn-admin-sm">Search</button>
    <?php if ($search || $statusFilter): ?>
      <a href="/admin/custom-requests" class="btn-admin btn-admin-outline btn-admin-sm">Clear</a>
    <?php endif; ?>
  </form>
</div>

<!-- Status tabs -->
<div style="display:flex;gap:0;margin-bottom:20px;border-bottom:1px solid var(--admin-border);overflow-x:auto">
  <?php
  $tabs = [
    ''          => 'All',
    'new'       => '🆕 New',
    'viewed'    => '👁 Viewed',
    'replied'   => '💬 Replied',
    'accepted'  => '✅ Accepted',
    'declined'  => '❌ Declined',
  ];
  foreach ($tabs as $val => $label):
    $isActive = $statusFilter === $val;
    $count = $val === '' ? array_sum($counts) : ($counts[$val] ?? 0);
  ?>
  <a href="?status=<?= urlencode($val) ?><?= $search ? '&q='.urlencode($search) : '' ?>"
     style="padding:8px 16px;font-family:'Montserrat',sans-serif;font-size:0.72rem;font-weight:700;
            text-decoration:none;white-space:nowrap;
            border-bottom:2px solid <?= $isActive ? 'var(--admin-primary)' : 'transparent' ?>;
            color:<?= $isActive ? 'var(--admin-primary)' : 'var(--admin-muted)' ?>;
            margin-bottom:-1px">
    <?= $label ?>
    <?php if ($count > 0): ?>
      <span class="admin-badge" style="margin-left:5px<?= $val === 'new' ? ';background:var(--admin-warning)' : '' ?>"><?= $count ?></span>
    <?php endif; ?>
  </a>
  <?php endforeach; ?>
</div>

<!-- Requests list -->
<?php if (empty($requests)): ?>
<div class="table-empty">
  <div class="table-empty-icon">✨</div>
  <p>No custom requests<?= $statusFilter ? ' with status "' . $statusFilter . '"' : '' ?> yet.</p>
  <?php if ($statusFilter || $search): ?>
    <a href="/admin/custom-requests" class="btn-admin btn-admin-outline btn-admin-sm" style="margin-top:12px">Clear filters</a>
  <?php endif; ?>
</div>

<?php else: ?>

<?php foreach ($requests as $req):
  $isOpen  = $openId === (int)$req['id'];
  $isNew   = $req['status'] === 'new';
  $statusColour = match($req['status']) {
    'new'      => 'var(--admin-warning)',
    'viewed'   => '#6366f1',
    'replied'  => 'var(--admin-primary)',
    'accepted' => 'var(--admin-success)',
    'declined' => 'var(--admin-danger)',
    default    => 'var(--admin-muted)',
  };
  $statusLabel = match($req['status']) {
    'new'      => '🆕 New',
    'viewed'   => '👁 Viewed',
    'replied'  => '💬 Replied',
    'accepted' => '✅ Accepted',
    'declined' => '❌ Declined',
    default    => ucfirst($req['status']),
  };
?>
<div class="admin-card" id="req-card-<?= $req['id'] ?>"
     style="margin-bottom:8px;<?= $isNew ? 'border-left:3px solid var(--admin-warning)' : '' ?>">

  <!-- ── Collapsed header ─────────────────────────────── -->
  <div style="display:flex;align-items:center;gap:12px;padding:13px 16px;cursor:pointer"
       onclick="toggleRequest(<?= $req['id'] ?>)">
    <span id="req-arrow-<?= $req['id'] ?>" style="font-size:0.9rem;color:var(--admin-muted);width:14px;flex-shrink:0">
      <?= $isOpen ? '▾' : '▸' ?>
    </span>

    <!-- Name + ref -->
    <div style="flex:1;min-width:0">
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
        <span style="font-family:'Montserrat',sans-serif;font-weight:800;font-size:0.88rem;color:var(--admin-primary-dark)">
          <?= htmlspecialchars($req['name']) ?>
        </span>
        <span style="font-size:0.68rem;color:var(--admin-muted)"><?= htmlspecialchars($req['ref']) ?></span>
        <?php if ($isNew): ?>
          <span style="background:var(--admin-warning);color:#fff;font-size:0.6rem;font-weight:700;padding:2px 7px;border-radius:10px">NEW</span>
        <?php endif; ?>
      </div>
      <div style="font-size:0.75rem;color:var(--admin-muted);margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:500px">
        <?= htmlspecialchars(mb_substr($req['style_desc'], 0, 100)) ?><?= mb_strlen($req['style_desc']) > 100 ? '…' : '' ?>
      </div>
    </div>

    <!-- Meta -->
    <div style="display:flex;align-items:center;gap:10px;flex-shrink:0;flex-wrap:wrap">
      <?php if ($req['preferred_date']): ?>
        <span style="font-size:0.72rem;color:var(--admin-muted)">📅 <?= date('j M', strtotime($req['preferred_date'])) ?></span>
      <?php endif; ?>
      <?php if ($req['budget_range']): ?>
        <span style="font-size:0.72rem;color:var(--admin-muted)">💷 <?= htmlspecialchars($req['budget_range']) ?></span>
      <?php endif; ?>
      <span style="font-size:0.7rem;color:var(--admin-muted)"><?= date('j M Y', strtotime($req['created_at'])) ?></span>
      <span class="status-badge" style="background:<?= $statusColour ?>15;color:<?= $statusColour ?>;border:1px solid <?= $statusColour ?>40">
        <?= $statusLabel ?>
      </span>
    </div>
  </div>

  <!-- ── Expanded panel ───────────────────────────────── -->
  <div id="req-body-<?= $req['id'] ?>" style="display:<?= $isOpen ? 'block' : 'none' ?>;border-top:1px solid var(--admin-border);padding:20px">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:20px">

      <!-- Left: Request details -->
      <div>
        <p style="font-family:'Montserrat',sans-serif;font-size:0.62rem;font-weight:700;letter-spacing:0.15em;text-transform:uppercase;color:var(--admin-muted);margin-bottom:10px">Request Details</p>

        <div class="detail-row"><span class="dl">Name</span><span class="dv"><?= htmlspecialchars($req['name']) ?></span></div>
        <div class="detail-row">
          <span class="dl">Email</span>
          <span class="dv"><a href="mailto:<?= htmlspecialchars($req['email']) ?>"><?= htmlspecialchars($req['email']) ?></a></span>
        </div>
        <?php if ($req['phone']): ?>
        <div class="detail-row">
          <span class="dl">Phone</span>
          <span class="dv">
            <a href="tel:<?= htmlspecialchars($req['phone']) ?>"><?= htmlspecialchars($req['phone']) ?></a>
            &nbsp;·&nbsp;
            <a href="https://wa.me/44<?= ltrim(preg_replace('/\s+/','',$req['phone']),'0') ?>" target="_blank" rel="noopener"
               style="color:#25D366;font-weight:700">WhatsApp →</a>
          </span>
        </div>
        <?php endif; ?>
        <?php if ($req['hair_length']): ?>
        <div class="detail-row">
          <span class="dl">Hair Length</span>
          <span class="dv"><?= ucfirst(str_replace('_',' ',$req['hair_length'])) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($req['budget_range']): ?>
        <div class="detail-row">
          <span class="dl">Budget</span>
          <span class="dv"><?= htmlspecialchars($req['budget_range']) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($req['preferred_date']): ?>
        <div class="detail-row">
          <span class="dl">Preferred Date</span>
          <span class="dv"><?= date('l, j F Y', strtotime($req['preferred_date'])) ?></span>
        </div>
        <?php endif; ?>
        <div class="detail-row">
          <span class="dl">Received</span>
          <span class="dv td-muted"><?= date('j M Y, g:ia', strtotime($req['created_at'])) ?></span>
        </div>

        <div style="margin-top:14px">
          <p style="font-family:'Montserrat',sans-serif;font-size:0.62rem;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;color:var(--admin-muted);margin-bottom:8px">Style Description</p>
          <div style="background:var(--admin-bg);border:1px solid var(--admin-border);border-radius:var(--admin-radius);padding:12px 14px;font-size:0.85rem;line-height:1.7;color:var(--admin-text)">
            <?= nl2br(htmlspecialchars($req['style_desc'])) ?>
          </div>
        </div>
      </div>

      <!-- Right: Image + previous reply -->
      <div>
        <?php if ($req['inspiration_url']): ?>
        <div style="margin-bottom:18px">
          <p style="font-family:'Montserrat',sans-serif;font-size:0.62rem;font-weight:700;letter-spacing:0.15em;text-transform:uppercase;color:var(--admin-muted);margin-bottom:10px">Inspiration Image</p>
          <a href="<?= htmlspecialchars($req['inspiration_url']) ?>" target="_blank" rel="noopener">
            <img src="<?= htmlspecialchars($req['inspiration_url']) ?>"
                 alt="Inspiration image"
                 style="max-width:100%;max-height:260px;object-fit:cover;border-radius:var(--admin-radius-lg);border:1px solid var(--admin-border);display:block">
          </a>
          <a href="<?= htmlspecialchars($req['inspiration_url']) ?>" target="_blank" rel="noopener"
             class="btn-admin btn-admin-outline btn-admin-sm" style="margin-top:8px;display:inline-flex;align-items:center;gap:5px">
            🔍 Open Full Size
          </a>
        </div>
        <?php else: ?>
        <div style="background:var(--admin-bg);border:1px dashed var(--admin-border);border-radius:var(--admin-radius-lg);padding:24px;text-align:center;margin-bottom:18px">
          <p style="color:var(--admin-muted);font-size:0.78rem">No inspiration image uploaded</p>
        </div>
        <?php endif; ?>

        <?php if ($req['admin_reply']): ?>
        <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:var(--admin-radius);padding:14px">
          <p style="font-family:'Montserrat',sans-serif;font-size:0.62rem;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;color:#166534;margin-bottom:8px">
            Your Previous Reply · <?= date('j M Y', strtotime($req['replied_at'])) ?>
          </p>
          <p style="font-size:0.82rem;color:#1a3a2a;line-height:1.65">
            <?= nl2br(htmlspecialchars($req['admin_reply'])) ?>
          </p>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- ── Reply form ────────────────────────────────── -->
    <?php if (!in_array($req['status'], ['accepted','declined'])): ?>
    <div style="background:#faf8fd;border:1px solid var(--admin-border);border-radius:var(--admin-radius-lg);padding:18px">
      <p style="font-family:'Montserrat',sans-serif;font-size:0.72rem;font-weight:800;color:var(--admin-primary-dark);margin-bottom:14px">
        📩 Reply to <?= htmlspecialchars(explode(' ',$req['name'])[0]) ?>
      </p>
      <form method="POST" action="/admin/custom-requests">
        <input type="hidden" name="action"     value="reply">
        <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
        <div class="admin-form-group" style="margin-bottom:12px">
          <textarea name="admin_reply" class="admin-input admin-textarea" rows="5"
                    placeholder="Type your reply here — price estimate, availability, questions, or a decline with kind explanation…"
                    required style="font-size:0.85rem"><?= htmlspecialchars($req['admin_reply'] ?? '') ?></textarea>
        </div>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
          <select name="reply_status" class="admin-input admin-select" style="width:auto;font-size:0.82rem">
            <option value="replied">Mark as: Replied</option>
            <option value="accepted">Mark as: Accepted ✅</option>
            <option value="declined">Mark as: Declined ❌</option>
          </select>
          <button type="submit" class="btn-admin btn-admin-primary">Send Reply & Update Status</button>
          <!-- Quick mark viewed if still new -->
          <?php if ($req['status'] === 'new'): ?>
          <form method="POST" action="/admin/custom-requests" style="display:inline">
            <input type="hidden" name="action"     value="mark_viewed">
            <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
            <button type="submit" class="btn-admin btn-admin-outline btn-admin-sm">Mark Viewed</button>
          </form>
          <?php endif; ?>
        </div>
      </form>
    </div>
    <?php else: ?>
    <!-- Already actioned — show re-reply option collapsed -->
    <details style="margin-top:4px">
      <summary class="btn-admin btn-admin-outline btn-admin-sm" style="cursor:pointer;list-style:none;display:inline-flex">
        Send another reply
      </summary>
      <div style="background:#faf8fd;border:1px solid var(--admin-border);border-radius:var(--admin-radius-lg);padding:18px;margin-top:10px">
        <form method="POST" action="/admin/custom-requests">
          <input type="hidden" name="action"     value="reply">
          <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
          <div class="admin-form-group" style="margin-bottom:12px">
            <textarea name="admin_reply" class="admin-input admin-textarea" rows="4" required placeholder="Follow-up message…"></textarea>
          </div>
          <div style="display:flex;gap:10px;align-items:center">
            <select name="reply_status" class="admin-input admin-select" style="width:auto;font-size:0.82rem">
              <option value="replied">Replied</option>
              <option value="accepted" <?= $req['status']==='accepted'?'selected':'' ?>>Accepted ✅</option>
              <option value="declined" <?= $req['status']==='declined'?'selected':'' ?>>Declined ❌</option>
            </select>
            <button type="submit" class="btn-admin btn-admin-primary btn-admin-sm">Send</button>
          </div>
        </form>
      </div>
    </details>
    <?php endif; ?>

    <!-- Delete -->
    <form method="POST" action="/admin/custom-requests" style="margin-top:14px">
      <input type="hidden" name="action"     value="delete">
      <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
      <button type="submit" class="btn-admin btn-admin-outline btn-admin-sm"
              onclick="return confirm('Delete this request permanently? This cannot be undone.')"
              style="color:var(--admin-danger);border-color:var(--admin-danger)">
        🗑 Delete Request
      </button>
    </form>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<script>
function toggleRequest(id) {
  const body  = document.getElementById('req-body-' + id);
  const arrow = document.getElementById('req-arrow-' + id);
  const open  = body.style.display !== 'none';
  body.style.display  = open ? 'none' : 'block';
  arrow.textContent   = open ? '▸' : '▾';

  // Mark as viewed via fetch if status is 'new'
  if (!open) {
    const card = document.getElementById('req-card-' + id);
    if (card && card.style.borderLeft.includes('warning')) {
      fetch('/admin/custom-requests', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'action=mark_viewed&request_id=' + id
      });
      card.style.borderLeft = '';
    }
  }
}
// Auto-open if ?open= param set
<?php if ($openId): ?>
document.addEventListener('DOMContentLoaded', () => {
  const body = document.getElementById('req-body-<?= $openId ?>');
  if (body) body.style.display = 'block';
  const arrow = document.getElementById('req-arrow-<?= $openId ?>');
  if (arrow) arrow.textContent = '▾';
  document.getElementById('req-card-<?= $openId ?>')?.scrollIntoView({behavior:'smooth',block:'start'});
});
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/includes/layout-end.php'; ?>
