<?php
// ============================================================
// BraidedbyAGB — Email Diagnostic
// FILE: /database/email-diagnostic.php
//
// Reports exactly what happens when the LIVE server tries to send email,
// so we can see why booking/admin emails aren't arriving.
//
// USAGE (after deploy):
//   https://braidedbyagb.co.uk/database/email-diagnostic.php?key=YOUR_MIGRATE_KEY
//   add &to=you@example.com to send the probes somewhere other than SITE_EMAIL.
//
// Reuses MIGRATE_KEY (from config/database.php) as the access secret.
// Delete this file once email is confirmed working.
// ============================================================

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/mailer.php';

if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    $key = defined('MIGRATE_KEY') ? MIGRATE_KEY : '';
    if ($key === '' || $key === 'CHANGE-ME-to-a-long-random-secret'
        || !hash_equals($key, (string)($_GET['key'] ?? ''))) {
        http_response_code(403);
        exit("Forbidden — missing or wrong ?key=\n");
    }
}

$to = $_GET['to'] ?? (defined('SITE_EMAIL') ? SITE_EMAIL : 'hello@braidedbyagb.co.uk');

echo "BraidedbyAGB — email diagnostic\n";
echo date('Y-m-d H:i:s') . "\n";
echo str_repeat('=', 56) . "\n\n";

// ── 1. Environment / config ───────────────────────────────
echo "[1] Environment & config\n";
echo "    PHP version ............ " . PHP_VERSION . "\n";
echo "    curl available ......... " . (function_exists('curl_init') ? 'yes' : 'NO — Resend cannot work') . "\n";
echo "    new mailer loaded ...... " . (function_exists('sendMail') ? 'yes (Resend sendMail present)' : 'NO — server still has OLD mailer.php') . "\n";
echo "    RESEND_API_KEY ......... " . (defined('RESEND_API_KEY') ? 'defined' : 'NOT DEFINED — config not uploaded?') . "\n";
if (defined('RESEND_API_KEY')) {
    $k = (string)RESEND_API_KEY;
    $placeholder = (strpos($k, 'xxxx') !== false);
    echo "        preview ............ " . substr($k, 0, 6) . "…(" . strlen($k) . " chars)"
        . ($placeholder ? "  <-- STILL THE PLACEHOLDER!" : "") . "\n";
}
echo "    MAIL_FROM .............. " . (defined('MAIL_FROM') ? MAIL_FROM : 'NOT DEFINED') . "\n";
echo "    MAIL_FROM_NAME ......... " . (defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'NOT DEFINED') . "\n";
echo "    SITE_EMAIL (admin) ..... " . (defined('SITE_EMAIL') ? SITE_EMAIL : 'NOT DEFINED') . "\n";
echo "    probe recipient ........ " . $to . "\n\n";

// ── 2. Raw Resend API call (verbose — shows the real response) ─
echo "[2] Raw Resend API call\n";
if (!function_exists('curl_init')) {
    echo "    SKIPPED — curl not available.\n\n";
} elseif (!defined('RESEND_API_KEY')) {
    echo "    SKIPPED — RESEND_API_KEY not defined.\n\n";
} else {
    $payload = [
        'from'    => (defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'BraidedbyAGB')
                     . ' <' . (defined('MAIL_FROM') ? MAIL_FROM : 'hello@braidedbyagb.co.uk') . '>',
        'to'      => [$to],
        'subject' => 'BraidedbyAGB diagnostic (raw) ' . date('H:i:s'),
        'html'    => '<p>Raw Resend probe at ' . date('Y-m-d H:i:s') . '</p>',
    ];
    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . RESEND_API_KEY, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 20,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    echo "    curl error ............. " . ($err !== '' ? $err : 'none') . "\n";
    echo "    HTTP status ............ " . $code . "\n";
    echo "    response body .......... " . $resp . "\n";
    echo "    => " . ($code === 200 || $code === 201 ? "RESEND ACCEPTED THE EMAIL ✓" : "RESEND REJECTED — read the body above") . "\n\n";
}

// ── 3. Through the app's own sendMail() ───────────────────
echo "[3] App sendMail() path\n";
if (function_exists('sendMail')) {
    $ok = sendMail($to, 'BraidedbyAGB diagnostic (sendMail) ' . date('H:i:s'),
                   '<p>sendMail() probe at ' . date('Y-m-d H:i:s') . '</p>',
                   'sendMail probe');
    echo "    sendMail() returned .... " . ($ok ? 'TRUE — sent ✓' : 'FALSE — failed (check error_log)') . "\n\n";
} else {
    echo "    SKIPPED — sendMail() not defined (old mailer on server).\n\n";
}

echo str_repeat('=', 56) . "\n";
echo "Done. If [2] shows HTTP 200 but you still get no email, check spam\n";
echo "and the Resend dashboard 'Emails' log for delivery/bounce status.\n";
