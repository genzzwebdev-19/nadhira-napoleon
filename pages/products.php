<?php
// ============================================
// PRODUCTS LISTING PAGE
// Menampilkan semua produk dengan tampilan bersih
// Website Nadhira Napoleon Pekanbaru
// ============================================
require_once '../config/database.php';

$conn = getConnection();

// Pagination & Filters
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 12;
$offset = ($page - 1) * $perPage;

$search = trim($_GET['search'] ?? '');
$categorySlug = trim($_GET['category'] ?? '');
$sort = $_GET['sort'] ?? 'terbaru';

// Handle null connection
if (!$conn) {
    $totalProducts = 0;
    $products = null;
    $totalPages = 1;
    $page = 1;
    $categories = null;
    include '../includes/header.php';
    echo '<section style="padding-top: calc(var(--navbar-total-height, 120px) + 8px); min-height: 100vh;"><div class="container"><div style="text-align:center;padding:60px 20px;"><i class="fas fa-exclamation-triangle" style="font-size:3rem;color:#D4A030;opacity:0.5;margin-bottom:20px;"></i><h2 style="font-family: Playfair Display, serif;">Koneksi Database Gagal</h2><p style="color:#888;">Silakan coba lagi nanti.</p></div></div></section>';
    include '../includes/footer.php';
    exit;
}

// Build query conditions
// Paket membership & paket spesial dijual lewat halaman khusus, bukan katalog produk biasa
$where = "WHERE p.is_active = TRUE AND p.id NOT IN (SELECT product_id FROM membership_plans WHERE product_id IS NOT NULL) AND p.id NOT IN (SELECT product_id FROM packages WHERE product_id IS NOT NULL)";

if ($search) {
    $s = $conn->real_escape_string($search);
    $where .= " AND (p.name LIKE '%$s%' OR p.description LIKE '%$s%')";
}
if ($categorySlug) {
    $cs = $conn->real_escape_string($categorySlug);
    $where .= " AND c.slug = '$cs'";
}

// Sorting
$orderBy = match($sort) {
    'termurah' => 'p.price ASC',
    'termahal' => 'p.price DESC',
    'terpopuler' => 'p.total_sold DESC',
    'rating' => 'p.rating DESC',
    default => 'p.created_at DESC',
};

// Get total count
$countResult = $conn->query("SELECT COUNT(*) as total FROM products p 
    LEFT JOIN product_categories c ON p.category_id = c.id $where");
$totalProducts = $countResult ? (int)$countResult->fetch_assoc()['total'] : 0;
$totalPages = max(1, ceil($totalProducts / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

// Get products with primary image
$products = $conn->query("
    SELECT p.*, c.name as category_name, c.slug as category_slug,
        (SELECT image FROM product_images WHERE product_id = p.id AND is_primary = 1 LIMIT 1) as product_image
    FROM products p
    LEFT JOIN product_categories c ON p.category_id = c.id
    $where
    ORDER BY $orderBy
    LIMIT $perPage OFFSET $offset
");

// Relasi produk → cabang (produk yang belum diatur = tersedia di semua cabang aktif)
$branchByProduct = [];
$rBp = $conn->query("SELECT bp.product_id, b.id, b.name, b.address, b.latitude, b.longitude, b.open_hours
    FROM branch_products bp
    JOIN branches b ON b.id = bp.branch_id
    WHERE bp.is_available = 1 AND b.is_active = 1");
if ($rBp) { while ($row = $rBp->fetch_assoc()) $branchByProduct[(int)$row['product_id']][] = $row; }
$allActiveBranches = getActiveBranches();

// Get all categories for filter (kecuali kategori produk paket)
$categories = $conn->query("SELECT * FROM product_categories WHERE slug <> 'paket-spesial' ORDER BY sort_order ASC");

$page_title = $categorySlug ? 'Produk - ' . ucfirst(str_replace('-', ' ', $categorySlug)) : 'Semua Produk';
$meta_description = 'Koleksi lengkap produk premium Nadhira Napoleon dari berbagai kategori.';
include '../includes/header.php';
?>

<style>
/* ===== SHOP PAGE STYLES ===== */
/* Toolbar */
.shop-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
    margin-bottom: 32px;
    padding: 16px 20px;
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 2px 12px rgba(184,148,15,0.06);
}
.toolbar-left {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.toolbar-right {
    display: flex;
    align-items: center;
    gap: 10px;
}

/* Search Input */
.search-input {
    padding: 10px 16px 10px 40px;
    border: 1.5px solid #e8e8d8;
    border-radius: 50px;
    font-family: 'Inter', sans-serif;
    font-size: 0.875rem;
    background: #fafaf7 url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23bbb' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Ccircle cx='11' cy='11' r='8'/%3E%3Cline x1='21' y1='21' x2='16.65' y2='16.65'/%3E%3C/svg%3E") 14px center no-repeat;
    outline: none;
    width: 220px;
    transition: border 0.2s;
}
.search-input:focus {
    border-color: #D4A030;
}

/* Sort Select */
.sort-select {
    padding: 10px 36px 10px 16px;
    border: 1.5px solid #e8e8d8;
    border-radius: 50px;
    font-family: 'Inter', sans-serif;
    font-size: 0.875rem;
    background: #fafaf7 url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23999' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E") calc(100% - 14px) center no-repeat;
    outline: none;
    appearance: none;
    -webkit-appearance: none;
    cursor: pointer;
    transition: border 0.2s;
    min-width: 140px;
}
.sort-select:focus {
    border-color: #D4A030;
}

/* Category Filter Pills */
.cat-filters {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 28px;
}
.cat-pill {
    padding: 7px 20px;
    border-radius: 50px;
    font-family: 'Inter', sans-serif;
    font-size: 0.8125rem;
    font-weight: 500;
    text-decoration: none;
    transition: all 0.2s;
    border: 1.5px solid #e8e8d8;
    background: #fff;
    color: #666;
}
.cat-pill:hover {
    border-color: #D4A030;
    color: #B8940F;
    transform: translateY(-1px);
}
.cat-pill.active {
    background: linear-gradient(135deg, #D4A030, #B8940F);
    color: #fff;
    border-color: transparent;
}

/* Product Grid */
.produk-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
    margin-bottom: 40px;
}

/* Product Card - Clean Style */
.produk-card {
    background: #fff;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 2px 12px rgba(0,0,0,0.04);
    transition: all 0.3s ease;
    position: relative;
}
.produk-card:hover {
    transform: translateY(-6px);
    box-shadow: 0 12px 40px rgba(184,148,15,0.10);
}

.produk-card-image {
    position: relative;
    overflow: hidden;
    aspect-ratio: 1/1;
    background: #f8f8f4;
}
.produk-card-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.5s ease;
}
.produk-card:hover .produk-card-image img {
    transform: scale(1.08);
}

/* Badge */
.produk-badge {
    position: absolute;
    top: 12px;
    left: 12px;
    z-index: 2;
    padding: 3px 12px;
    border-radius: 50px;
    font-size: 0.6875rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.produk-badge.best-seller {
    background: linear-gradient(135deg, #D4A030, #B8940F);
    color: #fff;
}
.produk-badge.diskon {
    background: #EF4444;
    color: #fff;
}
.produk-badge.baru {
    background: #10B981;
    color: #fff;
}

/* Quick Add Button on Hover */
.produk-card-add {
    position: absolute;
    bottom: 12px;
    right: 12px;
    z-index: 2;
    width: 42px;
    height: 42px;
    border-radius: 50%;
    background: #fff;
    border: none;
    box-shadow: 0 4px 16px rgba(0,0,0,0.12);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #B8940F;
    font-size: 1rem;
    opacity: 0;
    transform: translateY(10px);
    transition: all 0.3s ease;
}
.produk-card:hover .produk-card-add {
    opacity: 1;
    transform: translateY(0);
}
.produk-card-add:hover {
    background: linear-gradient(135deg, #D4A030, #B8940F);
    color: #fff;
    transform: translateY(-2px) scale(1.1);
}

/* Caption on Image */
.produk-card-caption {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    z-index: 1;
    padding: 44px 56px 14px 16px;
    background: linear-gradient(to top, rgba(46, 30, 8, 0.9) 0%, rgba(46, 30, 8, 0.45) 55%, transparent 100%);
    display: flex;
    flex-direction: column;
    gap: 2px;
    transition: background 0.3s ease;
}
.produk-card:hover .produk-card-caption {
    background: linear-gradient(to top, rgba(46, 30, 8, 0.95) 0%, rgba(46, 30, 8, 0.55) 60%, transparent 100%);
}
.produk-card-caption-name {
    font-family: 'Playfair Display', serif;
    font-size: 0.9375rem;
    font-weight: 600;
    color: #fff;
    line-height: 1.3;
    text-shadow: 0 1px 3px rgba(0, 0, 0, 0.35);
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.produk-card-caption-price {
    font-size: 1.0625rem;
    font-weight: 700;
    color: #FFE400;
    font-family: 'Playfair Display', serif;
    text-shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
}
.produk-card-caption-price .original {
    font-size: 0.75rem;
    color: rgba(255, 255, 255, 0.65);
    text-decoration: line-through;
    margin-left: 6px;
    font-weight: 400;
}

/* Pagination */
.pagination {
    display: flex;
    justify-content: center;
    gap: 6px;
    flex-wrap: wrap;
}
.page-link {
    display: flex;
    align-items: center;
    justify-content: center;
    min-width: 42px;
    height: 42px;
    padding: 0 14px;
    border-radius: 12px;
    font-size: 0.875rem;
    font-weight: 500;
    text-decoration: none;
    transition: all 0.2s;
    border: 1.5px solid #e8e8d8;
    background: #fff;
    color: #666;
}
.page-link:hover {
    border-color: #D4A030;
    color: #B8940F;
}
.page-link.active {
    background: linear-gradient(135deg, #D4A030, #B8940F);
    color: #fff;
    border-color: transparent;
}
.page-link.disabled {
    opacity: 0.4;
    pointer-events: none;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 80px 20px;
}
.empty-state i {
    font-size: 4rem;
    color: #D4A030;
    opacity: 0.3;
    margin-bottom: 20px;
}
.empty-state h2 {
    font-family: 'Playfair Display', serif;
    font-size: 1.5rem;
    color: #B8940F;
    margin-bottom: 12px;
}
.empty-state p {
    color: #999;
    margin-bottom: 24px;
}

/* Breadcrumb */
.shop-breadcrumb {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.8125rem;
    color: #999;
    margin-bottom: 16px;
    padding-top: calc(var(--navbar-total-height, 85px) + 8px);
}
.shop-breadcrumb a {
    color: #999;
    text-decoration: none;
}
.shop-breadcrumb a:hover {
    color: #D4A030;
}
.shop-breadcrumb .sep {
    color: #ddd;
}
.shop-breadcrumb .current {
    color: #B8940F;
}

/* Responsive */
@media (max-width: 1024px) {
    .produk-grid { grid-template-columns: repeat(3, 1fr); }
}
@media (max-width: 768px) {
    .produk-grid { grid-template-columns: repeat(2, 1fr); gap: 14px; }
    .shop-toolbar { flex-direction: column; align-items: stretch; }
    .toolbar-left, .toolbar-right { width: 100%; }
    .search-input { width: 100%; }
    .sort-select { width: 100%; }
}
@media (max-width: 480px) {
    .produk-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
    .produk-card-caption { padding: 36px 52px 10px 12px; }
    .produk-card-caption-name { font-size: 0.8125rem; }
    .produk-card-caption-price { font-size: 0.9375rem; }
}
</style>

<!-- ===== SHOP CONTENT ===== -->
<div class="shop-breadcrumb">
    <div class="container">
        <a href="<?= SITE_URL ?>">Home</a>
        <span class="sep">/</span>
        <span class="current">Toko</span>
        <?php if ($categorySlug): ?>
        <span class="sep">/</span>
        <span class="current"><?= htmlspecialchars(ucwords(str_replace('-', ' ', $categorySlug))) ?></span>
        <?php endif; ?>
    </div>
</div>

<section style="padding-bottom: 60px;">
    <div class="container">
        <!-- Toolbar -->
        <div class="shop-toolbar">
            <div class="toolbar-left">
                <form id="search-form" method="GET" style="display:flex;gap:8px;align-items:center;">
                    <?php if ($categorySlug): ?>
                    <input type="hidden" name="category" value="<?= htmlspecialchars($categorySlug) ?>">
                    <?php endif; ?>
                    <input type="text" name="search" class="search-input" placeholder="Cari produk..." value="<?= htmlspecialchars($search) ?>">
                    <button type="submit" style="padding:10px 16px;border:none;border-radius:50px;background:linear-gradient(135deg,#D4A030,#B8940F);color:#fff;cursor:pointer;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    </button>
                </form>
            </div>
            <div class="toolbar-right">
                <select class="sort-select" name="sort" form="search-form" onchange="this.form.submit()">
                    <option value="terbaru" <?= $sort === 'terbaru' ? 'selected' : '' ?>>Terbaru</option>
                    <option value="terpopuler" <?= $sort === 'terpopuler' ? 'selected' : '' ?>>Terpopuler</option>
                    <option value="termurah" <?= $sort === 'termurah' ? 'selected' : '' ?>>Termurah</option>
                    <option value="termahal" <?= $sort === 'termahal' ? 'selected' : '' ?>>Termahal</option>
                    <option value="rating" <?= $sort === 'rating' ? 'selected' : '' ?>>Rating</option>
                </select>
            </div>
        </div>

        <!-- Category Filters -->
        <div class="cat-filters">
            <a href="?<?= $search ? 'search='.urlencode($search).'&' : '' ?><?= $sort !== 'terbaru' ? 'sort='.$sort : '' ?>" 
               class="cat-pill <?= !$categorySlug ? 'active' : '' ?>">Semua</a>
            <?php if ($categories): while ($cat = $categories->fetch_assoc()): ?>
            <a href="?category=<?= htmlspecialchars($cat['slug']) ?><?= $search ? '&search='.urlencode($search) : '' ?><?= $sort !== 'terbaru' ? '&sort='.$sort : '' ?>" 
               class="cat-pill <?= $categorySlug === $cat['slug'] ? 'active' : '' ?>"><?= htmlspecialchars($cat['name']) ?></a>
            <?php endwhile; endif; ?>
        </div>

        <!-- Products Grid -->
        <?php if ($products && $products->num_rows > 0): ?>
        <div class="produk-grid">
            <?php while ($p = $products->fetch_assoc()): 
                $img = $p['product_image'] ?: 'https://images.unsplash.com/photo-1551024601-bec78aea704b?w=400&q=80';
                $hasDiscount = $p['discount_price'] > 0;
                $displayPrice = $hasDiscount ? $p['discount_price'] : $p['price'];
                $badge = '';
                $badgeClass = '';
                if ($p['is_best_seller']) { $badge = 'Best Seller'; $badgeClass = 'best-seller'; }
                elseif ($hasDiscount) { $badge = 'Diskon'; $badgeClass = 'diskon'; }
                elseif ($p['is_featured']) { $badge = 'Premium'; $badgeClass = 'baru'; }
            ?>
            <?php
                // Cabang tempat produk ini tersedia (fallback: semua cabang aktif)
                $prodBranches = $branchByProduct[(int)$p['id']] ?? $allActiveBranches;
                $prodBranchesJson = array_map(function ($b) {
                    return [
                        'id' => (int)$b['id'],
                        'name' => $b['name'],
                        'lat' => (float)$b['latitude'],
                        'lng' => (float)$b['longitude'],
                    ];
                }, $prodBranches);
            ?>
            <div class="produk-card" data-branches='<?= htmlspecialchars(json_encode($prodBranchesJson, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES) ?>'>
                <?php if ($badge): ?>
                <div class="produk-badge <?= $badgeClass ?>"><?= $badge ?></div>
                <?php endif; ?>
                
                <a href="<?= SITE_URL ?>/pages/product-detail.php?id=<?= $p['id'] ?>" class="produk-card-image">
                    <img src="<?= htmlspecialchars($img) ?>" alt="<?= htmlspecialchars($p['name']) ?>" loading="lazy">
                    <div class="produk-card-caption">
                        <span class="produk-card-caption-name"><?= htmlspecialchars($p['name']) ?></span>
                        <span class="produk-card-caption-price">
                            Rp <?= number_format($displayPrice, 0, ',', '.') ?>
                            <?php if ($hasDiscount): ?>
                                <span class="original">Rp <?= number_format($p['price'], 0, ',', '.') ?></span>
                            <?php endif; ?>
                        </span>
                    </div>
                </a>

                <div class="produk-card-branches">
                    <i class="fas fa-store" aria-hidden="true"></i> <span>Tersedia di <?= count($prodBranches) ?> cabang</span>
                </div>

                <button class="produk-card-add" onclick="addToCart(<?= $p['id'] ?>)" title="Tambah ke keranjang">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                </button>
            </div>
            <?php endwhile; ?>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <a href="?page=<?= max(1, $page - 1) ?><?= $categorySlug ? '&category='.$categorySlug : '' ?><?= $search ? '&search='.urlencode($search) : '' ?><?= $sort !== 'terbaru' ? '&sort='.$sort : '' ?>" 
               class="page-link <?= $page <= 1 ? 'disabled' : '' ?>">&laquo;</a>
            <?php 
            $startPage = max(1, $page - 2);
            $endPage = min($totalPages, $page + 2);
            if ($startPage > 1): ?>
                <a href="?page=1<?= $categorySlug ? '&category='.$categorySlug : '' ?><?= $search ? '&search='.urlencode($search) : '' ?><?= $sort !== 'terbaru' ? '&sort='.$sort : '' ?>" class="page-link">1</a>
                <?php if ($startPage > 2): ?><span style="display:flex;align-items:center;padding:0 8px;color:#ccc;">...</span><?php endif; ?>
            <?php endif; ?>
            <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                <a href="?page=<?= $i ?><?= $categorySlug ? '&category='.$categorySlug : '' ?><?= $search ? '&search='.urlencode($search) : '' ?><?= $sort !== 'terbaru' ? '&sort='.$sort : '' ?>" 
                   class="page-link <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
            <?php if ($endPage < $totalPages): ?>
                <?php if ($endPage < $totalPages - 1): ?><span style="display:flex;align-items:center;padding:0 8px;color:#ccc;">...</span><?php endif; ?>
                <a href="?page=<?= $totalPages ?><?= $categorySlug ? '&category='.$categorySlug : '' ?><?= $search ? '&search='.urlencode($search) : '' ?><?= $sort !== 'terbaru' ? '&sort='.$sort : '' ?>" class="page-link"><?= $totalPages ?></a>
            <?php endif; ?>
            <a href="?page=<?= min($totalPages, $page + 1) ?><?= $categorySlug ? '&category='.$categorySlug : '' ?><?= $search ? '&search='.urlencode($search) : '' ?><?= $sort !== 'terbaru' ? '&sort='.$sort : '' ?>" 
               class="page-link <?= $page >= $totalPages ? 'disabled' : '' ?>">&raquo;</a>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <!-- Empty State -->
        <div class="empty-state">
            <i class="fas fa-box-open"></i>
            <h2>Produk Tidak Ditemukan</h2>
            <p>
                <?php if ($search): ?>
                    Maaf, tidak ada produk yang cocok dengan pencarian "<?= htmlspecialchars($search) ?>".
                <?php else: ?>
                    Belum ada produk dalam kategori ini.
                <?php endif; ?>
            </p>
            <a href="<?= SITE_URL ?>/pages/products.php" class="btn btn-primary btn-lg">Lihat Semua Produk</a>
        </div>
        <?php endif; ?>
    </div>
</section>

<?php include '../includes/footer.php'; ?>
