<?php
// ============================================================
// BraidedbyAGB — Admin Reviews
// FILE: /admin/reviews.php
// ============================================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$db = getDB();

$pageTitle = 'Reviews';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id     = (int)($_POST['review_id'] ?? 0);
    $action = sanitize($_POST['action'] ?? '');
    if ($id) {
        if ($action === 'approve') {
            $db->prepare("UPDATE reviews SET status='approved', approved_at=NOW() WHERE id=?")->execute([$id]);
            // Queue a review incentive if enabled (handled by cron)
        } elseif ($action === 'reject') {
            $db->prepare("UPDATE reviews SET status='rejected' WHERE id=?")->execute([$id]);
        } elseif ($action === 'feature') {
            $db->prepare("UPDATE reviews SET is_featured = NOT is_featured WHERE id=?")->execute([$id]);
        } elseif ($action === 'delete') {
            $db->prepare("DELETE FROM reviews WHERE id=?")->execute([$id]);
        }
    }
    header('Location: /admin/reviews?msg=Done.');
    exit;
}


$pageTitle = $pageTitle ?? 'Reviews';
require_once __DIR__ . '/includes/layout.php';

$statusFilter = sanitize($_GET['status'] ?? 'pending');
$page    = max(1,(int)($_GET['page']??1));
$perPage = 20;
$offset  = ($page-1)*$perPage;

$where  = ['1=1']; $params = [];
if ($statusFilter && in_array($statusFilter,['pending','approved','rejected'])) {
    $where[] = 'r.status=?'; $params[] = $statusFilter;
}
$whereClause = implode(' AND ', $where);

$total = $db->prepare("SELECT COUNT(*) FROM reviews r WHERE $whereClause");
$total->execute($params); $total = (int)$total->fetchColumn();
$pages = ceil($total / $perPage);

$stmt = $db->prepare("
    SELECT r.*, c.name as c_name, c.email as c_email,
           s.name as s_name, p.name as p_name
    FROM reviews r
    JOIN customers c ON c.id = r.customer_id
    LEFT JOIN services s ON s.id = r.service_id
    LEFT JOIN products p ON p.id = r.product_id
    WHERE $whereClause
    ORDER BY r.submitted_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$reviews = $stmt->fetchAll();

$msg = sanitize($_GET['msg'] ?? '');
?>

<?php if ($msg): ?>
<div style="background:#d1fae5;border:1px solid #6ee7b7;padding:10px 16px;border-radius:var(--admin-radius);margin-bottom:16px;font-size:0.82rem;color:#065f46">
  ✓ <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<!-- Tabs -->
<div style="display:flex;gap:6px;margin-bottom:20px;border-bottom:1px solid var(--admin-border);padding-bottom:0">
  <?php foreach (['pending'=>'⏳ Pending','approved'=>'✅ Approved','rejected'=>'❌ Rejected'] as $s => $label):
    $count = $db->query("SELECT COUNT(*) FROM reviews WHERE status='$s'")->fetchColumn();
  ?>
  <a href="?status=<?= $s ?>"
     style="padding:8px 16px;font-family:'Montserrat',sans-serif;font-size:0.75rem;font-weight:700;text-decoration:none;border-bottom:2px solid <?= $statusFilter===$s ? 'var(--admin-primary)' : 'transparent' ?>;color:<?= $statusFilter===$s ? 'var(--admin-primary)' : 'var(--admin-muted)' ?>;margin-bottom:-1px">
    <?= $label ?> <?php if ($count): ?><span class="admin-badge" style="margin-left:4px"><?= $count ?></span><?php endif; ?>
  </a>
  <?php endforeach; ?>
</div>

<div class="admin-table-wrap">
  <div class="admin-table-header">
    <span class="admin-table-title"><?= $total ?> review<?= $total!=1?'s':'' ?> · <?= ucfirst($statusFilter) ?></span>
  </div>

  <?php if (empty($reviews)): ?>
    <div class="table-empty"><div class="table-empty-icon">⭐</div><p>No <?= $statusFilter ?> reviews</p></div>
  <?php else: ?>
  <?php foreach ($reviews as $r): ?>
  <div style="padding:16px 20px;border-bottom:1px solid var(--admin-border)">
    <div style="display:flex;align-items:flex-start;gap:16px">
      <!-- Star rating -->
      <div style="flex-shrink:0">
        <div class="admin-stars"><?= str_repeat('★', (int)$r['rating']) . str_repeat('☆', 5-(int)$r['rating']) ?></div>
        <div style="font-size:0.65rem;color:var(--admin-muted);margin-top:2px"><?= (int)$r['rating'] ?>/5</div>
      </div>

      <!-- Review content -->
      <div style="flex:1;min-width:0">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;flex-wrap:wrap">
          <span style="font-weight:700;font-size:0.85rem"><?= htmlspecialchars($r['c_name']) ?></span>
          <span style="font-size:0.72rem;color:var(--admin-muted)"><?= htmlspecialchars($r['c_email']) ?></span>
          <?php if ($r['s_name']): ?>
            <span class="status-badge status-pending" style="font-size:0.6rem"><?= htmlspecialchars($r['s_name']) ?></span>
          <?php elseif ($r['p_name']): ?>
            <span class="status-badge status-pending" style="font-size:0.6rem"><?= htmlspecialchars($r['p_name']) ?></span>
          <?php endif; ?>
          <?php if ($r['is_featured']): ?>
            <span class="status-badge status-confirmed" style="font-size:0.6rem">⭐ Featured</span>
          <?php endif; ?>
          <span style="font-size:0.68rem;color:var(--admin-muted);margin-left:auto"><?= date('j M Y', strtotime($r['created_at'])) ?></span>
        </div>

        <p style="font-size:0.82rem;color:var(--admin-text);line-height:1.6;margin-bottom:8px">
          "<?= htmlspecialchars($r['review_text']) ?>"
        </p>

        <?php if ($r['photo_url']): ?>
          <img src="<?= htmlspecialchars($r['photo_url']) ?>" alt="Review photo"
               style="max-width:120px;border-radius:var(--admin-radius);margin-bottom:8px;object-fit:cover;max-height:120px">
        <?php endif; ?>
      </div>

      <!-- Actions -->
      <div style="display:flex;flex-direction:column;gap:5px;flex-shrink:0">
        <?php if ($r['status'] === 'pending'): ?>
          <form method="POST">
            <input type="hidden" name="review_id" value="<?= $r['id'] ?>">
            <input type="hidden" name="action" value="approve">
            <button type="submit" class="btn-admin btn-admin-success btn-admin-sm">✓ Approve</button>
          </form>
          <form method="POST">
            <input type="hidden" name="review_id" value="<?= $r['id'] ?>">
            <input type="hidden" name="action" value="reject">
            <button type="submit" class="btn-admin btn-admin-danger btn-admin-sm">✕ Reject</button>
          </form>
        <?php endif; ?>
        <?php if ($r['status'] === 'approved'): ?>
          <form method="POST">
            <input type="hidden" name="review_id" value="<?= $r['id'] ?>">
            <input type="hidden" name="action" value="feature">
            <button type="submit" class="btn-admin btn-admin-gold btn-admin-sm">
              <?= $r['is_featured'] ? '★ Unfeature' : '★ Feature' ?>
            </button>
          </form>
        <?php endif; ?>
        <form method="POST">
          <input type="hidden" name="review_id" value="<?= $r['id'] ?>">
          <input type="hidden" name="action" value="delete">
          <button type="submit" class="btn-admin btn-admin-outline btn-admin-sm"
                  onclick="return confirm('Delete this review permanently?')">Delete</button>
        </form>
      </div>
    </div>
  </div>
  <?php endforeach; ?>

  <?php if ($pages > 1): ?>
  <div class="admin-pagination">
    <?php for ($p = 1; $p <= $pages; $p++): ?>
      <a href="?status=<?= urlencode($statusFilter) ?>&page=<?= $p ?>"
         class="admin-page-btn <?= $p===$page?'active':'' ?>"><?= $p ?></a>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/layout-end.php'; ?>
