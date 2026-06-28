<?php
// ============================================================
// BraidedbyAGB — API Router
// FILE: /api/index.php
// ============================================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// Global error handler — always return JSON, never empty
set_exception_handler(function(Throwable $e) {
    error_log('API fatal: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['error' => 'Server error. Please try again or contact us.']);
    exit;
});

$endpoint = sanitize($_GET['endpoint'] ?? '');

switch ($endpoint) {

    // ── SLOTS ─────────────────────────────────────────────
    case 'slots':
        $date      = sanitize($_GET['date'] ?? '');
        $serviceId = (int)($_GET['service_id'] ?? 0);
        if (!$date || !$serviceId) jsonResponse(['error' => 'Missing parameters'], 400);
        $db   = getDB();

        // Duration of the service being requested (new booking)
        $stmt = $db->prepare("SELECT duration_mins FROM services WHERE id=?");
        $stmt->execute([$serviceId]);
        $svc         = $stmt->fetch();
        $rawDurMins  = $svc ? (int)$svc['duration_mins'] : 0;
        $newDurMins  = $rawDurMins > 0 ? $rawDurMins : 60;
        $newDurSecs  = $newDurMins * 60;

        // Existing bookings with their actual durations.
        // Priority: admin override (b.duration_mins) → variant → service → 60 min default
        // Defensive try-catch: if the duration_mins column hasn't been added to live DB yet,
        // fall back to variant/service duration so bookings still block slots correctly.
        try {
            $stmt = $db->prepare("
                SELECT b.booked_time,
                       COALESCE(NULLIF(b.duration_mins,0), NULLIF(sv.duration_mins,0), NULLIF(s.duration_mins,0), 60) AS duration_mins
                FROM bookings b
                JOIN services s ON s.id = b.service_id
                LEFT JOIN service_variants sv ON sv.id = b.variant_id
                WHERE b.booked_date = ? AND b.status IN ('pending','confirmed')
            ");
            $stmt->execute([$date]);
            $booked = $stmt->fetchAll();
        } catch (Throwable $e) {
            // duration_mins column not yet migrated — use variant/service duration only
            $stmt = $db->prepare("
                SELECT b.booked_time,
                       COALESCE(NULLIF(sv.duration_mins,0), NULLIF(s.duration_mins,0), 60) AS duration_mins
                FROM bookings b
                JOIN services s ON s.id = b.service_id
                LEFT JOIN service_variants sv ON sv.id = b.variant_id
                WHERE b.booked_date = ? AND b.status IN ('pending','confirmed')
            ");
            $stmt->execute([$date]);
            $booked = $stmt->fetchAll();
        }

        // Full-day block check
        $stmt = $db->prepare("SELECT COUNT(*) FROM availability WHERE avail_date=? AND is_blocked=1 AND time_slot IS NULL");
        $stmt->execute([$date]);
        $fullDayBlocked = (int)$stmt->fetchColumn() > 0;

        // Specific time-slot blocks
        $stmt = $db->prepare("SELECT time_slot FROM availability WHERE avail_date=? AND is_blocked=1 AND time_slot IS NOT NULL");
        $stmt->execute([$date]);
        $blockedSlots = array_column($stmt->fetchAll(), 'time_slot');

        $dayStart = strtotime($date . ' 08:00:00');
        $dayEnd   = strtotime($date . ' 20:30:00');

        // Booking buffer — how many hours in advance a client must book.
        // Only applied when the requested date is today; future dates are always fully open.
        $bufferHours = (int)getSetting('booking_buffer_hours', '0');
        // Always block past times for today; buffer (if set) adds extra forward time on top.
        // cutoffTime is always at least time() so slots before NOW are always unavailable today.
        $cutoffTime  = time() + $bufferHours * 3600;

        $slots    = [];

        for ($t = $dayStart; $t < $dayEnd; $t += 30 * 60) {
            $ts = date('H:i:s', $t);

            // New service must finish by 20:30 (last slot starts at 8pm)
            if ($t + $newDurSecs > $dayEnd) {
                $slots[] = ['time' => $ts, 'label' => date('g:i A', $t), 'available' => false];
                continue;
            }

            // Block past and too-soon slots (today only)
            if ($date === date('Y-m-d') && $t < $cutoffTime) {
                $slots[] = ['time' => $ts, 'label' => date('g:i A', $t), 'available' => false];
                continue;
            }

            if ($fullDayBlocked || in_array($ts, $blockedSlots)) {
                $slots[] = ['time' => $ts, 'label' => date('g:i A', $t), 'available' => false];
                continue;
            }

            // Duration-aware overlap: new window [t, t+newDur) vs existing [bs, bs+existingDur)
            // Overlap if:  t < bs + existingDur   AND   t + newDur > bs
            $conflict = false;
            foreach ($booked as $row) {
                $bs          = strtotime($date . ' ' . $row['booked_time']);
                $existDurSec = (int)$row['duration_mins'] * 60;
                if ($t < $bs + $existDurSec && $t + $newDurSecs > $bs) {
                    $conflict = true;
                    break;
                }
            }

            $slots[] = ['time' => $ts, 'label' => date('g:i A', $t), 'available' => !$conflict];
        }
        jsonResponse(['slots' => $slots]);
        break;

    // ── AVAILABILITY ──────────────────────────────────────
    case 'availability':
        $year  = (int)($_GET['year']  ?? date('Y'));
        $month = (int)($_GET['month'] ?? date('n'));
        $db    = getDB();
        // Only return fully blocked dates (time_slot IS NULL) — partial slot blocks
        // do not grey out the whole date on the public calendar.
        $stmt  = $db->prepare("SELECT DISTINCT avail_date FROM availability WHERE YEAR(avail_date)=? AND MONTH(avail_date)=? AND is_blocked=1 AND time_slot IS NULL");
        $stmt->execute([$year, $month]);
        jsonResponse(['blocked' => array_column($stmt->fetchAll(), 'avail_date')]);
        break;

    // ── ADD-ONS ───────────────────────────────────────────
    case 'addons':
        $serviceId = (int)($_GET['service_id'] ?? 0);
        if (!$serviceId) jsonResponse(['addons' => []]);
        $db = getDB();
        // Per-service add-ons only — each service owns its add-ons and their prices.
        $stmt = $db->prepare("
            SELECT id, name, price
            FROM service_addons
            WHERE is_active = 1 AND service_id = ?
            ORDER BY id ASC
        ");
        $stmt->execute([$serviceId]);
        jsonResponse(['addons' => $stmt->fetchAll()]);
        break;

    // ── PIPELINE ──────────────────────────────────────────
    case 'pipeline':
        $serviceId = (int)($_GET['service_id'] ?? 0);
        if (!$serviceId) jsonResponse(['products' => []]);
        jsonResponse(['products' => getLinkedProducts($serviceId)]);
        break;

    // ── CREATE BOOKING PAYMENT INTENT ────────────────────
    case 'create-payment-intent':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
        $body   = json_decode(file_get_contents('php://input'), true) ?? [];
        $amount = (int)($body['amount'] ?? 0);
        if ($amount < 50) jsonResponse(['error' => 'Invalid amount'], 400);
        if (!defined('STRIPE_SECRET_KEY') || STRIPE_SECRET_KEY === '') {
            jsonResponse(['error' => 'Stripe is not configured. Please contact us to book.'], 500);
        }
        // Use curl directly — no Stripe PHP library required
        $ch = curl_init('https://api.stripe.com/v1/payment_intents');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_USERPWD        => STRIPE_SECRET_KEY . ':',
            CURLOPT_POSTFIELDS     => http_build_query([
                'amount'              => $amount,
                'currency'            => 'gbp',
                'metadata[source]'    => 'braidedbyagb_booking',
            ]),
            CURLOPT_HTTPHEADER     => ['Stripe-Version: 2024-06-20'],
        ]);
        $stripeResp = curl_exec($ch);
        $curlErr    = curl_error($ch);
        curl_close($ch);
        if ($curlErr) {
            error_log('Stripe curl error: ' . $curlErr);
            jsonResponse(['error' => 'Payment could not be initiated. Please try again.'], 500);
        }
        $intent = json_decode($stripeResp, true);
        if (!empty($intent['error'])) {
            error_log('Stripe error: ' . $intent['error']['message']);
            jsonResponse(['error' => 'Payment could not be initiated. Please try again.'], 500);
        }
        jsonResponse(['client_secret' => $intent['client_secret']]);
        break;

    // ── CONFIRM BOOKING ───────────────────────────────────
    case 'confirm-booking':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        foreach (['service_id','date','time','name','email','phone','payment_method'] as $f) {
            if (empty($data[$f])) jsonResponse(['error' => 'Missing required field: ' . $f], 400);
        }
        $db = getDB();
        $db->beginTransaction();
        try {
            // Upsert customer
            $stmt = $db->prepare("INSERT INTO customers (name, email, phone, email_optin) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name), phone=VALUES(phone), email_optin=VALUES(email_optin)");
            $stmt->execute([sanitize($data['name']), sanitizeEmail($data['email']), sanitize($data['phone']), (int)($data['email_optin'] ?? 1)]);
            $customerId = (int)$db->lastInsertId();
            if (!$customerId) {
                $r = $db->prepare("SELECT id FROM customers WHERE email=?");
                $r->execute([sanitizeEmail($data['email'])]);
                $customerId = (int)$r->fetchColumn();
            }

            // Block check — prevent blocked customers from booking
            if ($customerId) {
                $blk = $db->prepare("SELECT is_blocked FROM customers WHERE id=?");
                $blk->execute([$customerId]);
                $blkRow = $blk->fetch();
                if ($blkRow && $blkRow['is_blocked']) {
                    $db->rollBack();
                    jsonResponse(['error' => 'We are unable to accept your booking at this time. Please contact us directly.'], 403);
                }
            }

            // Check slot still available — pass actual service duration so the overlap
            // guard covers the full window (e.g. 3-hour service blocks [10:00, 13:00)).
            $svcDurStmt = $db->prepare("
                SELECT COALESCE(NULLIF(sv.duration_mins,0), NULLIF(s.duration_mins,0), 60) AS dur
                FROM services s
                LEFT JOIN service_variants sv ON sv.id = ?
                WHERE s.id = ?
            ");
            $svcDurStmt->execute([
                !empty($data['variant_id']) ? (int)$data['variant_id'] : null,
                (int)$data['service_id']
            ]);
            $svcDurRow  = $svcDurStmt->fetch();
            $confirmDur = ($svcDurRow && (int)$svcDurRow['dur'] > 0) ? (int)$svcDurRow['dur'] : 60;

            if (!isSlotAvailable($data['date'], $data['time'], $confirmDur)) {
                $db->rollBack();
                jsonResponse(['error' => 'This time slot was just taken. Please choose another time.'], 409);
            }

            // Create booking
            $ref         = generateBookingRef();
            $depositPaid = $data['payment_method'] === 'stripe' ? 1 : 0;
            $stmt = $db->prepare("
                INSERT INTO bookings
                    (booking_ref, customer_id, service_id, variant_id, booked_date, booked_time,
                     payment_method, deposit_amount, deposit_paid, total_price, remaining_balance,
                     client_notes, policy_accepted, status)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,'pending')
            ");
            $stmt->execute([
                $ref, $customerId, (int)$data['service_id'],
                !empty($data['variant_id']) ? (int)$data['variant_id'] : null,
                $data['date'], $data['time'], $data['payment_method'],
                (float)$data['deposit'], $depositPaid,
                (float)$data['total'],
                round((float)$data['total'] - (float)$data['deposit'], 2),
                sanitize($data['notes'] ?? '')
            ]);
            $bookingId = (int)$db->lastInsertId();

            // Add-ons
            if (!empty($data['addons'])) {
                $as = $db->prepare("INSERT INTO booking_addons (booking_id, addon_id, price_charged) SELECT ?, id, price FROM service_addons WHERE id=?");
                foreach ($data['addons'] as $a) $as->execute([$bookingId, (int)$a]);
            }

            // Pipeline products → create order
            if (!empty($data['pipeline_products'])) {
                $oRef   = generateOrderRef();
                $pTotal = array_sum(array_column($data['pipeline_products'], 'price'));
                $db->prepare("INSERT INTO orders (order_ref, customer_id, booking_id, subtotal, total, status, delivery_type, payment_method, from_pipeline) VALUES (?,?,?,?,?,'pending','shipping',?,1)")
                   ->execute([$oRef, $customerId, $bookingId, $pTotal, $pTotal, $data['payment_method']]);
                $orderId = (int)$db->lastInsertId();
                $is = $db->prepare("INSERT INTO order_items (order_id, product_id, quantity, price_charged) VALUES (?,?,1,?)");
                foreach ($data['pipeline_products'] as $prod) $is->execute([$orderId, (int)$prod['id'], (float)$prod['price']]);
            }

            // Payment record — stripe_id is NULL for bank transfers
            $stripeId = !empty($data['stripe_payment_id']) ? sanitize($data['stripe_payment_id']) : null;
            $db->prepare("
                INSERT INTO payments (booking_id, stripe_id, amount, type, method, status, confirmed_by, confirmed_at)
                VALUES (?,?,?,'deposit',?,?,?,?)
            ")->execute([
                $bookingId,
                $stripeId,
                (float)$data['deposit'],
                $data['payment_method'],
                $depositPaid ? 'succeeded' : 'pending',
                $depositPaid ? 'stripe_webhook' : null,
                $depositPaid ? date('Y-m-d H:i:s') : null
            ]);

            $db->commit();

            // Emails — non-fatal if mailer fails
            try {
                if (is_readable(__DIR__ . '/../includes/mailer.php')) {
                    require_once __DIR__ . '/../includes/mailer.php';
                    $customer = ['name' => sanitize($data['name']), 'email' => sanitizeEmail($data['email'])];
                    $svcRow   = $db->query("SELECT * FROM services WHERE id=" . (int)$data['service_id'])->fetch();
                    $bkgRow   = $db->query("SELECT * FROM bookings WHERE id={$bookingId}")->fetch();
                    emailBookingReceived($bkgRow, $customer, $svcRow);
                    emailAdminNewBooking($bkgRow, $customer, $svcRow);
                }
            } catch (Throwable $e) {
                error_log('Booking email error (non-fatal): ' . $e->getMessage());
            }

            jsonResponse(['success' => true, 'ref' => $ref]);

        } catch (Exception $e) {
            $db->rollBack();
            error_log('Booking confirm error: ' . $e->getMessage());
            jsonResponse(['error' => 'Booking could not be saved. Please contact us directly.'], 500);
        }
        break;

    // ── CONFIRM CART (multi-appointment / family booking) ─────────────
    // POST body:
    // {
    //   payer: { name, email, phone, email_optin },
    //   payment_method: 'stripe'|'bank_transfer',
    //   stripe_payment_id: 'pi_...'|null,
    //   items: [ { service_id, variant_id, date, time, guest_name, notes,
    //              addons:[id...], pipeline_products:[{id,price}...],
    //              total, deposit } ]
    // }
    // Creates one booking row per item, all sharing a cart_group_ref, in a single
    // transaction with an authoritative duration-aware overlap re-check (against
    // existing DB bookings AND sibling cart items). One combined deposit was already
    // charged client-side; each booking gets its own payment row with the shared
    // stripe_id so accounting/receipts stay per-booking.
    case 'confirm-cart':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
        $data  = json_decode(file_get_contents('php://input'), true) ?? [];
        $payer = $data['payer'] ?? [];
        $items = $data['items'] ?? [];
        $method = $data['payment_method'] ?? '';

        if (empty($payer['name']) || empty($payer['email']) || empty($payer['phone'])) {
            jsonResponse(['error' => 'Missing payer details'], 400);
        }
        if (!is_array($items) || count($items) === 0) {
            jsonResponse(['error' => 'Your cart is empty'], 400);
        }
        if (!in_array($method, ['stripe', 'bank_transfer'], true)) {
            jsonResponse(['error' => 'Invalid payment method'], 400);
        }
        // Each item must carry the core fields
        foreach ($items as $idx => $it) {
            foreach (['service_id', 'date', 'time'] as $f) {
                if (empty($it[$f])) jsonResponse(['error' => "Appointment " . ($idx + 1) . " is missing: $f"], 400);
            }
        }

        $db = getDB();
        $db->beginTransaction();
        try {
            // 1. Upsert payer customer
            $stmt = $db->prepare("INSERT INTO customers (name, email, phone, email_optin) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name), phone=VALUES(phone), email_optin=VALUES(email_optin)");
            $stmt->execute([
                sanitize($payer['name']),
                sanitizeEmail($payer['email']),
                sanitize($payer['phone']),
                (int)($payer['email_optin'] ?? 1),
            ]);
            $customerId = (int)$db->lastInsertId();
            if (!$customerId) {
                $r = $db->prepare("SELECT id FROM customers WHERE email=?");
                $r->execute([sanitizeEmail($payer['email'])]);
                $customerId = (int)$r->fetchColumn();
            }
            if (!$customerId) {
                $db->rollBack();
                jsonResponse(['error' => 'Could not create your customer record.'], 500);
            }

            // Block check
            $blk = $db->prepare("SELECT is_blocked FROM customers WHERE id=?");
            $blk->execute([$customerId]);
            $blkRow = $blk->fetch();
            if ($blkRow && $blkRow['is_blocked']) {
                $db->rollBack();
                jsonResponse(['error' => 'We are unable to accept your booking at this time. Please contact us directly.'], 403);
            }

            // 2. One shared group reference
            $groupRef = 'AGBC' . date('Y') . strtoupper(bin2hex(random_bytes(3)));

            // Prepared statements reused across items
            $durStmt = $db->prepare("
                SELECT COALESCE(NULLIF(sv.duration_mins,0), NULLIF(s.duration_mins,0), 60) AS dur
                FROM services s
                LEFT JOIN service_variants sv ON sv.id = ?
                WHERE s.id = ?
            ");
            $insBooking = $db->prepare("
                INSERT INTO bookings
                    (booking_ref, cart_group_ref, customer_id, guest_name, service_id, variant_id,
                     booked_date, booked_time, payment_method, deposit_amount, deposit_paid,
                     total_price, remaining_balance, client_notes, policy_accepted, status)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,'pending')
            ");
            $insAddon   = $db->prepare("INSERT INTO booking_addons (booking_id, addon_id, price_charged) SELECT ?, id, price FROM service_addons WHERE id=?");
            $insPayment = $db->prepare("
                INSERT INTO payments (booking_id, stripe_id, amount, type, method, status, confirmed_by, confirmed_at)
                VALUES (?,?,?,'deposit',?,?,?,?)
            ");

            $depositPaid = $method === 'stripe' ? 1 : 0;
            $stripeId    = !empty($data['stripe_payment_id']) ? sanitize($data['stripe_payment_id']) : null;

            $accepted    = [];   // [date => [ [startTs,endTs], ... ]] — sibling cart windows
            $createdRefs = [];
            $createdIds  = [];

            foreach ($items as $idx => $it) {
                $serviceId = (int)$it['service_id'];
                $variantId = !empty($it['variant_id']) ? (int)$it['variant_id'] : null;
                $date      = sanitize($it['date']);
                $time      = sanitize($it['time']);

                // Authoritative duration from DB (never trust client for overlap maths)
                $durStmt->execute([$variantId, $serviceId]);
                $durRow = $durStmt->fetch();
                $dur    = ($durRow && (int)$durRow['dur'] > 0) ? (int)$durRow['dur'] : 60;

                // (a) Conflict vs availability blocks + existing DB bookings (duration-aware)
                if (!isSlotAvailable($date, $time, $dur)) {
                    $db->rollBack();
                    jsonResponse(['error' => "The {$time} slot on {$date} was just taken. Please review your cart and pick another time."], 409);
                }

                // (b) Conflict vs other appointments already in THIS cart
                $start = strtotime($date . ' ' . $time);
                $end   = $start + $dur * 60;
                foreach (($accepted[$date] ?? []) as $win) {
                    if ($start < $win[1] && $end > $win[0]) {
                        $db->rollBack();
                        jsonResponse(['error' => "Two appointments in your cart overlap at {$time} on {$date}. Please adjust the times."], 409);
                    }
                }

                // Guest name — NULL means "the payer themselves"
                $guest = trim((string)($it['guest_name'] ?? ''));
                if ($guest === '' || strcasecmp($guest, 'myself') === 0) $guest = null;
                else $guest = sanitize($guest);

                $itTotal   = (float)($it['total'] ?? 0);
                $itDeposit = (float)($it['deposit'] ?? 0);
                $itBalance = round($itTotal - $itDeposit, 2);

                $ref = generateBookingRef();
                $insBooking->execute([
                    $ref, $groupRef, $customerId, $guest, $serviceId, $variantId,
                    $date, $time, $method, $itDeposit, $depositPaid,
                    $itTotal, $itBalance, sanitize($it['notes'] ?? ''),
                ]);
                $bookingId = (int)$db->lastInsertId();

                // Add-ons
                if (!empty($it['addons']) && is_array($it['addons'])) {
                    foreach ($it['addons'] as $a) $insAddon->execute([$bookingId, (int)$a]);
                }

                // Pipeline products → order
                if (!empty($it['pipeline_products']) && is_array($it['pipeline_products'])) {
                    $oRef   = generateOrderRef();
                    $pTotal = array_sum(array_column($it['pipeline_products'], 'price'));
                    $db->prepare("INSERT INTO orders (order_ref, customer_id, booking_id, subtotal, total, status, delivery_type, payment_method, from_pipeline) VALUES (?,?,?,?,?,'pending','shipping',?,1)")
                       ->execute([$oRef, $customerId, $bookingId, $pTotal, $pTotal, $method]);
                    $orderId = (int)$db->lastInsertId();
                    $is = $db->prepare("INSERT INTO order_items (order_id, product_id, quantity, price_charged) VALUES (?,?,1,?)");
                    foreach ($it['pipeline_products'] as $prod) $is->execute([$orderId, (int)$prod['id'], (float)$prod['price']]);
                }

                // Payment record — shared stripe_id across the group
                $insPayment->execute([
                    $bookingId,
                    $stripeId,
                    $itDeposit,
                    $method,
                    $depositPaid ? 'succeeded' : 'pending',
                    $depositPaid ? 'stripe_webhook' : null,
                    $depositPaid ? date('Y-m-d H:i:s') : null,
                ]);

                $accepted[$date][] = [$start, $end];
                $createdRefs[]     = $ref;
                $createdIds[]      = $bookingId;
            }

            $db->commit();

            // Emails — non-fatal (bookings already committed)
            try {
                if (is_readable(__DIR__ . '/../includes/mailer.php')) {
                    require_once __DIR__ . '/../includes/mailer.php';
                    // Re-load the created bookings with service/variant names for the email
                    $in     = implode(',', array_fill(0, count($createdIds), '?'));
                    $eStmt  = $db->prepare("
                        SELECT b.booking_ref, b.guest_name, b.booked_date, b.booked_time,
                               b.total_price, b.deposit_amount, b.remaining_balance,
                               s.name AS service_name, sv.variant_name
                        FROM bookings b
                        JOIN services s ON s.id = b.service_id
                        LEFT JOIN service_variants sv ON sv.id = b.variant_id
                        WHERE b.id IN ($in)
                        ORDER BY b.booked_date ASC, b.booked_time ASC
                    ");
                    $eStmt->execute($createdIds);
                    $emailItems = $eStmt->fetchAll();
                    $payerArr   = [
                        'name'  => sanitize($payer['name']),
                        'email' => sanitizeEmail($payer['email']),
                        'phone' => sanitize($payer['phone']),
                    ];
                    emailCartConfirmation($payerArr, $emailItems, $groupRef, $method);
                    emailAdminNewCartBooking($payerArr, $emailItems, $groupRef);
                }
            } catch (Throwable $e) {
                error_log('Cart email error (non-fatal): ' . $e->getMessage());
            }

            jsonResponse(['success' => true, 'group_ref' => $groupRef, 'refs' => $createdRefs]);

        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('Cart confirm error: ' . $e->getMessage());
            jsonResponse(['error' => 'Your booking could not be saved. Please contact us directly.'], 500);
        }
        break;

    // ── VALIDATE DISCOUNT ─────────────────────────────────
    case 'validate-discount':
        $code = strtoupper(sanitize($_GET['code'] ?? ''));
        if (!$code) jsonResponse(['valid' => false, 'error' => 'No code provided']);
        $db   = getDB();
        $stmt = $db->prepare("SELECT * FROM discount_codes WHERE code=? AND is_active=1 AND (uses_limit IS NULL OR times_used < uses_limit) AND (expiry_date IS NULL OR expiry_date >= CURDATE()) LIMIT 1");
        $stmt->execute([$code]);
        $discount = $stmt->fetch();
        if (!$discount) jsonResponse(['valid' => false, 'error' => 'Invalid or expired discount code.']);
        jsonResponse(['valid' => true, 'code' => $code, 'type' => $discount['type'], 'value' => (float)$discount['value']]);
        break;

    // ── CREATE ORDER PAYMENT INTENT ───────────────────────
    case 'create-order-payment-intent':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
        $body   = json_decode(file_get_contents('php://input'), true) ?? [];
        $amount = (int)($body['amount'] ?? 0);
        if ($amount < 50) jsonResponse(['error' => 'Invalid amount'], 400);
        if (!defined('STRIPE_SECRET_KEY') || STRIPE_SECRET_KEY === '') {
            jsonResponse(['error' => 'Stripe is not configured.'], 500);
        }
        $ch = curl_init('https://api.stripe.com/v1/payment_intents');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_USERPWD        => STRIPE_SECRET_KEY . ':',
            CURLOPT_POSTFIELDS     => http_build_query([
                'amount'              => $amount,
                'currency'            => 'gbp',
                'metadata[source]'    => 'braidedbyagb_shop',
            ]),
            CURLOPT_HTTPHEADER     => ['Stripe-Version: 2024-06-20'],
        ]);
        $stripeResp = curl_exec($ch);
        $curlErr    = curl_error($ch);
        curl_close($ch);
        if ($curlErr) {
            error_log('Stripe curl error: ' . $curlErr);
            jsonResponse(['error' => 'Payment could not be initiated.'], 500);
        }
        $intent = json_decode($stripeResp, true);
        if (!empty($intent['error'])) {
            error_log('Stripe shop error: ' . $intent['error']['message']);
            jsonResponse(['error' => 'Payment could not be initiated.'], 500);
        }
        jsonResponse(['client_secret' => $intent['client_secret']]);
        break;

    // ── CONFIRM ORDER ─────────────────────────────────────
    case 'confirm-order':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        foreach (['items','name','email','phone','payment_method','total'] as $f) {
            if (empty($data[$f])) jsonResponse(['error' => 'Missing: ' . $f], 400);
        }
        $db = getDB();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare("INSERT INTO customers (name, email, phone) VALUES (?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name), phone=VALUES(phone)");
            $stmt->execute([sanitize($data['name']), sanitizeEmail($data['email']), sanitize($data['phone'])]);
            $customerId = (int)$db->lastInsertId();
            if (!$customerId) {
                $r = $db->prepare("SELECT id FROM customers WHERE email=?");
                $r->execute([sanitizeEmail($data['email'])]);
                $customerId = (int)$r->fetchColumn();
            }

            $discountAmount = 0; $discountCodeId = null;
            if (!empty($data['discount_code'])) {
                $dStmt = $db->prepare("SELECT * FROM discount_codes WHERE code=? AND is_active=1 AND (uses_limit IS NULL OR times_used < uses_limit) AND (expiry_date IS NULL OR expiry_date >= CURDATE())");
                $dStmt->execute([strtoupper($data['discount_code'])]);
                $dc = $dStmt->fetch();
                if ($dc) {
                    $sub            = array_reduce($data['items'], fn($s,$i) => $s + $i['price'] * $i['quantity'], 0);
                    $discountAmount = $dc['type'] === 'percent' ? $sub * ($dc['value'] / 100) : min((float)$dc['value'], $sub);
                    $discountCodeId = $dc['id'];
                }
            }

            $sub      = array_reduce($data['items'], fn($s,$i) => $s + $i['price'] * $i['quantity'], 0);
            $shipping = $data['delivery_type'] === 'local_pickup' ? 0 : ($sub >= 50 ? 0 : 3.99);
            $total    = max(0, $sub - $discountAmount) + $shipping;
            $ref      = generateOrderRef();
            $paid     = $data['payment_method'] === 'stripe' ? 1 : 0;

            $stmt = $db->prepare("
                INSERT INTO orders
                    (order_ref, customer_id, subtotal, discount_amount, discount_code_id, shipping_cost,
                     total, status, delivery_type, delivery_address, payment_method, payment_status, stripe_payment_id)
                VALUES (?,?,?,?,?,?,?,'pending',?,?,?,?,?)
            ");
            $stmt->execute([
                $ref, $customerId, $sub, $discountAmount, $discountCodeId, $shipping, $total,
                $data['delivery_type'], sanitize($data['delivery_address'] ?? ''),
                $data['payment_method'], $paid ? 'paid' : 'pending',
                sanitize($data['stripe_payment_id'] ?? '')
            ]);
            $orderId = (int)$db->lastInsertId();

            $iStmt = $db->prepare("INSERT INTO order_items (order_id, product_id, variant_id, quantity, price_charged) VALUES (?,?,?,?,?)");
            $sStmt = $db->prepare("UPDATE product_variants SET stock_qty=GREATEST(0, stock_qty-?) WHERE product_id=?");
            foreach ($data['items'] as $item) {
                $iStmt->execute([$orderId, (int)$item['productId'], $item['variantId'] ? (int)$item['variantId'] : null, (int)$item['quantity'], (float)$item['price']]);
                $sStmt->execute([(int)$item['quantity'], (int)$item['productId']]);
            }

            if ($discountCodeId) {
                $db->prepare("UPDATE discount_codes SET times_used = times_used + 1 WHERE id=?")->execute([$discountCodeId]);
            }

            $db->commit();

            // Emails — non-fatal
            try {
                if (is_readable(__DIR__ . '/../includes/mailer.php')) {
                    require_once __DIR__ . '/../includes/mailer.php';
                    $customer = ['name' => sanitize($data['name']), 'email' => sanitizeEmail($data['email'])];
                    $orderRow = $db->query("SELECT * FROM orders WHERE id={$orderId}")->fetch();
                    emailOrderConfirmation($orderRow, $customer, $data['items']);
                    emailAdminNewOrder($orderRow, $customer, $data['items']);
                }
            } catch (Throwable $e) {
                error_log('Order email error (non-fatal): ' . $e->getMessage());
            }

            jsonResponse(['success' => true, 'ref' => $ref]);

        } catch (Exception $e) {
            $db->rollBack();
            error_log('Order confirm error: ' . $e->getMessage());
            jsonResponse(['error' => 'Order could not be saved. Please try again or contact us.'], 500);
        }
        break;

    // ── UPLOAD BANK TRANSFER RECEIPT ─────────────────────
    case 'upload-receipt':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
        $bookingRef = sanitize($_POST['booking_ref'] ?? '');
        if (!$bookingRef) jsonResponse(['error' => 'Missing booking reference'], 400);
        if (empty($_FILES['receipt']) || $_FILES['receipt']['error'] !== UPLOAD_ERR_OK) {
            jsonResponse(['error' => 'No file uploaded or upload error'], 400);
        }
        $allowed = ['image/jpeg','image/png','image/webp','application/pdf'];
        $finfo   = finfo_open(FILEINFO_MIME_TYPE);
        $mime    = finfo_file($finfo, $_FILES['receipt']['tmp_name']);
        finfo_close($finfo);
        if (!in_array($mime, $allowed)) {
            jsonResponse(['error' => 'Invalid file type. Please upload JPG, PNG, WebP or PDF.'], 400);
        }
        if ($_FILES['receipt']['size'] > 8 * 1024 * 1024) {
            jsonResponse(['error' => 'File too large. Maximum 8MB.'], 400);
        }
        $ext      = $mime === 'application/pdf' ? 'pdf' : pathinfo($_FILES['receipt']['name'], PATHINFO_EXTENSION);
        $filename = 'receipt-' . preg_replace('/[^a-z0-9]/i','', $bookingRef) . '-' . time() . '.' . strtolower($ext);
        $dir      = __DIR__ . '/../uploads/receipts/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        if (!move_uploaded_file($_FILES['receipt']['tmp_name'], $dir . $filename)) {
            jsonResponse(['error' => 'Could not save file. Please try again.'], 500);
        }
        $receiptUrl = '/uploads/receipts/' . $filename;
        // Save to booking record
        try {
            $db = getDB();
            $db->prepare("UPDATE bookings SET receipt_url=?, status='pending' WHERE booking_ref=?")
               ->execute([$receiptUrl, $bookingRef]);
            // Email admin
            try {
                require_once __DIR__ . '/../includes/mailer.php';
                $mail = createMailer();
                $mail->addAddress(SITE_EMAIL);
                $mail->Subject = 'Bank Transfer Receipt Received — ' . $bookingRef;
                $mail->Body    = emailWrapper("
                    <h2>Bank Transfer Receipt Uploaded</h2>
                    <div class='detail-box'>
                      <table>
                        <tr><td>Booking Ref:</td><td><strong>{$bookingRef}</strong></td></tr>
                        <tr><td>Receipt File:</td><td>{$filename}</td></tr>
                        <tr><td>Uploaded:</td><td>" . date('d M Y H:i') . "</td></tr>
                      </table>
                    </div>
                    <p>Please log in to the admin panel to verify and confirm this booking.</p>
                ");
                if ($mime !== 'application/pdf') {
                    $mail->addAttachment($dir . $filename, $filename);
                }
                $mail->send();
            } catch (Throwable $e) {
                error_log('Receipt email error: ' . $e->getMessage());
            }
        } catch (Exception $e) {
            error_log('Receipt DB error: ' . $e->getMessage());
        }
        jsonResponse(['success' => true, 'message' => 'Receipt uploaded successfully. We will confirm your booking within 24 hours.']);
        break;

    // ── PAY BOOKING (via admin payment link) ─────────────────
    // POST body: { token: '...', stripe_payment_id: 'pi_...' }
    // Validates the payment token, marks deposit paid, sends confirmation email,
    // and invalidates the token so the link can't be reused.
    case 'pay-booking':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
        $body  = json_decode(file_get_contents('php://input'), true) ?? [];
        $token = sanitize($body['token'] ?? '');
        $stripePayId = sanitize($body['stripe_payment_id'] ?? '');

        if (!$token)        jsonResponse(['error' => 'Missing token'], 400);
        if (!$stripePayId)  jsonResponse(['error' => 'Missing stripe_payment_id'], 400);

        $db = getDB();
        $db->beginTransaction();
        try {
            // Lock the row and verify token is still valid
            $stmt = $db->prepare("
                SELECT b.*, c.name AS c_name, c.email AS c_email,
                       s.name AS s_name
                FROM bookings b
                JOIN customers c ON c.id = b.customer_id
                JOIN services  s ON s.id = b.service_id
                WHERE b.payment_token = ?
                  AND b.deposit_paid  = 0
                  AND b.status NOT IN ('cancelled','completed')
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([$token]);
            $bk = $stmt->fetch();

            if (!$bk) {
                $db->rollBack();
                jsonResponse(['error' => 'Payment link is invalid or has already been used.'], 409);
            }

            // Mark deposit paid, clear token, auto-confirm if pending
            $db->prepare("
                UPDATE bookings
                SET deposit_paid    = 1,
                    payment_token   = NULL,
                    payment_method  = 'stripe',
                    status          = IF(status = 'pending', 'confirmed', status)
                WHERE id = ?
            ")->execute([$bk['id']]);

            // Insert payment record (upsert in case one exists already)
            $existsPay = $db->prepare("SELECT id FROM payments WHERE booking_id=? AND type='deposit'")->execute([$bk['id']]);
            if (!$db->query("SELECT COUNT(*) FROM payments WHERE booking_id={$bk['id']} AND type='deposit'")->fetchColumn()) {
                $db->prepare("
                    INSERT INTO payments (booking_id, stripe_id, amount, currency, type, method, status, confirmed_by, confirmed_at)
                    VALUES (?,?,?,'GBP','deposit','stripe','succeeded','stripe_webhook',NOW())
                ")->execute([$bk['id'], $stripePayId, $bk['deposit_amount']]);
            } else {
                $db->prepare("
                    UPDATE payments
                    SET stripe_id    = ?,
                        status       = 'succeeded',
                        confirmed_by = 'stripe_webhook',
                        confirmed_at = NOW()
                    WHERE booking_id = ? AND type = 'deposit'
                ")->execute([$stripePayId, $bk['id']]);
            }

            $db->commit();

            // Emails (non-fatal — payment is already confirmed, emails must never block)
            try {
                if (is_readable(__DIR__ . '/../includes/mailer.php')) {
                    require_once __DIR__ . '/../includes/mailer.php';
                    $freshBk  = $db->query("SELECT b.*, s.name as s_name FROM bookings b JOIN services s ON s.id=b.service_id WHERE b.id={$bk['id']}")->fetch();
                    $customer = ['name' => $bk['c_name'], 'email' => $bk['c_email']];
                    $service  = ['name' => $bk['s_name']];
                    // Send dedicated payment receipt to client (includes appointment summary)
                    emailPaymentReceipt($freshBk, $customer, $service, $stripePayId);
                    // Notify admin that the payment came in
                    emailAdminNewBooking($freshBk, $customer, $service);
                }
            } catch (Throwable $e) {
                error_log('pay-booking email error (non-fatal): ' . $e->getMessage());
            }

            jsonResponse(['success' => true, 'ref' => $bk['booking_ref']]);

        } catch (Exception $e) {
            $db->rollBack();
            error_log('pay-booking error: ' . $e->getMessage());
            jsonResponse(['error' => 'Could not confirm payment. Please contact us directly.'], 500);
        }
        break;

    default:
        jsonResponse(['error' => 'Unknown endpoint'], 404);
}