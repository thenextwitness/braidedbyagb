<?php
// ============================================================
// BraidedbyAGB — Portal dispatcher (PWA start_url)
// FILE: /public/app.php  →  /app
// Sends a signed-in user to the dashboard for their type; otherwise to sign-in.
// This is the PWA's start_url so the installed app always opens the right place.
// ============================================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/portal-auth.php';

portalResumeFromRemember();

if (!isPortalUser()) { header('Location: /login'); exit; }

switch (currentPortalType()) {
    case 'stylist': header('Location: /portal'); break;   // built in Phase C
    case 'client':
    default:        header('Location: /account'); break;
}
exit;
