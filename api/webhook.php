<?php
// ============================================================
// BraidedbyAGB — Stripe Webhook
// FILE: /api/webhook.php   (route: /api/webhook)
//
// Authoritative payment finalizer. Fires on payment_intent.succeeded (and
// async_payment_succeeded for methods that settle later) and runs the SAME
// idempotent finalize as the customer return page — so a booking/order is
// confirmed even if the customer closes the tab before returning.
//
// SETUP (once, in the Stripe dashboard → Developers → Webhooks):
//   1. Add endpoint:  https://braidedbyagb.co.uk/api/webhook.php
//      (use the direct .php file — the /api/webhook rewrite lives in .htaccess,
//       which cPanel does NOT auto-deploy, so the file URL is the safe choice.)
//   2. Select events: payment_intent.succeeded
//                     payment_intent.async_payment_succeeded  (optional)
//   3. Copy the "Signing secret" (whsec_...) and paste it into the SERVER's
//      config/database.php as STRIPE_WEBHOOK_SECRET (config is not deployed —
//      edit it directly on the server).
// ============================================================

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/stripe.php';

// Never leak internals; always answer Stripe with a bare status code.
$secret = defined('STRIPE_WEBHOOK_SECRET') ? STRIPE_WEBHOOK_SECRET : '';
if ($secret === '' || $secret === 'whsec_REPLACE_ME') {
    http_response_code(500);
    error_log('Stripe webhook: STRIPE_WEBHOOK_SECRET not configured.');
    echo 'not configured';
    exit;
}

$payload   = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

try {
    $event = stripeVerifyWebhook($payload, $sigHeader, $secret);
} catch (Throwable $e) {
    http_response_code(400);
    error_log('Stripe webhook signature error: ' . $e->getMessage());
    echo 'invalid signature';
    exit;
}

$type = $event['type'] ?? '';

// Only payment-success events do anything; everything else is acknowledged so
// Stripe stops retrying.
if (in_array($type, ['payment_intent.succeeded', 'payment_intent.async_payment_succeeded'], true)) {
    $intent = $event['data']['object'] ?? [];
    try {
        finalizeStripePayment(getDB(), $intent, 'stripe_webhook');
    } catch (Throwable $e) {
        // Return 500 so Stripe retries later (e.g. transient DB failure).
        http_response_code(500);
        error_log('Stripe webhook finalize error: ' . $e->getMessage());
        echo 'retry';
        exit;
    }
}

http_response_code(200);
echo 'ok';
