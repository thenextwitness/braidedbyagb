<?php
// ============================================================
// BraidedbyAGB — Course enrolment form + payment (public)
// FILE: /public/course-enrol.php  →  /academy/{slug}/enrol
//
// Collects learner details (guardian consent required for under-18s, enforced
// again server-side) and takes FULL payment via the Stripe Payment Element.
// The enrolment records are created by /api/course-enrol-intent.
// ============================================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

$db   = getDB();
$slug = sanitize($_GET['slug'] ?? '');
$c = $db->prepare("SELECT * FROM courses WHERE slug=? AND is_active=1");
$c->execute([$slug]);
$course = $c->fetch();
if (!$course) { header('Location: /academy'); exit; }
$courseId = (int)$course['id'];
$price    = (float)$course['price'];
$pence    = (int) round($price * 100);

$cohorts = $db->prepare("SELECT id, name, start_date FROM course_cohorts WHERE course_id=? AND is_active=1 ORDER BY start_date IS NULL, start_date ASC");
$cohorts->execute([$courseId]);
$cohorts = $cohorts->fetchAll();

$stripeOn = defined('STRIPE_PUBLIC_KEY') && STRIPE_PUBLIC_KEY !== '' && $pence >= 30;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex,nofollow">
  <title>Enrol — <?= htmlspecialchars($course['title']) ?> · BraidedbyAGB</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800;900&family=Lato:ital,wght@0,300;0,400;0,700;1,300&family=Cormorant+Garamond:ital,wght@1,600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/brand.css">
<?php include __DIR__ . '/../includes/brand-styles.php'; ?>
  <link rel="stylesheet" href="/assets/css/global.css">
  <link rel="stylesheet" href="/assets/css/pages.css">
<?php if ($stripeOn): ?><script src="https://js.stripe.com/v3/"></script><?php endif; ?>
  <style>
    .enrol-wrap{max-width:560px;margin:0 auto;padding:0 16px}
    .enrol-field{margin-bottom:14px}
    .enrol-field label{display:block;font-size:0.85rem;font-weight:600;color:#3a2740;margin-bottom:4px}
    .enrol-field input,.enrol-field select{width:100%;padding:10px 12px;border:1px solid #d9c7e0;border-radius:8px;font-size:0.95rem}
    #guardian-block{border:1px solid #f0d59a;background:#fffaf0;border-radius:10px;padding:14px;margin-bottom:14px}
    .enrol-consent{display:flex;gap:8px;align-items:flex-start;font-size:0.85rem;color:#3a2740}
    #stripe-error{color:#b91c1c;font-size:0.85rem;margin-top:8px;display:none}
    .muted{color:#6B5575;font-size:0.82rem}
  </style>
</head>
<body>
<?php include __DIR__ . '/../includes/nav.php'; ?>
<main class="page-content">
  <section class="section">
    <div class="enrol-wrap">
      <a href="/academy/<?= htmlspecialchars($course['slug']) ?>" class="auth-link" style="font-size:0.85rem">‹ Back to course</a>
      <h1 style="font-family:'Montserrat',sans-serif;color:#7A0050;font-size:1.5rem;margin:10px 0 4px">Enrol — <?= htmlspecialchars($course['title']) ?></h1>
      <p class="muted" style="margin-bottom:20px"><?= $price > 0 ? 'Full payment: £'.number_format($price,2) : 'This course is free.' ?></p>

      <form id="enrol-form" onsubmit="return false">
        <div class="enrol-field"><label>Full name *</label><input type="text" id="f-name" required></div>
        <div class="enrol-field"><label>Email *</label><input type="email" id="f-email" required></div>
        <div class="enrol-field"><label>Date of birth *</label><input type="date" id="f-dob" required max="<?= date('Y-m-d') ?>"></div>
        <?php if ($cohorts): ?>
        <div class="enrol-field"><label>Preferred intake</label>
          <select id="f-cohort">
            <option value="">No preference</option>
            <?php foreach ($cohorts as $co): ?>
              <option value="<?= (int)$co['id'] ?>"><?= htmlspecialchars($co['name']) ?><?= $co['start_date'] ? ' — '.date('j M Y', strtotime($co['start_date'])) : '' ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>

        <div id="guardian-block" style="display:none">
          <p style="font-weight:700;color:#92400e;margin:0 0 10px;font-size:0.9rem">You're under 18 — a parent/guardian must consent</p>
          <div class="enrol-field"><label>Parent/guardian name *</label><input type="text" id="f-gname"></div>
          <div class="enrol-field"><label>Parent/guardian contact (phone or email) *</label><input type="text" id="f-gcontact"></div>
          <label class="enrol-consent"><input type="checkbox" id="f-gconsent"> <span>I am the parent/guardian and I consent to this enrolment.</span></label>
        </div>

        <?php if ($stripeOn): ?>
          <div class="enrol-field"><label>Payment</label><div id="stripe-payment-element"></div></div>
          <div id="stripe-error"></div>
          <button type="button" id="pay-btn" class="btn btn-gold btn-lg" style="width:100%;margin-top:10px" onclick="submitEnrol()">Pay £<?= number_format($price,2) ?> &amp; enrol</button>
        <?php else: ?>
          <button type="button" id="pay-btn" class="btn btn-gold btn-lg" style="width:100%;margin-top:10px" onclick="submitEnrol()"><?= $price>0 ? 'Enrol' : 'Complete enrolment' ?></button>
          <?php if ($price > 0): ?><p class="muted" style="margin-top:8px">Online card payment is currently unavailable — we'll contact you to arrange payment.</p><?php endif; ?>
        <?php endif; ?>
        <p class="muted" style="margin-top:10px">Your details are used only to manage your enrolment.</p>
      </form>
    </div>
  </section>
</main>

<script>
var COURSE_ID = <?= $courseId ?>;
function isUnder18(dob){ if(!dob) return false; var d=new Date(dob); if(isNaN(d)) return false; var c=new Date(); c.setFullYear(c.getFullYear()-18); return d>c; }
var dobEl = document.getElementById('f-dob');
function syncGuardian(){ document.getElementById('guardian-block').style.display = isUnder18(dobEl.value) ? 'block' : 'none'; }
dobEl.addEventListener('change', syncGuardian);

function collect(){
  return {
    course_id: COURSE_ID,
    cohort_id: (document.getElementById('f-cohort')||{}).value || '',
    name: document.getElementById('f-name').value.trim(),
    email: document.getElementById('f-email').value.trim(),
    date_of_birth: document.getElementById('f-dob').value,
    guardian_name: (document.getElementById('f-gname')||{}).value || '',
    guardian_contact: (document.getElementById('f-gcontact')||{}).value || '',
    guardian_consent: !!(document.getElementById('f-gconsent')||{}).checked
  };
}
function validate(d){
  if(!d.name || !d.email || !d.date_of_birth) return 'Please fill in your name, email and date of birth.';
  if(isUnder18(d.date_of_birth) && (!d.guardian_name || !d.guardian_contact || !d.guardian_consent))
    return 'A parent/guardian name, contact and consent are required for under-18s.';
  return null;
}

<?php if ($stripeOn): ?>
var stripe = Stripe('<?= STRIPE_PUBLIC_KEY ?>');
var elements = stripe.elements({ mode:'payment', amount:<?= $pence ?>, currency:'gbp', locale:'en-GB' });
elements.create('payment', { layout:'tabs' }).mount('#stripe-payment-element');

function submitEnrol(){
  var btn=document.getElementById('pay-btn'), err=document.getElementById('stripe-error');
  var data=collect(); var v=validate(data);
  err.style.display='none';
  if(v){ err.textContent=v; err.style.display='block'; return; }
  btn.disabled=true; btn.textContent='Processing…';
  elements.submit().then(function(r){
    if(r.error) throw new Error(r.error.message||'Please check your payment details.');
    return fetch('/api/course-enrol-intent',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)});
  }).then(function(r){return r.json();}).then(function(res){
    if(res.error) throw new Error(res.error);
    if(res.free){ window.location.href='/academy/enrolled?e='+encodeURIComponent(res.enrolment_id)+'&free=1'; return; }
    var returnUrl=window.location.origin+'/academy/enrolled?e='+encodeURIComponent(res.enrolment_id);
    return stripe.confirmPayment({elements:elements,clientSecret:res.client_secret,confirmParams:{return_url:returnUrl},redirect:'if_required'})
      .then(function(result){
        if(result.error) throw new Error(result.error.message);
        var url='/academy/enrolled?e='+encodeURIComponent(res.enrolment_id);
        if(result.paymentIntent&&result.paymentIntent.id) url+='&payment_intent='+encodeURIComponent(result.paymentIntent.id);
        window.location.href=url;
      });
  }).catch(function(e){
    err.textContent=e.message||'Something went wrong. Please try again.'; err.style.display='block';
    btn.disabled=false; btn.textContent='Pay £<?= number_format($price,2) ?> & enrol';
  });
}
<?php else: ?>
function submitEnrol(){
  var btn=document.getElementById('pay-btn');
  var data=collect(); var v=validate(data);
  if(v){ alert(v); return; }
  btn.disabled=true; btn.textContent='Submitting…';
  fetch('/api/course-enrol-intent',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)})
    .then(function(r){return r.json();}).then(function(res){
      if(res.error) throw new Error(res.error);
      window.location.href='/academy/enrolled?e='+encodeURIComponent(res.enrolment_id)+(res.free?'&free=1':'');
    }).catch(function(e){ alert(e.message||'Something went wrong.'); btn.disabled=false; btn.textContent='<?= $price>0?'Enrol':'Complete enrolment' ?>'; });
}
<?php endif; ?>
</script>
</body>
</html>
