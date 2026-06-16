<?php
// ============================================================
// BraidedbyAGB — Admin Logout
// FILE: /admin/logout.php
// ============================================================
session_start();
session_unset();
session_destroy();
header('Location: /admin/login');
exit;
