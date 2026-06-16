<?php
// ============================================================
// BraidedbyAGB — Admin Accounting Dashboard
// FILE: /admin/accounting.php
// ============================================================
$pageTitle = 'Accounting';
require_once __DIR__ . '/includes/layout.php';

$msg = '';

// ── POST: Log expense ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = sanitize($_POST['action'] ?? '');

    if ($action === 'log_expense') {
        $amount   = (float)($_POST['amount']      ?? 0);
        $desc     = trim($_POST['description']    ?? '');
        $category = trim($_POST['category']       ?? 'Business Expenses');
        $date     = trim($_POST['expense_date']   ?? date('Y-m-d'));
        $notes    = trim($_POST['notes']          ?? '');
        if ($amount > 0 && $desc) {
            $db->prepare("INSERT INTO expenses (expense_date, description, amount, category, notes) VALUES (?,?,?,?,?)")
               ->execute([$date, $desc, $amount, $category, $notes]);
            $expId = (int)$db->lastInsertId();
            journalExpense($expId, $amount, $date, $desc);
            $msg = 'Expense logged successfully.';
        }
    }

    if ($action === 'log_draw') {
        $amount = (float)($_POST['amount'] ?? 0);
        $date   = trim($_POST['draw_date'] ?? date('Y-m-d'));
        $notes  = trim($_POST['notes']     ?? '');
        if ($amount > 0) {
            $db->prepare("INSERT INTO owner_draws (draw_date, amount, notes) VALUES (?,?,?)")
               ->execute([$date, $amount, $notes]);
            $drawId = (int)$db->lastInsertId();
            journalOwnerDraw($drawId, $amount, $date);
            $msg = 'Owner draw logged successfully.';
        }
    }
}

// ── Fetch summary data ────────────────────────────────────
$today      = date('Y-m-d');
$monthStart = date('Y-m-01');
$monthEnd   = date('Y-m-t');

// Revenue this month (completed bookings)
$revStmt = $db->prepare("SELECT COALESCE(SUM(total_price),0) FROM bookings WHERE status='completed' AND booked_date BETWEEN ? AND ?");
$revStmt->execute([$monthStart, $monthEnd]);
$monthRevenue = (float)$revStmt->fetchColumn();

// Expenses this month
$expStmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date BETWEEN ? AND ?");
$expStmt->execute([$monthStart, $monthEnd]);
$monthExpenses = (float)$expStmt->fetchColumn();

// Draws this month
$drawStmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM owner_draws WHERE draw_date BETWEEN ? AND ?");
$drawStmt->execute([$monthStart, $monthEnd]);
$monthDraws = (float)$drawStmt->fetchColumn();

$monthProfit = $monthRevenue - $monthExpenses - $monthDraws;

// Today's takings
$todayStmt = $db->prepare("SELECT COALESCE(SUM(total_price),0) FROM bookings WHERE status='completed' AND booked_date=?");
$todayStmt->execute([$today]);
$todayTakings = (float)$todayStmt->fetchColumn();

// Recent expenses
$expenses = $db->query("SELECT * FROM expenses ORDER BY expense_date DESC, created_at DESC LIMIT 30")->fetchAll();

// Recent draws
$draws = $db->query("SELECT * FROM owner_draws ORDER BY draw_date DESC, created_at DESC LIMIT 15")->fetchAll();

// Expense categories
$categories = ['Business Expenses', 'Cost of Sales', 'Marketing', 'Equipment', 'Transport', 'Other'];
?>

<?php if ($msg): ?>
  <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:12px 18px;margin-bottom:20px;color:#166534;font-weight:600">
    ✅ <?= htmlspecialchars($msg) ?>
  </div>
<?php endif; ?>

<div class="page-header" style="margin-bottom:24px">
  <div>
    <h2 class="section-heading">Accounting</h2>
    <p style="color:var(--admin-muted);font-size:0.8rem"><?= date('F Y') ?> — Month to date</p>
  </div>
</div>

<!-- Summary cards -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:28px">
  <div style="background:#fff;border:1px solid var(--admin-border,#e5e7eb);border-radius:10px;padding:18px;text-align:center">
    <div style="font-size:0.72rem;font-weight:700;color:var(--admin-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">Today's Takings</div>
    <div style="font-size:1.6rem;font-weight:800;color:#16a34a">£<?= number_format($todayTakings, 2) ?></div>
  </div>
  <div style="background:#fff;border:1px solid var(--admin-border,#e5e7eb);border-radius:10px;padding:18px;text-align:center">
    <div style="font-size:0.72rem;font-weight:700;color:var(--admin-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">Month Revenue</div>
    <div style="font-size:1.6rem;font-weight:800;color:var(--admin-primary)">£<?= number_format($monthRevenue, 2) ?></div>
  </div>
  <div style="background:#fff;border:1px solid var(--admin-border,#e5e7eb);border-radius:10px;padding:18px;text-align:center">
    <div style="font-size:0.72rem;font-weight:700;color:var(--admin-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">Expenses + Draws</div>
    <div style="font-size:1.6rem;font-weight:800;color:#dc2626">£<?= number_format($monthExpenses + $monthDraws, 2) ?></div>
  </div>
  <div style="background:<?= $monthProfit >= 0 ? '#f0fdf4' : '#fef2f2' ?>;border:1px solid <?= $monthProfit >= 0 ? '#86efac' : '#fca5a5' ?>;border-radius:10px;padding:18px;text-align:center">
    <div style="font-size:0.72rem;font-weight:700;color:var(--admin-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">Net Profit</div>
    <div style="font-size:1.6rem;font-weight:800;color:<?= $monthProfit >= 0 ? '#16a34a' : '#dc2626' ?>">
      <?= $monthProfit < 0 ? '-' : '' ?>£<?= number_format(abs($monthProfit), 2) ?>
    </div>
  </div>
</div>

<!-- Log forms side by side -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:28px">

  <!-- Log Expense -->
  <div style="background:#fff;border:1px solid var(--admin-border,#e5e7eb);border-radius:10px;padding:24px">
    <h3 style="font-size:0.75rem;font-weight:700;color:var(--admin-muted);text-transform:uppercase;letter-spacing:.5px;margin:0 0 18px">Log Expense</h3>
    <form method="POST" style="display:flex;flex-direction:column;gap:12px">
      <input type="hidden" name="action" value="log_expense">
      <div>
        <label style="font-size:0.8rem;font-weight:600;color:var(--admin-text);display:block;margin-bottom:4px">Amount (£) *</label>
        <input type="number" name="amount" step="0.01" min="0.01" required class="admin-input" placeholder="0.00" style="width:100%">
      </div>
      <div>
        <label style="font-size:0.8rem;font-weight:600;color:var(--admin-text);display:block;margin-bottom:4px">Description *</label>
        <input type="text" name="description" required class="admin-input" placeholder="e.g. Hair extensions stock" style="width:100%">
      </div>
      <div>
        <label style="font-size:0.8rem;font-weight:600;color:var(--admin-text);display:block;margin-bottom:4px">Category</label>
        <select name="category" class="admin-input" style="width:100%">
          <?php foreach ($categories as $cat): ?>
            <option value="<?= $cat ?>"><?= $cat ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label style="font-size:0.8rem;font-weight:600;color:var(--admin-text);display:block;margin-bottom:4px">Date</label>
        <input type="date" name="expense_date" value="<?= $today ?>" class="admin-input" style="width:100%">
      </div>
      <button class="btn-admin btn-admin-primary">Log Expense</button>
    </form>
  </div>

  <!-- Pay Myself -->
  <div style="background:#fff;border:1px solid var(--admin-border,#e5e7eb);border-radius:10px;padding:24px">
    <h3 style="font-size:0.75rem;font-weight:700;color:var(--admin-muted);text-transform:uppercase;letter-spacing:.5px;margin:0 0 18px">Pay Myself</h3>
    <form method="POST" style="display:flex;flex-direction:column;gap:12px">
      <input type="hidden" name="action" value="log_draw">
      <div>
        <label style="font-size:0.8rem;font-weight:600;color:var(--admin-text);display:block;margin-bottom:4px">Amount (£) *</label>
        <input type="number" name="amount" step="0.01" min="0.01" required class="admin-input" placeholder="0.00" style="width:100%">
      </div>
      <div>
        <label style="font-size:0.8rem;font-weight:600;color:var(--admin-text);display:block;margin-bottom:4px">Date</label>
        <input type="date" name="draw_date" value="<?= $today ?>" class="admin-input" style="width:100%">
      </div>
      <div>
        <label style="font-size:0.8rem;font-weight:600;color:var(--admin-text);display:block;margin-bottom:4px">Notes (optional)</label>
        <input type="text" name="notes" class="admin-input" placeholder="e.g. Monthly salary" style="width:100%">
      </div>
      <button class="btn-admin btn-admin-primary">💸 Pay Myself</button>
    </form>

    <!-- Month draw total -->
    <div style="margin-top:20px;padding:14px;background:#fafafa;border-radius:8px">
      <div style="font-size:0.75rem;color:var(--admin-muted);margin-bottom:4px">Drawn this month</div>
      <div style="font-size:1.3rem;font-weight:800;color:var(--admin-text)">£<?= number_format($monthDraws, 2) ?></div>
    </div>
  </div>
</div>

<!-- Recent expenses -->
<div style="background:#fff;border:1px solid var(--admin-border,#e5e7eb);border-radius:10px;padding:20px;margin-bottom:20px">
  <h3 style="font-size:0.75rem;font-weight:700;color:var(--admin-muted);text-transform:uppercase;letter-spacing:.5px;margin:0 0 14px">Recent Expenses</h3>
  <?php if (empty($expenses)): ?>
    <p style="color:var(--admin-muted);font-size:0.85rem">No expenses logged yet.</p>
  <?php else: ?>
    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead>
          <tr><th>Date</th><th>Description</th><th>Category</th><th style="text-align:right">Amount</th></tr>
        </thead>
        <tbody>
        <?php foreach ($expenses as $e): ?>
          <tr>
            <td style="font-size:0.82rem"><?= date('j M Y', strtotime($e['expense_date'])) ?></td>
            <td><?= htmlspecialchars($e['description']) ?></td>
            <td style="color:var(--admin-muted);font-size:0.82rem"><?= htmlspecialchars($e['category']) ?></td>
            <td style="font-weight:700;color:#dc2626;text-align:right">£<?= number_format((float)$e['amount'], 2) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<!-- Recent draws -->
<?php if (!empty($draws)): ?>
<div style="background:#fff;border:1px solid var(--admin-border,#e5e7eb);border-radius:10px;padding:20px">
  <h3 style="font-size:0.75rem;font-weight:700;color:var(--admin-muted);text-transform:uppercase;letter-spacing:.5px;margin:0 0 14px">Owner Draws</h3>
  <div class="admin-table-wrap">
    <table class="admin-table">
      <thead>
        <tr><th>Date</th><th>Notes</th><th style="text-align:right">Amount</th></tr>
      </thead>
      <tbody>
      <?php foreach ($draws as $d): ?>
        <tr>
          <td style="font-size:0.82rem"><?= date('j M Y', strtotime($d['draw_date'])) ?></td>
          <td style="color:var(--admin-muted)"><?= htmlspecialchars($d['notes'] ?? '—') ?></td>
          <td style="font-weight:700;color:var(--admin-text);text-align:right">£<?= number_format((float)$d['amount'], 2) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/layout-end.php'; ?>
