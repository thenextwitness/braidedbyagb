<?php
// ============================================================
// BraidedbyAGB — Enrolment confirmation (public)
// FILE: /public/course-enrolled.php  →  /academy/enrolled
//
// Return page after Stripe. Finalizes via /api/finalize-payment (idempotent;
// the webhook also finalizes). For free enrolments there is nothing to verify.
// ============================================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

$pi   = sanitize($_GET['payment_intent'] ?? '');
$free = ($_GET['free'] ?? '') === '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex,nofollow">
  <title>You're enrolled — BraidedbyAGB Academy</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800;900&family=Lato:ital,wght@0,300;0,400;0,700;1,300&family=Cormorant+Garamond:ital,wght@1,600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/brand.css">
<?php include __DIR__ . '/../includes/brand-styles.php'; ?>
  <link rel="stylesheet" href="/assets/css/global.css">
  <link rel="stylesheet" href="/assets/css/pages.css">
</head>
<body>
<?php include __DIR__ . '/../includes/nav.php'; ?>
<main class="page-content">
  <section class="section">
    <div class="container" style="max-width:560px;text-align:center">
      <div id="state-loading" <?= ($free || !$pi) ? 'style="display:none"' : '' ?>>
        <div style="font-size:2rem">⏳</div>
        <h1 style="font-family:'Montserrat',sans-serif;color:#7A0050;font-size:1.4rem">Confirming your enrolment…</h1>
        <p class="muted" style="color:#6B5575">One moment while we confirm your payment.</p>
      </div>
      <div id="state-done" <?= ($free || !$pi) ? '' : 'style="display:none"' ?>>
        <div style="font-size:2.4rem">🎉</div>
        <h1 style="font-family:'Montserrat',sans-serif;color:#7A0050;font-size:1.5rem">You're enrolled!</h1>
        <p style="color:#3a2740;line-height:1.6">Thank you — your place is booked. We'll be in touch with the details for your course. A confirmation has been noted on your account.</p>
        <div style="margin-top:20px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
          <a href="/academy" class="btn btn-outline">Browse more courses</a>
          <a href="/account" class="btn btn-gold">My account</a>
        </div>
      </div>
      <div id="state-error" style="display:none">
        <div style="font-size:2rem">⚠️</div>
        <h1 style="font-family:'Montserrat',sans-serif;color:#7A0050;font-size:1.4rem">Payment not confirmed yet</h1>
        <p class="muted" style="color:#6B5575">If you completed payment, it may take a moment — refresh this page. If you were charged, don't worry: your enrolment will be confirmed automatically. <a class="auth-link" href="/contact">Contact us</a> if anything looks wrong.</p>
      </div>
    </div>
  </section>
</main>
<?php if ($pi && !$free): ?>
<script>
fetch('/api/finalize-payment?payment_intent=<?= urlencode($pi) ?>')
  .then(function(r){return r.json();})
  .then(function(d){
    document.getElementById('state-loading').style.display='none';
    if(d && (d.finalized || d.status==='succeeded')) document.getElementById('state-done').style.display='';
    else document.getElementById('state-error').style.display='';
  })
  .catch(function(){
    document.getElementById('state-loading').style.display='none';
    document.getElementById('state-error').style.display='';
  });
</script>
<?php endif; ?>
</body>
</html>
