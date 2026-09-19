<?php
// ============================================================
// BraidedbyAGB — Account navigation tabs
// FILE: /includes/account-tabs.php
// Set $activeTab (e.g. '/account/bookings') before including.
// ============================================================
$activeTab = $activeTab ?? '';
$accountTabs = [
    '/account'          => 'Dashboard',
    '/account/bookings' => 'Bookings',
    '/account/learning' => 'Learning',
    '/account/orders'   => 'Orders',
    '/account/payments' => 'Payments',
    '/account/profile'  => 'Profile',
];
?>
<nav class="account-tabs">
  <?php foreach ($accountTabs as $href => $label): ?>
    <a class="account-tab <?= $activeTab === $href ? 'active' : '' ?>" href="<?= $href ?>"><?= $label ?></a>
  <?php endforeach; ?>
  <a class="account-tab" href="/logout">Sign out</a>
</nav>
