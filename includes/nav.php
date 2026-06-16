<?php
// ============================================================
// BraidedbyAGB — Global Navigation
// FILE: /includes/nav.php
// ============================================================
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
?>
<header class="site-header" id="site-header">
  <div class="header-inner">

    <!-- Logo -->
    <a href="/" class="header-logo" aria-label="BraidedbyAGB Home">
      <div class="logo-icon">
        <!-- Stylized woman with orbital ring — SVG representation of brand logo -->
        <svg viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
          <ellipse cx="24" cy="24" rx="22" ry="10" stroke="#D4AF37" stroke-width="1.5" fill="none" transform="rotate(-20 24 24)" opacity="0.9"/>
          <circle cx="24" cy="13" r="6" fill="#800080"/>
          <path d="M16 22 Q14 34 18 38 Q24 42 30 38 Q34 34 32 22" fill="#800080"/>
          <path d="M20 38 Q18 44 16 46" stroke="#800080" stroke-width="3" stroke-linecap="round"/>
          <path d="M28 38 Q28 43 26 46" stroke="#800080" stroke-width="2.5" stroke-linecap="round"/>
          <path d="M26 46 Q28 47 32 46" stroke="#800080" stroke-width="2" stroke-linecap="round"/>
          <circle cx="6" cy="28" r="2" fill="#D4AF37" opacity="0.7"/>
        </svg>
      </div>
      <div class="logo-text">
        <span class="logo-brand">BraidedbyAGB</span>
        <span class="logo-tagline">African Hair Braiding Specialist</span>
      </div>
    </a>

    <!-- Desktop Navigation -->
    <nav class="main-nav" aria-label="Main navigation">
      <ul class="nav-list">
        <li><a href="/services" class="nav-link <?= $currentPage === 'services' ? 'active' : '' ?>">Services</a></li>
        <li><a href="/shop" class="nav-link <?= $currentPage === 'shop' ? 'active' : '' ?>">Shop</a></li>
        <li><a href="/about" class="nav-link <?= $currentPage === 'about' ? 'active' : '' ?>">About</a></li>
        <li><a href="/contact" class="nav-link <?= $currentPage === 'contact' ? 'active' : '' ?>">Contact</a></li>
      </ul>
    </nav>

    <!-- Header Actions -->
    <div class="header-actions">
      <a href="https://instagram.com/BraidedbyAGB" target="_blank" rel="noopener" class="header-social" aria-label="Instagram">
        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>
      </a>
      <a href="https://wa.me/447769064971" target="_blank" rel="noopener" class="header-social" aria-label="WhatsApp">
        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.134.558 4.135 1.532 5.875L0 24l6.318-1.508A11.95 11.95 0 0012 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 21.818a9.818 9.818 0 01-5.002-1.368l-.36-.213-3.731.89.933-3.618-.234-.372A9.818 9.818 0 1112 21.818z"/></svg>
      </a>
      <a href="/booking" class="btn btn-primary btn-nav">Book Now</a>
      <button class="mobile-toggle" id="mobileToggle" aria-label="Toggle menu" aria-expanded="false">
        <span></span><span></span><span></span>
      </button>
    </div>
  </div>

  <!-- Mobile Menu -->
  <nav class="mobile-nav" id="mobileNav" aria-hidden="true">
    <ul class="mobile-nav-list">
      <li><a href="/services" class="mobile-nav-link">Services</a></li>
      <li><a href="/shop" class="mobile-nav-link">Shop Extensions</a></li>
      <li><a href="/about" class="mobile-nav-link">About</a></li>
      <li><a href="/contact" class="mobile-nav-link">Contact</a></li>
      <li><a href="/booking" class="mobile-nav-link mobile-cta">Book Your Appointment</a></li>
    </ul>
    <div class="mobile-socials">
      <a href="https://instagram.com/BraidedbyAGB" target="_blank" rel="noopener">Instagram</a>
      <span>·</span>
      <a href="https://wa.me/447769064971" target="_blank" rel="noopener">WhatsApp</a>
    </div>
  </nav>
</header>
