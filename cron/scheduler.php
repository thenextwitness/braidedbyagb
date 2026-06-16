<?php
// ============================================================
// BraidedbyAGB — Cron Scheduler
// FILE: /cron/scheduler.php
//
// SETUP IN CPANEL:
//   Cron Jobs → Every 30 minutes:
//   php /home/jussxvwc/braidedbyagb.co.uk/cron/scheduler.php >> /home/jussxvwc/braidedbyagb.co.uk/cron/cron.log 2>&1
// ============================================================

// Safety: only run from CLI or with a secret key
if (php_sapi_name() !== 'cli') {
    $secret = $_GET['secret'] ?? '';
    $expected = 'agb-cron-' . date('Ymd'); // Changes daily
    if ($secret !== $expected) {
        http_response_code(403);
        exit('Forbidden');
    }
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/mailer.php';

$db  = getDB();
$now = new DateTime('now', new DateTimeZone('Europe/London'));
$log = [];

function cronLog(string $msg): void {
    global $log;
    $ts    = date('[Y-m-d H:i:s]');
    $log[] = "$ts $msg";
    echo "$ts $msg\n";
}

cronLog('Cron started');

// ─────────────────────────────────────────────────────────
// 1. SEND 24-HOUR REMINDERS  (only fires between 08:00–11:00 UK time)
//    This ensures clients get a "see you tomorrow" email in the morning,
//    not in the middle of the night.
// ─────────────────────────────────────────────────────────
try {
    $hour24 = (int)$now->format('H');
    if ($hour24 >= 8 && $hour24 < 11) {
    $tomorrow = (clone $now)->modify('+1 day')->format('Y-m-d');
    $stmt = $db->prepare("
        SELECT b.*, c.name as c_name, c.email as c_email, s.name as s_name,
               COALESCE(NULLIF(b.custom_style_name,''), s.name) AS display_name
        FROM bookings b
        JOIN customers c ON c.id = b.customer_id
        JOIN services  s ON s.id = b.service_id
        WHERE b.booked_date = ?
          AND b.status = 'confirmed'
          AND b.reminder_24_sent = 0
    ");
    $stmt->execute([$tomorrow]);
    foreach ($stmt->fetchAll() as $b) {
        $customer = ['name' => $b['c_name'], 'email' => $b['c_email']];
        $service  = ['name' => $b['display_name'] ?? $b['s_name']];
        if (emailReminder24hr($b, $customer, $service)) {
            $db->prepare("UPDATE bookings SET reminder_24_sent = 1 WHERE id = ?")->execute([$b['id']]);
            cronLog("[24hr] Reminder → {$b['c_email']} ({$b['booking_ref']})");
        }
    }
    } // end hour window check
} catch (Exception $e) {
    cronLog('[ERROR] 24hr reminders: ' . $e->getMessage());
}

// ─────────────────────────────────────────────────────────
// 2. SEND 2-HOUR REMINDERS
// ─────────────────────────────────────────────────────────
try {
    $today       = $now->format('Y-m-d');
    $twoAhead    = (clone $now)->modify('+2 hours');
    $windowStart = $twoAhead->format('H:i:00');
    $windowEnd   = (clone $twoAhead)->modify('+30 minutes')->format('H:i:00');

    $stmt = $db->prepare("
        SELECT b.*, c.name as c_name, c.email as c_email, s.name as s_name,
               COALESCE(NULLIF(b.custom_style_name,''), s.name) AS display_name
        FROM bookings b
        JOIN customers c ON c.id = b.customer_id
        JOIN services  s ON s.id = b.service_id
        WHERE b.booked_date = ?
          AND b.booked_time BETWEEN ? AND ?
          AND b.status = 'confirmed'
          AND b.reminder_2_sent = 0
    ");
    $stmt->execute([$today, $windowStart, $windowEnd]);
    foreach ($stmt->fetchAll() as $b) {
        $customer = ['name' => $b['c_name'], 'email' => $b['c_email']];
        $service  = ['name' => $b['display_name'] ?? $b['s_name']];
        if (emailReminder2hr($b, $customer, $service)) {
            $db->prepare("UPDATE bookings SET reminder_2_sent = 1 WHERE id = ?")->execute([$b['id']]);
            cronLog("[2hr] Reminder → {$b['c_email']} ({$b['booking_ref']})");
        }
    }
} catch (Exception $e) {
    cronLog('[ERROR] 2hr reminders: ' . $e->getMessage());
}

// ─────────────────────────────────────────────────────────
// 3. POST-APPOINTMENT REVIEW REQUESTS
// ─────────────────────────────────────────────────────────
try {
    $reviewDelay = (int)getSetting('review_delay_hours', '3');
    $cutoff      = (clone $now)->modify("-{$reviewDelay} hours")->format('Y-m-d H:i:s');

    $stmt = $db->prepare("
        SELECT b.*, c.name as c_name, c.email as c_email, s.name as s_name
        FROM bookings b
        JOIN customers c ON c.id = b.customer_id
        JOIN services  s ON s.id = b.service_id
        WHERE b.status = 'completed'
          AND b.review_request_sent = 0
          AND b.updated_at <= ?
    ");
    $stmt->execute([$cutoff]);
    foreach ($stmt->fetchAll() as $b) {
        $token     = generateToken(64);
        $expiresAt = (clone $now)->modify('+7 days')->format('Y-m-d H:i:s');
        $db->prepare("
            INSERT INTO review_requests (customer_id, booking_id, review_type, token, sent_at, expires_at)
            VALUES (?, ?, 'service', ?, NOW(), ?)
        ")->execute([$b['customer_id'], $b['id'], $token, $expiresAt]);

        $customer = ['name' => $b['c_name'], 'email' => $b['c_email']];
        $service  = ['name' => $b['s_name']];
        if (emailReviewRequest($b, $customer, $service, $token)) {
            $db->prepare("UPDATE bookings SET review_request_sent = 1 WHERE id = ?")->execute([$b['id']]);
            cronLog("[review] Request → {$b['c_email']} ({$b['booking_ref']})");
        }
    }
} catch (Exception $e) {
    cronLog('[ERROR] Review requests: ' . $e->getMessage());
}

// ─────────────────────────────────────────────────────────
// 4. POST-DELIVERY PRODUCT REVIEW REQUESTS
// ─────────────────────────────────────────────────────────
try {
    $productDelay  = (int)getSetting('product_review_delay_days', '2');
    $productCutoff = (clone $now)->modify("-{$productDelay} days")->format('Y-m-d H:i:s');

    $stmt = $db->prepare("
        SELECT o.*, c.name as c_name, c.email as c_email
        FROM orders o
        JOIN customers c ON c.id = o.customer_id
        WHERE o.status IN ('delivered','collected')
          AND o.review_request_sent = 0
          AND o.updated_at <= ?
    ");
    $stmt->execute([$productCutoff]);
    foreach ($stmt->fetchAll() as $o) {
        $token     = generateToken(64);
        $expiresAt = (clone $now)->modify('+7 days')->format('Y-m-d H:i:s');
        $db->prepare("
            INSERT INTO review_requests (customer_id, order_id, review_type, token, sent_at, expires_at)
            VALUES (?, ?, 'product', ?, NOW(), ?)
        ")->execute([$o['customer_id'], $o['id'], $token, $expiresAt]);

        $firstProduct = $db->prepare("SELECT p.name FROM order_items oi JOIN products p ON p.id=oi.product_id WHERE oi.order_id=? LIMIT 1");
        $firstProduct->execute([$o['id']]);
        $productName = $firstProduct->fetchColumn() ?: 'your recent order';

        $customer    = ['name' => $o['c_name'], 'email' => $o['c_email']];
        $fakeBooking = ['booking_ref' => $o['order_ref']];
        $fakeService = ['name' => $productName];
        if (emailReviewRequest($fakeBooking, $customer, $fakeService, $token)) {
            $db->prepare("UPDATE orders SET review_request_sent = 1 WHERE id = ?")->execute([$o['id']]);
            cronLog("[product-review] Request → {$o['c_email']} ({$o['order_ref']})");
        }
    }
} catch (Exception $e) {
    cronLog('[ERROR] Product review requests: ' . $e->getMessage());
}

// ─────────────────────────────────────────────────────────
// 5. AUTO-CANCEL UNPAID BANK TRANSFERS
// ─────────────────────────────────────────────────────────
try {
    $holdHours  = (int)getSetting('bank_transfer_hold_hours', '24');
    $holdCutoff = (clone $now)->modify("-{$holdHours} hours")->format('Y-m-d H:i:s');

    $stmt = $db->prepare("
        SELECT b.*, c.email as c_email, c.name as c_name, s.name as s_name
        FROM bookings b
        JOIN customers c ON c.id = b.customer_id
        JOIN services  s ON s.id = b.service_id
        WHERE b.payment_method = 'bank_transfer'
          AND b.deposit_paid = 0
          AND b.status = 'pending'
          AND b.created_at <= ?
    ");
    $stmt->execute([$holdCutoff]);
    foreach ($stmt->fetchAll() as $b) {
        $db->prepare("UPDATE bookings SET status='rejected', admin_notes='Auto-cancelled: bank transfer not received within {$holdHours} hours.' WHERE id=?")
           ->execute([$b['id']]);
        $customer = ['name' => $b['c_name'], 'email' => $b['c_email']];
        $service  = ['name' => $b['s_name']];
        emailBookingRejected($b, $customer, $service, "Payment was not received within {$holdHours} hours. Please rebook and complete payment promptly.");
        cronLog("[auto-cancel] {$b['booking_ref']} — unpaid bank transfer");
    }
} catch (Exception $e) {
    cronLog('[ERROR] Auto-cancel: ' . $e->getMessage());
}

// ─────────────────────────────────────────────────────────
// 6. LOW STOCK ALERTS (max once per 3 days per variant)
// ─────────────────────────────────────────────────────────
try {
    $stmt = $db->prepare("
        SELECT p.name as product_name, pv.colour, pv.size,
               pv.stock_qty, pv.low_stock_alert, pv.id as variant_id
        FROM product_variants pv
        JOIN products p ON p.id = pv.product_id
        WHERE pv.stock_qty <= pv.low_stock_alert
          AND p.is_active = 1
          AND (pv.low_stock_alerted_at IS NULL
               OR pv.low_stock_alerted_at < DATE_SUB(NOW(), INTERVAL 3 DAY))
    ");
    $stmt->execute();
    $lowStock = $stmt->fetchAll();

    if (!empty($lowStock)) {
        $rows = '';
        $ids  = [];
        foreach ($lowStock as $item) {
            $variant = trim(($item['colour'] ?? '') . ' ' . ($item['size'] ?? ''));
            $rows   .= "<tr><td>{$item['product_name']}</td><td>{$variant}</td>"
                     . "<td style='color:#8B0000;font-weight:700'>{$item['stock_qty']} remaining</td></tr>";
            $ids[]   = (int)$item['variant_id'];
        }
        $adminUrl = ADMIN_URL;
        $content  = "<h2>Low Stock Alert ⚠️</h2>"
                  . "<p>The following products are running low:</p>"
                  . "<div class='detail-box'><table style='width:100%'>"
                  . "<tr style='font-weight:700'><td>Product</td><td>Variant</td><td>Stock</td></tr>"
                  . $rows . "</table></div>"
                  . "<p style='text-align:center'><a href='{$adminUrl}/products' class='cta-btn'>Update Stock Levels</a></p>";
        $mail = createMailer();
        $mail->addAddress(SITE_EMAIL);
        $mail->Subject = '⚠️ Low Stock Alert — BraidedbyAGB';
        $mail->Body    = emailWrapper($content);
        $mail->send();

        // Mark alerted
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $db->prepare("UPDATE product_variants SET low_stock_alerted_at = NOW() WHERE id IN ($placeholders)")
           ->execute($ids);
        cronLog('[low-stock] Alert sent for ' . count($lowStock) . ' variant(s)');
    }
} catch (Exception $e) {
    cronLog('[ERROR] Low stock alerts: ' . $e->getMessage());
}

// ─────────────────────────────────────────────────────────
// 7A. ADMIN MORNING DAILY BRIEF (07:30 – 08:00 UK time)
// ─────────────────────────────────────────────────────────
try {
    if (getSetting('admin_notify_morning', '1') === '1') {
        $hour = (int)$now->format('H');
        $min  = (int)$now->format('i');
        $today = $now->format('Y-m-d');

        if ($hour === 7 && $min >= 30) {
            $lastSent = getSetting('admin_morning_brief_last_sent', '');
            if ($lastSent !== $today) {
                $stmt = $db->prepare("
                    SELECT b.id, b.booking_ref, b.booked_time, b.deposit_paid,
                           b.remaining_balance, b.client_notes,
                           c.name as c_name, c.phone as c_phone, c.email as c_email,
                           s.name as s_name
                    FROM bookings b
                    JOIN customers c ON c.id = b.customer_id
                    JOIN services  s ON s.id = b.service_id
                    WHERE b.booked_date = ? AND b.status = 'confirmed'
                    ORDER BY b.booked_time ASC
                ");
                $stmt->execute([$today]);
                $todayBookings = $stmt->fetchAll();

                if (emailAdminDailyBrief($todayBookings, $today)) {
                    setSetting('admin_morning_brief_last_sent', $today);
                    cronLog('[admin-brief] Morning brief sent — ' . count($todayBookings) . ' appointment(s)');
                }
            }
        }
    }
} catch (Exception $e) {
    cronLog('[ERROR] Admin morning brief: ' . $e->getMessage());
}

// ─────────────────────────────────────────────────────────
// 7B. ADMIN EVENING TOMORROW PREVIEW (20:00 – 20:30 UK time)
// ─────────────────────────────────────────────────────────
try {
    if (getSetting('admin_notify_evening', '1') === '1') {
        $hour = (int)$now->format('H');
        $min  = (int)$now->format('i');
        $today    = $now->format('Y-m-d');
        $tomorrow = (clone $now)->modify('+1 day')->format('Y-m-d');

        if ($hour === 20 && $min < 30) {
            $lastSent = getSetting('admin_evening_preview_last_sent', '');
            if ($lastSent !== $today) {
                $stmt = $db->prepare("
                    SELECT b.id, b.booking_ref, b.booked_time, b.deposit_paid,
                           b.remaining_balance, b.client_notes, b.variant_id,
                           c.name as c_name, c.phone as c_phone, c.email as c_email,
                           s.name as s_name, sv.variant_name,
                           GROUP_CONCAT(sa.name ORDER BY sa.id SEPARATOR ', ') as addon_names
                    FROM bookings b
                    JOIN customers c ON c.id = b.customer_id
                    JOIN services  s ON s.id = b.service_id
                    LEFT JOIN service_variants sv ON sv.id = b.variant_id
                    LEFT JOIN booking_addons ba ON ba.booking_id = b.id
                    LEFT JOIN service_addons sa ON sa.id = ba.addon_id
                    WHERE b.booked_date = ? AND b.status = 'confirmed'
                    GROUP BY b.id
                    ORDER BY b.booked_time ASC
                ");
                $stmt->execute([$tomorrow]);
                $tomorrowBookings = $stmt->fetchAll();

                if (emailAdminEveningPreview($tomorrowBookings, $tomorrow)) {
                    setSetting('admin_evening_preview_last_sent', $today);
                    cronLog('[admin-preview] Evening preview sent — ' . count($tomorrowBookings) . ' appointment(s) tomorrow');
                }
            }
        }
    }
} catch (Exception $e) {
    cronLog('[ERROR] Admin evening preview: ' . $e->getMessage());
}

// ─────────────────────────────────────────────────────────
// 7C. ADMIN 30-MINUTE PRE-APPOINTMENT ALERT (every cron run)
// ─────────────────────────────────────────────────────────
try {
    if (getSetting('admin_notify_30min', '1') === '1') {
        $today       = $now->format('Y-m-d');
        $in30        = (clone $now)->modify('+30 minutes');
        $in60        = (clone $now)->modify('+60 minutes');
        $windowStart = $in30->format('H:i:00');
        $windowEnd   = $in60->format('H:i:00');

        $stmt = $db->prepare("
            SELECT b.id, b.booking_ref, b.booked_time, b.deposit_paid,
                   b.remaining_balance, b.client_notes, b.variant_id,
                   c.name as c_name, c.phone as c_phone, c.email as c_email,
                   s.name as s_name, sv.variant_name
            FROM bookings b
            JOIN customers c ON c.id = b.customer_id
            JOIN services  s ON s.id = b.service_id
            LEFT JOIN service_variants sv ON sv.id = b.variant_id
            WHERE b.booked_date = ?
              AND b.booked_time BETWEEN ? AND ?
              AND b.status = 'confirmed'
              AND b.admin_reminder_30_sent = 0
        ");
        $stmt->execute([$today, $windowStart, $windowEnd]);
        foreach ($stmt->fetchAll() as $b) {
            if (emailAdminPreAppointment($b)) {
                $db->prepare("UPDATE bookings SET admin_reminder_30_sent = 1 WHERE id = ?")->execute([$b['id']]);
                cronLog("[admin-30min] Pre-appointment alert → {$b['c_name']} at {$b['booked_time']} ({$b['booking_ref']})");
            }
        }
    }
} catch (Exception $e) {
    cronLog('[ERROR] Admin 30-min alert: ' . $e->getMessage());
}

// ─────────────────────────────────────────────────────────
// 8. AUTO-COMPLETE PAST BOOKINGS
//    confirmed/pending bookings whose date has passed are
//    automatically marked completed every cron run.
// ─────────────────────────────────────────────────────────
try {
    $today = $now->format('Y-m-d');

    // Fetch them so we can award loyalty points per booking
    $stmt = $db->prepare("
        SELECT b.id, b.booking_ref, b.total_price, b.customer_id
        FROM bookings b
        WHERE b.status IN ('confirmed', 'pending')
          AND b.booked_date < ?
    ");
    $stmt->execute([$today]);
    $toComplete = $stmt->fetchAll();

    foreach ($toComplete as $b) {
        $db->prepare("UPDATE bookings SET status = 'completed' WHERE id = ?")
           ->execute([$b['id']]);

        // Award loyalty points if the helper function exists
        if (function_exists('awardLoyaltyPoints')) {
            awardLoyaltyPoints((int)$b['id'], $db);
        }

        cronLog("[auto-complete] {$b['booking_ref']} marked completed");
    }

    if (!empty($toComplete)) {
        cronLog('[auto-complete] ' . count($toComplete) . ' booking(s) completed');
    }
} catch (Exception $e) {
    cronLog('[ERROR] Auto-complete: ' . $e->getMessage());
}

cronLog('Cron completed');
