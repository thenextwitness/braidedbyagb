<?php
// ============================================================
// BraidedbyAGB — Temporary Landing Page
// This file sits at the root and routes to public/index.php
// Replace with full homepage once Phase 2 is complete
// ============================================================

// If public/index.php exists, forward to it
if (file_exists(__DIR__ . '/public/index.php')) {
    require_once __DIR__ . '/public/index.php';
    exit;
}

// Otherwise show a holding page
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>BraidedbyAGB — Coming Soon</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700;900&family=Lato:ital,wght@0,300;1,300&display=swap" rel="stylesheet">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #4B0082 0%, #2a0050 50%, #800080 100%);
    font-family: 'Lato', sans-serif;
    color: #fff;
    text-align: center;
    padding: 2rem;
  }
  .container { max-width: 560px; }
  .logo {
    font-family: 'Montserrat', sans-serif;
    font-size: clamp(2rem, 8vw, 3.5rem);
    font-weight: 900;
    color: #ffffff;
    letter-spacing: 1px;
    margin-bottom: 0.5rem;
  }
  .tagline {
    font-family: 'Montserrat', sans-serif;
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.25em;
    text-transform: uppercase;
    color: #D4AF37;
    margin-bottom: 2.5rem;
  }
  .divider {
    width: 60px;
    height: 2px;
    background: #D4AF37;
    margin: 0 auto 2rem;
  }
  .message {
    font-size: 1.1rem;
    font-weight: 300;
    line-height: 1.8;
    color: rgba(255,255,255,0.85);
    margin-bottom: 2.5rem;
    font-style: italic;
  }
  .phone {
    font-family: 'Montserrat', sans-serif;
    font-size: 1.4rem;
    font-weight: 700;
    color: #ffffff;
    margin-bottom: 0.5rem;
  }
  .cta {
    font-family: 'Montserrat', sans-serif;
    font-size: 0.7rem;
    font-weight: 700;
    letter-spacing: 0.2em;
    text-transform: uppercase;
    color: #D4AF37;
  }
  .whatsapp-btn {
    display: inline-block;
    margin-top: 1.5rem;
    padding: 14px 32px;
    background: #25D366;
    color: #fff;
    font-family: 'Montserrat', sans-serif;
    font-size: 0.8rem;
    font-weight: 700;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    text-decoration: none;
    border-radius: 2px;
  }
  .location {
    margin-top: 2rem;
    font-size: 0.85rem;
    color: rgba(255,255,255,0.5);
    letter-spacing: 0.1em;
    text-transform: uppercase;
  }
</style>
</head>
<body>
<div class="container">
  <div class="logo">BraidedbyAGB</div>
  <div class="tagline">African Hair Braiding Specialist</div>
  <div class="divider"></div>
  <p class="message">
    Specializing in flawless, long-lasting braids — authentic African hair braiding designs just for you.
    <br><br>
    Our new website is launching very soon. In the meantime, get in touch to book your appointment.
  </p>
  <div class="phone">07769 064 971</div>
  <div class="cta">Book Your Appointment!</div>
  <a href="https://wa.me/447769064971?text=Hi%2C%20I%27d%20like%20to%20book%20an%20appointment%20with%20BraidedbyAGB"
     class="whatsapp-btn">
    💬 WhatsApp Us
  </a>
  <div class="location">Farnborough, Hampshire, UK</div>
</div>
</body>
</html>
