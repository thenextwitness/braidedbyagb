<?php
// ============================================================
// BraidedbyAGB — Email System (Resend HTTP API)
// FILE: /includes/mailer.php
//
// Transport is Resend (https://resend.com) over curl — no SMTP, no PHPMailer.
// All existing email functions are unchanged: they still call createMailer(),
// which now returns a lightweight shim exposing the same PHPMailer-style
// interface (addAddress / Subject / Body / AltBody / addAttachment / send).
// On send() the shim forwards everything to sendMail() → Resend.
// ============================================================

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/helpers.php';

/**
 * Low-level transport: send one email via the Resend API.
 *
 * @param string|array $to    A recipient string ("a@b.com" or "Name <a@b.com>")
 *                            or an array of such strings.
 * @param array        $attachments  [['filename'=>..., 'content'=>base64...], ...]
 * @return bool  true on success (HTTP 200/201 with an id), false otherwise (non-fatal).
 */
function sendMail($to, string $subject, string $html, string $altText = '', array $attachments = []): bool {
    if (!defined('RESEND_API_KEY') || RESEND_API_KEY === '' || strpos(RESEND_API_KEY, 'xxxx') !== false) {
        error_log('sendMail: RESEND_API_KEY is not configured — email not sent.');
        return false;
    }

    $recipients = is_array($to) ? array_values(array_filter($to)) : [(string)$to];
    if (empty($recipients)) { error_log('sendMail: no recipient.'); return false; }

    $payload = [
        'from'     => MAIL_FROM_NAME . ' <' . MAIL_FROM . '>',
        'to'       => $recipients,
        'subject'  => $subject,
        'html'     => $html,
        'reply_to' => MAIL_REPLY_TO,
    ];
    if ($altText !== '')     $payload['text']        = $altText;
    if (!empty($attachments)) $payload['attachments'] = $attachments;

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . RESEND_API_KEY,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 20,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) { error_log('sendMail curl error: ' . $err); return false; }
    if ($code === 200 || $code === 201) {
        $data = json_decode($resp, true);
        if (!empty($data['id'])) return true;
        error_log('sendMail: unexpected Resend response: ' . $resp);
        return false;
    }
    error_log("sendMail: Resend returned HTTP {$code}: " . $resp);
    return false;
}

/**
 * PHPMailer-compatible shim so all existing email functions keep working
 * unchanged. Collects recipients/subject/body/attachments, then on send()
 * forwards to sendMail() (Resend).
 */
class MailShim {
    public string $Subject = '';
    public string $Body    = '';
    public string $AltBody = '';
    private array $recipients  = [];
    private array $attachments = [];

    public function addAddress(string $email, string $name = ''): void {
        if ($email === '') return;
        $this->recipients[] = $name !== '' ? "{$name} <{$email}>" : $email;
    }
    /** Mirrors PHPMailer::addAttachment($path, $name) — reads + base64-encodes the file. */
    public function addAttachment(string $path, string $name = ''): void {
        if (is_readable($path)) {
            $this->attachments[] = [
                'filename' => $name !== '' ? $name : basename($path),
                'content'  => base64_encode((string)file_get_contents($path)),
            ];
        }
    }
    public function send(): bool {
        if (empty($this->recipients)) return false;
        return sendMail($this->recipients, $this->Subject, $this->Body, $this->AltBody, $this->attachments);
    }
}

// ── Base mailer factory (now returns the Resend-backed shim) ──
function createMailer(): MailShim {
    return new MailShim();
}

/**
 * Styled "Where to find us" address box for post-payment confirmation emails.
 * Returns '' when no business_address has been set, so it's safe to inject
 * unconditionally. The exact address is only included in PAID/confirmed emails.
 */
function appointmentLocationBlock(): string {
    $addr = trim(getSetting('business_address', ''));
    if ($addr === '') return '';
    $addrHtml = nl2br(htmlspecialchars($addr, ENT_QUOTES, 'UTF-8'));
    return <<<HTML
<div class="detail-box">
  <p style="margin:0 0 8px;font-weight:700;font-size:14px;color:#4B0082;text-transform:uppercase;letter-spacing:0.05em">📍 Where to find us</p>
  <p style="margin:0;font-size:15px;line-height:1.6">{$addrHtml}</p>
</div>
HTML;
}

// ── Email wrapper template ────────────────────────────────
function emailWrapper(string $content, string $preheader = ''): string {
    $siteUrl  = SITE_URL;
    $siteName = SITE_NAME;
    $phone    = SITE_PHONE;
    $year     = date('Y');
    $logoUrl  = SITE_URL . '/assets/images/braidedbyagblogo.png';

    // Branding pulled from settings so it stays in sync with the rest of the site
    $tagline      = htmlspecialchars(getSetting('site_tagline', 'African Hair Braiding Specialist'), ENT_QUOTES, 'UTF-8');
    $addressTown  = htmlspecialchars(getSetting('site_address', 'Farnborough, Hampshire, UK'), ENT_QUOTES, 'UTF-8');
    $instaUrl     = trim(getSetting('instagram_url', ''));
    $instaHandle  = htmlspecialchars(getSetting('instagram_handle', '@BraidedbyAGB'), ENT_QUOTES, 'UTF-8');
    $waNumber     = preg_replace('/\D/', '', getSetting('whatsapp_number', '447769064971'));
    if (strlen($waNumber) === 11 && str_starts_with($waNumber, '0')) $waNumber = '44' . substr($waNumber, 1);

    // Hidden preheader — the preview line shown by inbox clients before opening.
    // If none is supplied, derive a contextual one from the email's own opening
    // copy (first ~120 chars of the body text) so every email gets a meaningful
    // preview line without each function having to specify it.
    if ($preheader === '') {
        $plain = trim(preg_replace('/\s+/', ' ', strip_tags($content)));
        $preheader = function_exists('mb_substr') ? mb_substr($plain, 0, 120) : substr($plain, 0, 120);
    }
    $preheaderHtml = $preheader !== ''
        ? '<div style="display:none;max-height:0;overflow:hidden;opacity:0;mso-hide:all;">'
          . htmlspecialchars($preheader, ENT_QUOTES, 'UTF-8')
          . str_repeat('&#8199;&#65279;', 30) . '</div>'
        : '';

    // Social row (Instagram) — only shown when a URL is configured
    $socialHtml = $instaUrl !== ''
        ? '<p style="margin:10px 0 4px;"><a href="' . htmlspecialchars($instaUrl, ENT_QUOTES, 'UTF-8')
          . '" style="color:#ffffff;text-decoration:none;">📷 Follow us on Instagram ' . $instaHandle . '</a></p>'
        : '';

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$siteName}</title>
<style>
  body { margin:0; padding:0; background:#F9EEF9; font-family:'Lato',Arial,sans-serif; color:#1A0014; }
  .wrapper { max-width:600px; margin:0 auto; background:#ffffff; }
  .header  { background:linear-gradient(135deg,#4B0082,#800080); padding:30px 40px 26px; text-align:center; }
  .logo-badge { display:inline-block; background:#ffffff; border-radius:16px; padding:12px 20px; }
  .logo-badge img { display:block; width:160px; max-width:62vw; height:auto; border:0; }
  .header p  { color:#D4AF37; font-size:12px; letter-spacing:2px; text-transform:uppercase; margin:16px 0 0; }
  .body    { padding:40px; }
  .body h2 { color:#4B0082; font-size:22px; font-weight:700; margin:0 0 16px; }
  .body p  { font-size:16px; line-height:1.7; color:#1A0014; margin:0 0 14px; }
  .detail-box { background:#F9EEF9; border-left:4px solid #800080; padding:20px 24px; margin:24px 0; border-radius:4px; }
  .detail-box table { width:100%; border-collapse:collapse; }
  .detail-box td { padding:6px 0; font-size:15px; }
  .detail-box td:first-child { font-weight:700; color:#4B0082; width:160px; }
  .cta-btn { display:inline-block; background:#800080; color:#ffffff !important; font-weight:700; font-size:14px; letter-spacing:1px; text-transform:uppercase; padding:14px 32px; text-decoration:none; margin:8px 0; border-radius:6px; }
  .cta-btn:hover { background:#660066; }
  .policy-box { background:#FFF3E0; border:1px solid #F5C584; padding:16px 20px; border-radius:4px; font-size:14px; color:#7A4500; margin:20px 0; }
  .gold-text { color:#D4AF37; font-weight:700; }
  .footer  { background:#4B0082; padding:28px 40px; text-align:center; }
  .footer p { color:#D4AF37; font-size:13px; margin:4px 0; }
  .footer a { color:#ffffff; text-decoration:none; }
  .divider { height:1px; background:#E8D8EE; margin:24px 0; }
</style>
</head>
<body>
{$preheaderHtml}
<div class="wrapper">
  <div class="header">
    <span class="logo-badge">
      <img src="{$logoUrl}" alt="{$siteName} — Beauty Salon" width="160">
    </span>
    <p>{$tagline}</p>
  </div>
  <div class="body">
    {$content}
  </div>
  <div class="footer">
    <p><strong style="color:#ffffff;letter-spacing:1px;">BOOK YOUR APPOINTMENT</strong></p>
    <p><a href="https://wa.me/{$waNumber}">💬 WhatsApp {$phone}</a></p>
    {$socialHtml}
    <p>{$addressTown}</p>
    <p style="margin-top:12px;font-size:11px;color:#C9A8E0;">
      © {$year} {$siteName}. All rights reserved.<br>
      <a href="{$siteUrl}/policies" style="color:#C9A8E0;">Policies</a> &nbsp;·&nbsp;
      <a href="{$siteUrl}/contact" style="color:#C9A8E0;">Contact</a> &nbsp;·&nbsp;
      <a href="{$siteUrl}/booking" style="color:#C9A8E0;">Book Online</a>
    </p>
  </div>
</div>
</body>
</html>
HTML;
}

// ── 1. Booking Request Received ───────────────────────────
function emailBookingReceived(array $booking, array $customer, array $service): bool {
    try {
        $mail = createMailer();
        $mail->addAddress($customer['email'], $customer['name']);
        $mail->Subject = 'Booking Request Received — BraidedbyAGB';
        $ref  = sanitize($booking['booking_ref']);
        $name = sanitize($customer['name']);
        $svc  = sanitize($service['name']);
        $date = formatDate($booking['booked_date']);
        $time = formatTime($booking['booked_time']);
        $content = <<<HTML
<h2>We've received your booking request! 💜</h2>
<p>Hi {$name}, thank you for choosing BraidedbyAGB. Your booking request is now being reviewed and we'll confirm it shortly.</p>
<div class="detail-box">
  <table>
    <tr><td>Reference:</td><td><strong>{$ref}</strong></td></tr>
    <tr><td>Service:</td><td>{$svc}</td></tr>
    <tr><td>Date:</td><td>{$date}</td></tr>
    <tr><td>Time:</td><td>{$time}</td></tr>
  </table>
</div>
<div class="policy-box">
  ⏰ <strong>Late Arrival Reminder:</strong> Please arrive within <strong>20 minutes</strong> of your appointment time. After 20 minutes your appointment may be cancelled and your deposit forfeited. If you're running late, please WhatsApp us immediately on 07769064971.
</div>
<p>We'll send you a confirmation email as soon as your booking is approved. If you have any questions, please don't hesitate to reach out.</p>
<p style="text-align:center;margin-top:28px;">
  <a href="https://wa.me/447769064971" class="cta-btn">WhatsApp Us</a>
</p>
HTML;
        $mail->Body = emailWrapper($content);
        return $mail->send();
    } catch (Exception $e) {
        error_log('Email error (bookingReceived): ' . $e->getMessage());
        return false;
    }
}

// ── 2. Booking Approved ───────────────────────────────────
function emailBookingApproved(array $booking, array $customer, array $service, array $addons = []): bool {
    try {
        $mail = createMailer();
        $mail->addAddress($customer['email'], $customer['name']);
        $mail->Subject = 'Appointment Confirmed ✓ — BraidedbyAGB';
        $ref     = sanitize($booking['booking_ref']);
        $name    = sanitize($customer['name']);
        $svc     = sanitize($service['name']);
        $date    = formatDate($booking['booked_date']);
        $time    = formatTime($booking['booked_time']);
        $deposit = formatPrice((float)$booking['deposit_amount']);
        $balance = formatPrice((float)$booking['remaining_balance']);
        $total   = formatPrice((float)$booking['total_price']);
        $addonHtml = '';
        foreach ($addons as $a) {
            $addonHtml .= '<tr><td>Add-on:</td><td>' . sanitize($a['name']) . ' (+' . formatPrice((float)$a['price_charged']) . ')</td></tr>';
        }
        $locationBlock = appointmentLocationBlock();
        $content = <<<HTML
<h2>Your appointment is confirmed! ✨</h2>
<p>Hi {$name}, we're so excited to see you! Here are your confirmed appointment details:</p>
<div class="detail-box">
  <table>
    <tr><td>Reference:</td><td><strong>{$ref}</strong></td></tr>
    <tr><td>Service:</td><td>{$svc}</td></tr>
    {$addonHtml}
    <tr><td>Date:</td><td><strong>{$date}</strong></td></tr>
    <tr><td>Time:</td><td><strong>{$time}</strong></td></tr>
    <tr><td>Total Price:</td><td>{$total}</td></tr>
    <tr><td>Deposit Paid:</td><td class="gold-text">{$deposit} ✓</td></tr>
    <tr><td>Balance Due:</td><td>{$balance} (payable on the day)</td></tr>
  </table>
</div>
{$locationBlock}
<div class="policy-box">
  ⏰ <strong>Late Arrival Policy:</strong> Please arrive within <strong>20 minutes</strong> of your appointment time. Arrivals after 20 minutes may result in cancellation and forfeiture of your deposit. Running late? WhatsApp us straight away on 07769064971.<br><br>
  ❌ <strong>Cancellation Policy:</strong> Cancellations must be made at least <strong>48 hours</strong> before your appointment. Deposits are non-refundable.
</div>
<p>We'll send you a reminder the day before and on the morning of your appointment. See you soon! 💕</p>
HTML;
        $mail->Body = emailWrapper($content);
        return $mail->send();
    } catch (Exception $e) {
        error_log('Email error (bookingApproved): ' . $e->getMessage());
        return false;
    }
}

// ── 3. Booking Rejected ───────────────────────────────────
function emailBookingRejected(array $booking, array $customer, array $service, string $reason = ''): bool {
    try {
        $mail = createMailer();
        $mail->addAddress($customer['email'], $customer['name']);
        $mail->Subject = 'Regarding Your Booking Request — BraidedbyAGB';
        $name   = sanitize($customer['name']);
        $svc    = sanitize($service['name']);
        $date   = formatDate($booking['booked_date']);
        $reason = $reason ? '<p><em>Reason: ' . sanitize($reason) . '</em></p>' : '';
        $siteUrl= SITE_URL;
        $content = <<<HTML
<h2>Booking Update</h2>
<p>Hi {$name}, unfortunately we're unable to confirm your booking for <strong>{$svc}</strong> on <strong>{$date}</strong> at this time.</p>
{$reason}
<p>We're sorry for any inconvenience. We'd love to find a time that works — please reach out to us directly and we'll do our best to accommodate you.</p>
<p style="text-align:center;margin-top:28px;">
  <a href="{$siteUrl}/booking" class="cta-btn">Book Again</a>
  &nbsp;&nbsp;
  <a href="https://wa.me/447769064971" class="cta-btn" style="background:#25D366;">WhatsApp Us</a>
</p>
HTML;
        $mail->Body = emailWrapper($content);
        return $mail->send();
    } catch (Exception $e) {
        error_log('Email error (bookingRejected): ' . $e->getMessage());
        return false;
    }
}

// ── 4. Booking Rescheduled ────────────────────────────────
function emailBookingRescheduled(array $booking, array $customer, array $service): bool {
    try {
        $mail = createMailer();
        $mail->addAddress($customer['email'], $customer['name']);
        $mail->Subject = 'Your Appointment Has Been Rescheduled — BraidedbyAGB';
        $name = sanitize($customer['name']);
        $svc  = sanitize($service['name']);
        $date = formatDate($booking['booked_date']);
        $time = formatTime($booking['booked_time']);
        $ref  = sanitize($booking['booking_ref']);
        $content = <<<HTML
<h2>Your appointment has been rescheduled 📅</h2>
<p>Hi {$name}, your appointment has been updated. Here are your new details:</p>
<div class="detail-box">
  <table>
    <tr><td>Reference:</td><td>{$ref}</td></tr>
    <tr><td>Service:</td><td>{$svc}</td></tr>
    <tr><td>New Date:</td><td><strong>{$date}</strong></td></tr>
    <tr><td>New Time:</td><td><strong>{$time}</strong></td></tr>
  </table>
</div>
<div class="policy-box">
  ⏰ Please remember to arrive within <strong>20 minutes</strong> of your appointment time. If you have any concerns about the new date, please WhatsApp us on 07769064971.
</div>
HTML;
        $mail->Body = emailWrapper($content);
        return $mail->send();
    } catch (Exception $e) {
        error_log('Email error (rescheduled): ' . $e->getMessage());
        return false;
    }
}

// ── 5. 24-Hour Reminder ───────────────────────────────────
function emailReminder24hr(array $booking, array $customer, array $service): bool {
    try {
        $mail = createMailer();
        $mail->addAddress($customer['email'], $customer['name']);
        $mail->Subject = 'Reminder: Your Appointment is Tomorrow — BraidedbyAGB';
        $name    = sanitize($customer['name']);
        $svc     = sanitize($service['name']);
        $date    = formatDate($booking['booked_date']);
        $time    = formatTime($booking['booked_time']);
        $balance = formatPrice((float)$booking['remaining_balance']);
        $content = <<<HTML
<h2>See you tomorrow! 💕</h2>
<p>Hi {$name}, just a friendly reminder that your appointment is <strong>tomorrow</strong>.</p>
<div class="detail-box">
  <table>
    <tr><td>Service:</td><td><strong>{$svc}</strong></td></tr>
    <tr><td>Date:</td><td><strong>{$date}</strong></td></tr>
    <tr><td>Time:</td><td><strong>{$time}</strong></td></tr>
    <tr><td>Balance Due:</td><td>{$balance} (cash or bank transfer on the day)</td></tr>
  </table>
</div>
<div class="policy-box">
  ⏰ <strong>Late Arrival Reminder:</strong> Please arrive within <strong>20 minutes</strong> of your appointment time. If you're running late, please WhatsApp us straight away on <a href="https://wa.me/447769064971">07769064971</a>.<br><br>
  ❌ <strong>Cancellation:</strong> If you need to cancel, you must do so at least <strong>48 hours</strong> before your appointment. Your deposit is non-refundable.
</div>
<p>We can't wait to create your perfect look! 🌟</p>
HTML;
        $mail->Body = emailWrapper($content);
        return $mail->send();
    } catch (Exception $e) {
        error_log('Email error (24hr reminder): ' . $e->getMessage());
        return false;
    }
}

// ── 6. 2-Hour Reminder ────────────────────────────────────
function emailReminder2hr(array $booking, array $customer, array $service): bool {
    try {
        $mail = createMailer();
        $mail->addAddress($customer['email'], $customer['name']);
        $mail->Subject = 'Today\'s the Day! Your Appointment is in 2 Hours — BraidedbyAGB';
        $name = sanitize($customer['name']);
        $svc  = sanitize($service['name']);
        $time = formatTime($booking['booked_time']);
        $content = <<<HTML
<h2>Your appointment is in 2 hours! ✨</h2>
<p>Hi {$name}, this is your final reminder! Your <strong>{$svc}</strong> appointment is at <strong>{$time}</strong> today.</p>
<div class="policy-box">
  ⏰ <strong>Important:</strong> Please arrive within <strong>20 minutes</strong> of your appointment time. Running late? Please WhatsApp us immediately on <a href="https://wa.me/447769064971">07769064971</a> so we can do our best to accommodate you.
</div>
<p>See you very soon — get ready to love your new look! 💜</p>
<p style="text-align:center;margin-top:24px;">
  <a href="https://wa.me/447769064971" class="cta-btn" style="background:#25D366;">I'm on my way!</a>
</p>
HTML;
        $mail->Body = emailWrapper($content);
        return $mail->send();
    } catch (Exception $e) {
        error_log('Email error (2hr reminder): ' . $e->getMessage());
        return false;
    }
}

// ── 7. Post-Appointment Review Request ────────────────────
function emailReviewRequest(array $booking, array $customer, array $service, string $token): bool {
    try {
        $mail = createMailer();
        $mail->addAddress($customer['email'], $customer['name']);
        $mail->Subject = 'How was your BraidedbyAGB experience? 💕';
        $name     = sanitize($customer['name']);
        $svc      = sanitize($service['name']);
        $siteUrl  = SITE_URL;
        $link     = $siteUrl . '/review?token=' . urlencode($token);
        $incentiveEnabled = getSetting('review_incentive_enabled', '0') === '1';
        $incentiveHtml = '';
        if ($incentiveEnabled) {
            $discount = getSetting('review_incentive_discount', '10');
            $incentiveHtml = <<<HTML
<div style="background:#F9EEF9;border:1px solid #E8D8EE;padding:16px;margin:20px 0;text-align:center;border-radius:4px;">
  <p style="margin:0;color:#4B0082;font-weight:700;">🎁 Leave a review and get <span class="gold-text">{$discount}% off</span> your next booking!</p>
  <p style="margin:4px 0 0;font-size:13px;color:#6B5575;">Your personal discount code will be emailed to you once your review is submitted.</p>
</div>
HTML;
        }
        $content = <<<HTML
<h2>Hope you're loving your new look! 💜</h2>
<p>Hi {$name}, it was such a pleasure having you at BraidedbyAGB for your <strong>{$svc}</strong>!</p>
<p>We'd love to hear what you thought about your experience. It only takes 60 seconds and your feedback means the world to us — and helps other clients feel confident choosing BraidedbyAGB. 🌟</p>
{$incentiveHtml}
<p style="text-align:center;margin-top:28px;">
  <a href="{$link}" class="cta-btn">Leave My Review ★★★★★</a>
</p>
<p style="font-size:13px;color:#9B8BA5;margin-top:20px;">This review link is personal to you and expires in 7 days. If you have any questions, just WhatsApp us on 07769064971.</p>
HTML;
        $mail->Body = emailWrapper($content);
        return $mail->send();
    } catch (Exception $e) {
        error_log('Email error (reviewRequest): ' . $e->getMessage());
        return false;
    }
}

// ── 8. Order Confirmation ─────────────────────────────────
function emailOrderConfirmation(array $order, array $customer, array $items): bool {
    try {
        $mail = createMailer();
        $mail->addAddress($customer['email'], $customer['name']);
        $mail->Subject = 'Order Confirmed — BraidedbyAGB';
        $name  = sanitize($customer['name']);
        $ref   = sanitize($order['order_ref']);
        $total = formatPrice((float)$order['total']);
        $delivery = $order['delivery_type'] === 'local_pickup'
            ? 'Local Pickup (Farnborough)'
            : 'UK Standard Delivery';
        $itemsHtml = '';
        foreach ($items as $item) {
            $pName    = sanitize($item['name']);
            $colour   = isset($item['colour']) ? ' — ' . sanitize($item['colour']) : '';
            $qty      = (int)$item['quantity'];
            $price    = formatPrice((float)$item['price_charged']);
            $itemsHtml .= "<tr><td>{$pName}{$colour} × {$qty}</td><td style='text-align:right'>{$price}</td></tr>";
        }
        $content = <<<HTML
<h2>Thank you for your order! 🛍️</h2>
<p>Hi {$name}, your order has been received and is being prepared.</p>
<div class="detail-box">
  <table>
    <tr><td>Order Reference:</td><td><strong>{$ref}</strong></td></tr>
    <tr><td>Delivery:</td><td>{$delivery}</td></tr>
    <tr><td>Total:</td><td><strong>{$total}</strong></td></tr>
  </table>
  <div class="divider"></div>
  <table style="width:100%">
    {$itemsHtml}
  </table>
</div>
<p>We'll send you another email as soon as your order is on its way. If you have any questions, WhatsApp us on 07769064971.</p>
HTML;
        $mail->Body = emailWrapper($content);
        return $mail->send();
    } catch (Exception $e) {
        error_log('Email error (orderConfirmation): ' . $e->getMessage());
        return false;
    }
}

// ── 8b. Admin Alert: New Shop Order ───────────────────────
// Mirrors emailAdminNewBooking but for shop orders (previously orders had no
// admin alert). $items may use either order_items keys (name/quantity/price_charged)
// or the checkout cart keys (name/quantity/price) — handled defensively.
function emailAdminNewOrder(array $order, array $customer, array $items): bool {
    try {
        $mail = createMailer();
        $mail->addAddress(SITE_EMAIL, SITE_NAME . ' Admin');
        $mail->Subject = '🛍️ New Shop Order — ' . ($order['order_ref'] ?? '');
        $name   = sanitize($customer['name'] ?? '');
        $email  = sanitize($customer['email'] ?? '');
        $ref    = sanitize($order['order_ref'] ?? '');
        $total  = formatPrice((float)($order['total'] ?? 0));
        $method = ucfirst(str_replace('_', ' ', (string)($order['payment_method'] ?? '')));
        $payStatus = ($order['payment_status'] ?? '') === 'paid' ? 'Paid ✓' : 'Pending';
        $delivery = ($order['delivery_type'] ?? '') === 'local_pickup' ? 'Local Pickup' : 'Delivery';
        $itemsHtml = '';
        foreach ($items as $item) {
            $pName = sanitize($item['name'] ?? ('Product #' . ($item['productId'] ?? $item['product_id'] ?? '')));
            $qty   = (int)($item['quantity'] ?? 1);
            $price = formatPrice((float)($item['price_charged'] ?? $item['price'] ?? 0));
            $itemsHtml .= "<tr><td>{$pName} × {$qty}</td><td style='text-align:right'>{$price}</td></tr>";
        }
        $adminUrl = ADMIN_URL;
        $content = <<<HTML
<h2>New Shop Order Received 🛍️</h2>
<div class="detail-box">
  <table>
    <tr><td>Order Ref:</td><td><strong>{$ref}</strong></td></tr>
    <tr><td>Customer:</td><td>{$name}</td></tr>
    <tr><td>Email:</td><td>{$email}</td></tr>
    <tr><td>Fulfilment:</td><td>{$delivery}</td></tr>
    <tr><td>Payment:</td><td>{$method} — {$payStatus}</td></tr>
    <tr><td>Total:</td><td><strong>{$total}</strong></td></tr>
  </table>
  <div class="divider"></div>
  <table style="width:100%">{$itemsHtml}</table>
</div>
<p style="text-align:center;margin-top:20px;">
  <a href="{$adminUrl}/orders" class="cta-btn">Review in Admin Portal</a>
</p>
HTML;
        $mail->Body = emailWrapper($content);
        return $mail->send();
    } catch (Exception $e) {
        error_log('Email error (adminNewOrder): ' . $e->getMessage());
        return false;
    }
}

// ── 9. Order Dispatched ───────────────────────────────────
function emailOrderDispatched(array $order, array $customer, string $trackingNumber = ''): bool {
    try {
        $mail = createMailer();
        $mail->addAddress($customer['email'], $customer['name']);
        $mail->Subject = 'Your Order is on its Way! 📦 — BraidedbyAGB';
        $name = sanitize($customer['name']);
        $ref  = sanitize($order['order_ref']);

        // Build tracking block if a tracking number was provided.
        // Falls back to order.tracking_number column if the parameter is blank.
        $tracking = trim($trackingNumber);
        if ($tracking === '' && !empty($order['tracking_number'])) {
            $tracking = trim((string)$order['tracking_number']);
        }
        $trackingBlock = '';
        if ($tracking !== '') {
            $safeTrack = sanitize($tracking);
            $trackingBlock = <<<HTML
<div class="detail-box">
  <table>
    <tr><td>Order Reference:</td><td><strong>{$ref}</strong></td></tr>
    <tr><td>Tracking Number:</td><td><strong style="font-family:monospace">{$safeTrack}</strong></td></tr>
  </table>
  <p style="margin:12px 0 0;font-size:13px;color:#6B5575;">Use the tracking number above with your delivery provider to follow your order's progress.</p>
</div>
HTML;
        }

        $content = <<<HTML
<h2>Your order is on its way! 📦</h2>
<p>Hi {$name}, great news — your BraidedbyAGB order <strong>{$ref}</strong> has been dispatched!</p>
{$trackingBlock}
<p>Please allow 2–5 working days for UK standard delivery. If you have any questions, WhatsApp us on 07769064971.</p>
<p>Enjoy your hair products, and don't forget to tag us in your finished looks on Instagram — <strong>@BraidedbyAGB</strong> 💜</p>
HTML;
        $mail->Body = emailWrapper($content);
        return $mail->send();
    } catch (Exception $e) {
        error_log('Email error (orderDispatched): ' . $e->getMessage());
        return false;
    }
}

// ── 10. Admin New Booking Alert ───────────────────────────
// (kept in position 10 — new admin notification functions added below at 11–13)
function emailAdminNewBooking(array $booking, array $customer, array $service): bool {
    try {
        $mail = createMailer();
        $mail->addAddress(SITE_EMAIL, SITE_NAME . ' Admin');
        $mail->Subject = '🔔 New Booking Request — ' . $booking['booking_ref'];
        $name   = sanitize($customer['name']);
        $email  = sanitize($customer['email']);
        $phone  = sanitize($customer['phone'] ?? 'Not provided');
        $svc    = sanitize($service['name']);
        $date   = formatDate($booking['booked_date']);
        $time   = formatTime($booking['booked_time']);
        $method = ucfirst(str_replace('_', ' ', $booking['payment_method']));
        $deposit= formatPrice((float)$booking['deposit_amount']);
        $adminUrl = ADMIN_URL;
        $content = <<<HTML
<h2>New Booking Request Received</h2>
<div class="detail-box">
  <table>
    <tr><td>Client:</td><td>{$name}</td></tr>
    <tr><td>Email:</td><td>{$email}</td></tr>
    <tr><td>Phone:</td><td>{$phone}</td></tr>
    <tr><td>Service:</td><td><strong>{$svc}</strong></td></tr>
    <tr><td>Date:</td><td><strong>{$date}</strong></td></tr>
    <tr><td>Time:</td><td><strong>{$time}</strong></td></tr>
    <tr><td>Payment:</td><td>{$method}</td></tr>
    <tr><td>Deposit:</td><td>{$deposit}</td></tr>
  </table>
</div>
<p style="text-align:center;margin-top:20px;">
  <a href="{$adminUrl}/bookings" class="cta-btn">Review in Admin Portal</a>
</p>
HTML;
        $mail->Body = emailWrapper($content);
        return $mail->send();
    } catch (Exception $e) {
        error_log('Email error (adminNewBooking): ' . $e->getMessage());
        return false;
    }
}

// ── 11. Admin Morning Daily Brief ────────────────────────
// Sent at 7:30 AM — lists ALL confirmed bookings for today.
// $bookings = array of booking rows (with c_name, c_phone, s_name columns)
function emailAdminDailyBrief(array $bookings, string $date): bool {
    try {
        $mail = createMailer();
        $mail->addAddress(SITE_EMAIL, SITE_NAME . ' Admin');
        $count     = count($bookings);
        $dateLabel = date('l, j F Y', strtotime($date));
        $mail->Subject = "☀️ Today's Schedule — {$count} appointment" . ($count !== 1 ? 's' : '') . " — {$dateLabel}";

        $adminUrl = ADMIN_URL;

        if (empty($bookings)) {
            $bodyHtml = '<p style="text-align:center;font-size:1.1em;padding:24px 0;color:#4B0082;">No appointments booked for today. Enjoy your day! 🌸</p>';
        } else {
            $rows = '';
            foreach ($bookings as $b) {
                $time    = formatTime($b['booked_time']);
                $client  = htmlspecialchars($b['c_name']);
                $service = htmlspecialchars($b['s_name']);
                $phone   = htmlspecialchars($b['c_phone'] ?? '');
                $rawPhone = preg_replace('/\D/', '', $phone);
                if (strlen($rawPhone) === 11 && str_starts_with($rawPhone, '0')) {
                    $rawPhone = '44' . substr($rawPhone, 1);
                }
                $waLink  = $rawPhone ? "https://wa.me/{$rawPhone}" : '#';
                $detailLink = "{$adminUrl}/bookings/{$b['id']}";
                $depositBadge = $b['deposit_paid']
                    ? '<span style="background:#d1fae5;color:#065f46;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;">PAID</span>'
                    : '<span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;">UNPAID</span>';
                $rows .= "
                <tr style='border-bottom:1px solid #E8D8EE'>
                  <td style='padding:10px 8px;font-weight:700;color:#4B0082;white-space:nowrap'>{$time}</td>
                  <td style='padding:10px 8px'><strong>{$client}</strong><br><span style='color:#6B5575;font-size:13px'>{$service}</span></td>
                  <td style='padding:10px 8px'>{$depositBadge}</td>
                  <td style='padding:10px 8px;white-space:nowrap'>
                    " . ($rawPhone ? "<a href='{$waLink}' style='background:#25D366;color:#fff;padding:4px 10px;border-radius:4px;text-decoration:none;font-size:12px;font-weight:700'>💬 WhatsApp</a>" : '') . "
                    <a href='{$detailLink}' style='background:#800080;color:#fff;padding:4px 10px;border-radius:4px;text-decoration:none;font-size:12px;font-weight:700;margin-left:4px'>View</a>
                  </td>
                </tr>";
            }
            $bodyHtml = "
            <table style='width:100%;border-collapse:collapse'>
              <tr style='background:#f3e8ff'>
                <th style='padding:8px;text-align:left;font-size:12px;color:#4B0082'>Time</th>
                <th style='padding:8px;text-align:left;font-size:12px;color:#4B0082'>Client &amp; Service</th>
                <th style='padding:8px;text-align:left;font-size:12px;color:#4B0082'>Deposit</th>
                <th style='padding:8px;text-align:left;font-size:12px;color:#4B0082'>Actions</th>
              </tr>
              {$rows}
            </table>";
        }

        $content = <<<HTML
<h2>☀️ Good morning! Here's your day — {$dateLabel}</h2>
<p>You have <strong>{$count} appointment{$count_s}</strong> confirmed for today.</p>
<div class="detail-box" style="padding:0;overflow:hidden">
  {$bodyHtml}
</div>
<p style="text-align:center;margin-top:20px">
  <a href="{$adminUrl}/bookings" class="cta-btn">Open Admin Panel</a>
</p>
HTML;
        // Fix: PHP heredoc can't interpolate expressions — compute count_s separately
        $content = str_replace('{$count_s}', $count !== 1 ? 's' : '', $content);

        $mail->Body = emailWrapper($content);
        return $mail->send();
    } catch (Exception $e) {
        error_log('Email error (adminDailyBrief): ' . $e->getMessage());
        return false;
    }
}

// ── 12. Admin Evening Tomorrow Preview ────────────────────
// Sent at 8:00 PM — lists ALL confirmed bookings for tomorrow.
function emailAdminEveningPreview(array $bookings, string $date): bool {
    try {
        $mail = createMailer();
        $mail->addAddress(SITE_EMAIL, SITE_NAME . ' Admin');
        $count     = count($bookings);
        $dateLabel = date('l, j F Y', strtotime($date));
        $mail->Subject = "🌙 Tomorrow's Preview — {$count} appointment" . ($count !== 1 ? 's' : '') . " — {$dateLabel}";

        $adminUrl = ADMIN_URL;

        if (empty($bookings)) {
            $bodyHtml = '<p style="text-align:center;font-size:1.1em;padding:24px 0;color:#4B0082;">No appointments booked for tomorrow. A quiet day ahead! 🌸</p>';
        } else {
            $rows = '';
            foreach ($bookings as $b) {
                $time    = formatTime($b['booked_time']);
                $client  = htmlspecialchars($b['c_name']);
                $service = htmlspecialchars($b['s_name']);
                $variant = $b['variant_name'] ? ' — ' . htmlspecialchars($b['variant_name']) : '';
                $addons  = $b['addon_names'] ? '<br><span style="color:#6B5575;font-size:12px">+ ' . htmlspecialchars($b['addon_names']) . '</span>' : '';
                $phone   = htmlspecialchars($b['c_phone'] ?? '');
                $rawPhone = preg_replace('/\D/', '', $phone);
                if (strlen($rawPhone) === 11 && str_starts_with($rawPhone, '0')) {
                    $rawPhone = '44' . substr($rawPhone, 1);
                }
                $depositBadge = $b['deposit_paid']
                    ? '<span style="background:#d1fae5;color:#065f46;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;">PAID</span>'
                    : '<span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;">UNPAID ⚠️</span>';
                $rows .= "
                <tr style='border-bottom:1px solid #E8D8EE'>
                  <td style='padding:10px 8px;font-weight:700;color:#4B0082;white-space:nowrap'>{$time}</td>
                  <td style='padding:10px 8px'>
                    <strong>{$client}</strong><br>
                    <span style='color:#6B5575;font-size:13px'>{$service}{$variant}</span>
                    {$addons}
                  </td>
                  <td style='padding:10px 8px'>{$depositBadge}</td>
                </tr>";
            }
            $bodyHtml = "
            <table style='width:100%;border-collapse:collapse'>
              <tr style='background:#f3e8ff'>
                <th style='padding:8px;text-align:left;font-size:12px;color:#4B0082'>Time</th>
                <th style='padding:8px;text-align:left;font-size:12px;color:#4B0082'>Client &amp; Service</th>
                <th style='padding:8px;text-align:left;font-size:12px;color:#4B0082'>Deposit</th>
              </tr>
              {$rows}
            </table>";
        }

        $content = <<<HTML
<h2>🌙 Tomorrow's Schedule — {$dateLabel}</h2>
<p>You have <strong>{$count} appointment{$count_s}</strong> booked for tomorrow. Here's what to prepare for:</p>
<div class="detail-box" style="padding:0;overflow:hidden">
  {$bodyHtml}
</div>
<p style="text-align:center;margin-top:20px">
  <a href="{$adminUrl}/bookings" class="cta-btn">Open Admin Panel</a>
</p>
HTML;
        $content = str_replace('{$count_s}', $count !== 1 ? 's' : '', $content);

        $mail->Body = emailWrapper($content);
        return $mail->send();
    } catch (Exception $e) {
        error_log('Email error (adminEveningPreview): ' . $e->getMessage());
        return false;
    }
}

// ── 13. Admin 30-Minute Pre-Appointment Alert ─────────────
// Sent 30 minutes before each individual appointment.
// $booking must include c_name, c_phone, c_email, s_name, booked_time, id, booking_ref
function emailAdminPreAppointment(array $booking): bool {
    try {
        $mail = createMailer();
        $mail->addAddress(SITE_EMAIL, SITE_NAME . ' Admin');
        $client  = htmlspecialchars($booking['c_name']);
        $service = htmlspecialchars($booking['s_name']);
        $time    = formatTime($booking['booked_time']);
        $mail->Subject = "⏰ 30-min reminder: {$client} at {$time}";

        $phone   = $booking['c_phone'] ?? '';
        $rawPhone = preg_replace('/\D/', '', $phone);
        if (strlen($rawPhone) === 11 && str_starts_with($rawPhone, '0')) {
            $rawPhone = '44' . substr($rawPhone, 1);
        }
        $waLink   = $rawPhone ? "https://wa.me/{$rawPhone}?text=" . urlencode("Hi {$booking['c_name']}, we're looking forward to seeing you at {$time}!") : '#';
        $adminUrl = ADMIN_URL;
        $detailLink = "{$adminUrl}/bookings/{$booking['id']}";
        $notes    = $booking['client_notes'] ? htmlspecialchars($booking['client_notes']) : '<em style="color:#9B8BA5">None</em>';
        $balance  = formatPrice((float)$booking['remaining_balance']);
        $variant  = !empty($booking['variant_name']) ? ' — ' . htmlspecialchars($booking['variant_name']) : '';

        $content = <<<HTML
<h2>⏰ {$client} arrives in 30 minutes</h2>
<div class="detail-box">
  <table>
    <tr><td>Client:</td><td><strong>{$client}</strong></td></tr>
    <tr><td>Phone:</td><td><a href="tel:{$phone}">{$phone}</a></td></tr>
    <tr><td>Service:</td><td>{$service}{$variant}</td></tr>
    <tr><td>Time:</td><td><strong>{$time}</strong></td></tr>
    <tr><td>Balance due:</td><td><strong>{$balance}</strong></td></tr>
    <tr><td>Client notes:</td><td>{$notes}</td></tr>
  </table>
</div>
<p style="text-align:center;margin-top:20px">
  <a href="{$waLink}" class="cta-btn" style="background:#25D366">💬 Message Client</a>
  &nbsp;
  <a href="{$detailLink}" class="cta-btn">View Booking</a>
</p>
HTML;
        $mail->Body = emailWrapper($content);
        return $mail->send();
    } catch (Exception $e) {
        error_log('Email error (adminPreAppointment): ' . $e->getMessage());
        return false;
    }
}

// ── 11. Admin New Review Alert ────────────────────────────
function emailAdminNewReview(array $review, array $customer): bool {
    try {
        $mail = createMailer();
        $mail->addAddress(SITE_EMAIL, SITE_NAME . ' Admin');
        $mail->Subject = '⭐ New Review Pending Approval — BraidedbyAGB';
        $name   = sanitize($customer['name']);
        $rating = (int)$review['rating'];
        $stars  = str_repeat('★', $rating) . str_repeat('☆', 5 - $rating);
        $text   = sanitize($review['review_text']);
        $adminUrl = ADMIN_URL;
        $content = <<<HTML
<h2>New Review Awaiting Your Approval</h2>
<div class="detail-box">
  <p><strong>From:</strong> {$name}</p>
  <p><strong>Rating:</strong> <span class="gold-text">{$stars} ({$rating}/5)</span></p>
  <p><strong>Review:</strong><br><em>"{$text}"</em></p>
</div>
<p style="text-align:center;margin-top:20px;">
  <a href="{$adminUrl}/reviews" class="cta-btn">Approve or Reject</a>
</p>
HTML;
        $mail->Body = emailWrapper($content);
        return $mail->send();
    } catch (Exception $e) {
        error_log('Email error (adminNewReview): ' . $e->getMessage());
        return false;
    }
}

// ── 12. Customer: Custom Request Received ────────────────
function emailCustomRequestReceived(array $req): bool {
    try {
        $mail = createMailer();
        $mail->addAddress($req['email'], $req['name']);
        $mail->Subject = 'Custom Style Request Received — BraidedbyAGB';
        $name    = sanitize($req['name']);
        $ref     = sanitize($req['ref']);
        $siteUrl = SITE_URL;
        $content = <<<HTML
<h2>We've got your request! ✨</h2>
<p>Hi {$name}, thank you for reaching out to BraidedbyAGB. We've received your custom style request and we'll be in touch within <strong>24 hours</strong> with a personalised quote.</p>
<div class="detail-box">
  <table>
    <tr><td>Reference:</td><td><strong>{$ref}</strong></td></tr>
    <tr><td>Status:</td><td>Under review</td></tr>
  </table>
</div>
<p>In the meantime, if you'd like to chat or share any more inspiration, feel free to WhatsApp us directly — we'd love to hear from you.</p>
<p>We'll get back to you very soon! 💜</p>
<p style="text-align:center;margin-top:28px;">
  <a href="https://wa.me/447769064971" class="cta-btn" style="background:#25D366;">💬 WhatsApp Us</a>
  &nbsp;&nbsp;
  <a href="{$siteUrl}/booking" class="cta-btn">Browse Standard Services</a>
</p>
HTML;
        $mail->Body = emailWrapper($content);
        return $mail->send();
    } catch (Exception $e) {
        error_log('Email error (customRequestReceived): ' . $e->getMessage());
        return false;
    }
}

// ── 13. Admin: New Custom Request Alert ──────────────────
function emailAdminNewCustomRequest(array $req): bool {
    try {
        $mail = createMailer();
        $mail->addAddress(SITE_EMAIL, SITE_NAME . ' Admin');
        $mail->Subject = '✨ New Custom Style Request — ' . $req['ref'];
        $name      = sanitize($req['name']);
        $email     = sanitize($req['email']);
        $phone     = $req['phone'] ? sanitize($req['phone']) : 'Not provided';
        $ref       = sanitize($req['ref']);
        $styleDesc = sanitize($req['style_desc']);
        $budget    = $req['budget_range'] ? sanitize($req['budget_range']) : '—';
        $prefDate  = $req['preferred_date'] ? date('j F Y', strtotime($req['preferred_date'])) : '—';
        $adminUrl  = ADMIN_URL;
        $content = <<<HTML
<h2>New Custom Style Request</h2>
<p>A new custom request has just come in — review it and send a reply in the admin portal.</p>
<div class="detail-box">
  <table>
    <tr><td>Reference:</td><td><strong>{$ref}</strong></td></tr>
    <tr><td>Name:</td><td>{$name}</td></tr>
    <tr><td>Email:</td><td>{$email}</td></tr>
    <tr><td>Phone:</td><td>{$phone}</td></tr>
    <tr><td>Budget:</td><td>{$budget}</td></tr>
    <tr><td>Preferred Date:</td><td>{$prefDate}</td></tr>
    <tr><td colspan="2" style="padding-top:10px;font-weight:700;color:#4B0082">Style Description:</td></tr>
    <tr><td colspan="2" style="padding-top:4px">{$styleDesc}</td></tr>
  </table>
</div>
<p style="text-align:center;margin-top:20px;">
  <a href="{$adminUrl}/custom-requests" class="cta-btn">View &amp; Reply in Admin</a>
</p>
HTML;
        $mail->Body = emailWrapper($content);
        return $mail->send();
    } catch (Exception $e) {
        error_log('Email error (adminNewCustomRequest): ' . $e->getMessage());
        return false;
    }
}

// ── 14. Customer: Admin Reply to Custom Request ───────────
function emailCustomRequestReply(array $req, string $reply, string $status): bool {
    try {
        $mail = createMailer();
        $mail->addAddress($req['email'], $req['name']);

        $subjectMap = [
            'accepted' => '🎉 Your Custom Request Has Been Accepted — BraidedbyAGB',
            'declined' => 'Regarding Your Custom Request — BraidedbyAGB',
            'replied'  => 'Reply to Your Custom Style Request — BraidedbyAGB',
        ];
        $mail->Subject = $subjectMap[$status] ?? $subjectMap['replied'];

        $name    = sanitize($req['name']);
        $ref     = sanitize($req['ref']);
        $reply   = sanitize($reply);
        $siteUrl = SITE_URL;

        $intro = match($status) {
            'accepted' => "<h2>Great news — your request has been accepted! 🎉</h2>
                          <p>Hi {$name}, we're excited to let you know that we can create your custom style! Here's our response:</p>",
            'declined' => "<h2>Regarding your custom style request</h2>
                          <p>Hi {$name}, thank you for your interest in BraidedbyAGB. We've reviewed your custom request and wanted to get back to you personally:</p>",
            default    => "<h2>We've reviewed your request 💜</h2>
                          <p>Hi {$name}, thank you for your patience! Here is our reply to your custom style request:</p>",
        };

        $bookingCta = $status === 'accepted'
            ? "<p style=\"text-align:center;margin-top:28px;\">
                 <a href=\"{$siteUrl}/booking\" class=\"cta-btn\">Book Your Appointment →</a>
                 &nbsp;&nbsp;
                 <a href=\"https://wa.me/447769064971\" class=\"cta-btn\" style=\"background:#25D366;\">💬 WhatsApp Us</a>
               </p>"
            : "<p style=\"text-align:center;margin-top:28px;\">
                 <a href=\"https://wa.me/447769064971\" class=\"cta-btn\" style=\"background:#25D366;\">💬 WhatsApp Us</a>
                 &nbsp;&nbsp;
                 <a href=\"{$siteUrl}/booking\" class=\"cta-btn\">Browse Our Services</a>
               </p>";

        $content = <<<HTML
{$intro}
<div class="detail-box">
  <p style="margin:0 0 6px"><strong>Your request reference:</strong> {$ref}</p>
  <div class="divider"></div>
  <p style="margin:0;line-height:1.75;white-space:pre-line">{$reply}</p>
</div>
<p>If you have any questions or would like to discuss further, please don't hesitate to WhatsApp us or reply to this email.</p>
{$bookingCta}
HTML;
        $mail->Body = emailWrapper($content);
        return $mail->send();
    } catch (Exception $e) {
        error_log('Email error (customRequestReply): ' . $e->getMessage());
        return false;
    }
}

// ── 15a. Payment Receipt (Stripe via /pay link or direct booking) ────
// Called after a Stripe card payment is confirmed server-side.
// Sends a clear receipt with Stripe transaction reference + appointment summary.
function emailPaymentReceipt(array $booking, array $customer, array $service, string $stripeId = ''): bool {
    try {
        $mail = createMailer();
        $mail->addAddress($customer['email'], $customer['name']);
        $mail->Subject = 'Payment Received ✓ — BraidedbyAGB';

        $name    = sanitize($customer['name']);
        $svc     = sanitize($service['name']);
        $ref     = sanitize($booking['booking_ref']);
        $date    = formatDate($booking['booked_date']);
        $time    = formatTime($booking['booked_time']);
        $deposit = formatPrice((float)$booking['deposit_amount']);
        $balance = formatPrice((float)$booking['remaining_balance']);
        $total   = formatPrice((float)$booking['total_price']);
        $paidOn  = date('j F Y \a\t g:i A');
        $txRef   = $stripeId ? sanitize($stripeId) : 'N/A';
        $locationBlock = appointmentLocationBlock();

        $content = <<<HTML
<h2>Payment received — thank you! 💜</h2>
<p>Hi {$name}, we've received your deposit payment. Your appointment is confirmed and we can't wait to see you!</p>

<div class="detail-box">
  <p style="margin:0 0 12px;font-weight:700;font-size:14px;color:#4B0082;text-transform:uppercase;letter-spacing:0.05em">Payment Receipt</p>
  <table>
    <tr><td>Amount Paid:</td><td class="gold-text" style="font-size:18px;font-weight:800">{$deposit}</td></tr>
    <tr><td>Date Paid:</td><td>{$paidOn}</td></tr>
    <tr><td>Payment Method:</td><td>Card (Stripe)</td></tr>
    <tr><td>Transaction Ref:</td><td style="font-family:monospace;font-size:13px;color:#6B5575">{$txRef}</td></tr>
    <tr><td>Booking Ref:</td><td><strong>{$ref}</strong></td></tr>
  </table>
</div>

<div class="detail-box">
  <p style="margin:0 0 12px;font-weight:700;font-size:14px;color:#4B0082;text-transform:uppercase;letter-spacing:0.05em">Your Appointment</p>
  <table>
    <tr><td>Service:</td><td><strong>{$svc}</strong></td></tr>
    <tr><td>Date:</td><td><strong>{$date}</strong></td></tr>
    <tr><td>Time:</td><td><strong>{$time}</strong></td></tr>
    <tr><td>Total Price:</td><td>{$total}</td></tr>
    <tr><td>Deposit Paid:</td><td class="gold-text">{$deposit} ✓</td></tr>
    <tr><td>Balance Due on Day:</td><td>{$balance}</td></tr>
  </table>
</div>

{$locationBlock}

<div class="policy-box">
  ⏰ <strong>Late Arrival Policy:</strong> Please arrive within <strong>20 minutes</strong> of your appointment time. Running late? WhatsApp us straight away on 07769064971.<br><br>
  ❌ <strong>Cancellation Policy:</strong> Cancellations must be made at least <strong>48 hours</strong> before your appointment. Deposits are non-refundable.
</div>

<p>We'll send you a reminder the day before and 2 hours before your appointment. See you soon! 💕</p>
<p style="text-align:center;margin-top:28px;">
  <a href="https://wa.me/447769064971" class="cta-btn" style="background:#25D366;">💬 WhatsApp Us</a>
</p>
HTML;
        $mail->Body    = emailWrapper($content);
        $mail->AltBody = "Payment received: {$deposit} for {$svc} on {$date} at {$time}. Booking ref: {$ref}. Transaction: {$txRef}.";
        return $mail->send();
    } catch (Exception $e) {
        error_log('Email error (paymentReceipt): ' . $e->getMessage());
        return false;
    }
}

// ── 15b. Bank Transfer Confirmation to Client ─────────────
// Called when a client submits the "I've sent the transfer" form on /pay.
// Sends them the bank details so they have a reference, and sets expectations.
function emailBankTransferConfirm(array $booking, array $customer): bool {
    try {
        $mail = createMailer();
        $mail->addAddress($customer['email'], $customer['name']);
        $mail->Subject = 'Bank Transfer Details — BraidedbyAGB';

        $name    = sanitize($customer['name']);
        $ref     = sanitize($booking['booking_ref']);
        $svc     = sanitize($booking['s_name'] ?? '');
        $date    = formatDate($booking['booked_date']);
        $time    = formatTime($booking['booked_time']);
        $deposit = formatPrice((float)$booking['deposit_amount']);

        // Read the current bank details from settings
        $bankName = getSetting('bank_account_name',  'BraidedbyAGB');
        $bankSort = getSetting('bank_sort_code',      '');
        $bankAcc  = getSetting('bank_account_number', '');

        $bankAccRow = $bankAcc
            ? "<tr><td>Account No:</td><td><strong style='font-family:monospace'>{$bankAcc}</strong></td></tr>"
            : '';
        $bankSortRow = $bankSort
            ? "<tr><td>Sort Code:</td><td><strong style='font-family:monospace'>{$bankSort}</strong></td></tr>"
            : '';

        // Pre-encode for WhatsApp URL (can't use <?= ?> tags inside a heredoc)
        $waRef = rawurlencode("Hi, I have sent my deposit transfer for booking {$ref}");

        $content = <<<HTML
<h2>Bank transfer details 🏦</h2>
<p>Hi {$name}, thank you! We've noted that you're sending your deposit by bank transfer. Here are the payment details — please transfer as soon as possible to hold your appointment.</p>

<div class="detail-box">
  <p style="margin:0 0 12px;font-weight:700;font-size:14px;color:#4B0082;text-transform:uppercase;letter-spacing:0.05em">Transfer To</p>
  <table>
    <tr><td>Account Name:</td><td><strong>{$bankName}</strong></td></tr>
    {$bankSortRow}
    {$bankAccRow}
    <tr><td>Amount:</td><td class="gold-text" style="font-size:18px;font-weight:800">{$deposit}</td></tr>
    <tr><td>Reference:</td><td><strong style='font-family:monospace'>{$ref}</strong></td></tr>
  </table>
  <p style="margin:12px 0 0;font-size:13px;color:#6B5575;">⚠️ Please use your booking reference <strong>{$ref}</strong> as the payment reference so we can match your transfer.</p>
</div>

<div class="detail-box">
  <p style="margin:0 0 12px;font-weight:700;font-size:14px;color:#4B0082;text-transform:uppercase;letter-spacing:0.05em">Your Appointment (held)</p>
  <table>
    <tr><td>Service:</td><td><strong>{$svc}</strong></td></tr>
    <tr><td>Date:</td><td><strong>{$date}</strong></td></tr>
    <tr><td>Time:</td><td><strong>{$time}</strong></td></tr>
    <tr><td>Deposit Due:</td><td>{$deposit}</td></tr>
  </table>
</div>

<div class="policy-box">
  ⏰ <strong>Important:</strong> Your appointment is being held while we await payment. If we don't receive your transfer within <strong>24 hours</strong>, your slot may be released. Once we confirm receipt, you'll get a booking confirmation email.
</div>

<p>Once you've sent the transfer, feel free to let us know via WhatsApp — it helps us confirm faster!</p>
<p style="text-align:center;margin-top:28px;">
  <a href="https://wa.me/447769064971?text={$waRef}" class="cta-btn" style="background:#25D366;">💬 Let Us Know via WhatsApp</a>
</p>
HTML;
        $mail->Body    = emailWrapper($content);
        $mail->AltBody = "Bank transfer details for booking {$ref}: Account name {$bankName}, Sort code {$bankSort}, Account no {$bankAcc}. Transfer {$deposit} using reference {$ref}.";
        return $mail->send();
    } catch (Exception $e) {
        error_log('Email error (bankTransferConfirm): ' . $e->getMessage());
        return false;
    }
}

// ── 15c. Multi-Appointment (Cart) Confirmation to Client ──────────────
// Sent after a family/group checkout creates several bookings at once.
// $payer:  ['name'=>, 'email'=>]
// $items:  array of rows, each with keys: booking_ref, guest_name (nullable),
//          service_name, variant_name (nullable), booked_date, booked_time,
//          total_price, deposit_amount, remaining_balance
// $groupRef: shared cart_group_ref
// $paymentMethod: 'stripe' | 'bank_transfer'
function emailCartConfirmation(array $payer, array $items, string $groupRef, string $paymentMethod = 'stripe'): bool {
    try {
        $mail = createMailer();
        $mail->addAddress($payer['email'], $payer['name']);

        $count = count($items);
        $mail->Subject = $count > 1
            ? "Your {$count} Appointments Are Booked ✓ — BraidedbyAGB"
            : 'Your Appointment Is Booked ✓ — BraidedbyAGB';

        $name   = sanitize($payer['name']);
        $countS = $count !== 1 ? 's' : '';
        $isAre  = $count !== 1 ? 'are' : 'is';

        $totalPrice   = 0.0;
        $totalDeposit = 0.0;
        $totalBalance = 0.0;
        $cards        = '';

        foreach ($items as $i => $it) {
            $totalPrice   += (float)$it['total_price'];
            $totalDeposit += (float)$it['deposit_amount'];
            $totalBalance += (float)$it['remaining_balance'];

            $ref     = sanitize($it['booking_ref']);
            $svc     = sanitize($it['service_name']);
            $variant = !empty($it['variant_name']) ? ' — ' . sanitize($it['variant_name']) : '';
            $date    = formatDate($it['booked_date']);
            $time    = formatTime($it['booked_time']);
            $deposit = formatPrice((float)$it['deposit_amount']);
            $total   = formatPrice((float)$it['total_price']);
            $balance = formatPrice((float)$it['remaining_balance']);
            // Who the appointment is for — falls back to the payer when not set
            $who     = !empty($it['guest_name']) ? sanitize($it['guest_name']) : $name;
            $num     = $i + 1;

            $cards .= <<<HTML
<div class="detail-box">
  <p style="margin:0 0 12px;font-weight:700;font-size:14px;color:#4B0082;text-transform:uppercase;letter-spacing:0.05em">Appointment {$num} — {$who}</p>
  <table>
    <tr><td>Reference:</td><td><strong>{$ref}</strong></td></tr>
    <tr><td>Service:</td><td><strong>{$svc}{$variant}</strong></td></tr>
    <tr><td>Date:</td><td><strong>{$date}</strong></td></tr>
    <tr><td>Time:</td><td><strong>{$time}</strong></td></tr>
    <tr><td>Total Price:</td><td>{$total}</td></tr>
    <tr><td>Deposit Paid:</td><td class="gold-text">{$deposit} ✓</td></tr>
    <tr><td>Balance Due:</td><td>{$balance} (on the day)</td></tr>
  </table>
</div>
HTML;
        }

        $grpPrice   = formatPrice($totalPrice);
        $grpDeposit = formatPrice($totalDeposit);
        $grpBalance = formatPrice($totalBalance);

        $payLabel = $paymentMethod === 'bank_transfer'
            ? 'Deposit total to transfer'
            : 'Deposit total paid';

        $summaryBox = <<<HTML
<div class="detail-box" style="border-left-color:#D4AF37">
  <p style="margin:0 0 12px;font-weight:700;font-size:14px;color:#4B0082;text-transform:uppercase;letter-spacing:0.05em">Combined Summary ({$count} appointment{$countS})</p>
  <table>
    <tr><td>Group Reference:</td><td><strong>{$groupRef}</strong></td></tr>
    <tr><td>Total Price:</td><td>{$grpPrice}</td></tr>
    <tr><td>{$payLabel}:</td><td class="gold-text" style="font-size:18px;font-weight:800">{$grpDeposit}</td></tr>
    <tr><td>Balance on Days:</td><td>{$grpBalance}</td></tr>
  </table>
</div>
HTML;

        $bankNote = '';
        if ($paymentMethod === 'bank_transfer') {
            $bankNote = <<<HTML
<div class="policy-box">
  🏦 <strong>Bank transfer:</strong> Your appointments are held while we await your deposit transfer of <strong>{$grpDeposit}</strong>. Please use group reference <strong>{$groupRef}</strong> when transferring. Slots may be released if payment isn't received within 24 hours.
</div>
HTML;
        }

        // Reveal the exact address only once the deposit is actually paid (card).
        // Bank-transfer carts are still "held" pending payment, so no address yet.
        $locationBlock = $paymentMethod === 'bank_transfer' ? '' : appointmentLocationBlock();

        $content = <<<HTML
<h2>You're all booked in! 💜</h2>
<p>Hi {$name}, thank you for booking with BraidedbyAGB. Here {$isAre} your {$count} confirmed appointment{$countS}:</p>
{$summaryBox}
{$cards}
{$bankNote}
{$locationBlock}
<div class="policy-box">
  ⏰ <strong>Late Arrival Policy:</strong> Please arrive within <strong>20 minutes</strong> of each appointment time. Running late? WhatsApp us straight away on 07769064971.<br><br>
  ❌ <strong>Cancellation Policy:</strong> Cancellations must be made at least <strong>48 hours</strong> before the appointment. Deposits are non-refundable.
</div>
<p>We'll send reminders the day before and 2 hours before each appointment. See you soon! 💕</p>
<p style="text-align:center;margin-top:28px;">
  <a href="https://wa.me/447769064971" class="cta-btn" style="background:#25D366;">💬 WhatsApp Us</a>
</p>
HTML;

        $mail->Body    = emailWrapper($content);
        $mail->AltBody = "Your {$count} appointment(s) are booked. Group ref {$groupRef}. Total deposit {$grpDeposit}, balance {$grpBalance} due on the day(s).";
        return $mail->send();
    } catch (Exception $e) {
        error_log('Email error (cartConfirmation): ' . $e->getMessage());
        return false;
    }
}

// ── 15d. Admin Alert: New Multi-Appointment (Cart) Booking ────────────
function emailAdminNewCartBooking(array $payer, array $items, string $groupRef): bool {
    try {
        $mail = createMailer();
        $mail->addAddress(SITE_EMAIL, SITE_NAME . ' Admin');
        $count = count($items);
        $mail->Subject = "🔔 New Group Booking ({$count} appts) — {$groupRef}";

        $name  = sanitize($payer['name']);
        $email = sanitize($payer['email']);
        $phone = sanitize($payer['phone'] ?? 'Not provided');
        $adminUrl = ADMIN_URL;

        $rows = '';
        foreach ($items as $it) {
            $ref     = sanitize($it['booking_ref']);
            $svc     = sanitize($it['service_name']);
            $variant = !empty($it['variant_name']) ? ' — ' . sanitize($it['variant_name']) : '';
            $date    = formatDate($it['booked_date']);
            $time    = formatTime($it['booked_time']);
            $who     = !empty($it['guest_name']) ? sanitize($it['guest_name']) : $name;
            $rows .= "<tr><td style='padding:6px 0'><strong>{$time}</strong> · {$date}</td><td style='padding:6px 0'>{$who}<br><span style='color:#6B5575;font-size:13px'>{$svc}{$variant} · {$ref}</span></td></tr>";
        }

        $content = <<<HTML
<h2>New Group Booking Received</h2>
<div class="detail-box">
  <table>
    <tr><td>Payer:</td><td>{$name}</td></tr>
    <tr><td>Email:</td><td>{$email}</td></tr>
    <tr><td>Phone:</td><td>{$phone}</td></tr>
    <tr><td>Group Ref:</td><td><strong>{$groupRef}</strong></td></tr>
  </table>
  <div class="divider"></div>
  <table style="width:100%">{$rows}</table>
</div>
<p style="text-align:center;margin-top:20px;">
  <a href="{$adminUrl}/bookings" class="cta-btn">Review in Admin Portal</a>
</p>
HTML;
        $mail->Body = emailWrapper($content);
        return $mail->send();
    } catch (Exception $e) {
        error_log('Email error (adminNewCartBooking): ' . $e->getMessage());
        return false;
    }
}

// ── 15. Customer: Admin-Created Booking Confirmation + Payment Link ──
function emailBookingCreatedByAdmin(array $booking, array $customer, array $service, ?string $paymentLink = null): bool {
    try {
        $mail = createMailer();
        $mail->addAddress($customer['email'], $customer['name']);
        $mail->Subject = '📅 Booking Confirmation — ' . $service['name'];

        $date    = date('l j F Y', strtotime($booking['booked_date']));
        $time    = substr($booking['booked_time'], 0, 5);
        $total   = '£' . number_format((float)$booking['total_price'],   2);
        $deposit = '£' . number_format((float)$booking['deposit_amount'], 2);
        $balance = '£' . number_format((float)$booking['remaining_balance'], 2);

        // Payment-link block (shown when booking is pending/unpaid)
        $payBlock = '';
        if ($paymentLink) {
            $safeLink = htmlspecialchars($paymentLink, ENT_QUOTES, 'UTF-8');
            $payBlock = <<<HTML
<div style="background:#fff3f9;border:2px solid #CC1A8A;border-radius:10px;padding:20px;margin:24px 0;text-align:center;">
  <p style="margin:0 0 8px;font-size:15px;font-weight:700;color:#2A0020;">💳 Deposit Payment Required</p>
  <p style="margin:0 0 18px;font-size:13px;color:#7A4A70;">
    A deposit of <strong>{$deposit}</strong> is required to secure your appointment.<br>
    Please pay at your earliest convenience.
  </p>
  <a href="{$safeLink}"
     style="background:#CC1A8A;color:#fff;padding:13px 30px;border-radius:8px;
            text-decoration:none;font-weight:700;font-size:14px;display:inline-block;">
    Pay Deposit Now →
  </a>
  <p style="margin:14px 0 0;font-size:11px;color:#7A4A70;">
    Or copy this link:<br>
    <a href="{$safeLink}" style="color:#CC1A8A;word-break:break-all;">{$safeLink}</a>
  </p>
</div>
HTML;
        }

        $variantLine = !empty($booking['variant_name'])
            ? '<tr><td style="padding:7px 0;color:#7A4A70;width:45%">Option</td><td style="padding:7px 0;font-weight:600">' . htmlspecialchars($booking['variant_name']) . '</td></tr>'
            : '';

        $content = <<<HTML
<p style="font-size:16px;color:#2A0020;">Hi {$customer['name']},</p>
<p>Your appointment has been booked. Here are the details:</p>
<table style="width:100%;border-collapse:collapse;margin:16px 0;font-size:14px;">
  <tr><td style="padding:7px 0;color:#7A4A70;width:45%">Service</td>
      <td style="padding:7px 0;font-weight:600">{$service['name']}</td></tr>
  {$variantLine}
  <tr><td style="padding:7px 0;color:#7A4A70">Date</td>
      <td style="padding:7px 0;font-weight:600">{$date}</td></tr>
  <tr><td style="padding:7px 0;color:#7A4A70">Time</td>
      <td style="padding:7px 0;font-weight:600">{$time}</td></tr>
  <tr><td style="padding:7px 0;color:#7A4A70">Total Price</td>
      <td style="padding:7px 0;font-weight:600">{$total}</td></tr>
  <tr><td style="padding:7px 0;color:#7A4A70">Deposit Due</td>
      <td style="padding:7px 0;font-weight:600">{$deposit}</td></tr>
  <tr><td style="padding:7px 0;color:#7A4A70">Balance on Day</td>
      <td style="padding:7px 0;font-weight:600">{$balance}</td></tr>
</table>
{$payBlock}
<p style="font-size:13px;color:#7A4A70;">
  If you have any questions, feel free to WhatsApp us or reply to this email.
</p>
HTML;
        $mail->Body    = emailWrapper($content, 'Booking Confirmation');
        $mail->AltBody = "Hi {$customer['name']},\n\nYour booking for {$service['name']} on $date at $time has been confirmed.\nTotal: $total | Deposit: $deposit | Balance on day: $balance"
                       . ($paymentLink ? "\n\nPay your deposit here: $paymentLink" : '');
        return $mail->send();
    } catch (Exception $e) {
        error_log('Email error (bookingCreatedByAdmin): ' . $e->getMessage());
        return false;
    }
}