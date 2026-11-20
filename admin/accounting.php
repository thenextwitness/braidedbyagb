<?php
// ============================================================
// BraidedbyAGB — Admin Accounting
// FILE: /admin/accounting.php   (/admin/accounting.php?tab=overview|reports|expenses|owed)
//
// Every figure comes from the double-entry ledger (includes/finance.php), so
// services, shop sales, travel fees, courses, forfeits, refunds and expenses
// all agree across tabs, exports and the app.
// ============================================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$db = getDB();

$tab = in_array($_GET['tab'] ?? '', ['overview', 'reports', 'expenses', 'owed'], true) ? $_GET['tab'] : 'overview';
[$pFrom, $pTo, $pLabel] = financePeriod((string)($_GET['period'] ?? 'this_month'), $_GET['from'] ?? null, $_GET['to'] ?? null);
$periodQs = http_build_query(array_filter(['period' => $_GET['period'] ?? null, 'from' => $_GET['from'] ?? null, 'to' => $_GET['to'] ?? null]));

// ── POST actions (Post/Redirect/Get so a refresh never repeats one) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $back   = (string)($_POST['tab'] ?? 'overview');
    $note   = 'Saved.';
    try {
        if ($action === 'log_expense') {
            $amount = round((float)($_POST['amount'] ?? 0), 2);
            $desc   = trim(strip_tags((string)($_POST['description'] ?? '')));
            $cat    = in_array($_POST['category'] ?? '', EXPENSE_CATEGORIES, true) ? $_POST['category'] : 'Other';
            $date   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['expense_date'] ?? '')) ? $_POST['expense_date'] : date('Y-m-d');
            $from   = in_array($_POST['paid_from'] ?? '', ['bank', 'cash', 'stripe'], true) ? $_POST['paid_from'] : 'bank';
            $notes  = trim(strip_tags((string)($_POST['notes'] ?? '')));
            $receipt = null;
            if (!empty($_FILES['receipt']['name'])) {
                $receipt = uploadReceipt($_FILES['receipt']);
                if ($receipt === false) throw new RuntimeException('Receipt not saved: use a photo (JPG/PNG/WebP) or PDF under 8 MB. The expense was not logged.');
            }
            if ($amount <= 0 || $desc === '') throw new RuntimeException('Enter an amount and a description.');
            logExpense($db, $date, $desc, $amount, $cat, $from, $notes, $receipt);
            if (!empty($_POST['repeat_monthly'])) {
                $db->prepare("INSERT INTO recurring_expenses (description, amount, category, paid_from, day_of_month, last_posted_month) VALUES (?,?,?,?,?,?)")
                   ->execute([$desc, $amount, $cat, $from, (int)date('j', strtotime($date)), substr($date, 0, 7)]);
                $note = 'Expense logged, and set to repeat every month.';
            } else {
                $note = 'Expense logged.';
            }
        } elseif ($action === 'void_expense') {
            if ($why = voidExpense($db, (int)($_POST['expense_id'] ?? 0))) throw new RuntimeException($why);
            $note = 'Expense removed (a reversing entry was added to the books).';
        } elseif ($action === 'stop_recurring') {
            $db->prepare("UPDATE recurring_expenses SET is_active = 0 WHERE id = ?")->execute([(int)($_POST['recurring_id'] ?? 0)]);
            $note = 'That expense will no longer repeat.';
        } elseif ($action === 'log_draw') {
            $amount = round((float)($_POST['amount'] ?? 0), 2);
            $date   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['draw_date'] ?? '')) ? $_POST['draw_date'] : date('Y-m-d');
            $notes  = trim(strip_tags((string)($_POST['notes'] ?? '')));
            if ($amount <= 0) throw new RuntimeException('Enter an amount.');
            $db->prepare("INSERT INTO owner_draws (draw_date, amount, notes) VALUES (?,?,?)")->execute([$date, $amount, $notes]);
            journalOwnerDraw((int)$db->lastInsertId(), $amount, $date);
            $note = 'Payment to yourself logged.';
        } elseif ($action === 'record_refund') {
            $kind   = ($_POST['kind'] ?? '') === 'order' ? 'order' : 'booking';
            $method = in_array($_POST['method'] ?? '', ['stripe', 'bank', 'cash'], true) ? $_POST['method'] : 'stripe';
            $why = recordRefund($db, $kind, (string)($_POST['reference'] ?? ''), (float)($_POST['amount'] ?? 0), $method,
                                trim(strip_tags((string)($_POST['reason'] ?? ''))), (string)($_POST['refund_date'] ?? date('Y-m-d')));
            if ($why) throw new RuntimeException($why);
            if ($kind === 'order') {
                $o = $db->prepare("SELECT id FROM orders WHERE order_ref = ?"); $o->execute([strtoupper(trim((string)$_POST['reference']))]);
                if ($oid = (int)$o->fetchColumn()) syncOrderExtras($db, $oid);
            }
            $note = 'Refund recorded in the books. (Remember to send the money back in Stripe or your bank if you haven\'t.)';
        }
        $msg = ['ok', $note];
    } catch (Throwable $e) {
        $msg = ['err', $e instanceof RuntimeException ? $e->getMessage() : 'Not saved: something went wrong.'];
        if (!($e instanceof RuntimeException)) error_log('accounting POST ' . $action . ': ' . $e->getMessage());
    }
    header('Location: /admin/accounting.php?tab=' . urlencode($back) . '&' . ($periodQs ? $periodQs . '&' : '')
         . 'm=' . urlencode($msg[0]) . '&t=' . urlencode($msg[1]));
    exit;
}

// ── Spreadsheet downloads (CSV opens in Excel / Numbers / Google Sheets) ──
if (isset($_GET['export'])) {
    $kind = (string)$_GET['export'];
    $fn   = 'braidedbyagb-' . $kind . '-' . $pFrom . '-to-' . $pTo . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fn . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // so Excel reads £ correctly
    if ($kind === 'pl') {
        $pl = financeProfitAndLoss($db, $pFrom, $pTo);
        fputcsv($out, ['BraidedbyAGB profit and loss', $pLabel, "$pFrom to $pTo"]);
        fputcsv($out, []);
        fputcsv($out, ['Income', 'Account', 'Amount (£)']);
        foreach ($pl['income'] as $r) fputcsv($out, [$r['name'], $r['code'], number_format($r['amount'], 2, '.', '')]);
        fputcsv($out, ['Total income', '', number_format($pl['total_income'], 2, '.', '')]);
        fputcsv($out, []);
        fputcsv($out, ['Expenses', 'Account', 'Amount (£)']);
        foreach ($pl['expenses'] as $r) fputcsv($out, [$r['name'], $r['code'], number_format($r['amount'], 2, '.', '')]);
        fputcsv($out, ['Total expenses', '', number_format($pl['total_expenses'], 2, '.', '')]);
        fputcsv($out, []);
        fputcsv($out, ['Profit (before paying yourself)', '', number_format($pl['profit'], 2, '.', '')]);
        fputcsv($out, ['Paid to yourself (drawings, not an expense)', '', number_format($pl['draws'], 2, '.', '')]);
        fputcsv($out, []);
        fputcsv($out, ['Expenses by category', 'Count', 'Amount (£)']);
        foreach ($pl['categories'] as $c) fputcsv($out, [$c['category'], $c['n'], number_format((float)$c['total'], 2, '.', '')]);
    } elseif ($kind === 'expenses') {
        fputcsv($out, ['Date', 'Description', 'Category', 'Paid from', 'Amount (£)', 'Receipt', 'Notes']);
        $q = $db->prepare("SELECT * FROM expenses WHERE expense_date BETWEEN ? AND ? AND COALESCE(is_void,0) = 0 ORDER BY expense_date, id");
        $q->execute([$pFrom, $pTo]);
        foreach ($q->fetchAll() as $e) fputcsv($out, [$e['expense_date'], $e['description'], $e['category'], $e['paid_from'] ?? 'bank',
            number_format((float)$e['amount'], 2, '.', ''), $e['receipt_url'] ? rtrim(SITE_URL, '/') . $e['receipt_url'] : '', $e['notes']]);
    } else {   // journal: every line, for an accountant
        fputcsv($out, ['Date', 'Entry', 'Reference', 'Type', 'Account code', 'Account', 'Debit (£)', 'Credit (£)', 'Memo']);
        $q = $db->prepare("SELECT e.entry_date, e.id, e.reference, e.source, a.code, a.name, l.debit, l.credit, l.memo
                           FROM journal_entry_lines l JOIN journal_entries e ON e.id = l.journal_entry_id JOIN accounts a ON a.id = l.account_id
                           WHERE e.entry_date BETWEEN ? AND ? ORDER BY e.entry_date, e.id, l.id");
        $q->execute([$pFrom, $pTo]);
        foreach ($q->fetchAll() as $r) fputcsv($out, [$r['entry_date'], $r['id'], $r['reference'], $r['source'], $r['code'], $r['name'],
            number_format((float)$r['debit'], 2, '.', ''), number_format((float)$r['credit'], 2, '.', ''), $r['memo']]);
    }
    fclose($out);
    exit;
}

$pageTitle = 'Accounting';
require_once __DIR__ . '/includes/layout.php';

$flash = isset($_GET['t']) ? [($_GET['m'] ?? '') === 'err' ? 'err' : 'ok', (string)$_GET['t']] : null;
$money = fn($v) => ($v < 0 ? '−' : '') . '£' . number_format(abs((float)$v), 2);
$card  = 'background:#fff;border:1px solid var(--admin-border,#e5e7eb);border-radius:10px;padding:20px;margin-bottom:20px';
$h3    = 'font-size:0.75rem;font-weight:700;color:var(--admin-muted);text-transform:uppercase;letter-spacing:.5px;margin:0 0 14px';
$label = 'font-size:0.8rem;font-weight:600;color:var(--admin-text);display:block;margin-bottom:4px';
$presets = ['this_month' => 'This month', 'last_month' => 'Last month', 'this_quarter' => 'This quarter',
            'last_quarter' => 'Last quarter', 'this_tax_year' => 'This tax year', 'last_tax_year' => 'Last tax year', 'custom' => 'Custom dates'];
$curPreset = array_key_exists($_GET['period'] ?? '', $presets) ? $_GET['period'] : 'this_month';
?>

<?php if ($flash): ?>
  <div style="background:<?= $flash[0] === 'ok' ? '#f0fdf4' : '#fef2f2' ?>;border:1px solid <?= $flash[0] === 'ok' ? '#86efac' : '#fca5a5' ?>;border-radius:8px;padding:12px 18px;margin-bottom:20px;color:<?= $flash[0] === 'ok' ? '#166534' : '#991b1b' ?>;font-weight:600">
    <?= $flash[0] === 'ok' ? '✅' : '⚠️' ?> <?= htmlspecialchars($flash[1]) ?>
  </div>
<?php endif; ?>

<div class="page-header" style="margin-bottom:16px">
  <div>
    <h2 class="section-heading">Accounting</h2>
    <p style="color:var(--admin-muted);font-size:0.8rem">Bookings, shop sales, travel fees, courses, refunds and expenses, all from one set of books.</p>
  </div>
</div>

<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px">
  <?php foreach (['overview' => '📊 Overview', 'reports' => '📄 Reports & exports', 'expenses' => '🧾 Expenses', 'owed' => '💷 Money owed'] as $k => $v): ?>
    <a href="/admin/accounting.php?tab=<?= $k ?>" class="btn-admin <?= $tab === $k ? 'btn-admin-primary' : 'btn-admin-outline' ?>"><?= $v ?></a>
  <?php endforeach; ?>
</div>

<?php if ($tab === 'overview'):
  $pl    = financeProfitAndLoss($db, date('Y-m-01'), date('Y-m-t'));
  $today = financeProfitAndLoss($db, date('Y-m-d'), date('Y-m-d'));
  $owed  = financeMoneyOwed($db);
?>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:24px">
  <?php foreach ([
      ["Today's income", $today['total_income'], '#16a34a'],
      [date('F') . ' income', $pl['total_income'], 'var(--admin-primary)'],
      [date('F') . ' expenses', $pl['total_expenses'], '#dc2626'],
      ['Profit this month', $pl['profit'], $pl['profit'] >= 0 ? '#16a34a' : '#dc2626'],
      ['Paid to yourself', $pl['draws'], 'var(--admin-text)'],
      ['Due on the day (next 6 wks)', $owed['balance_due'], '#ca8a04'],
  ] as [$t, $v, $c]): ?>
    <div style="background:#fff;border:1px solid var(--admin-border,#e5e7eb);border-radius:10px;padding:16px;text-align:center">
      <div style="font-size:0.7rem;font-weight:700;color:var(--admin-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px"><?= $t ?></div>
      <div style="font-size:1.45rem;font-weight:800;color:<?= $c ?>"><?= $money($v) ?></div>
    </div>
  <?php endforeach; ?>
</div>
<p style="font-size:0.75rem;color:var(--admin-muted);margin:-12px 0 20px">Income counts a booking when it's marked completed, a shop order when it's paid. "Profit" is before what you pay yourself.</p>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:20px;margin-bottom:8px">
  <div style="<?= $card ?>">
    <h3 style="<?= $h3 ?>"><?= date('F') ?> income by type</h3>
    <?php if (!$pl['income']): ?><p class="td-muted" style="font-size:0.85rem">Nothing yet this month.</p><?php endif; ?>
    <?php foreach ($pl['income'] as $r): ?>
      <div style="display:flex;justify-content:space-between;font-size:0.85rem;padding:5px 0;border-bottom:1px solid #f3f4f6"><span><?= htmlspecialchars($r['name']) ?></span><strong><?= $money($r['amount']) ?></strong></div>
    <?php endforeach; ?>
  </div>

  <div style="<?= $card ?>">
    <h3 style="<?= $h3 ?>">Pay myself</h3>
    <form method="POST" style="display:flex;flex-direction:column;gap:10px">
      <input type="hidden" name="action" value="log_draw"><input type="hidden" name="tab" value="overview">
      <div><label style="<?= $label ?>">Amount (£) *</label><input type="number" name="amount" step="0.01" min="0.01" required class="admin-input" style="width:100%"></div>
      <div><label style="<?= $label ?>">Date</label><input type="date" name="draw_date" value="<?= date('Y-m-d') ?>" class="admin-input" style="width:100%"></div>
      <div><label style="<?= $label ?>">Notes</label><input type="text" name="notes" class="admin-input" placeholder="e.g. Monthly pay" style="width:100%"></div>
      <button class="btn-admin btn-admin-primary">💸 Log payment to myself</button>
    </form>
  </div>

  <div style="<?= $card ?>">
    <h3 style="<?= $h3 ?>">Record a refund</h3>
    <p style="font-size:0.75rem;color:var(--admin-muted);margin:-6px 0 10px">Send the money back in Stripe or your bank first, then record it here so the books match. Cancelling a paid shop order reverses it automatically.</p>
    <form method="POST" style="display:flex;flex-direction:column;gap:10px">
      <input type="hidden" name="action" value="record_refund"><input type="hidden" name="tab" value="overview">
      <div style="display:flex;gap:8px">
        <select name="kind" class="admin-input" style="width:130px"><option value="booking">Booking</option><option value="order">Shop order</option></select>
        <input type="text" name="reference" required class="admin-input" placeholder="Reference, e.g. AGB-2026-0418" style="flex:1">
      </div>
      <div style="display:flex;gap:8px">
        <input type="number" name="amount" step="0.01" min="0.01" required class="admin-input" placeholder="£ amount" style="width:130px">
        <select name="method" class="admin-input" style="flex:1"><option value="stripe">Back to card (Stripe)</option><option value="bank">Bank transfer</option><option value="cash">Cash</option></select>
      </div>
      <input type="text" name="reason" class="admin-input" placeholder="Reason (optional)">
      <input type="date" name="refund_date" value="<?= date('Y-m-d') ?>" class="admin-input">
      <button class="btn-admin btn-admin-outline" onclick="return confirm('Record this refund in the books?')">Record refund</button>
    </form>
  </div>
</div>

<?php elseif ($tab === 'reports'):
  $pl = financeProfitAndLoss($db, $pFrom, $pTo);
?>
<form method="GET" style="<?= $card ?>;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
  <input type="hidden" name="tab" value="reports">
  <div><label style="<?= $label ?>">Period</label>
    <select name="period" class="admin-input" onchange="document.getElementById('custDates').style.display = this.value === 'custom' ? 'flex' : 'none'">
      <?php foreach ($presets as $k => $v): ?><option value="<?= $k ?>" <?= $curPreset === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?>
    </select></div>
  <div id="custDates" style="display:<?= $curPreset === 'custom' ? 'flex' : 'none' ?>;gap:8px">
    <div><label style="<?= $label ?>">From</label><input type="date" name="from" value="<?= htmlspecialchars($pFrom) ?>" class="admin-input"></div>
    <div><label style="<?= $label ?>">To</label><input type="date" name="to" value="<?= htmlspecialchars($pTo) ?>" class="admin-input"></div>
  </div>
  <button class="btn-admin btn-admin-primary">Show</button>
  <span style="margin-left:auto;font-size:0.8rem;color:var(--admin-muted)">UK tax years run 6 April to 5 April.</span>
</form>

<div style="<?= $card ?>">
  <h3 style="<?= $h3 ?>">Profit and loss: <?= htmlspecialchars($pLabel) ?> (<?= date('j M Y', strtotime($pFrom)) ?> – <?= date('j M Y', strtotime($pTo)) ?>)</h3>
  <table class="admin-table" style="max-width:640px">
    <tbody>
      <tr><td colspan="2" style="font-weight:800;background:#faf5ff">Income</td></tr>
      <?php foreach ($pl['income'] as $r): ?><tr><td><?= htmlspecialchars($r['name']) ?></td><td style="text-align:right"><?= $money($r['amount']) ?></td></tr><?php endforeach; ?>
      <tr><td style="font-weight:700">Total income</td><td style="text-align:right;font-weight:700"><?= $money($pl['total_income']) ?></td></tr>
      <tr><td colspan="2" style="font-weight:800;background:#faf5ff">Expenses</td></tr>
      <?php foreach ($pl['expenses'] as $r): ?><tr><td><?= htmlspecialchars($r['name']) ?></td><td style="text-align:right"><?= $money($r['amount']) ?></td></tr><?php endforeach; ?>
      <tr><td style="font-weight:700">Total expenses</td><td style="text-align:right;font-weight:700"><?= $money($pl['total_expenses']) ?></td></tr>
      <tr><td style="font-weight:800;font-size:1rem">Profit (before paying yourself)</td><td style="text-align:right;font-weight:800;font-size:1rem;color:<?= $pl['profit'] >= 0 ? '#16a34a' : '#dc2626' ?>"><?= $money($pl['profit']) ?></td></tr>
      <tr><td class="td-muted">Paid to yourself (drawings: not an expense for tax)</td><td style="text-align:right" class="td-muted"><?= $money($pl['draws']) ?></td></tr>
    </tbody>
  </table>
  <?php if ($pl['categories']): ?>
    <h3 style="<?= $h3 ?>;margin-top:22px">Expenses by category</h3>
    <table class="admin-table" style="max-width:640px"><tbody>
      <?php foreach ($pl['categories'] as $c): ?><tr><td><?= htmlspecialchars($c['category']) ?> <span class="td-muted">(<?= (int)$c['n'] ?>)</span></td><td style="text-align:right"><?= $money($c['total']) ?></td></tr><?php endforeach; ?>
    </tbody></table>
  <?php endif; ?>
  <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:18px">
    <?php $eq = 'tab=reports' . ($periodQs ? '&' . $periodQs : ''); ?>
    <a class="btn-admin btn-admin-primary" href="/admin/accounting.php?<?= htmlspecialchars($eq) ?>&export=pl">⬇ Profit &amp; loss (spreadsheet)</a>
    <a class="btn-admin btn-admin-outline" href="/admin/accounting.php?<?= htmlspecialchars($eq) ?>&export=expenses">⬇ Expenses list</a>
    <a class="btn-admin btn-admin-outline" href="/admin/accounting.php?<?= htmlspecialchars($eq) ?>&export=journal">⬇ Full ledger (for your accountant)</a>
  </div>
  <p style="font-size:0.72rem;color:var(--admin-muted);margin-top:10px">Spreadsheets open in Excel, Numbers or Google Sheets. This is a record for you and your accountant, not tax advice.</p>
</div>

<?php elseif ($tab === 'expenses'):
  $expenses  = $db->query("SELECT * FROM expenses ORDER BY expense_date DESC, id DESC LIMIT 60")->fetchAll();
  $recurring = $db->query("SELECT * FROM recurring_expenses WHERE is_active = 1 ORDER BY day_of_month")->fetchAll();
?>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:20px">
  <div style="<?= $card ?>">
    <h3 style="<?= $h3 ?>">Log an expense</h3>
    <form method="POST" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:10px">
      <input type="hidden" name="action" value="log_expense"><input type="hidden" name="tab" value="expenses">
      <div style="display:flex;gap:8px">
        <div style="width:130px"><label style="<?= $label ?>">Amount (£) *</label><input type="number" name="amount" step="0.01" min="0.01" required class="admin-input" style="width:100%"></div>
        <div style="flex:1"><label style="<?= $label ?>">Date</label><input type="date" name="expense_date" value="<?= date('Y-m-d') ?>" class="admin-input" style="width:100%"></div>
      </div>
      <div><label style="<?= $label ?>">Description *</label><input type="text" name="description" required class="admin-input" placeholder="e.g. Braiding hair, 20 packs" style="width:100%"></div>
      <div style="display:flex;gap:8px">
        <div style="flex:1"><label style="<?= $label ?>">Category</label>
          <select name="category" class="admin-input" style="width:100%"><?php foreach (EXPENSE_CATEGORIES as $c): ?><option><?= htmlspecialchars($c) ?></option><?php endforeach; ?></select></div>
        <div style="width:140px"><label style="<?= $label ?>">Paid from</label>
          <select name="paid_from" class="admin-input" style="width:100%"><option value="bank">Bank</option><option value="cash">Cash</option><option value="stripe">Stripe balance</option></select></div>
      </div>
      <div><label style="<?= $label ?>">Receipt (photo or PDF, optional)</label><input type="file" name="receipt" accept="image/jpeg,image/png,image/webp,application/pdf" class="admin-input" style="width:100%;padding:6px"></div>
      <div><label style="<?= $label ?>">Notes</label><input type="text" name="notes" class="admin-input" style="width:100%"></div>
      <label style="font-size:0.8rem;display:flex;gap:8px;align-items:center"><input type="checkbox" name="repeat_monthly" value="1"> Repeat every month on this day (rent, subscriptions…)</label>
      <button class="btn-admin btn-admin-primary">Log expense</button>
    </form>
  </div>

  <div style="<?= $card ?>">
    <h3 style="<?= $h3 ?>">Repeating every month</h3>
    <?php if (!$recurring): ?><p class="td-muted" style="font-size:0.85rem">None. Tick "Repeat every month" when logging an expense.</p><?php endif; ?>
    <?php foreach ($recurring as $r): ?>
      <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;padding:8px 0;border-bottom:1px solid #f3f4f6;font-size:0.85rem">
        <span><strong><?= htmlspecialchars($r['description']) ?></strong><br><span class="td-muted"><?= $money($r['amount']) ?> on day <?= (int)$r['day_of_month'] ?> · <?= htmlspecialchars($r['category']) ?></span></span>
        <form method="POST" style="margin:0"><input type="hidden" name="action" value="stop_recurring"><input type="hidden" name="tab" value="expenses"><input type="hidden" name="recurring_id" value="<?= (int)$r['id'] ?>">
          <button class="btn-admin btn-admin-outline btn-admin-sm" onclick="return confirm('Stop repeating this expense? Past ones stay.')">Stop</button></form>
      </div>
    <?php endforeach; ?>
    <p style="font-size:0.72rem;color:var(--admin-muted);margin-top:10px">Logged automatically on that day each month.</p>
  </div>
</div>

<div style="<?= $card ?>">
  <h3 style="<?= $h3 ?>">Recent expenses</h3>
  <?php if (!$expenses): ?><p class="td-muted" style="font-size:0.85rem">No expenses logged yet.</p><?php else: ?>
  <div class="admin-table-wrap"><table class="admin-table">
    <thead><tr><th>Date</th><th>Description</th><th>Category</th><th>Paid from</th><th>Receipt</th><th style="text-align:right">Amount</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($expenses as $e): $void = (int)($e['is_void'] ?? 0) === 1; ?>
      <tr style="<?= $void ? 'opacity:0.5;text-decoration:line-through' : '' ?>">
        <td style="font-size:0.82rem"><?= date('j M Y', strtotime($e['expense_date'])) ?></td>
        <td><?= htmlspecialchars($e['description']) ?><?= !empty($e['recurring_id']) ? ' <span class="td-muted" title="Repeating">🔁</span>' : '' ?></td>
        <td class="td-muted" style="font-size:0.82rem"><?= htmlspecialchars($e['category']) ?></td>
        <td class="td-muted" style="font-size:0.82rem"><?= htmlspecialchars(ucfirst($e['paid_from'] ?? 'bank')) ?></td>
        <td><?= !empty($e['receipt_url']) ? '<a href="' . htmlspecialchars($e['receipt_url']) . '" target="_blank">View</a>' : '<span class="td-muted">—</span>' ?></td>
        <td style="font-weight:700;color:#dc2626;text-align:right"><?= $money($e['amount']) ?></td>
        <td><?php if (!$void): ?><form method="POST" style="margin:0"><input type="hidden" name="action" value="void_expense"><input type="hidden" name="tab" value="expenses"><input type="hidden" name="expense_id" value="<?= (int)$e['id'] ?>">
          <button class="btn-admin btn-admin-outline btn-admin-sm" title="Logged by mistake? Remove it" onclick="return confirm('Remove this expense? A reversing entry is added to the books.')">✕</button></form><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php else: /* owed */
  $o = financeMoneyOwed($db);
?>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:24px">
  <?php foreach ([
      ['Due on the day, next 6 weeks', $o['balance_due'], '#ca8a04', 'Balances clients pay at their appointments.'],
      ['Deposits held', $o['deposits_held'], 'var(--admin-primary)', 'Paid, but not income until the appointment is completed.'],
      ['Deposits not yet paid', array_sum(array_column($o['unpaid_deposits'], 'deposit_amount')), '#dc2626', 'Upcoming bookings still waiting on a deposit.'],
      ['Unpaid shop orders', array_sum(array_column($o['unpaid_orders'], 'total')), '#dc2626', 'Orders placed but not paid.'],
      ['Referral credit owed', $o['referral_credit'], 'var(--admin-text)', '"Bring your bestie" credit clients can still use.'],
  ] as [$t, $v, $c, $hint]): ?>
    <div style="background:#fff;border:1px solid var(--admin-border,#e5e7eb);border-radius:10px;padding:16px">
      <div style="font-size:0.7rem;font-weight:700;color:var(--admin-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px"><?= $t ?></div>
      <div style="font-size:1.4rem;font-weight:800;color:<?= $c ?>"><?= $money($v) ?></div>
      <div style="font-size:0.72rem;color:var(--admin-muted);margin-top:4px"><?= $hint ?></div>
    </div>
  <?php endforeach; ?>
</div>

<div style="<?= $card ?>">
  <h3 style="<?= $h3 ?>">Coming in, week by week</h3>
  <?php if (!$o['weeks']): ?><p class="td-muted" style="font-size:0.85rem">No upcoming appointments in the next 6 weeks.</p><?php else: ?>
  <table class="admin-table" style="max-width:700px"><thead><tr><th>Week of</th><th>Appointments</th><th style="text-align:right">Deposits already paid</th><th style="text-align:right">Due on the day</th></tr></thead><tbody>
    <?php foreach ($o['weeks'] as $w): ?>
      <tr><td><?= date('D j M', strtotime($w['first_day'] . ' -' . (((int)date('N', strtotime($w['first_day']))) - 1) . ' days')) ?></td><td><?= (int)$w['n'] ?></td>
          <td style="text-align:right"><?= $money($w['deposits']) ?></td><td style="text-align:right;font-weight:700"><?= $money($w['balance']) ?></td></tr>
    <?php endforeach; ?>
  </tbody></table><?php endif; ?>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:20px">
  <div style="<?= $card ?>">
    <h3 style="<?= $h3 ?>">Upcoming bookings waiting on a deposit</h3>
    <?php if (!$o['unpaid_deposits']): ?><p class="td-muted" style="font-size:0.85rem">None.</p><?php endif; ?>
    <?php foreach ($o['unpaid_deposits'] as $b): ?>
      <div style="display:flex;justify-content:space-between;font-size:0.85rem;padding:6px 0;border-bottom:1px solid #f3f4f6">
        <a href="/admin/bookings/<?= (int)$b['id'] ?>"><?= htmlspecialchars($b['name']) ?> · <?= htmlspecialchars($b['booking_ref']) ?></a>
        <span><?= formatDate($b['booked_date'], 'j M') ?> · <strong><?= $money($b['deposit_amount']) ?></strong></span>
      </div>
    <?php endforeach; ?>
  </div>
  <div style="<?= $card ?>">
    <h3 style="<?= $h3 ?>">Unpaid shop orders</h3>
    <?php if (!$o['unpaid_orders']): ?><p class="td-muted" style="font-size:0.85rem">None.</p><?php endif; ?>
    <?php foreach ($o['unpaid_orders'] as $r): ?>
      <div style="display:flex;justify-content:space-between;font-size:0.85rem;padding:6px 0;border-bottom:1px solid #f3f4f6">
        <span><?= htmlspecialchars($r['name']) ?> · <?= htmlspecialchars($r['order_ref']) ?> <span class="td-muted">(<?= htmlspecialchars(str_replace('_', ' ', $r['payment_method'])) ?>, <?= date('j M', strtotime($r['created_at'])) ?>)</span></span>
        <strong><?= $money($r['total']) ?></strong>
      </div>
    <?php endforeach; ?>
    <p style="font-size:0.72rem;color:var(--admin-muted);margin-top:8px">Confirm or cancel these in Orders. Cancelling puts any reserved stock back.</p>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/layout-end.php'; ?>
