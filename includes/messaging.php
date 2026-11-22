<?php
// ============================================================
// BraidedbyAGB — Batch messages to clients (email + WhatsApp queue)
// FILE: /includes/messaging.php  (loaded at the foot of helpers.php)
//
// Owner-approved design: docs/mockups/2026-11-batch-messages.png.
//   · Email: only to clients who allow emails (customers.email_optin = 1), each
//     with a one-click unsubscribe link (/api/unsubscribe?t=…) — UK PECR.
//     Sent a few at a time (sendBatchChunk) by the page and, if the page is
//     closed, by the scheduler (job 15), so a batch always finishes.
//   · WhatsApp: can't be sent in bulk from a website, so it's a one-tap queue:
//     each chat opens with the personalised text; the owner presses send.
// Tables: message_batches, message_batch_recipients; customers.unsubscribe_token.
// ============================================================

/** Ready-made messages. {first name} and {last style} are filled in per client. */
const MESSAGE_TEMPLATES = [
    'miss_you' => ['label' => 'We miss you 💕',
        'subject' => 'We miss you, {first name}! 💕',
        'body'    => "Hi {first name},\n\nIt's been a little while since your {last style} and we'd love to see you back in the chair!\n\nBook in a minute with the button below, or just reply on WhatsApp.\n\nLove, AGB 💕"],
    'new_dates' => ['label' => 'New dates open 📅',
        'subject' => 'New dates are open, {first name} 📅',
        'body'    => "Hi {first name},\n\nNew appointment dates have just opened and they go quickly. If you're ready for a refresh after your {last style}, now's a great time to grab your spot.\n\nLove, AGB 💕"],
    'seasonal' => ['label' => 'Seasonal message 🎄',
        'subject' => 'Get your hair ready for the season, {first name} ✨',
        'body'    => "Hi {first name},\n\nThe season's busy dates are filling up! Get booked in early so your hair is ready for every occasion.\n\nLove, AGB 💕"],
    'blank' => ['label' => 'Blank', 'subject' => '', 'body' => "Hi {first name},\n\n\n\nLove, AGB 💕"],
];

/** Lapsed-client filter choices: label => [min days, max days or null]. */
const LAPSE_FILTERS = ['2' => ['2 months', 60, null], '3' => ['3 months', 90, null], '6' => ['6 months', 180, null], '12' => ['1 year+', 365, null]];

/** One client's personal details for messages: first name + their last completed style. */
function messageClient(PDO $db, int $customerId): ?array {
    $q = $db->prepare("SELECT c.id, c.name, c.email, c.phone, c.email_optin,
                              (SELECT s.name FROM bookings b JOIN services s ON s.id = b.service_id
                               WHERE b.customer_id = c.id AND b.status = 'completed'
                               ORDER BY b.booked_date DESC, b.id DESC LIMIT 1) AS last_style
                       FROM customers c WHERE c.id = ?");
    $q->execute([$customerId]);
    $c = $q->fetch();
    if (!$c) return null;
    $c['first'] = trim(explode(' ', trim((string)$c['name']))[0]) ?: 'there';
    $c['last_style'] = $c['last_style'] ?: 'last appointment';
    return $c;
}

/** Fill {first name} / {last style} (case-insensitive) — plain text, not yet escaped. */
function personaliseMessage(string $text, array $client): string {
    return str_ireplace(['{first name}', '{last style}'], [$client['first'], $client['last_style']], $text);
}

/** The client's one-click unsubscribe link (token created the first time). */
function unsubscribeUrl(PDO $db, int $customerId): string {
    $q = $db->prepare("SELECT unsubscribe_token FROM customers WHERE id = ?");
    $q->execute([$customerId]);
    $t = (string)$q->fetchColumn();
    if ($t === '') {
        $t = bin2hex(random_bytes(16));
        $db->prepare("UPDATE customers SET unsubscribe_token = ? WHERE id = ? AND unsubscribe_token IS NULL")->execute([$t, $customerId]);
        $q->execute([$customerId]);
        $t = (string)$q->fetchColumn();
    }
    return rtrim(SITE_URL, '/') . '/api/unsubscribe?t=' . rawurlencode($t);
}

/** Full branded email HTML for one client (subject returned too). */
function buildBatchEmail(PDO $db, array $client, string $subject, string $body, bool $bookButton, bool $businessCopy = false): array {
    if (!function_exists('emailWrapper')) require_once __DIR__ . '/mailer.php';
    $subj  = personaliseMessage($subject, $client);
    $text  = personaliseMessage($body, $client);
    $paras = '';
    foreach (preg_split('/\R{2,}/', trim($text)) as $p) {
        if (trim($p) === '') continue;
        $paras .= '<p>' . nl2br(htmlspecialchars(trim($p), ENT_QUOTES, 'UTF-8')) . '</p>';
    }
    $heading = htmlspecialchars($subj, ENT_QUOTES, 'UTF-8');
    $book    = htmlspecialchars(rtrim(SITE_URL, '/') . '/booking', ENT_QUOTES, 'UTF-8');
    $btn     = $bookButton ? '<p style="text-align:center;margin:24px 0"><a href="' . $book . '" class="cta-btn">See available times</a></p>' : '';
    // The business copy gets a dead link, so clicking it can't unsubscribe a real client.
    $unsub   = $businessCopy ? '#' : htmlspecialchars(unsubscribeUrl($db, (int)$client['id']), ENT_QUOTES, 'UTF-8');
    $content = "<h2>{$heading}</h2>{$paras}{$btn}"
             . '<p style="font-size:12px;color:#9B8BA5;margin-top:22px">You\'re receiving this because you\'re a BraidedbyAGB client. '
             . '<a href="' . $unsub . '" style="color:#9B8BA5">Unsubscribe</a> in one click.</p>';
    return ['subject' => $subj, 'html' => emailWrapper($content)];
}

/** WhatsApp text for one client (with the booking link when the button is on). */
function buildBatchWhatsApp(array $client, string $body, bool $bookButton): string {
    $t = personaliseMessage($body, $client);
    return $bookButton ? rtrim($t) . "\n\nBook here: " . rtrim(SITE_URL, '/') . '/booking' : $t;
}

/**
 * Create a batch. $channel 'email' | 'whatsapp'. Clients who can't receive it
 * (emails turned off / no email; no phone for WhatsApp) are recorded as skipped.
 * Returns the batch id.
 */
function createMessageBatch(PDO $db, string $channel, array $customerIds, string $template, string $subject, string $body, bool $bookButton, string $audience): int {
    $ids = array_values(array_unique(array_filter(array_map('intval', $customerIds))));
    $db->beginTransaction();
    try {
        $db->prepare("INSERT INTO message_batches (channel, template, subject, body, book_button, audience, total, status)
                      VALUES (?,?,?,?,?,?,?, 'sending')")
           ->execute([$channel, $template, $subject, $body, $bookButton ? 1 : 0, $audience, count($ids)]);
        $bid = (int)$db->lastInsertId();
        $get = $db->prepare("SELECT email, phone, email_optin, COALESCE(is_blocked,0) AS blocked FROM customers WHERE id = ?");
        $ins = $db->prepare("INSERT IGNORE INTO message_batch_recipients (batch_id, customer_id, status, error) VALUES (?,?,?,?)");
        foreach ($ids as $cid) {
            $get->execute([$cid]);
            $c = $get->fetch();
            if (!$c || (int)$c['blocked'] === 1)                                         $ins->execute([$bid, $cid, 'skipped', 'Not available']);
            elseif ($channel === 'email' && ((int)$c['email_optin'] !== 1 || trim((string)$c['email']) === ''))
                $ins->execute([$bid, $cid, 'skipped', trim((string)$c['email']) === '' ? 'No email address' : ($c['email_optin'] === null ? 'Not asked about offers' : 'Emails turned off')]);
            elseif ($channel === 'whatsapp' && waClientLink($c['phone'], 'x') === '')     $ins->execute([$bid, $cid, 'skipped', 'No phone number']);
            else                                                                          $ins->execute([$bid, $cid, 'pending', null]);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    refreshBatchCounts($db, $bid);
    return $bid;
}

/** Recount a batch and mark it done when nothing is left pending. */
function refreshBatchCounts(PDO $db, int $batchId): array {
    $q = $db->prepare("SELECT status, COUNT(*) AS n FROM message_batch_recipients WHERE batch_id = ? GROUP BY status");
    $q->execute([$batchId]);
    $n = ['pending' => 0, 'sending' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'opened' => 0];
    foreach ($q->fetchAll() as $r) $n[$r['status']] = (int)$r['n'];
    $done = $n['pending'] === 0 && $n['sending'] === 0;
    $db->prepare("UPDATE message_batches SET sent = ?, failed = ?, status = IF(?, 'done', status) WHERE id = ?")
       ->execute([$n['sent'] + $n['opened'], $n['failed'], $done ? 1 : 0, $batchId]);
    if ($done) {
        // Only the call that actually finishes the batch sends the business copy.
        $fin = $db->prepare("UPDATE message_batches SET finished_at = NOW() WHERE id = ? AND finished_at IS NULL");
        $fin->execute([$batchId]);
        if ($fin->rowCount() === 1) {
            try { sendBatchBusinessCopy($db, $batchId, $n['sent'] + $n['opened']); }
            catch (Throwable $e) { error_log('Batch business copy failed: ' . $e->getMessage()); }
        }
    }
    return $n;
}

/**
 * One copy of a finished email batch to the business inbox (not one per client).
 * Shows the message as the first client received it; its unsubscribe link is disabled.
 */
function sendBatchBusinessCopy(PDO $db, int $batchId, int $sentCount): void {
    if ($sentCount < 1) return;
    if (!function_exists('sendMail')) require_once __DIR__ . '/mailer.php';
    $to = businessCopyAddress();
    if ($to === '') return;
    $b = $db->prepare("SELECT * FROM message_batches WHERE id = ? AND channel = 'email'");
    $b->execute([$batchId]);
    $batch = $b->fetch();
    if (!$batch) return;
    $f = $db->prepare("SELECT customer_id FROM message_batch_recipients WHERE batch_id = ? AND status IN ('sent','opened') ORDER BY id LIMIT 1");
    $f->execute([$batchId]);
    $client = messageClient($db, (int)$f->fetchColumn());
    if (!$client) return;
    $mail  = buildBatchEmail($db, $client, (string)$batch['subject'], (string)$batch['body'], (int)$batch['book_button'] === 1, true);
    $label = $sentCount === 1 ? '1 client' : $sentCount . ' clients';
    $note  = '<div style="background:#fdf6dd;border:1px solid #D4AF37;border-radius:8px;padding:10px 14px;margin:0 auto 14px;max-width:600px;'
           . 'font-family:Arial,sans-serif;font-size:13px;color:#4B0082">Your copy: this batch message was sent to <strong>' . $label
           . '</strong>. Shown as ' . htmlspecialchars($client['first'], ENT_QUOTES, 'UTF-8') . ' received it; each client saw their own name.</div>';
    $html  = preg_replace('/<body([^>]*)>/i', '<body$1>' . $note, $mail['html'], 1, $cnt);
    if (!$cnt) $html = $note . $mail['html'];
    sendMail($to, '[Copy] ' . $mail['subject'] . ' (sent to ' . $label . ')', $html, '', [], ['no_copy' => true]);
}

/** Send up to $limit pending emails of a batch. Safe to call from two places at once. Returns counts. */
function sendBatchChunk(PDO $db, int $batchId, int $limit = 6): array {
    $b = $db->prepare("SELECT * FROM message_batches WHERE id = ? AND channel = 'email'");
    $b->execute([$batchId]);
    $batch = $b->fetch();
    if (!$batch || $batch['status'] !== 'sending') return refreshBatchCounts($db, $batchId);
    if (!function_exists('sendMail')) require_once __DIR__ . '/mailer.php';
    $pick = $db->prepare("SELECT id, customer_id FROM message_batch_recipients WHERE batch_id = ? AND status = 'pending' ORDER BY id LIMIT " . max(1, min(20, $limit)));
    $pick->execute([$batchId]);
    // A row left 'sending' by a closed page goes back to pending after 10 minutes.
    $db->prepare("UPDATE message_batch_recipients SET status = 'pending'
                  WHERE batch_id = ? AND status = 'sending' AND claimed_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)")
       ->execute([$batchId]);
    $claim = $db->prepare("UPDATE message_batch_recipients SET status = 'sending', claimed_at = NOW() WHERE id = ? AND status = 'pending'");
    $done  = $db->prepare("UPDATE message_batch_recipients SET status = ?, error = ?, sent_at = NOW() WHERE id = ?");
    foreach ($pick->fetchAll() as $r) {
        $claim->execute([(int)$r['id']]);
        if ($claim->rowCount() !== 1) continue;            // the other sender has it
        $client = messageClient($db, (int)$r['customer_id']);
        if (!$client || (int)$client['email_optin'] !== 1 || trim((string)$client['email']) === '') {
            $done->execute(['skipped', 'Emails turned off', (int)$r['id']]); continue;
        }
        try {
            $mail = buildBatchEmail($db, $client, (string)$batch['subject'], (string)$batch['body'], (int)$batch['book_button'] === 1);
            // no_copy: the business gets one copy per batch when it finishes, not one per client.
            $ok = sendMail(trim((string)$client['email']), $mail['subject'], $mail['html'], '', [], ['no_copy' => true]);
            $done->execute([$ok ? 'sent' : 'failed', $ok ? null : 'The email service refused it', (int)$r['id']]);
        } catch (Throwable $e) {
            $done->execute(['failed', substr($e->getMessage(), 0, 250), (int)$r['id']]);
        }
        usleep(400000);   // stay well under the email service's rate limit
    }
    return refreshBatchCounts($db, $batchId);
}

/** Scheduler job 15: finish any email batch the owner left (page closed). */
function sendPendingBatches(PDO $db): int {
    $sent = 0;
    foreach ($db->query("SELECT id FROM message_batches WHERE status = 'sending' AND channel = 'email' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN) as $bid) {
        $n = sendBatchChunk($db, (int)$bid, 20);
        $sent += $n['sent'];
    }
    return $sent;
}
