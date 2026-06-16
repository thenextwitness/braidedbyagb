<?php
// ============================================================
// BraidedbyAGB — Admin Products
// FILE: /admin/products.php
// ALL POST HANDLING BEFORE layout.php to avoid headers-sent error
// ============================================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = sanitize($_POST['action'] ?? '');
    try {

        if ($action === 'save_product') {
            $id   = (int)($_POST['id'] ?? 0);
            $name = sanitize($_POST['name'] ?? '');
            if (!$name) { header('Location: /admin/products?error=Product+name+is+required.'); exit; }

            // Slug
            $rawSlug = trim($_POST['slug'] ?? '');
            $slug    = $rawSlug !== '' ? slugify($rawSlug) : slugify($name);
            if (!$slug) { header('Location: /admin/products?error=Could+not+generate+URL+slug.'); exit; }

            // Unique slug for new products
            if (!$id) {
                $chk = $db->prepare('SELECT id FROM products WHERE slug=?');
                $chk->execute([$slug]);
                if ($chk->fetch()) $slug .= '-' . substr(time(), -4);
            }

            $desc     = sanitize($_POST['description'] ?? '');
            $price    = (float)($_POST['price'] ?? 0);
            $cat      = sanitize($_POST['category'] ?? '');
            $freeGift = (int)($_POST['free_gift'] ?? 0);
            $giftDesc = sanitize($_POST['free_gift_desc'] ?? '');
            $featured = (int)($_POST['is_featured'] ?? 0);
            $active   = (int)($_POST['is_active'] ?? 0);

            // Image
            $img = sanitize($_POST['image_url'] ?? '');
            if (!empty($_FILES['image_file']['name']) && $_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
                $up = uploadImage($_FILES['image_file'], 'products');
                if ($up) { $img = $up; }
                else { header('Location: /admin/products?error=Image+upload+failed+%E2%80%94+use+JPG%2FPNG%2FWebP+max+5MB.'); exit; }
            }

            if ($id) {
                $db->prepare('UPDATE products SET name=?,slug=?,description=?,price=?,category=?,image_url=?,free_gift=?,free_gift_desc=?,is_featured=?,is_active=? WHERE id=?')
                   ->execute([$name,$slug,$desc,$price,$cat,$img,$freeGift,$giftDesc,$featured,$active,$id]);
                header('Location: /admin/products?msg=' . urlencode('"' . $name . '" updated.'));
            } else {
                $maxOrder = (int)$db->query('SELECT COALESCE(MAX(display_order),0) FROM products')->fetchColumn();
                $db->prepare('INSERT INTO products (name,slug,description,price,category,image_url,free_gift,free_gift_desc,is_featured,is_active,display_order) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                   ->execute([$name,$slug,$desc,$price,$cat,$img,$freeGift,$giftDesc,$featured,$active,$maxOrder+1]);
                $newId = (int)$db->lastInsertId();
                // Auto-create a default variant so the product appears in shop
                $db->prepare('INSERT INTO product_variants (product_id,colour,stock_qty,low_stock_alert,display_order) VALUES (?,?,10,3,1)')
                   ->execute([$newId, 'Default']);
                header('Location: /admin/products?msg=' . urlencode('"' . $name . '" added to shop. Default stock set to 10 — update in Variants column.'));
            }
            exit;

        } elseif ($action === 'update_stock') {
            $db->prepare('UPDATE product_variants SET stock_qty=?,low_stock_alert=? WHERE id=?')
               ->execute([max(0,(int)$_POST['stock_qty']), max(0,(int)$_POST['low_stock_alert']), (int)$_POST['variant_id']]);
            header('Location: /admin/products?msg=Stock+updated.');
            exit;

        } elseif ($action === 'add_variant') {
            $pid      = (int)$_POST['product_id'];
            $maxOrder = (int)$db->query("SELECT COALESCE(MAX(display_order),0) FROM product_variants WHERE product_id=$pid")->fetchColumn();
            $db->prepare('INSERT INTO product_variants (product_id,colour,size,stock_qty,low_stock_alert,display_order) VALUES (?,?,?,?,?,?)')
               ->execute([$pid, sanitize($_POST['colour']??''), sanitize($_POST['size']??''), max(0,(int)$_POST['stock_qty']), max(0,(int)($_POST['low_stock_alert']??5)), $maxOrder+1]);
            header('Location: /admin/products?msg=Variant+added.');
            exit;

        } elseif ($action === 'toggle_active') {
            $db->prepare('UPDATE products SET is_active = NOT is_active WHERE id=?')->execute([(int)$_POST['id']]);
            header('Location: /admin/products?msg=Product+visibility+toggled.');
            exit;

        } elseif ($action === 'delete_variant') {
            $db->prepare('DELETE FROM product_variants WHERE id=?')->execute([(int)$_POST['id']]);
            header('Location: /admin/products?msg=Variant+deleted.');
            exit;

        } elseif ($action === 'delete_product') {
            $id = (int)$_POST['id'];
            $db->prepare('DELETE FROM product_variants WHERE product_id=?')->execute([$id]);
            $db->prepare('DELETE FROM service_product_links WHERE product_id=?')->execute([$id]);
            $db->prepare('DELETE FROM products WHERE id=?')->execute([$id]);
            header('Location: /admin/products?msg=Product+deleted.');
            exit;
        }

    } catch (Exception $e) {
        error_log('Products admin error: ' . $e->getMessage());
        header('Location: /admin/products?error=' . urlencode('Error: ' . $e->getMessage()));
        exit;
    }
}

// ── Safe to output HTML now ──────────────────────────────────
$pageTitle = 'Products';
require_once __DIR__ . '/includes/layout.php';

$msg    = htmlspecialchars($_GET['msg']   ?? '');
$error  = htmlspecialchars($_GET['error'] ?? '');
$search = sanitize($_GET['q']      ?? '');
$filter = sanitize($_GET['filter'] ?? '');
$editId = (int)($_GET['edit'] ?? 0);

$where = ['1=1']; $params = [];
if ($filter === 'low_stock') {
    $where[] = 'EXISTS (SELECT 1 FROM product_variants pv2 WHERE pv2.product_id=p.id AND pv2.stock_qty <= pv2.low_stock_alert AND pv2.stock_qty > 0)';
}
if ($search) {
    $where[] = '(p.name LIKE ? OR p.category LIKE ?)';
    $params  = array_merge($params, ['%'.$search.'%', '%'.$search.'%']);
}
$whereClause = implode(' AND ', $where);

$stmt = $db->prepare("
    SELECT p.*,
           COALESCE(SUM(pv.stock_qty),0) as total_stock,
           COUNT(DISTINCT pv.id) as variant_count
    FROM products p
    LEFT JOIN product_variants pv ON pv.product_id = p.id
    WHERE $whereClause
    GROUP BY p.id
    ORDER BY p.display_order ASC
");
$stmt->execute($params);
$products = $stmt->fetchAll();

$variantsByProduct = [];
foreach ($db->query('SELECT * FROM product_variants ORDER BY product_id,display_order')->fetchAll() as $v) {
    $variantsByProduct[$v['product_id']][] = $v;
}

$editProduct = $editId ? $db->query("SELECT * FROM products WHERE id=$editId")->fetch() : null;
?>

<?php if ($msg):   ?><div style="background:#d1fae5;border:1px solid #6ee7b7;padding:10px 16px;border-radius:var(--admin-radius);margin-bottom:16px;font-size:0.82rem;color:#065f46">✓ <?= $msg ?></div><?php endif; ?>
<?php if ($error): ?><div style="background:#fee2e2;border:1px solid #fca5a5;padding:10px 16px;border-radius:var(--admin-radius);margin-bottom:16px;font-size:0.82rem;color:#991b1b">✗ <?= $error ?></div><?php endif; ?>

<!-- Add/Edit form -->
<?php if ($editProduct || isset($_GET['add'])): ?>
<div class="admin-form-card" style="margin-bottom:20px;border-top:3px solid var(--admin-primary)">
  <div class="admin-form-title"><?= $editProduct ? 'Edit: '.htmlspecialchars($editProduct['name']) : 'Add New Product' ?></div>
  <form method="POST" action="/admin/products" enctype="multipart/form-data">
    <input type="hidden" name="action" value="save_product">
    <?php if ($editProduct): ?><input type="hidden" name="id" value="<?= $editProduct['id'] ?>"><?php endif; ?>
    <div class="admin-form-row">
      <div class="admin-form-group">
        <label class="admin-label">Product Name *</label>
        <input class="admin-input" type="text" name="name" value="<?= htmlspecialchars($editProduct['name'] ?? '') ?>" required>
      </div>
      <div class="admin-form-group">
        <label class="admin-label">URL Slug (auto-generated)</label>
        <input class="admin-input" type="text" name="slug" value="<?= htmlspecialchars($editProduct['slug'] ?? '') ?>" placeholder="leave blank to auto-generate">
      </div>
    </div>
    <div class="admin-form-row">
      <div class="admin-form-group">
        <label class="admin-label">Price (£) *</label>
        <input class="admin-input" type="number" name="price" value="<?= $editProduct['price'] ?? '' ?>" step="0.01" min="0" required>
      </div>
      <div class="admin-form-group">
        <label class="admin-label">Category</label>
        <input class="admin-input" type="text" name="category" value="<?= htmlspecialchars($editProduct['category'] ?? '') ?>" placeholder="e.g. Extensions, Hair Care">
      </div>
    </div>
    <div class="admin-form-group">
      <label class="admin-label">Description</label>
      <textarea class="admin-input admin-textarea" name="description" rows="3"><?= htmlspecialchars($editProduct['description'] ?? '') ?></textarea>
    </div>
    <div class="admin-form-group">
      <label class="admin-label">Product Image</label>
      <?php if (!empty($editProduct['image_url'])): ?>
        <div style="margin-bottom:6px">
          <img src="<?= htmlspecialchars($editProduct['image_url']) ?>" style="height:70px;width:70px;object-fit:cover;border-radius:6px;border:1px solid var(--admin-border)">
        </div>
      <?php endif; ?>
      <input class="admin-input" type="file" name="image_file" accept="image/jpeg,image/png,image/webp" style="padding:6px;cursor:pointer">
      <span style="font-size:0.7rem;color:var(--admin-muted)">Upload JPG/PNG/WebP (max 5MB) — or paste URL below:</span>
      <input class="admin-input" type="url" name="image_url" value="<?= htmlspecialchars($editProduct['image_url'] ?? '') ?>" placeholder="https://… (optional if uploading above)" style="margin-top:6px">
    </div>
    <div style="display:flex;gap:20px;margin-bottom:12px;flex-wrap:wrap">
      <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:0.8rem">
        <input type="hidden"   name="free_gift" value="0">
        <input type="checkbox" name="free_gift" value="1" <?= ($editProduct['free_gift'] ?? 0) ? 'checked' : '' ?> style="accent-color:var(--admin-primary)">
        Free Gift Included
      </label>
      <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:0.8rem">
        <input type="hidden"   name="is_featured" value="0">
        <input type="checkbox" name="is_featured" value="1" <?= ($editProduct['is_featured'] ?? 0) ? 'checked' : '' ?> style="accent-color:var(--admin-primary)">
        Featured (shown on homepage)
      </label>
      <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:0.8rem">
        <input type="hidden"   name="is_active" value="0">
        <input type="checkbox" name="is_active" value="1" <?= isset($editProduct) ? ($editProduct['is_active'] ? 'checked' : '') : 'checked' ?> style="accent-color:var(--admin-primary)">
        Active (visible in shop)
      </label>
    </div>
    <div class="admin-form-group">
      <label class="admin-label">Free Gift Description</label>
      <input class="admin-input" type="text" name="free_gift_desc" value="<?= htmlspecialchars($editProduct['free_gift_desc'] ?? '') ?>" placeholder="e.g. Free edge control included">
    </div>
    <div style="display:flex;gap:10px">
      <button type="submit" class="btn-admin btn-admin-primary"><?= $editProduct ? 'Save Changes' : 'Add Product' ?></button>
      <a href="/admin/products" class="btn-admin btn-admin-outline">Cancel</a>
    </div>
  </form>
</div>
<?php endif; ?>

<!-- Header bar -->
<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:16px">
  <form method="GET" action="/admin/products" style="display:flex;gap:8px;flex:1;flex-wrap:wrap">
    <input class="admin-search" type="text" name="q" placeholder="Search products…" value="<?= htmlspecialchars($search) ?>" style="max-width:220px">
    <select name="filter" class="admin-input admin-select" style="width:160px" onchange="this.form.submit()">
      <option value="">All products</option>
      <option value="low_stock" <?= $filter==='low_stock'?'selected':'' ?>>Low Stock Alert</option>
    </select>
    <button type="submit" class="btn-admin btn-admin-primary">Search</button>
    <?php if ($search || $filter): ?><a href="/admin/products" class="btn-admin btn-admin-outline">Clear</a><?php endif; ?>
  </form>
  <a href="/admin/products?add=1" class="btn-admin btn-admin-primary">+ Add Product</a>
</div>

<!-- Products table -->
<div class="admin-table-wrap">
  <div class="admin-table-header">
    <span class="admin-table-title"><?= count($products) ?> product<?= count($products)!=1?'s':'' ?></span>
  </div>
  <?php if (empty($products)): ?>
    <div class="table-empty"><div class="table-empty-icon">🛍️</div><p>No products yet. Click <strong>+ Add Product</strong> above.</p></div>
  <?php else: ?>
  <table class="admin-table">
    <thead>
      <tr><th>Product</th><th>Category</th><th>Price</th><th>Variants & Stock</th><th>Status</th><th>Actions</th></tr>
    </thead>
    <tbody>
      <?php foreach ($products as $prod):
        $variants    = $variantsByProduct[$prod['id']] ?? [];
        $totalStock  = (int)$prod['total_stock'];
        $lowCount    = count(array_filter($variants, fn($v) => $v['stock_qty'] <= $v['low_stock_alert'] && $v['stock_qty'] > 0));
      ?>
      <tr>
        <td>
          <div class="td-name"><?= htmlspecialchars($prod['name']) ?></div>
          <div class="td-muted" style="font-size:0.7rem">/shop/<?= htmlspecialchars($prod['slug']) ?></div>
          <?php if ($prod['free_gift']): ?><span class="status-badge status-pending" style="font-size:0.55rem">🎁 Free Gift</span><?php endif; ?>
          <?php if ($prod['is_featured']): ?><span class="status-badge status-confirmed" style="font-size:0.55rem">⭐ Featured</span><?php endif; ?>
        </td>
        <td class="td-muted"><?= htmlspecialchars($prod['category'] ?? '—') ?></td>
        <td class="td-price">£<?= number_format($prod['price'],2) ?></td>
        <td>
          <div style="margin-bottom:6px">
            <span style="font-weight:700;color:<?= $totalStock===0?'var(--admin-error)':($lowCount>0?'var(--admin-warning)':'var(--admin-success)') ?>">
              <?= $totalStock ?> units
            </span>
            <?php if ($lowCount): ?><div style="font-size:0.65rem;color:var(--admin-warning)">⚠️ <?= $lowCount ?> low</div><?php endif; ?>
          </div>
          <?php foreach ($variants as $v): ?>
          <form method="POST" action="/admin/products" style="display:flex;gap:4px;align-items:center;margin-bottom:4px">
            <input type="hidden" name="action"     value="update_stock">
            <input type="hidden" name="variant_id" value="<?= $v['id'] ?>">
            <span style="font-size:0.7rem;color:var(--admin-muted);min-width:50px"><?= htmlspecialchars(trim(($v['colour']??'').($v['size']?' '.$v['size']:''))) ?: 'Default' ?></span>
            <input class="admin-input" type="number" name="stock_qty"       value="<?= $v['stock_qty'] ?>"       min="0" style="width:56px;font-size:0.72rem;padding:3px 6px" title="Stock">
            <input class="admin-input" type="number" name="low_stock_alert" value="<?= $v['low_stock_alert'] ?>" min="0" style="width:42px;font-size:0.72rem;padding:3px 6px" title="Alert at">
            <button type="submit" class="btn-admin btn-admin-primary btn-admin-sm">✓</button>
          </form>
          <?php endforeach; ?>
          <form method="POST" action="/admin/products" style="display:flex;gap:4px;align-items:center;margin-top:6px;padding-top:6px;border-top:1px solid var(--admin-border)">
            <input type="hidden" name="action"     value="add_variant">
            <input type="hidden" name="product_id" value="<?= $prod['id'] ?>">
            <input class="admin-input" type="text"   name="colour"    placeholder="Colour" style="width:70px;font-size:0.7rem;padding:3px 6px">
            <input class="admin-input" type="text"   name="size"      placeholder="Size"   style="width:50px;font-size:0.7rem;padding:3px 6px">
            <input class="admin-input" type="number" name="stock_qty" value="0"             style="width:50px;font-size:0.7rem;padding:3px 6px" min="0">
            <button type="submit" class="btn-admin btn-admin-gold btn-admin-sm">+</button>
          </form>
        </td>
        <td><span class="status-badge <?= $prod['is_active']?'status-confirmed':'status-cancelled' ?>"><?= $prod['is_active']?'Active':'Hidden' ?></span></td>
        <td>
          <div style="display:flex;gap:5px;flex-wrap:wrap">
            <a href="/admin/products?edit=<?= $prod['id'] ?>" class="btn-admin btn-admin-outline btn-admin-sm">Edit</a>
            <form method="POST" style="display:inline">
              <input type="hidden" name="action" value="toggle_active">
              <input type="hidden" name="id"     value="<?= $prod['id'] ?>">
              <button type="submit" class="btn-admin btn-admin-outline btn-admin-sm"><?= $prod['is_active']?'Hide':'Show' ?></button>
            </form>
            <a href="/shop/<?= htmlspecialchars($prod['slug']) ?>" target="_blank" class="btn-admin btn-admin-outline btn-admin-sm">↗</a>
            <form method="POST" style="display:inline">
              <input type="hidden" name="action" value="delete_product">
              <input type="hidden" name="id"     value="<?= $prod['id'] ?>">
              <button type="submit" class="btn-admin btn-admin-danger btn-admin-sm"
                      onclick="return confirm('Delete \'<?= addslashes($prod['name']) ?>\'? This cannot be undone.')">🗑</button>
            </form>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/layout-end.php'; ?>
