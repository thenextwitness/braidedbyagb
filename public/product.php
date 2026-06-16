<?php
// ============================================================
// BraidedbyAGB — Single Product Page
// FILE: /public/product.php
// ============================================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

$db   = getDB();
$slug = sanitize($_GET['slug'] ?? '');

if (!$slug) { header('Location: /shop'); exit; }

$stmt = $db->prepare("SELECT * FROM products WHERE slug = ? AND is_active = 1");
$stmt->execute([$slug]);
$product = $stmt->fetch();
if (!$product) { header('Location: /shop'); exit; }

// Fetch variants
$variants = $db->prepare("SELECT * FROM product_variants WHERE product_id = ? ORDER BY display_order ASC");
$variants->execute([$product['id']]);
$variants = $variants->fetchAll();

// Which services link to this product (for social proof)
$linkedServices = $db->prepare("
    SELECT s.name, s.slug FROM service_product_links spl
    JOIN services s ON s.id = spl.service_id
    WHERE spl.product_id = ? AND spl.is_active = 1 AND s.is_active = 1
");
$linkedServices->execute([$product['id']]);
$linkedServices = $linkedServices->fetchAll();

// Product reviews
$reviews = $db->prepare("
    SELECT r.*, c.name as client_name FROM reviews r
    JOIN customers c ON c.id = r.customer_id
    WHERE r.product_id = ? AND r.status = 'approved'
    ORDER BY r.is_featured DESC, r.approved_at DESC LIMIT 6
");
$reviews->execute([$product['id']]);
$reviews = $reviews->fetchAll();
$rating  = getAverageRating(null, (int)$product['id']);

$totalStock = array_sum(array_column($variants, 'stock_qty'));
$inStock    = $totalStock > 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($product['name']) ?> — BraidedbyAGB</title>
  <meta name="description" content="<?= htmlspecialchars(mb_strimwidth($product['description'] ?? '', 0, 160, '...')) ?>">
  <link rel="canonical" href="https://braidedbyagb.co.uk/shop/<?= htmlspecialchars($product['slug']) ?>">
  <meta property="og:type" content="product">
  <meta property="og:url" content="https://braidedbyagb.co.uk/shop/<?= htmlspecialchars($product['slug']) ?>">
  <meta property="og:title" content="<?= htmlspecialchars($product['name']) ?> — BraidedbyAGB">
  <meta property="og:description" content="<?= htmlspecialchars(mb_strimwidth($product['description'] ?? '', 0, 160, '...')) ?>">
  <meta property="og:image" content="<?= $product['image_url'] ? 'https://braidedbyagb.co.uk' . htmlspecialchars($product['image_url']) : 'https://braidedbyagb.co.uk/assets/images/braidedbyagblogo.png' ?>">
  <meta name="twitter:card" content="summary_large_image">
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "Product",
    "name": "<?= addslashes(htmlspecialchars_decode($product['name'])) ?>",
    "description": "<?= addslashes(htmlspecialchars_decode(mb_substr($product['description'] ?? '', 0, 200))) ?>",
    "image": "<?= $product['image_url'] ? 'https://braidedbyagb.co.uk' . htmlspecialchars($product['image_url']) : '' ?>",
    "brand": { "@type": "Brand", "name": "BraidedbyAGB" },
    "offers": {
      "@type": "Offer",
      "priceCurrency": "GBP",
      "price": "<?= number_format((float)$product['price'], 2) ?>",
      "availability": "<?= $inStock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock' ?>",
      "seller": { "@type": "Organization", "name": "BraidedbyAGB" }
    }
  }
  </script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800;900&family=Lato:ital,wght@0,300;0,400;0,700;1,300&family=Cormorant+Garamond:ital,wght@1,600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/brand.css">
<?php include __DIR__ . '/../includes/brand-styles.php'; ?>
  <link rel="stylesheet" href="/assets/css/global.css">
  <link rel="stylesheet" href="/assets/css/pages.css">
  <link rel="stylesheet" href="/assets/css/shop.css">
<?php include __DIR__ . '/../includes/gtag.php'; ?>
</head>
<body>
<?php include __DIR__ . '/../includes/nav.php'; ?>

<main class="page-content" style="background:var(--color-white)">
  <div class="container">

    <!-- Breadcrumb -->
    <nav class="breadcrumb" aria-label="Breadcrumb">
      <a href="/">Home</a>
      <span>›</span>
      <a href="/shop">Shop</a>
      <span>›</span>
      <span><?= htmlspecialchars($product['name']) ?></span>
    </nav>

    <!-- Product layout -->
    <div class="product-detail-layout">

      <!-- Images -->
      <div class="product-images" data-animate="fadeLeft">
        <div class="product-main-img-wrap" id="main-img-wrap">
          <?php if ($product['image_url']): ?>
            <img src="<?= htmlspecialchars($product['image_url']) ?>"
                 alt="<?= htmlspecialchars($product['name']) ?>"
                 class="product-main-img"
                 id="main-product-img">
          <?php else: ?>
            <div class="product-main-img-placeholder">🛍️</div>
          <?php endif; ?>
          <?php if ($product['free_gift']): ?>
            <span class="badge badge-gold product-detail-gift-badge">🎁 Free Gift Included</span>
          <?php endif; ?>
        </div>
      </div>

      <!-- Info -->
      <div class="product-info" data-animate="fadeRight">
        <?php if ($product['category']): ?>
          <p class="product-detail-category"><?= htmlspecialchars($product['category']) ?></p>
        <?php endif; ?>

        <h1 class="product-detail-name"><?= htmlspecialchars($product['name']) ?></h1>

        <!-- Rating -->
        <?php if ($rating['total'] > 0): ?>
        <div class="product-detail-rating">
          <?= renderStars($rating['average']) ?>
          <span class="rating-count">(<?= $rating['total'] ?> review<?= $rating['total'] !== 1 ? 's' : '' ?>)</span>
        </div>
        <?php endif; ?>

        <!-- Price -->
        <div class="product-detail-price">£<?= number_format((float)$product['price'], 2) ?></div>

        <!-- Description -->
        <?php if ($product['description']): ?>
          <p class="product-detail-desc"><?= nl2br(htmlspecialchars($product['description'])) ?></p>
        <?php endif; ?>

        <!-- Free gift info -->
        <?php if ($product['free_gift'] && $product['free_gift_desc']): ?>
          <div class="free-gift-notice">
            <span>🎁</span>
            <div>
              <strong>Free Gift Included</strong>
              <p><?= htmlspecialchars($product['free_gift_desc']) ?></p>
            </div>
          </div>
        <?php endif; ?>

        <!-- Variant selector -->
        <?php if (!empty($variants)): ?>
        <div class="product-variant-section">
          <p class="variant-section-label">Select Colour / Option</p>
          <div class="variant-colour-grid" id="variant-colour-grid">
            <?php foreach ($variants as $v):
              $vInStock = (int)$v['stock_qty'] > 0;
            ?>
            <button class="variant-colour-btn <?= !$vInStock ? 'out-of-stock' : '' ?>"
                    data-variant-id="<?= (int)$v['id'] ?>"
                    data-colour="<?= htmlspecialchars($v['colour'] ?? '') ?>"
                    data-size="<?= htmlspecialchars($v['size'] ?? '') ?>"
                    data-stock="<?= (int)$v['stock_qty'] ?>"
                    <?= !$vInStock ? 'title="Out of stock"' : '' ?>
                    onclick="selectVariant(this)">
              <span class="vcolour-swatch"
                    style="background:<?= getColourHex($v['colour'] ?? '') ?>">
              </span>
              <span class="vcolour-label">
                <?= htmlspecialchars(trim(($v['colour'] ?? '') . ' ' . ($v['size'] ?? ''))) ?>
              </span>
              <?php if ($vInStock && $v['stock_qty'] <= 3): ?>
                <span class="vcolour-low">Only <?= $v['stock_qty'] ?> left</span>
              <?php endif; ?>
              <?php if (!$vInStock): ?>
                <span class="vcolour-oos">Out of stock</span>
              <?php endif; ?>
            </button>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <!-- Quantity -->
        <div class="product-qty-row">
          <label class="form-label">Quantity</label>
          <div class="qty-control">
            <button class="qty-btn" id="qty-minus" onclick="changeQty(-1)">−</button>
            <input type="number" id="qty-input" value="1" min="1" max="10"
                   class="qty-input" readonly>
            <button class="qty-btn" id="qty-plus" onclick="changeQty(1)">+</button>
          </div>
        </div>

        <!-- Add to cart -->
        <div class="product-actions-row">
          <button class="btn btn-primary btn-lg flex-1"
                  id="add-to-cart-main"
                  <?= !$inStock ? 'disabled' : '' ?>
                  onclick="addToCartFromPage()">
            <?= $inStock ? 'Add to Cart' : 'Out of Stock' ?>
          </button>
          <a href="/cart" class="btn btn-outline-primary btn-lg" id="view-cart-btn" style="display:none">
            View Cart →
          </a>
        </div>

        <!-- Used with services -->
        <?php if (!empty($linkedServices)): ?>
        <div class="product-linked-services">
          <p class="linked-label">Perfect for:</p>
          <div class="linked-tags">
            <?php foreach ($linkedServices as $ls): ?>
              <a href="/booking/<?= htmlspecialchars($ls['slug']) ?>" class="linked-tag">
                <?= htmlspecialchars($ls['name']) ?>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <!-- Trust signals -->
        <div class="product-trust">
          <div class="trust-row"><span>🚚</span> UK standard delivery 2–5 working days</div>
          <div class="trust-row"><span>📍</span> Free local pickup — Farnborough</div>
          <div class="trust-row"><span>🔒</span> Secure payment via Stripe or bank transfer</div>
          <div class="trust-row"><span>❌</span> No returns on opened products</div>
        </div>
      </div>
    </div>

    <!-- Product reviews -->
    <?php if (!empty($reviews)): ?>
    <section class="section product-reviews-section">
      <h2 class="section-title" style="margin-bottom:var(--space-8)">
        Customer Reviews
        <?php if ($rating['total'] > 0): ?>
          <span style="font-size:var(--text-lg);font-weight:400;color:var(--color-text-muted);margin-left:var(--space-3)">
            <?= number_format($rating['average'], 1) ?>★ (<?= $rating['total'] ?>)
          </span>
        <?php endif; ?>
      </h2>
      <div class="reviews-grid">
        <?php foreach ($reviews as $r): ?>
        <article class="review-card">
          <div class="star-rating" style="margin-bottom:var(--space-3)">
            <?php for ($i = 1; $i <= 5; $i++): ?>
              <span class="star <?= $i <= (int)$r['rating'] ? '' : 'empty' ?>">★</span>
            <?php endfor; ?>
          </div>
          <?php if ($r['photo_url']): ?>
            <img src="<?= htmlspecialchars($r['photo_url']) ?>" alt="Review photo"
                 alt="Review photo"
                 style="width:100%;border-radius:var(--border-radius);margin-bottom:var(--space-3);object-fit:cover;max-height:200px">
          <?php endif; ?>
          <blockquote class="review-text">"<?= htmlspecialchars($r['review_text']) ?>"</blockquote>
          <div class="review-meta">
            <div class="review-author">
              <span class="reviewer-name"><?= htmlspecialchars($r['client_name']) ?></span>
            </div>
            <span class="review-date"><?= date('M Y', strtotime($r['approved_at'])) ?></span>
          </div>
        </article>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>

  </div>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
<script src="/assets/js/main.js"></script>
<script src="/assets/js/cart.js"></script>
<script>
const PRODUCT_ID   = <?= (int)$product['id'] ?>;
const PRODUCT_NAME = <?= json_encode($product['name']) ?>;
const PRODUCT_PRICE= <?= (float)$product['price'] ?>;
let selectedVariantId = null;
let qty = 1;

function selectVariant(btn) {
  document.querySelectorAll('.variant-colour-btn').forEach(b => b.classList.remove('selected'));
  btn.classList.add('selected');
  selectedVariantId = parseInt(btn.dataset.variantId);
  const inStock = parseInt(btn.dataset.stock) > 0;
  const addBtn  = document.getElementById('add-to-cart-main');
  addBtn.disabled   = !inStock;
  addBtn.textContent = inStock ? 'Add to Cart' : 'Out of Stock';
}

function changeQty(delta) {
  qty = Math.max(1, Math.min(10, qty + delta));
  document.getElementById('qty-input').value = qty;
}

function addToCartFromPage() {
  for (let i = 0; i < qty; i++) {
    Cart.add(PRODUCT_ID, selectedVariantId, PRODUCT_NAME, PRODUCT_PRICE);
  }
  const viewBtn = document.getElementById('view-cart-btn');
  if (viewBtn) viewBtn.style.display = 'inline-flex';
  document.getElementById('add-to-cart-main').textContent = '✓ Added to Cart';
  setTimeout(() => {
    document.getElementById('add-to-cart-main').textContent = 'Add to Cart';
  }, 2500);
}
</script>
<?php
function getColourHex(string $name): string {
    $map = [
        'black'=>'#1a1a1a','1b'=>'#2c1a0e','1b/30'=>'#3d2010','dark brown'=>'#3d1a00',
        'brown'=>'#6b3a1f','light brown'=>'#8b5a2b','blonde'=>'#d4a832','27'=>'#c08430',
        '30'=>'#8b4513','33'=>'#5c1a0a','burgundy'=>'#6b0f1a','red'=>'#8b0000',
        'white'=>'#f5f5f5','grey'=>'#888888','natural'=>'#c4a882',
    ];
    return $map[strtolower(trim($name))] ?? '#c4a882';
}
?>
</body>
</html>
