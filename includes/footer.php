<?php
// ============================================================
// BraidedbyAGB — Global Footer
// FILE: /includes/footer.php
// ============================================================
?>
<footer class="site-footer">

  <!-- Top footer bar -->
  <div class="footer-cta-bar">
    <div class="container">
      <div class="footer-cta-inner">
        <div class="footer-cta-text">
          <span class="font-accent">"Get the Look You Deserve!"</span>
        </div>
        <div class="footer-cta-action">
          <a href="tel:07769064971" class="footer-phone">07769 064 971</a>
          <a href="/booking" class="btn btn-gold">Book Your Appointment!</a>
        </div>
      </div>
    </div>
  </div>

  <!-- Main footer -->
  <div class="footer-main">
    <div class="container">
      <div class="footer-grid">

        <!-- Brand column -->
        <div class="footer-brand">
          <a href="/" class="footer-logo">BraidedbyAGB</a>
          <p class="footer-tagline">African Hair Braiding Specialist</p>
          <p class="footer-desc">Specializing in flawless, long-lasting braids — authentic African hair braiding designs just for you. Farnborough, Hampshire, UK.</p>
          <div class="footer-socials">
            <a href="https://instagram.com/BraidedbyAGB" target="_blank" rel="noopener" class="social-link" aria-label="Instagram">
              <svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>
            </a>
            <?php if (($tiktokUrl = getSetting("tiktok_url", ""))): ?>
            <a href="<?= htmlspecialchars($tiktokUrl) ?>" target="_blank" rel="noopener" class="social-link" aria-label="TikTok">
              <svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20"><path d="M19.59 6.69a4.83 4.83 0 01-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 01-2.88 2.5 2.89 2.89 0 01-2.89-2.89 2.89 2.89 0 012.89-2.89c.28 0 .54.04.79.1V9.01a6.33 6.33 0 00-.79-.05 6.34 6.34 0 00-6.34 6.34 6.34 6.34 0 006.34 6.34 6.34 6.34 0 006.33-6.34V8.73a8.2 8.2 0 004.79 1.52V6.81a4.85 4.85 0 01-1.02-.12z"/></svg>
            </a>
            <?php endif; ?>
            <?php if (($fbUrl = getSetting("facebook_url", ""))): ?>
            <a href="<?= htmlspecialchars($fbUrl) ?>" target="_blank" rel="noopener" class="social-link" aria-label="Facebook">
              <svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
            </a>
            <?php endif; ?>
            <a href="https://wa.me/447769064971" target="_blank" rel="noopener" class="social-link" aria-label="WhatsApp">
              <svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.134.558 4.135 1.532 5.875L0 24l6.318-1.508A11.95 11.95 0 0012 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 21.818a9.818 9.818 0 01-5.002-1.368l-.36-.213-3.731.89.933-3.618-.234-.372A9.818 9.818 0 1112 21.818z"/></svg>
            </a>
          </div>
        </div>

        <!-- Services column -->
        <div class="footer-col">
          <h4 class="footer-col-title">Our Services</h4>
          <ul class="footer-links">
            <li><a href="/services/box-braids">Box Braids</a></li>
            <li><a href="/services/knotless-braids">Knotless Braids</a></li>
            <li><a href="/services/feed-in-braids">Feed-In Braids</a></li>
            <li><a href="/services/cornrows-simple">Cornrows</a></li>
            <li><a href="/services/twists">Twists (Passion/Marley)</a></li>
            <li><a href="/services/kids-styles">Kids Styles</a></li>
            <li><a href="/services/starter-locs">Starter Locs <span class="badge-new-sm">New</span></a></li>
            <li><a href="/services/loc-retwists">Loc Retwists <span class="badge-new-sm">New</span></a></li>
          </ul>
        </div>

        <!-- Quick links column -->
        <div class="footer-col">
          <h4 class="footer-col-title">Quick Links</h4>
          <ul class="footer-links">
            <li><a href="/booking">Book an Appointment</a></li>
            <li><a href="/shop">Shop Extensions</a></li>
            <li><a href="/about">About Us</a></li>
            <li><a href="/contact">Contact</a></li>
            <li><a href="/policies">Policies</a></li>
          </ul>
          <h4 class="footer-col-title" style="margin-top:1.5rem;">Contact</h4>
          <ul class="footer-links">
            <li><a href="tel:07769064971">07769 064 971</a></li>
            <li><a href="mailto:hello@braidedbyagb.co.uk">hello@braidedbyagb.co.uk</a></li>
            <li><span>Farnborough, Hampshire, UK</span></li>
          </ul>
        </div>

      </div>
    </div>
  </div>

  <!-- Accepted payment methods -->
  <style>
    .footer-payments{border-top:1px solid rgba(255,255,255,.08);padding:20px 0}
    .footer-payments .container{display:flex;align-items:center;justify-content:center;gap:16px;flex-wrap:wrap}
    .footer-payments-label{color:rgba(255,255,255,.6);font-size:.78rem;letter-spacing:.05em;text-transform:uppercase}
    .payment-logos{display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:center}
    .pay-chip{height:32px;min-width:52px;padding:0 10px;border-radius:6px;background:#fff;display:inline-flex;align-items:center;justify-content:center;box-shadow:0 1px 3px rgba(0,0,0,.25);white-space:nowrap}
    .pay-visa{color:#1434CB;font:italic 800 16px/1 Arial,Helvetica,sans-serif;letter-spacing:.5px}
    .pay-paypal{font:italic 800 15px/1 Arial,Helvetica,sans-serif}
    .pay-paypal .p1{color:#003087}.pay-paypal .p2{color:#009cde}
    .pay-klarna{background:#FFB3C7;color:#0A0A0A;font:700 15px/1 Arial,Helvetica,sans-serif}
    .pay-clearpay{background:#B2FCE4;color:#0A0A0A;font:700 14px/1 Arial,Helvetica,sans-serif}
    .pay-mc{padding:0 8px}
  </style>
  <div class="footer-payments">
    <div class="container">
      <span class="footer-payments-label">Secure payments accepted</span>
      <div class="payment-logos">
        <span class="pay-chip pay-visa" role="img" aria-label="Visa">VISA</span>
        <span class="pay-chip pay-mc" role="img" aria-label="Mastercard">
          <svg width="36" height="22" viewBox="0 0 40 24" aria-hidden="true">
            <circle cx="15" cy="12" r="9" fill="#EB001B"/>
            <circle cx="25" cy="12" r="9" fill="#F79E1B"/>
            <path d="M20 5.4a9 9 0 0 0 0 13.2 9 9 0 0 0 0-13.2z" fill="#FF5F00"/>
          </svg>
        </span>
        <span class="pay-chip pay-paypal" role="img" aria-label="PayPal"><span class="p1">Pay</span><span class="p2">Pal</span></span>
        <span class="pay-chip pay-klarna" role="img" aria-label="Klarna">Klarna</span>
        <span class="pay-chip pay-clearpay" role="img" aria-label="Clearpay">Clearpay</span>
      </div>
    </div>
  </div>

  <!-- Bottom bar -->
  <div class="footer-bottom">
    <div class="container">
      <div class="footer-bottom-inner">
        <p>© <?= date('Y') ?> BraidedbyAGB. All rights reserved.</p>
        <p><a href="/policies">Privacy Policy</a> · <a href="/policies#cancellation">Cancellation Policy</a> · <a href="/policies#refund">Refund Policy</a></p>
      </div>
    </div>
  </div>

</footer>

<!-- Floating WhatsApp Button -->
<a href="https://wa.me/447769064971?text=Hi%2C%20I%27d%20like%20to%20book%20an%20appointment%20at%20BraidedbyAGB"
   class="whatsapp-float"
   target="_blank"
   rel="noopener"
   aria-label="Chat on WhatsApp">
  <svg viewBox="0 0 24 24" fill="white" width="28" height="28"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.134.558 4.135 1.532 5.875L0 24l6.318-1.508A11.95 11.95 0 0012 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 21.818a9.818 9.818 0 01-5.002-1.368l-.36-.213-3.731.89.933-3.618-.234-.372A9.818 9.818 0 1112 21.818z"/></svg>
</a>

<!-- Toast Notification -->
<div class="toast" id="toast" role="alert" aria-live="polite"></div>

<!-- ── AI Chat Widget ──────────────────────────────────── -->
<link rel="stylesheet" href="/assets/css/chat-widget.css">
<script src="/assets/js/chat-widget.js" defer></script>
