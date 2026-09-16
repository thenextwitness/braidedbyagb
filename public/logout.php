<?php
// ============================================================
// BraidedbyAGB — Portal sign-out
// FILE: /public/logout.php  →  /logout
// Clears only the portal session keys + remember token; an admin sharing the
// same browser stays signed in.
// ============================================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/portal-auth.php';

portalLogout();
header('Location: /login');
exit;
