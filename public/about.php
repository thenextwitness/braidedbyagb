<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" type="image/png" href="/favicon-96x96.png" sizes="96x96">
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <link rel="shortcut icon" href="/favicon.ico">
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
  <link rel="manifest" href="/site.webmanifest">
  <title>About — BraidedbyAGB · African Hair Braiding Specialist</title>
  <meta name="description" content="The story behind BraidedbyAGB — a passionate African hair braiding specialist in Farnborough, UK.">
  <link rel="canonical" href="https://braidedbyagb.co.uk/about">
  <meta property="og:type" content="website">
  <meta property="og:url" content="https://braidedbyagb.co.uk/about">
  <meta property="og:title" content="About — BraidedbyAGB · African Hair Braiding Specialist">
  <meta property="og:description" content="The story behind BraidedbyAGB — a passionate African hair braiding specialist in Farnborough, UK.">
  <meta property="og:image" content="https://braidedbyagb.co.uk/assets/images/founder.png">
  <meta name="twitter:card" content="summary_large_image">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800;900&family=Lato:ital,wght@0,300;0,400;0,700;1,300&family=Cormorant+Garamond:ital,wght@1,600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/brand.css">
<?php include __DIR__ . '/../includes/brand-styles.php'; ?>
  <link rel="stylesheet" href="/assets/css/global.css">
  <link rel="stylesheet" href="/assets/css/pages.css">
<?php include __DIR__ . '/../includes/gtag.php'; ?>
</head>
<body>
<?php include __DIR__ . '/../includes/nav.php'; ?>
<main class="page-content">

  <section class="page-hero">
    <div class="page-hero-bg"></div>
    <div class="container">
      <div class="page-hero-content" data-animate="fadeUp">
        <p class="section-label" style="justify-content:center;color:var(--color-gold)"><span>Our Story</span></p>
        <h1 class="page-hero-title">About BraidedbyAGB</h1>
        <p class="page-hero-subtitle">Authentic artistry rooted in culture. Premium results rooted in passion.</p>
      </div>
    </div>
  </section>

  <!-- Founder Story -->
  <section style="background:var(--color-white)">
    <div class="container">
      <div class="about-story-grid">
        <div data-animate="fadeLeft">
          <div class="founder-img-frame" style="max-width:480px">
            <img src="/assets/images/founder.png"
                 alt="BraidedbyAGB founder"
                 class="founder-img"
                 onerror="this.parentElement.classList.add('no-img')">
          </div>
        </div>
        <div class="about-story-text" data-animate="fadeRight">
          <p class="section-label"><span>The Founder</span></p>
          <h2 class="section-title">Glory — The Passion Behind Every Braid</h2>
          <p>
            Welcome to BraidedbyAGB — where every appointment is more than just a hair service. It's a celebration of culture, identity, and self-expression rooted in the rich tradition of African hair braiding.
          </p>
          <p>
            Based in Farnborough, Hampshire, I built BraidedbyAGB from a deep love for the craft. Having grown up surrounded by the beauty and artistry of African braiding traditions, I wanted to bring that same excellence — combined with modern technique and genuine care — to every client who sits in my chair.
          </p>
          <p>
            My studio is a private, welcoming space where you will always feel seen, heard and valued. Whether it's your first braid appointment or your fiftieth, I bring the same level of precision and passion to every single style.
          </p>
          <p>
            BraidedbyAGB is not just a business — it's a promise. A promise of flawless, long-lasting styles that honour your hair and reflect who you are.
          </p>
          <a href="/booking" class="btn btn-primary btn-lg" style="margin-top:var(--space-4)">Book Your Appointment</a>
        </div>
      </div>
    </div>
  </section>

  <!-- Brand Values -->
  <section class="section" style="background:var(--color-bg-light)">
    <div class="container">
      <div class="section-header text-center" data-animate="fadeUp">
        <p class="section-label" style="justify-content:center"><span>What We Stand For</span></p>
        <h2 class="section-title">Our Values</h2>
      </div>
      <div class="about-values-grid" style="margin-top:var(--space-10)" data-animate="stagger">
        <?php
        $values = [
          ['icon'=>'✦', 'title'=>'Authentic', 'desc'=>'Deep cultural understanding and respect for African hair braiding traditions. Every style is crafted with knowledge, not just technique.'],
          ['icon'=>'💜', 'title'=>'Specialist', 'desc'=>'We don\'t do everything — we do one thing exceptionally well. African hair braiding is our sole focus and our highest skill.'],
          ['icon'=>'✨', 'title'=>'Premium', 'desc'=>'From the products we use to the care we take, every detail is chosen with your hair\'s health and beauty as the priority.'],
          ['icon'=>'🤝', 'title'=>'Welcoming', 'desc'=>'A warm, safe and inclusive space where every client is treated with kindness, patience and professionalism.'],
        ];
        foreach ($values as $i => $v):
        ?>
        <div class="about-value-card" data-delay="<?= $i*100 ?>">
          <div class="about-value-icon"><?= $v['icon'] ?></div>
          <h3 class="about-value-title"><?= $v['title'] ?></h3>
          <p class="about-value-desc"><?= $v['desc'] ?></p>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <!-- Why choose us -->
  <section class="section" style="background:var(--color-white)">
    <div class="container">
      <div class="about-story-grid">
        <div class="about-story-text" data-animate="fadeLeft">
          <p class="section-label"><span>Why Choose BraidedbyAGB</span></p>
          <h2 class="section-title">Flawless Artistry,<br>Every Time</h2>
          <p>Choosing where to get your hair braided is a personal decision — and we don't take that trust lightly. Here's what sets BraidedbyAGB apart:</p>
          <div style="display:flex;flex-direction:column;gap:var(--space-4);margin:var(--space-6) 0">
            <?php
            $reasons = [
              ['icon'=>'🎯', 'title'=>'Specialist Focus', 'text'=>'100% dedicated to African hair braiding — no distractions, just excellence.'],
              ['icon'=>'🏠', 'title'=>'Private Studio', 'text'=>'A relaxed, one-on-one experience in a comfortable private home studio setting.'],
              ['icon'=>'⏱', 'title'=>'Respect for Your Time', 'text'=>'We run on schedule. Your appointment starts on time, every time.'],
              ['icon'=>'📱', 'title'=>'Easy Booking', 'text'=>'Book online in minutes. Pay your deposit securely. Receive reminders automatically.'],
              ['icon'=>'💅', 'title'=>'Quality Products', 'text'=>'We only use premium hair products and extensions, available for you to purchase directly.'],
            ];
            foreach ($reasons as $r):
            ?>
            <div style="display:flex;gap:var(--space-4);align-items:flex-start">
              <span style="font-size:1.5rem;flex-shrink:0"><?= $r['icon'] ?></span>
              <div>
                <h5 style="font-weight:700;color:var(--color-deep-purple);margin-bottom:3px"><?= $r['title'] ?></h5>
                <p style="font-size:var(--text-sm);color:var(--color-text-muted);line-height:1.5;margin:0"><?= $r['text'] ?></p>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <div data-animate="fadeRight">
          <div style="background:var(--gradient-hero);border-radius:var(--border-radius-xl);padding:var(--space-12);color:white;text-align:center">
            <p class="font-accent" style="font-size:2rem;color:var(--color-gold);display:block;margin-bottom:var(--space-4)">"Get the Look<br>You Deserve!"</p>
            <p style="color:rgba(255,255,255,0.75);line-height:1.7;margin-bottom:var(--space-8)">Specializing in flawless, long-lasting braids — authentic African hair braiding designs just for you.</p>
            <a href="/booking" class="btn btn-gold btn-lg">Book Now</a>
            <p style="margin-top:var(--space-5);font-family:var(--font-primary);font-weight:800;font-size:1.2rem">07769 064 971</p>
            <p style="font-size:var(--text-sm);color:rgba(255,255,255,0.5);margin-top:var(--space-1)">Farnborough, Hampshire, UK</p>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section class="cta-strip">
    <div class="container">
      <div class="cta-strip-inner">
        <h3 class="cta-strip-title">Ready to experience the BraidedbyAGB difference?</h3>
        <a href="/booking" class="btn btn-gold btn-lg">Book Your Appointment!</a>
      </div>
    </div>
  </section>

</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
<script src="/assets/js/main.js"></script>
</body>
</html>
