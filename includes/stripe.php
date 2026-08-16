<?php
// ============================================================
// BraidedbyAGB — Stripe helpers + idempotent payment finalization
// FILE: /includes/stripe.php
//
// Central home for all Stripe API access and the ONE authoritative
// place that flips a pending booking/order to paid. Both the customer
// return page and the Stripe webhook call finalizeStripePayment(),
// which is fully idempotent (safe to run twice — only the first call
// that performs the state transition sends emails / adjusts stock).
//
// Assumes config/database.php + includes/helpers.php are already loaded.
// ============================================================

if (!function_exists('getDB')) {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/helpers.php';
}

// ── Config helpers ──────────────────────────────────────────
function stripeConfigured(): bool {
    return defined('STRIPE_SECRET_KEY') && STRIPE_SECRET_KEY !== '';
}

// ── Low-level Stripe REST call (curl, no SDK) ───────────────
// Returns [int $httpCode, array $decodedBody]. Throws on connection failure.
function stripeRequest(string $method, string $path, array $params = [], array $extraHeaders = []): array {
    if (!stripeConfigured()) {
        throw new RuntimeException('Stripe is not configured.');
    }
    $ch      = curl_init();
    $headers = array_merge(['Stripe-Version: 2024-06-20'], $extraHeaders);
    $opts    = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => STRIPE_SECRET_KEY . ':',
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
    ];
    if (strtoupper($method) === 'POST') {
        $opts[CURLOPT_URL]        = 'https://api.stripe.com' . $path;
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($params);
    } else {
        $qs = $params ? ('?' . http_build_query($params)) : '';
        $opts[CURLOPT_URL] = 'https://api.stripe.com' . $path . $qs;
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($err !== '') {
        throw new RuntimeException('Stripe connection error: ' . $err);
    }
    $body = json_decode((string) $resp, true);
    if (!is_array($body)) $body = [];
    return [$code, $body];
}

// ── Create a PaymentIntent with automatic payment methods ───
// automatic_payment_methods surfaces every method enabled in the Stripe
// dashboard (card, Klarna, Clearpay, PayPal, …) with no per-brand code.
// $metadata links the intent back to our DB record for finalization.
function stripeCreatePaymentIntent(int $amountPence, string $currency, array $metadata = [], ?string $idempotencyKey = null): array {
    $params = [
        'amount'                              => $amountPence,
        'currency'                            => strtolower($currency),
        'automatic_payment_methods[enabled]'  => 'true',
    ];
    foreach ($metadata as $k => $v) {
        $params["metadata[$k]"] = (string) $v;
    }
    $extra = [];
    if ($idempotencyKey !== null && $idempotencyKey !== '') {
        $extra[] = 'Idempotency-Key: ' . $idempotencyKey;
    }
    [$code, $intent] = stripeRequest('POST', '/v1/payment_intents', $params, $extra);
    if ($code >= 400 || !empty($intent['error'])) {
        $msg = $intent['error']['message'] ?? 'Unknown Stripe error';
        error_log('Stripe create PI error: ' . $msg);
        throw new RuntimeException($msg);
    }
    return $intent;
}

// ── Retrieve a PaymentIntent (to verify status server-side) ─
function stripeRetrievePaymentIntent(string $piId): array {
    [$code, $intent] = stripeRequest('GET', '/v1/payment_intents/' . urlencode($piId));
    if ($code >= 400 || !empty($intent['error'])) {
        $msg = $intent['error']['message'] ?? 'Unknown Stripe error';
        error_log('Stripe retrieve PI error: ' . $msg);
        throw new RuntimeException($msg);
    }
    return $intent;
}

// ── Verify a webhook signature (no SDK) ─────────────────────
// Implements Stripe's scheme: signed_payload = "{t}.{raw body}",
// HMAC-SHA256 with the endpoint secret, constant-time compared to any v1.
function stripeVerifyWebhook(string $payload, string $sigHeader, string $secret, int $tolerance = 300): array {
    if ($secret === '') {
        throw new RuntimeException('Webhook secret not configured');
    }
    $parts = [];
    foreach (explode(',', $sigHeader) as $kv) {
        $pair = explode('=', trim($kv), 2);
        if (count($pair) === 2) $parts[$pair[0]][] = $pair[1];
    }
    $t    = $parts['t'][0]  ?? null;
    $sigs = $parts['v1']    ?? [];
    if ($t === null || !$sigs) {
        throw new RuntimeException('Invalid Stripe-Signature header');
    }
    $expected = hash_hmac('sha256', $t . '.' . $payload, $secret);
    $match    = false;
    foreach ($sigs as $sig) {
        if (hash_equals($expected, $sig)) { $match = true; break; }
    }
    if (!$match) {
        throw new RuntimeException('Signature verification failed');
    }
    if ($tolerance > 0 && abs(time() - (int) $t) > $tolerance) {
        throw new RuntimeException('Timestamp outside tolerance');
    }
    $event = json_decode($payload, true);
    if (!is_array($event)) {
        throw new RuntimeException('Invalid webhook JSON payload');
    }
    return $event;
}

// ============================================================
//  FINALIZATION — the single source of truth for "mark paid"
// ============================================================

// Route a succeeded PaymentIntent to the correct finalizer using its
// metadata['kind']. Idempotent. $confirmedBy is 'stripe_webhook' or 'return_page'.
function finalizeStripePayment(PDO $db, array $intent, string $confirmedBy): array {
    $piId   = (string) ($intent['id'] ?? '');
    $status = (string) ($intent['status'] ?? '');
    $meta   = $intent['metadata'] ?? [];
    $kind   = (string) ($meta['kind'] ?? '');

    if ($status !== 'succeeded') {
        // Some redirect methods settle asynchronously ('processing'); nothing
        // to do yet — the webhook will fire again on success.
        return ['finalized' => false, 'status' => $status, 'kind' => $kind];
    }
    switch ($kind) {
        case 'cart':        return finalizeCartPayment($db, $piId, $meta, $confirmedBy);
        case 'pay-booking': return finalizePayBookingPayment($db, $piId, $meta, $confirmedBy);
        case 'order':       return finalizeOrderPayment($db, $piId, $meta, $confirmedBy);
        default:
            error_log("finalizeStripePayment: unknown/empty kind for PI {$piId}");
            return ['finalized' => false, 'reason' => 'unknown_kind', 'kind' => $kind];
    }
}

// ── Booking cart (booking.php) ──────────────────────────────
function finalizeCartPayment(PDO $db, string $piId, array $meta, string $confirmedBy): array {
    $groupRef = (string) ($meta['group_ref'] ?? '');
    if ($groupRef === '') return ['finalized' => false, 'reason' => 'no_group_ref'];

    $transitioned = false;
    $customerId   = 0;
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("SELECT id, customer_id, deposit_paid FROM bookings WHERE cart_group_ref = ? FOR UPDATE");
        $stmt->execute([$groupRef]);
        $rows = $stmt->fetchAll();
        if (!$rows) { $db->rollBack(); return ['finalized' => false, 'reason' => 'not_found']; }

        $customerId = (int) $rows[0]['customer_id'];
        $unpaid     = array_filter($rows, fn($r) => !$r['deposit_paid']);

        if ($unpaid) {
            $transitioned = true;
            $db->prepare("UPDATE bookings SET deposit_paid = 1 WHERE cart_group_ref = ?")->execute([$groupRef]);
            $db->prepare("
                UPDATE payments SET status = 'succeeded', confirmed_by = ?, confirmed_at = NOW()
                WHERE stripe_id = ? AND type = 'deposit'
            ")->execute([$confirmedBy, $piId]);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('finalizeCartPayment error: ' . $e->getMessage());
        throw $e;
    }

    $refs = [];
    if ($transitioned) {
        // Emails — non-fatal; money is already captured and rows are committed.
        try {
            require_once __DIR__ . '/mailer.php';
            $payerStmt = $db->prepare("SELECT name, email, phone FROM customers WHERE id = ?");
            $payerStmt->execute([$customerId]);
            $payer = $payerStmt->fetch() ?: [];

            $eStmt = $db->prepare("
                SELECT b.booking_ref, b.guest_name, b.booked_date, b.booked_time,
                       b.total_price, b.deposit_amount, b.remaining_balance,
                       s.name AS service_name, sv.variant_name
                FROM bookings b
                JOIN services s ON s.id = b.service_id
                LEFT JOIN service_variants sv ON sv.id = b.variant_id
                WHERE b.cart_group_ref = ?
                ORDER BY b.booked_date ASC, b.booked_time ASC
            ");
            $eStmt->execute([$groupRef]);
            $items = $eStmt->fetchAll();
            $refs  = array_column($items, 'booking_ref');
            if ($payer && $items) {
                emailCartConfirmation($payer, $items, $groupRef, 'stripe');
                emailAdminNewCartBooking($payer, $items, $groupRef);
            }
        } catch (Throwable $e) {
            error_log('finalizeCartPayment email error (non-fatal): ' . $e->getMessage());
        }
    } else {
        $r = $db->prepare("SELECT booking_ref FROM bookings WHERE cart_group_ref = ? ORDER BY booked_date, booked_time");
        $r->execute([$groupRef]);
        $refs = array_column($r->fetchAll(), 'booking_ref');
    }
    return ['finalized' => true, 'already' => !$transitioned, 'group_ref' => $groupRef, 'refs' => $refs];
}

// ── Pay-a-deposit link (pay.php) ────────────────────────────
function finalizePayBookingPayment(PDO $db, string $piId, array $meta, string $confirmedBy): array {
    $bookingId = (int) ($meta['booking_id'] ?? 0);
    if ($bookingId <= 0) return ['finalized' => false, 'reason' => 'no_booking_id'];

    $transitioned = false;
    $bk = null;
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("
            SELECT b.*, c.name AS c_name, c.email AS c_email, s.name AS s_name
            FROM bookings b
            JOIN customers c ON c.id = b.customer_id
            JOIN services  s ON s.id = b.service_id
            WHERE b.id = ? FOR UPDATE
        ");
        $stmt->execute([$bookingId]);
        $bk = $stmt->fetch();
        if (!$bk) { $db->rollBack(); return ['finalized' => false, 'reason' => 'not_found']; }

        if (!$bk['deposit_paid']) {
            $transitioned = true;
            $db->prepare("
                UPDATE bookings
                SET deposit_paid   = 1,
                    payment_token  = NULL,
                    payment_method = 'stripe',
                    status         = IF(status = 'pending', 'confirmed', status)
                WHERE id = ?
            ")->execute([$bookingId]);

            $hasPay = (int) $db->query("SELECT COUNT(*) FROM payments WHERE booking_id={$bookingId} AND type='deposit'")->fetchColumn();
            if ($hasPay) {
                $db->prepare("
                    UPDATE payments
                    SET stripe_id = ?, status = 'succeeded', method = 'stripe',
                        confirmed_by = ?, confirmed_at = NOW()
                    WHERE booking_id = ? AND type = 'deposit'
                ")->execute([$piId, $confirmedBy, $bookingId]);
            } else {
                $db->prepare("
                    INSERT INTO payments (booking_id, stripe_id, amount, currency, type, method, status, confirmed_by, confirmed_at)
                    VALUES (?,?,?, 'GBP', 'deposit', 'stripe', 'succeeded', ?, NOW())
                ")->execute([$bookingId, $piId, $bk['deposit_amount'], $confirmedBy]);
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('finalizePayBookingPayment error: ' . $e->getMessage());
        throw $e;
    }

    if ($transitioned) {
        try {
            require_once __DIR__ . '/mailer.php';
            $fresh    = $db->query("SELECT b.*, s.name AS s_name FROM bookings b JOIN services s ON s.id=b.service_id WHERE b.id={$bookingId}")->fetch();
            $customer = ['name' => $bk['c_name'], 'email' => $bk['c_email']];
            $service  = ['name' => $bk['s_name']];
            emailPaymentReceipt($fresh, $customer, $service, $piId);
            emailAdminNewBooking($fresh, $customer, $service);
        } catch (Throwable $e) {
            error_log('finalizePayBookingPayment email error (non-fatal): ' . $e->getMessage());
        }
    }
    return ['finalized' => true, 'already' => !$transitioned, 'ref' => $bk['booking_ref']];
}

// ── Shop order (checkout.php) ───────────────────────────────
function finalizeOrderPayment(PDO $db, string $piId, array $meta, string $confirmedBy): array {
    $orderId = (int) ($meta['order_id'] ?? 0);
    if ($orderId <= 0) return ['finalized' => false, 'reason' => 'no_order_id'];

    $transitioned = false;
    $order = null;
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("SELECT * FROM orders WHERE id = ? FOR UPDATE");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        if (!$order) { $db->rollBack(); return ['finalized' => false, 'reason' => 'not_found']; }

        if (($order['payment_status'] ?? '') !== 'paid') {
            $transitioned = true;
            $db->prepare("UPDATE orders SET payment_status = 'paid', stripe_payment_id = ? WHERE id = ?")
               ->execute([$piId, $orderId]);

            // Decrement stock + increment discount usage — done here (not at order
            // creation) so abandoned Stripe checkouts never consume stock/uses.
            // Decrement by the specific variant (see decrementProductStock).
            $items = $db->prepare("SELECT product_id, variant_id, quantity FROM order_items WHERE order_id = ?");
            $items->execute([$orderId]);
            foreach ($items->fetchAll() as $it) {
                decrementProductStock($db, (int) $it['product_id'], $it['variant_id'] !== null ? (int) $it['variant_id'] : null, (int) $it['quantity']);
            }
            if (!empty($order['discount_code_id'])) {
                $db->prepare("UPDATE discount_codes SET uses_count = uses_count + 1 WHERE id = ?")
                   ->execute([(int) $order['discount_code_id']]);
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('finalizeOrderPayment error: ' . $e->getMessage());
        throw $e;
    }

    if ($transitioned) {
        try {
            require_once __DIR__ . '/mailer.php';
            $cust = $db->prepare("SELECT name, email FROM customers WHERE id = ?");
            $cust->execute([(int) $order['customer_id']]);
            $customer = $cust->fetch() ?: ['name' => '', 'email' => ''];

            $eItems = $db->prepare("
                SELECT p.name AS name, oi.quantity, oi.price_charged
                FROM order_items oi
                JOIN products p ON p.id = oi.product_id
                WHERE oi.order_id = ?
            ");
            $eItems->execute([$orderId]);
            $items = $eItems->fetchAll();

            $fresh = $db->query("SELECT * FROM orders WHERE id={$orderId}")->fetch();
            if ($customer['email']) emailOrderConfirmation($fresh, $customer, $items);
            emailAdminNewOrder($fresh, $customer, $items);
        } catch (Throwable $e) {
            error_log('finalizeOrderPayment email error (non-fatal): ' . $e->getMessage());
        }
    }
    return ['finalized' => true, 'already' => !$transitioned, 'ref' => $order['order_ref']];
}
