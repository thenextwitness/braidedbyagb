<?php
// ============================================================
// BraidedbyAGB — Shop Page
// FILE: /public/shop.php
// ============================================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

$db = getDB();

// Fetch all active products with stock info
$products = $db->query("
    SELECT p.*,
           COALESCE(SUM(pv.stock_qty), 0) as total_stock,
           MIN(pv.stock_qty) as min_stock,
           COUNT(DISTINCT pv.id) as variant_count,
           GROUP_CONCAT(DISTINCT pv.colour ORDER BY pv.display_order SEPARATOR ',') as colours
    FROM products p
    LEFT JOIN product_variants pv ON pv.product_id = p.id AND pv.colour != 'Default'
    WHERE p.is_active = 1
    GROUP BY p.id
    ORDER BY p.display_order ASC
")->fetchAll();

$categories = array_unique(array_filter(array_column($products, 'category')));
sort($categories);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Shop Hair Extensions — BraidedbyAGB</title>
  <meta name="description" content="Shop premium hair extensions, packs and hair care products at BraidedbyAGB. UK delivery or local pickup in Farnborough.">
  <link rel="canonical" href="https://braidedbyagb.co.uk/shop">
  <meta property="og:type" content="website">
  <meta property="og:url" content="https://braidedbyagb.co.uk/shop">
  <meta property="og:title" content="Shop Hair Extensions — BraidedbyAGB">
  <meta property="og:description" content="Shop premium hair extensions, packs and hair care products at BraidedbyAGB. UK delivery or local pickup in Farnborough.">
  <meta property="og:image" content="https://braidedbyagb.co.uk/assets/images/braidedbyagblogo.png">
  <meta name="twitter:card" content="summary_large_image">
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

<main class="page-content">

  <section class="page-hero">
    <div class="page-hero-bg"></div>
    <div class="container">
      <div class="page-hero-content" data-animate="fadeUp">
        <p class="section-label" style="justify-content:center;color:var(--color-gold)"><span>Premium Quality</span></p>
        <h1 class="page-hero-title">Shop Extensions</h1>
        <p class="page-hero-subtitle">Premium hair packs and products — delivered to your door or collect in Farnborough.</p>
      </div>
    </div>
  </section>

  <!-- Delivery banner -->
  <div class="delivery-banner">
    <div class="container">
      <div class="delivery-banner-inner">
        <span>🚚 UK Standard Delivery Available</span>
        <span class="divider-dot">·</span>
        <span>📍 Free Local Pickup — Farnborough</span>
        <span class="divider-dot">·</span>
        <span>💳 Secure Payment via Stripe or Bank Transfer</span>
      </div>
    </div>
  </div>

  <div class="container">
    <div class="shop-layout">

      <!-- ── Sidebar Filters ── -->
      <aside class="shop-sidebar">
        <div class="shop-filter-group">
          <p class="shop-filter-title">Category</p>
          <label class="shop-filter-option">
            <input type="radio" name="cat-filter" value="all" checked> All Products
          </label>
          <?php foreach ($categories as $cat): ?>
          <label class="shop-filter-option">
            <input type="radio" name="cat-filter" value="<?= htmlspecialchars(strtolower($cat)) ?>">
            <?= htmlspecialchars($cat) ?>
          </label>
          <?php endforeach; ?>
        </div>

        <div class="shop-filter-group">
          <p class="shop-filter-title">Availability</p>
          <label class="shop-filter-option">
            <input type="checkbox" id="filter-in-stock"> In Stock Only
          </label>
          <label class="shop-filter-option">
            <input type="checkbox" id="filter-free-gift"> Free Gift Included
          </label>
        </div>

        <div class="shop-filter-group">
          <p class="shop-filter-title">Sort By</p>
          <select id="sort-select" class="form-control" style="font-size:var(--text-sm)">
            <option value="default">Featured</option>
            <option value="price-asc">Price: Low to High</option>
            <option value="price-desc">Price: High to Low</option>
            <option value="name">Name A–Z</option>
          </select>
        </div>
      </aside>

      <!-- ── Products Grid ── -->
      <div class="shop-main">
        <div class="shop-products-header">
          <p class="shop-products-count" id="products-count"><?= count($products) ?> products</p>
          <div class="shop-mobile-filters">
            <select id="sort-select-mobile" class="form-control" style="font-size:var(--text-sm)">
              <option value="default">Sort: Featured</option>
              <option value="price-asc">Price: Low to High</option>
              <option value="price-desc">Price: High to Low</option>
              <option value="name">Name A–Z</option>
            </select>
          </div>
        </div>

        <?php if (empty($products)): ?>
        <div class="shop-empty">
          <p style="font-size:3rem;margin-bottom:var(--space-4)">🛍️</p>
          <h3 style="color:var(--color-deep-purple);margin-bottom:var(--space-3)">Products Coming Soon</h3>
          <p style="color:var(--color-text-muted);margin-bottom:var(--space-6)">We're adding our product range. Check back soon or WhatsApp us to enquire.</p>
          <a href="https://wa.me/447769064971" class="btn btn-primary">WhatsApp to Enquire</a>
        </div>
        <?php else: ?>
        <div class="shop-grid" id="shop-grid">
          <?php foreach ($products as $p):
            // Products with only a "Default" variant count as in-stock
            $realVariantCount = (int)$p['variant_count'];
            $defaultStmt = $db->prepare("SELECT SUM(stock_qty) FROM product_variants WHERE product_id=? AND colour='Default'");
            $defaultStmt->execute([(int)$p['id']]);
            $defaultStock = (int)$defaultStmt->fetchColumn();
            $inStock  = (int)$p['total_stock'] > 0 || $defaultStock > 0;
            $lowStock = $inStock && (int)$p['min_stock'] <= 3 && (int)$p['min_stock'] > 0;
            $colours  = array_filter(explode(',', $p['colours'] ?? ''));
          ?>
          <article class="product-shop-card"
                   data-category="<?= htmlspecialchars(strtolower($p['category'] ?? '')) ?>"
                   data-price="<?= (float)$p['price'] ?>"
                   data-name="<?= htmlspecialchars(strtolower($p['name'])) ?>"
                   data-stock="<?= $inStock ? '1' : '0' ?>"
                   data-gift="<?= $p['free_gift'] ? '1' : '0' ?>">

            <!-- Badges -->
            <div class="product-card-badges">
              <?php if ($p['free_gift']): ?>
                <span class="badge badge-gold">🎁 Free Gift</span>
              <?php endif; ?>
              <?php if (!$inStock): ?>
                <span class="badge badge-error">Out of Stock</span>
              <?php elseif ($lowStock): ?>
                <span class="badge badge-warning">Low Stock</span>
              <?php endif; ?>
            </div>

            <!-- Image -->
            <a href="/shop/<?= htmlspecialchars($p['slug']) ?>" class="product-card-img-link">
              <div class="product-card-img-wrap">
                <?php if ($p['image_url']): ?>
                  <img src="<?= htmlspecialchars($p['image_url']) ?>"
                       alt="<?= htmlspecialchars($p['name']) ?>"
                       loading="lazy"
                       class="product-card-img">
                <?php else: ?>
                  <div class="product-card-img-placeholder">🛍️</div>
                <?php endif; ?>
                <div class="product-card-overlay">
                  <span>View Product</span>
                </div>
              </div>
            </a>

            <div class="product-card-body">
              <?php if ($p['category']): ?>
                <p class="product-card-category"><?= htmlspecialchars($p['category']) ?></p>
              <?php endif; ?>
              <h3 class="product-card-name">
                <a href="/shop/<?= htmlspecialchars($p['slug']) ?>"><?= htmlspecialchars($p['name']) ?></a>
              </h3>

              <?php if (!empty($colours)): ?>
              <div class="product-colours">
                <?php foreach (array_slice($colours, 0, 6) as $colour): ?>
                  <span class="colour-dot" title="<?= htmlspecialchars($colour) ?>"
                        style="background:<?= getColourHex($colour) ?>"></span>
                <?php endforeach; ?>
                <?php if (count($colours) > 6): ?>
                  <span class="colour-more">+<?= count($colours) - 6 ?></span>
                <?php endif; ?>
              </div>
              <?php endif; ?>

              <div class="product-card-footer">
                <span class="product-card-price">£<?= number_format((float)$p['price'], 2) ?></span>
                <?php if ($inStock): ?>
                  <button class="btn btn-primary btn-sm add-to-cart-btn"
                          data-product-id="<?= (int)$p['id'] ?>"
                          data-product-name="<?= htmlspecialchars($p['name']) ?>"
                          data-product-price="<?= (float)$p['price'] ?>">
                    Add to Cart
                  </button>
                <?php else: ?>
                  <span class="out-of-stock-label">Out of Stock</span>
                <?php endif; ?>
              </div>
            </div>
          </article>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>

    </div>
  </div>

  <!-- Cart preview strip (shows when cart has items) -->
  <div class="cart-strip" id="cart-strip" style="display:none">
    <div class="container">
      <div class="cart-strip-inner">
        <span class="cart-strip-info">
          🛍️ <strong id="cart-strip-count">0</strong> item(s) in your cart —
          <strong id="cart-strip-total">£0.00</strong>
        </span>
        <a href="/cart" class="btn btn-gold">View Cart & Checkout</a>
      </div>
    </div>
  </div>

</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
<script src="/assets/js/main.js"></script>
<script src="/assets/js/cart.js"></script>
<script>
// ── Colour hex helper (mirrors PHP function) ─────────────
function getColourHexJs(name) {
  const map = {
    'black':'#1a1a1a','1b':'#2c1a0e','1b/30':'#3d2010','dark brown':'#3d1a00',
    'brown':'#6b3a1f','light brown':'#8b5a2b','blonde':'#d4a832','27':'#c08430',
    '30':'#8b4513','33':'#5c1a0a','burgundy':'#6b0f1a','red':'#8b0000',
    'white':'#f5f5f5','grey':'#888888','natural':'#c4a882','mixed':'linear-gradient(90deg,#1a1a1a,#6b3a1f,#c08430)',
  };
  return map[name?.toLowerCase()] || '#c4a882';
}

// ── Filter & sort logic ───────────────────────────────────
let activeCategory = 'all';
let sortOrder      = 'default';
let filterInStock  = false;
let filterGift     = false;

function applyFilters() {
  const cards = document.querySelectorAll('.product-shop-card');
  let visible = 0;
  cards.forEach(card => {
    const catMatch   = activeCategory === 'all' || card.dataset.category === activeCategory;
    const stockMatch = !filterInStock || card.dataset.stock === '1';
    const giftMatch  = !filterGift    || card.dataset.gift  === '1';
    const show = catMatch && stockMatch && giftMatch;
    card.style.display = show ? '' : 'none';
    if (show) visible++;
  });
  document.getElementById('products-count').textContent = visible + ' product' + (visible !== 1 ? 's' : '');
  applySorting();
}

function applySorting() {
  const grid  = document.getElementById('shop-grid');
  if (!grid) return;
  const cards = Array.from(grid.querySelectorAll('.product-shop-card:not([style*="display: none"])'));
  cards.sort((a, b) => {
    if (sortOrder === 'price-asc')  return parseFloat(a.dataset.price) - parseFloat(b.dataset.price);
    if (sortOrder === 'price-desc') return parseFloat(b.dataset.price) - parseFloat(a.dataset.price);
    if (sortOrder === 'name')       return a.dataset.name.localeCompare(b.dataset.name);
    return 0;
  });
  cards.forEach(c => grid.appendChild(c));
}

document.querySelectorAll('[name="cat-filter"]').forEach(radio => {
  radio.addEventListener('change', () => { activeCategory = radio.value; applyFilters(); });
});
document.getElementById('filter-in-stock')?.addEventListener('change', e => { filterInStock = e.target.checked; applyFilters(); });
document.getElementById('filter-free-gift')?.addEventListener('change', e => { filterGift = e.target.checked; applyFilters(); });
['sort-select','sort-select-mobile'].forEach(id => {
  document.getElementById(id)?.addEventListener('change', e => { sortOrder = e.target.value; applySorting(); });
});

// ── Cart strip ───────────────────────────────────────────
function updateCartStrip() {
  const count = Cart.count();
  const total = Cart.total();
  const strip = document.getElementById('cart-strip');
  if (!strip) return;
  if (count > 0) {
    strip.style.display = 'block';
    document.getElementById('cart-strip-count').textContent = count;
    document.getElementById('cart-strip-total').textContent = '£' + total.toFixed(2);
  } else {
    strip.style.display = 'none';
  }
}
document.addEventListener('DOMContentLoaded', updateCartStrip);
document.addEventListener('click', e => {
  if (e.target.closest('.add-to-cart-btn')) setTimeout(updateCartStrip, 100);
});
</script>
<?php
// Helper: colour name to hex (server-side for SSR)
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
