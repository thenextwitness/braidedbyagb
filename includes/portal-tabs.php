<?php
// ============================================================
// BraidedbyAGB — Stylist portal navigation tabs
// FILE: /includes/portal-tabs.php
// Set $activeTab (e.g. '/portal/earnings') before including.
// Mirrors account-tabs.php but for the stylist portal.
// ============================================================
$activeTab = $activeTab ?? '';
$portalTabs = [
    '/portal'          => 'Dashboard',
    '/portal/earnings' => 'Earnings',
];
?>
<nav class="account-tabs">
  <?php foreach ($portalTabs as $href => $label): ?>
    <a class="account-tab <?= $activeTab === $href ? 'active' : '' ?>" href="<?= $href ?>"><?= $label ?></a>
  <?php endforeach; ?>
  <a class="account-tab" href="/logout">Sign out</a>
</nav>
