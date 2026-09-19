<?php
// ============================================================
// BraidedbyAGB — Admin Course Enrolments
// FILE: /admin/enrolments.php
// ALL POST HANDLING BEFORE layout.php to avoid headers-sent error
//
// Manage who is enrolled: manual/sponsored enrolment (no online payment),
// status changes, recording manual/waived payments (posted to 4030), and
// issuing certificates. Online enrolment + Stripe payment is Phase D3.
// ============================================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/courses.php';
requireAdmin();
$db = getDB();

$msg = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = sanitize($_POST['action'] ?? '');
    try {
        if ($action === 'create_enrolment') {
            $courseId = (int)($_POST['course_id'] ?? 0);
            $cohortId = ($_POST['cohort_id'] ?? '') === '' ? null : (int)$_POST['cohort_id'];
            $email    = strtolower(trim(sanitize($_POST['email'] ?? '')));
            $name     = sanitize($_POST['name'] ?? '');
            $amountDue = max(0.0, round((float)($_POST['amount_due'] ?? 0), 2));
            $payStatus = in_array($_POST['payment_status'] ?? '', ['unpaid','waived','paid'], true) ? $_POST['payment_status'] : 'unpaid';
            if (!$courseId || $name === '' || !validateEmail($email)) {
                $error = 'Course, name and a valid email are required.';
            } else {
                // Resolve or create the customer (a trainee is just a customer).
                $cs = $db->prepare("SELECT id FROM customers WHERE email=?"); $cs->execute([$email]);
                $customerId = (int)($cs->fetchColumn() ?: 0);
                if (!$customerId) {
                    $db->prepare("INSERT INTO customers (name,email) VALUES (?,?)")->execute([$name, $email]);
                    $customerId = (int)$db->lastInsertId();
                }
                $db->beginTransaction();
                try {
                    // Seat check inside the transaction so the last seat can't oversell.
                    if ($cohortId !== null) {
                        $db->prepare("SELECT id FROM course_cohorts WHERE id=? FOR UPDATE")->execute([$cohortId]);
                        if (cohortSeatsLeft($db, $cohortId) < 1) {
                            $db->rollBack();
                            $error = 'That cohort is full. Add a seat or pick another cohort.';
                        }
                    }
                    if (!$error) {
                        $status = 'active';
                        $db->prepare("INSERT INTO course_enrolments (customer_id,course_id,cohort_id,status,payment_status,amount_due)
                                      VALUES (?,?,?,?,?,?)")
                           ->execute([$customerId,$courseId,$cohortId,$status, $payStatus === 'paid' ? 'unpaid' : $payStatus, $amountDue]);
                        $enrolId = (int)$db->lastInsertId();
                        // "Paid" at creation records a manual payment for the full amount due.
                        if ($payStatus === 'paid' && $amountDue > 0.005) {
                            $db->prepare("INSERT INTO course_payments (enrolment_id,amount,type,method,status) VALUES (?,?,?,?,'succeeded')")
                               ->execute([$enrolId, $amountDue, 'full', sanitize($_POST['method'] ?? 'bank_transfer')]);
                            journalCoursePayment($db, (int)$db->lastInsertId());
                            refreshEnrolmentPayment($db, $enrolId);
                        }
                        $db->commit();
                        $msg = 'Enrolment created.';
                    }
                } catch (Throwable $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    if ($e->getCode() === '23000') $error = 'That person is already enrolled in this cohort.';
                    else { error_log('create_enrolment: '.$e->getMessage()); $error = 'Could not create enrolment.'; }
                }
            }

        } elseif ($action === 'set_status') {
            $eid = (int)$_POST['id'];
            $st  = in_array($_POST['status'] ?? '', ['pending','active','completed','withdrawn','waitlisted'], true) ? $_POST['status'] : 'active';
            $completedAt = $st === 'completed' ? date('Y-m-d H:i:s') : null;
            $db->prepare("UPDATE course_enrolments SET status=?, completed_at=? WHERE id=?")->execute([$st, $completedAt, $eid]);
            $msg = 'Enrolment status updated.';

        } elseif ($action === 'record_payment') {
            $eid    = (int)$_POST['id'];
            $amount = round((float)($_POST['amount'] ?? 0), 2);
            $method = $_POST['method'] ?? 'bank_transfer';
            if ($method === 'waived') {
                $db->prepare("UPDATE course_enrolments SET payment_status='waived' WHERE id=?")->execute([$eid]);
                $msg = 'Enrolment marked as waived (sponsored).';
            } elseif ($amount > 0.005) {
                $type = sanitize($_POST['type'] ?? 'full');
                $db->prepare("INSERT INTO course_payments (enrolment_id,amount,type,method,status) VALUES (?,?,?,?,'succeeded')")
                   ->execute([$eid, $amount, in_array($type,['deposit','full','installment'],true)?$type:'full', in_array($method,['cash','bank_transfer','stripe','other'],true)?$method:'bank_transfer']);
                journalCoursePayment($db, (int)$db->lastInsertId());
                refreshEnrolmentPayment($db, $eid);
                $msg = 'Payment recorded.';
            } else { $error = 'Enter an amount, or choose Waived.'; }

        } elseif ($action === 'issue_certificate') {
            $eid  = (int)$_POST['id'];
            $name = sanitize($_POST['name_on_certificate'] ?? '');
            if ($name === '') { $error = 'Enter the name for the certificate.'; }
            else {
                $ref = 'CERT-' . strtoupper(substr(bin2hex(random_bytes(4)),0,8));
                $db->prepare("INSERT INTO course_certificates (enrolment_id,certificate_ref,name_on_certificate) VALUES (?,?,?)")
                   ->execute([$eid, $ref, $name]);
                $msg = 'Certificate issued (' . $ref . ').';
            }
        }
    } catch (Exception $e) {
        error_log('Enrolments admin error: ' . $e->getMessage());
        $error = $error ?: 'An error occurred. Please try again.';
    }
}

$pageTitle = 'Course Enrolments';
require_once __DIR__ . '/includes/layout.php';

$filter = sanitize($_GET['status'] ?? '');
try {
    $courses = $db->query("SELECT id, title FROM courses ORDER BY title")->fetchAll();
    $cohorts = $db->query("SELECT id, course_id, name FROM course_cohorts WHERE is_active=1 ORDER BY start_date IS NULL, start_date")->fetchAll();

    $where = $filter && in_array($filter, ['pending','active','completed','withdrawn','waitlisted'], true) ? "WHERE e.status=" . $db->quote($filter) : '';
    $enrolments = $db->query("
        SELECT e.*, c.name AS customer_name, c.email AS customer_email,
               co.name AS cohort_name, cr.title AS course_title,
               tp.date_of_birth, tp.guardian_name, tp.guardian_contact, tp.guardian_consent,
               (SELECT COUNT(*) FROM course_certificates cc WHERE cc.enrolment_id=e.id) AS cert_count
        FROM course_enrolments e
        JOIN customers c ON c.id = e.customer_id
        JOIN courses   cr ON cr.id = e.course_id
        LEFT JOIN course_cohorts co ON co.id = e.cohort_id
        LEFT JOIN trainee_profiles tp ON tp.customer_id = e.customer_id
        $where
        ORDER BY e.enrolled_at DESC LIMIT 300
    ")->fetchAll();
} catch (Exception $e) {
    $courses = []; $cohorts = []; $enrolments = [];
    $error = $error ?: 'Could not load enrolments. Run the database migration first.';
}

function isUnder18(?string $dob): bool {
    if (!$dob) return false;
    $t = strtotime($dob); if (!$t) return false;
    return $t > strtotime('-18 years');
}
function payBadge(string $s): array {
    return match ($s) {
        'paid'         => ['Paid','#065f46','#d1fae5'],
        'deposit_paid' => ['Deposit','#854d0e','#fef9c3'],
        'waived'       => ['Waived','#3730a3','#e0e7ff'],
        default        => ['Unpaid','#991b1b','#fee2e2'],
    };
}
?>

<?php if ($msg):   ?><div class="alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="page-header" style="margin-bottom:20px">
  <div><h2 class="section-heading">Course Enrolments</h2>
    <p style="color:var(--admin-muted);font-size:0.8rem"><?= count($enrolments) ?> shown · <a href="/admin/academy">Manage courses →</a></p></div>
  <button class="btn-admin btn-admin-primary" onclick="document.getElementById('new-enrol').classList.toggle('hidden')">+ Enrol someone</button>
</div>

<!-- Manual / sponsored enrolment -->
<div id="new-enrol" class="hidden" style="margin-bottom:20px">
  <div class="admin-card"><div class="admin-card-body">
    <?php if (empty($courses)): ?>
      <p style="color:var(--admin-muted);font-size:0.85rem">Create a course first on <a href="/admin/academy">the Academy page</a>.</p>
    <?php else: ?>
    <form method="POST" action="/admin/enrolments">
      <input type="hidden" name="action" value="create_enrolment">
      <div class="admin-form-row" style="margin-bottom:12px">
        <div class="admin-form-group"><label class="admin-label">Course *</label>
          <select class="admin-input" name="course_id" id="enrolCourse" required onchange="filterCohorts()">
            <option value="">— Select —</option>
            <?php foreach ($courses as $c): ?><option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['title']) ?></option><?php endforeach; ?>
          </select></div>
        <div class="admin-form-group"><label class="admin-label">Cohort</label>
          <select class="admin-input" name="cohort_id" id="enrolCohort">
            <option value="">No specific cohort</option>
            <?php foreach ($cohorts as $co): ?><option value="<?= (int)$co['id'] ?>" data-course="<?= (int)$co['course_id'] ?>"><?= htmlspecialchars($co['name']) ?></option><?php endforeach; ?>
          </select></div>
      </div>
      <div class="admin-form-row" style="margin-bottom:12px">
        <div class="admin-form-group"><label class="admin-label">Trainee name *</label><input class="admin-input" name="name" required></div>
        <div class="admin-form-group"><label class="admin-label">Email *</label><input class="admin-input" type="email" name="email" required></div>
        <div class="admin-form-group"><label class="admin-label">Amount due (£)</label><input class="admin-input" type="number" name="amount_due" step="0.01" min="0" value="0"></div>
        <div class="admin-form-group"><label class="admin-label">Payment</label>
          <select class="admin-input" name="payment_status">
            <option value="unpaid">Unpaid</option><option value="paid">Paid now</option><option value="waived">Waived (sponsored)</option>
          </select></div>
      </div>
      <p style="font-size:0.72rem;color:var(--admin-muted);margin-bottom:10px">If the trainee is under 18, capture guardian consent on their profile — a public enrolment form (Phase D3) will collect this automatically.</p>
      <button class="btn-admin btn-admin-primary" type="submit">Create Enrolment</button>
    </form>
    <?php endif; ?>
  </div></div>
</div>

<!-- Filter -->
<div style="display:flex;gap:6px;margin-bottom:14px;flex-wrap:wrap">
  <?php foreach (['' => 'All','active'=>'Active','pending'=>'Pending','completed'=>'Completed','waitlisted'=>'Waitlist','withdrawn'=>'Withdrawn'] as $k=>$lbl): ?>
    <a href="/admin/enrolments<?= $k?('?status='.$k):'' ?>" class="btn-admin btn-admin-sm <?= $filter===$k?'btn-admin-primary':'btn-admin-outline' ?>"><?= $lbl ?></a>
  <?php endforeach; ?>
</div>

<?php if (empty($enrolments)): ?>
  <div class="table-empty"><div class="table-empty-icon">📝</div><p>No enrolments<?= $filter?' with that status':'' ?> yet.</p></div>
<?php else: foreach ($enrolments as $e): $eid=(int)$e['id']; [$pl,$pf,$pb]=payBadge($e['payment_status']); $minor=isUnder18($e['date_of_birth']); ?>
  <div class="admin-card" style="margin-bottom:10px">
    <div class="admin-card-header" style="cursor:pointer" onclick="document.getElementById('e-<?= $eid ?>').classList.toggle('hidden')">
      <span class="admin-card-title"><?= htmlspecialchars($e['customer_name']) ?>
        <span style="font-weight:400;color:var(--admin-muted);font-size:0.8rem">· <?= htmlspecialchars($e['course_title']) ?><?= $e['cohort_name']?(' · '.htmlspecialchars($e['cohort_name'])):'' ?></span>
      </span>
      <span style="display:flex;gap:6px;align-items:center">
        <?php if ($minor): ?><span class="admin-badge" style="background:#fef3c7;color:#92400e">Under 18</span><?php endif; ?>
        <span class="admin-badge" style="background:#ede9fe;color:#5b21b6"><?= ucfirst($e['status']) ?></span>
        <span class="admin-badge" style="background:<?= $pb ?>;color:<?= $pf ?>"><?= $pl ?></span>
      </span>
    </div>
    <div id="e-<?= $eid ?>" class="admin-card-body hidden">
      <p style="font-size:0.75rem;color:var(--admin-muted);margin-bottom:10px">
        <?= htmlspecialchars($e['customer_email']) ?> ·
        Due £<?= number_format((float)$e['amount_due'],2) ?> · Paid £<?= number_format((float)$e['amount_paid'],2) ?>
        <?php if ($minor): ?><br><strong style="color:#92400e">Guardian:</strong> <?= htmlspecialchars($e['guardian_name'] ?: '—') ?> (<?= htmlspecialchars($e['guardian_contact'] ?: 'no contact') ?>) · consent <?= (int)$e['guardian_consent']?'✓':'✗ MISSING' ?><?php endif; ?>
      </p>
      <div style="display:flex;gap:16px;flex-wrap:wrap">
        <form method="POST" action="/admin/enrolments" style="display:flex;gap:6px;align-items:flex-end">
          <input type="hidden" name="action" value="set_status"><input type="hidden" name="id" value="<?= $eid ?>">
          <div class="admin-form-group" style="margin:0"><label class="admin-label" style="font-size:0.68rem">Status</label>
            <select class="admin-input" name="status">
              <?php foreach (['pending','active','completed','waitlisted','withdrawn'] as $s): ?><option value="<?= $s ?>" <?= $e['status']===$s?'selected':'' ?>><?= ucfirst($s) ?></option><?php endforeach; ?>
            </select></div>
          <button class="btn-admin btn-admin-sm btn-admin-primary" type="submit">Update</button>
        </form>
        <form method="POST" action="/admin/enrolments" style="display:flex;gap:6px;align-items:flex-end">
          <input type="hidden" name="action" value="record_payment"><input type="hidden" name="id" value="<?= $eid ?>">
          <div class="admin-form-group" style="margin:0"><label class="admin-label" style="font-size:0.68rem">Amount £</label><input class="admin-input" type="number" name="amount" step="0.01" min="0" style="width:90px"></div>
          <div class="admin-form-group" style="margin:0"><label class="admin-label" style="font-size:0.68rem">Method</label>
            <select class="admin-input" name="method"><option value="bank_transfer">Bank</option><option value="cash">Cash</option><option value="other">Other</option><option value="waived">Waived</option></select></div>
          <button class="btn-admin btn-admin-sm btn-admin-outline" type="submit">Record</button>
        </form>
        <?php if ($e['status'] === 'completed' && (int)$e['cert_count'] === 0): ?>
        <form method="POST" action="/admin/enrolments" style="display:flex;gap:6px;align-items:flex-end">
          <input type="hidden" name="action" value="issue_certificate"><input type="hidden" name="id" value="<?= $eid ?>">
          <div class="admin-form-group" style="margin:0"><label class="admin-label" style="font-size:0.68rem">Name on certificate</label><input class="admin-input" name="name_on_certificate" value="<?= htmlspecialchars($e['customer_name']) ?>" style="width:180px"></div>
          <button class="btn-admin btn-admin-sm btn-admin-primary" type="submit">Issue certificate</button>
        </form>
        <?php elseif ((int)$e['cert_count'] > 0): ?>
          <span style="font-size:0.78rem;color:#065f46;align-self:center">🎓 Certificate issued</span>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php endforeach; endif; ?>

<style>#new-enrol.hidden,[id^="e-"].hidden{display:none}</style>
<script>
function filterCohorts() {
  var cid = document.getElementById('enrolCourse').value;
  document.querySelectorAll('#enrolCohort option[data-course]').forEach(function(o){
    o.hidden = cid !== '' && o.getAttribute('data-course') !== cid;
  });
  document.getElementById('enrolCohort').value = '';
}
</script>

<?php require_once __DIR__ . '/includes/layout-end.php'; ?>
