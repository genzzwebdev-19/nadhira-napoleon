<?php
// ============================================
// PRODUCT DETAIL PAGE
// Menampilkan detail produk dari database
// Website Nadhira Napoleon Pekanbaru
// ============================================
require_once '../config/database.php';
require_once '../config/rbac.php'; // verifyCsrf/csrfField untuk form ulasan

$conn = getConnection();
$productId = (int)($_GET['id'] ?? 0);

// Get product data
$product = null;
if ($conn && $productId > 0) {
    $r = $conn->query("
        SELECT p.*, c.name as category_name, c.slug as category_slug
        FROM products p
        LEFT JOIN product_categories c ON p.category_id = c.id
        WHERE p.id = $productId AND p.is_active = TRUE
        LIMIT 1
    ");
    if ($r && $r->num_rows > 0) $product = $r->fetch_assoc();
}

// Paket membership tidak ditampilkan sebagai produk biasa — arahkan ke halaman membership
if ($product && isMembershipProduct($productId)) {
    header('Location: ' . SITE_URL . '/pages/membership.php');
    exit;
}

// 404 if not found
if (!$product) {
    header('HTTP/1.0 404 Not Found');
    $page_title = 'Produk Tidak Ditemukan';
    $meta_description = 'Produk yang Anda cari tidak ditemukan.';
    include '../includes/header.php';
    ?>
    <section style="min-height: 80vh; display: flex; align-items: center; padding-top: calc(var(--navbar-total-height, 80px) + 8px);">
        <div class="container">
            <div style="text-align: center; max-width: 500px; margin: 0 auto;">
                <i class="fas fa-box-open" style="font-size: 4rem; color: var(--soft-gold); opacity: 0.5; margin-bottom: var(--space-xl);"></i>
                <h1 style="font-family: var(--font-display); font-size: var(--text-3xl); font-weight: 700; margin-bottom: var(--space-md);">
                    Produk <span class="gold-text">Tidak Ditemukan</span>
                </h1>
                <p style="color: var(--text-muted); margin-bottom: var(--space-2xl);">Maaf, produk yang Anda cari tidak tersedia.</p>
                <a href="<?= SITE_URL ?>/pages/products.php" class="btn btn-primary btn-lg"><i class="fas fa-arrow-left"></i> Kembali ke Produk</a>
            </div>
        </div>
    </section>
    <?php include '../includes/footer.php'; exit;
}

// Get product images
$images = $conn->query("SELECT * FROM product_images WHERE product_id = $productId ORDER BY is_primary DESC, sort_order ASC");
$productImages = [];
if ($images) { while ($img = $images->fetch_assoc()) $productImages[] = $img; }

// Get reviews
$reviews = $conn->query("SELECT * FROM product_reviews WHERE product_id = $productId AND is_active = TRUE ORDER BY created_at DESC");
$reviewCount = $reviews ? $reviews->num_rows : 0;

// ============================================
// POST - SIMPAN ULASAN PRODUK
// Hanya pembeli terverifikasi (pesanan berstatus 'delivered' berisi produk ini)
// yang boleh menulis ulasan. Ulasan baru berstatus pending (is_active = 0)
// hingga disetujui admin (admin/reviews.php).
// ============================================
$reviewMessage = '';
$reviewError = '';
$canReview = false;
$alreadyReviewed = false;
if (isLoggedIn()) {
    $canReview = userCanReviewProduct((int)$_SESSION['user_id'], $productId);
    $alreadyReviewed = userAlreadyReviewedProduct((int)$_SESSION['user_id'], $productId);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review'])) {
    if (!isLoggedIn()) {
        $reviewError = 'Silakan login terlebih dahulu untuk menulis ulasan.';
    } elseif (!verifyCsrf()) {
        $reviewError = 'Sesi berakhir. Muat ulang halaman dan coba lagi.';
    } else {
        $uid = (int)$_SESSION['user_id'];
        $rating = (int)($_POST['rating'] ?? 0);
        $reviewText = trim($_POST['review'] ?? '');
        if ($rating < 1 || $rating > 5) {
            $reviewError = 'Pilih rating bintang (1 - 5).';
        } elseif ($reviewText === '') {
            $reviewError = 'Tuliskan komentar ulasan Anda.';
        } elseif (userAlreadyReviewedProduct($uid, $productId)) {
            $reviewError = 'Anda sudah pernah mengulas produk ini.';
        } elseif (!userCanReviewProduct($uid, $productId)) {
            $reviewError = 'Hanya pembeli yang sudah menerima produk ini yang bisa menulis ulasan.';
        } else {
            $reviewer = getCurrentUser();
            $rName = trim($reviewer['full_name'] ?? '');
            if ($rName === '') $rName = trim($reviewer['name'] ?? '');
            if ($rName === '') $rName = trim($reviewer['email'] ?? '');
            $rNameE = $conn->real_escape_string(mb_substr($rName, 0, 255));
            $reviewE = $conn->real_escape_string(mb_substr($reviewText, 0, 2000));
            $ok = $conn->query("INSERT INTO product_reviews (product_id, user_id, reviewer_name, rating, review, is_verified, is_active)
                VALUES ($productId, $uid, '$rNameE', $rating, '$reviewE', 1, 0)");
            if ($ok) {
                $reviewMessage = 'Terima kasih! Ulasan Anda terkirim dan menunggu persetujuan admin.';
            } else {
                $reviewError = 'Gagal menyimpan ulasan: ' . $conn->error;
            }
        }
    }
}

// Cabang tempat produk ini tersedia (produk yang belum diatur = semua cabang aktif)
// + stok per cabang (branch_products.stock) untuk ditampilkan di panel ketersediaan
ensureBranchProductsStock();
ensureBranchesOpenHours(); // pastikan kolom jam buka/tutup tersedia
$prodBranches = [];
$rBp = $conn->query("SELECT b.id, b.name, b.address, b.latitude, b.longitude, b.open_hours, b.open_time, b.close_time,
        COALESCE(bp.stock, 0) AS stock
    FROM branch_products bp
    JOIN branches b ON b.id = bp.branch_id
    WHERE bp.product_id = $productId AND bp.is_available = 1 AND b.is_active = 1");
if ($rBp) { while ($row = $rBp->fetch_assoc()) $prodBranches[] = $row; }
if (empty($prodBranches)) {
    $prodBranches = getActiveBranches();
    foreach ($prodBranches as $i => $b) {
        $s = getProductStockForBranch($productId, (int)$b['id']);
        $prodBranches[$i]['stock'] = $s !== null ? $s : (int)$product['stock'];
    }
}

// Get related products (same category, exclude current)
$related = $conn->query("
    SELECT p.id, p.name, p.price, p.discount_price, p.slug, p.rating,
        (SELECT image FROM product_images WHERE product_id = p.id AND is_primary = 1 LIMIT 1) as product_image
    FROM products p WHERE p.category_id = {$product['category_id']} AND p.id != $productId AND p.is_active = TRUE
    AND p.id NOT IN (SELECT product_id FROM membership_plans WHERE product_id IS NOT NULL)
    ORDER BY RAND() LIMIT 4
");
$relatedProducts = [];
if ($related) { while ($rp = $related->fetch_assoc()) $relatedProducts[] = $rp; }

$page_title = htmlspecialchars($product['name']) . ' - Detail Produk';
$meta_description = htmlspecialchars(mb_substr(strip_tags($product['description']), 0, 160));
include '../includes/header.php';

$price = (float)$product['price'];
$discount = (float)$product['discount_price'];
$hasDiscount = $discount > 0;
$displayPrice = $hasDiscount ? $discount : $price;
$stars = str_repeat('★', (int)$product['rating']) . str_repeat('☆', 5 - (int)$product['rating']);
$stockStatus = $product['stock'] > 5 ? 'Tersedia' : ($product['stock'] > 0 ? 'Sisa ' . $product['stock'] : 'Habis');
$stockClass = $product['stock'] > 5 ? '#10B981' : ($product['stock'] > 0 ? '#D97706' : '#DC2626');
?>

<section class="product-detail">
    <div class="container">
        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="<?= SITE_URL ?>">Home</a>
            <span class="separator">/</span>
            <a href="<?= BASE_PATH ?>/pages/products.php">Produk</a>
            <span class="separator">/</span>
            <a href="<?= BASE_PATH ?>/pages/products.php?category=<?= urlencode($product['category_slug']) ?>"><?= htmlspecialchars($product['category_name']) ?></a>
            <span class="separator">/</span>
            <span class="current"><?= htmlspecialchars($product['name']) ?></span>
        </div>

        <div class="product-detail-layout">
            <!-- Product Gallery -->
            <div class="product-gallery" data-aos="fade-right">
                <?php $mainImage = !empty($productImages) ? $productImages[0]['image'] : 'https://images.unsplash.com/photo-1551024601-bec78aea704b?w=800&q=80'; ?>
                <div class="product-gallery-main" onclick="openImageLightbox('<?= htmlspecialchars($mainImage, ENT_QUOTES) ?>')">
                    <img src="<?= htmlspecialchars($mainImage) ?>" 
                         alt="<?= htmlspecialchars($product['name']) ?>" id="main-product-image">
                    <div class="product-gallery-zoom">
                        <i class="fas fa-expand"></i>
                    </div>
                </div>
                <?php if (count($productImages) > 1): ?>
                <div class="product-gallery-thumbs">
                    <?php foreach ($productImages as $i => $img): 
                        $thumbSrc = $img['image'];
                    ?>
                    <div class="product-gallery-thumb <?= $i === 0 ? 'active' : '' ?>" 
                         onclick="switchProductImage(this, '<?= htmlspecialchars($thumbSrc, ENT_QUOTES) ?>')">
                        <img src="<?= htmlspecialchars($thumbSrc) ?>" alt="<?= htmlspecialchars($product['name']) ?> View <?= $i + 1 ?>">
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Product Info -->
            <div class="product-info" data-aos="fade-left" data-aos-delay="100">
                <div class="product-card-category" style="font-size: var(--text-sm); margin-bottom: var(--space-sm);">
                    <?= htmlspecialchars($product['category_name']) ?>
                </div>
                <h1><?= htmlspecialchars($product['name']) ?></h1>

                <div class="product-meta">
                    <div style="display: flex; align-items: center; gap: 4px;">
                        <span style="color: var(--soft-gold);"><?= $stars ?></span>
                        <span style="color: var(--text-muted); font-size: var(--text-sm);">(<?= $reviewCount ?> ulasan)</span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 4px;">
                        <i class="fas fa-shopping-bag" style="color: var(--soft-gold);"></i>
                        <span style="color: var(--text-muted); font-size: var(--text-sm);">Terjual <?= number_format($product['total_sold']) ?>+</span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 4px;">
                        <i class="fas fa-check-circle" style="color: <?= $stockClass ?>;"></i>
                        <span style="color: <?= $stockClass ?>; font-size: var(--text-sm);"><?= $stockStatus ?></span>
                    </div>
                </div>

                <div class="product-price-section">
                    <div class="product-price">
                        Rp <?= number_format($displayPrice, 0, ',', '.') ?>
                        <?php if ($hasDiscount): ?>
                            <span class="original">Rp <?= number_format($price, 0, ',', '.') ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if (isLoggedIn() && getMemberDiscountRate() > 0):
                        $mRate = getMemberDiscountRate();
                        $mLevel = getMemberLevelLabel(getCurrentUser()['membership'] ?? '');
                        $mPrice = round($displayPrice * (1 - $mRate / 100));
                    ?>
                    <div style="display: inline-block; margin-top: var(--space-sm); padding: 6px 12px; background: var(--soft-gold-gradient); border: 1px dashed var(--soft-gold); border-radius: 8px; font-size: var(--text-sm); color: var(--text-secondary);">
                        💎 Harga member <?= htmlspecialchars($mLevel) ?>: <strong style="color: var(--warm-orange);">Rp <?= number_format($mPrice, 0, ',', '.') ?></strong>
                        <small style="color: var(--text-muted);">(diskon <?= (int)$mRate ?>% otomatis di keranjang)</small>
                    </div>
                    <?php endif; ?>
                    <p style="color: var(--text-muted); font-size: var(--text-sm); margin-top: var(--space-sm);">
                        <?php if ($hasDiscount): ?>Hemat Rp <?= number_format($price - $discount, 0, ',', '.') ?>! <?php endif; ?>
                        Harga per box
                    </p>
                </div>

                <p style="color: var(--text-secondary); line-height: 1.8; margin-bottom: var(--space-xl);">
                    <?= nl2br(htmlspecialchars($product['description'])) ?>
                </p>

                <!-- Ketersediaan di Cabang -->
                <?php if (!empty($prodBranches)): ?>
                <div class="branch-avail-panel" data-branches='<?= htmlspecialchars(json_encode(array_map(function ($b) { return ['name' => $b['name']]; }, $prodBranches), JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES) ?>'>
                    <div class="branch-avail-head">
                        <i class="fas fa-store" aria-hidden="true"></i>
                        <div>
                            <strong>Tersedia di <?= count($prodBranches) ?> cabang</strong>
                            <span class="branch-avail-hint">Terdekat dari lokasi Anda akan ditandai otomatis.</span>
                        </div>
                    </div>
                    <div class="branch-avail-list">
                        <?php foreach ($prodBranches as $b):
                            $bStock = (int)($b['stock'] ?? 0);
                            $stockLabel = $bStock > 5 ? 'Tersedia' : ($bStock > 0 ? 'Sisa ' . $bStock : 'Habis');
                            $stockColor = $bStock > 5 ? '#059669' : ($bStock > 0 ? '#D97706' : '#DC2626');
                        ?>
                        <div class="branch-avail-item" data-lat="<?= (float)$b['latitude'] ?>" data-lng="<?= (float)$b['longitude'] ?>">
                            <div class="branch-avail-info">
                                <span class="branch-nearest-badge">Terdekat</span>
                                <strong><?= htmlspecialchars($b['name']) ?></strong>
                                <small><?= htmlspecialchars($b['address']) ?></small>
                                <?php $branchHours = formatBranchHours($b); ?>
                                <?php if ($branchHours !== ''): ?>
                                <small class="branch-avail-hours"><i class="far fa-clock" aria-hidden="true"></i> <?= htmlspecialchars($branchHours) ?></small>
                                <?php endif; ?>
                                <small class="branch-avail-stock" style="color: <?= $stockColor ?>;"><i class="fas fa-box" aria-hidden="true"></i> <?= $stockLabel ?></small>
                            </div>
                            <span class="branch-avail-dist">—</span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="product-qty">
                    <span style="font-weight: 500;">Jumlah:</span>
                    <button class="qty-btn minus" onclick="updateQty(-1)"><i class="fas fa-minus"></i></button>
                    <input type="number" class="qty-input" id="product-qty" value="1" min="1" max="99">
                    <button class="qty-btn plus" onclick="updateQty(1)"><i class="fas fa-plus"></i></button>
                </div>

                <div class="product-actions">
                    <button class="btn btn-primary btn-lg" style="flex: 1;" onclick="addToCart(<?= $product['id'] ?>)">
                        <i class="fas fa-shopping-bag"></i> Tambah ke Keranjang
                    </button>
                    <button class="btn btn-dark btn-lg" style="flex: 1;" onclick="addToCart(<?= $product['id'] ?>); window.location.href='<?= BASE_PATH ?>/pages/cart.php'">
                        <i class="fas fa-bolt"></i> Beli Sekarang
                    </button>
                    <button class="btn btn-outline btn-lg btn-icon wishlist-btn" onclick="toggleWishlist(<?= $product['id'] ?>, this)" aria-label="Wishlist" data-product-id="<?= $product['id'] ?>">
                        <i class="far fa-heart wishlist-icon"></i>
                    </button>
                    <button class="btn btn-outline btn-lg btn-icon" onclick="navigator.share({title:'<?= htmlspecialchars($product['name'], ENT_QUOTES) ?>',url:window.location.href})" aria-label="Share">
                        <i class="fas fa-share-alt"></i>
                    </button>
                </div>

                <!-- Tanya via WhatsApp -->
                <?php
                $waSiteName = trim(getSetting('site_name', SITE_NAME));
                if ($waSiteName === '') { $waSiteName = SITE_NAME; }
                $waProductMsg = 'Halo ' . $waSiteName . ', saya ingin bertanya tentang produk ' . $product['name'] . '. Apakah produk ini tersedia?';
                ?>
                <a href="<?= htmlspecialchars(getWhatsAppLink($waProductMsg)) ?>" 
                   class="btn btn-whatsapp btn-lg w-full" 
                   target="_blank" 
                   rel="noopener"
                   style="margin-bottom: var(--space-xl);">
                    <i class="fab fa-whatsapp"></i> Tanya via WhatsApp
                </a>

                <!-- Info Tabs -->
                <div class="product-info-tabs">
                    <div class="tab-nav">
                        <span class="tab-nav-item active" data-tab="description">Deskripsi</span>
                        <?php if ($product['composition']): ?>
                        <span class="tab-nav-item" data-tab="composition">Komposisi</span>
                        <?php endif; ?>
                        <?php if ($product['storage_instructions']): ?>
                        <span class="tab-nav-item" data-tab="storage">Penyimpanan</span>
                        <?php endif; ?>
                        <span class="tab-nav-item" data-tab="reviews">Ulasan (<?= $reviewCount ?>)</span>
                    </div>

                    <div class="tab-content active" data-tab-content="description">
                        <p style="line-height: 1.8;"><?= nl2br(htmlspecialchars($product['description'])) ?></p>
                        <?php if ($product['weight']): ?>
                        <p style="line-height: 1.8; margin-top: var(--space-md);"><strong>Berat:</strong> <?= htmlspecialchars($product['weight']) ?></p>
                        <?php endif; ?>
                        <?php if ($product['expiration']): ?>
                        <p style="line-height: 1.8;"><strong>Masa Kadaluarsa:</strong> <?= htmlspecialchars($product['expiration']) ?></p>
                        <?php endif; ?>
                        <?php if ($product['storage_instructions']): ?>
                        <p style="line-height: 1.8;"><strong>Cara Penyimpanan:</strong> <?= htmlspecialchars($product['storage_instructions']) ?></p>
                        <?php endif; ?>
                    </div>

                    <?php if ($product['composition']): ?>
                    <div class="tab-content" data-tab-content="composition">
                        <p style="line-height: 1.8;"><?= nl2br(htmlspecialchars($product['composition'])) ?></p>
                        <p style="line-height: 1.8; margin-top: var(--space-md);"><strong>Informasi Alergi:</strong> Mengandung gluten, susu, dan telur.</p>
                    </div>
                    <?php endif; ?>

                    <?php if ($product['storage_instructions']): ?>
                    <div class="tab-content" data-tab-content="storage">
                        <p style="line-height: 1.8;"><?= nl2br(htmlspecialchars($product['storage_instructions'])) ?></p>
                    </div>
                    <?php endif; ?>

                    <div class="tab-content" data-tab-content="reviews">
                        <?php if ($reviewMessage): ?>
                        <div style="background: #ECFDF5; border: 1px solid #A7F3D0; border-radius: var(--radius-md); padding: var(--space-md); margin-bottom: var(--space-lg); color: #065F46; font-size: var(--text-sm);">
                            <i class="fas fa-check-circle"></i> <?= htmlspecialchars($reviewMessage) ?>
                        </div>
                        <?php endif; ?>
                        <?php if ($reviewError): ?>
                        <div style="background: #FEF2F2; border: 1px solid #FECACA; border-radius: var(--radius-md); padding: var(--space-md); margin-bottom: var(--space-lg); color: #DC2626; font-size: var(--text-sm);">
                            <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($reviewError) ?>
                        </div>
                        <?php endif; ?>

                        <!-- Form Tulis Ulasan -->
                        <?php if ($canReview && !$alreadyReviewed): ?>
                        <div style="background: var(--soft-gold-gradient); border: 1px dashed var(--soft-gold); border-radius: var(--radius-md); padding: var(--space-lg); margin-bottom: var(--space-xl);">
                            <h4 style="font-family: var(--font-display); font-weight: 600; margin-bottom: var(--space-sm);">✍️ Tulis Ulasan Anda</h4>
                            <form method="POST" action="">
                                <input type="hidden" name="submit_review" value="1">
                                <?= csrfField() ?>
                                <div style="margin-bottom: var(--space-sm);">
                                    <label style="font-size: var(--text-sm); font-weight: 600; display: block; margin-bottom: 6px;">Rating</label>
                                    <div class="review-stars-input" style="display: flex; gap: 6px; font-size: 26px; cursor: pointer;">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <span data-star="<?= $i ?>" class="review-star" style="color: #d1d5db; transition: color .15s ease;">★</span>
                                        <?php endfor; ?>
                                    </div>
                                    <input type="hidden" name="rating" id="review-rating" value="5">
                                </div>
                                <div class="form-group">
                                    <label style="font-size: var(--text-sm); font-weight: 600; display: block; margin-bottom: 6px;">Komentar</label>
                                    <textarea name="review" class="form-textarea" rows="3" maxlength="2000"
                                              placeholder="Bagaimana menurut Anda tentang produk ini?" required></textarea>
                                </div>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-paper-plane"></i> Kirim Ulasan
                                </button>
                                <small style="display: block; margin-top: 8px; color: var(--text-muted);">Ulasan akan tampil setelah disetujui admin.</small>
                            </form>
                        </div>
                        <?php elseif (isLoggedIn() && $alreadyReviewed): ?>
                        <div style="background: #FFFBEB; border: 1px solid #FDE68A; border-radius: var(--radius-md); padding: var(--space-md); margin-bottom: var(--space-xl); font-size: var(--text-sm); color: #92400E;">
                            <i class="fas fa-check-circle"></i> Anda sudah pernah mengulas produk ini.
                        </div>
                        <?php elseif (!isLoggedIn()): ?>
                        <div style="background: var(--soft-gold-gradient); border: 1px dashed var(--soft-gold); border-radius: var(--radius-md); padding: var(--space-md); margin-bottom: var(--space-xl); font-size: var(--text-sm); color: var(--text-secondary);">
                            <i class="fas fa-star"></i> Punya pengalaman dengan produk ini?
                            <a href="<?= BASE_PATH ?>/auth/login.php?redirect=<?= urlencode($_SERVER['REQUEST_URI']) ?>" style="font-weight: 700; color: var(--soft-gold);">Login</a> lalu beri ulasan Anda!
                        </div>
                        <?php elseif (isLoggedIn() && !$canReview): ?>
                        <div style="background: #F3F4F6; border: 1px dashed #d1d5db; border-radius: var(--radius-md); padding: var(--space-md); margin-bottom: var(--space-xl); font-size: var(--text-sm); color: var(--text-muted);">
                            <i class="fas fa-lock"></i> Ulasan hanya untuk pembeli terverifikasi — setelah pesanan berisi produk ini berstatus <strong>Selesai</strong>, Anda bisa menulis ulasan di sini.
                        </div>
                        <?php endif; ?>

                        <?php if ($reviewCount > 0): ?>
                        <div style="display: flex; gap: var(--space-md); margin-bottom: var(--space-xl);">
                            <div style="text-align: center; padding: var(--space-lg); background: var(--soft-gold-gradient); border-radius: var(--radius-md); min-width: 120px;">
                                <div style="font-family: var(--font-display); font-size: var(--text-4xl); font-weight: 700; color: var(--soft-gold);">
                                    <?= number_format($product['rating'], 1) ?>
                                </div>
                                <div style="color: var(--soft-gold); font-size: var(--text-sm);"><?= $stars ?></div>
                                <div style="font-size: var(--text-xs); color: var(--text-muted);"><?= $reviewCount ?> ulasan</div>
                            </div>
                        </div>
                        <?php while ($review = $reviews->fetch_assoc()): 
                            $rStars = str_repeat('★', (int)$review['rating']) . str_repeat('☆', 5 - (int)$review['rating']);
                        ?>
                        <div class="testimonial-card" style="margin-bottom: var(--space-md);">
                            <div class="testimonial-stars"><?= $rStars ?></div>
                            <p class="testimonial-content" style="font-size: var(--text-sm);">"<?= htmlspecialchars($review['review']) ?>"</p>
                            <div class="testimonial-author">
                                <img src="https://i.pravatar.cc/100?img=<?= $review['id'] % 10 + 1 ?>" alt="<?= htmlspecialchars($review['reviewer_name']) ?>" class="testimonial-avatar">
                                <div>
                                    <div class="testimonial-name" style="font-size: var(--text-sm);"><?= htmlspecialchars($review['reviewer_name']) ?></div>
                                    <div class="testimonial-verified">Verified Purchase</div>
                                </div>
                            </div>
                        </div>
                        <?php endwhile; ?>
                        <?php else: ?>
                        <p style="text-align: center; color: var(--text-muted); padding: var(--space-xl);">Belum ada ulasan untuk produk ini. Jadilah yang pertama!</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Related Products -->
        <?php if (!empty($relatedProducts)): ?>
        <section style="padding: var(--space-4xl) 0;">
            <h2 style="font-family: var(--font-display); font-size: var(--text-3xl); font-weight: 700; margin-bottom: var(--space-xl);">
                Produk <span class="gold-text">Terkait</span>
            </h2>
            <div class="grid grid-4">
                <?php foreach ($relatedProducts as $rp): 
                    $rpPrice = $rp['discount_price'] > 0 ? $rp['discount_price'] : $rp['price'];
                    $rpImg = $rp['product_image'] ?: 'https://images.unsplash.com/photo-1551024601-bec78aea704b?w=400&q=80';
                ?>
                <div class="product-card">
                    <a href="?id=<?= $rp['id'] ?>" class="product-card-image">
                        <img src="<?= htmlspecialchars($rpImg) ?>" alt="<?= htmlspecialchars($rp['name']) ?>" loading="lazy">
                    </a>
                    <div class="product-card-body">
                        <div class="product-card-category"><?= htmlspecialchars($product['category_name']) ?></div>
                        <a href="?id=<?= $rp['id'] ?>">
                            <h3 class="product-card-name" style="font-size: var(--text-base);"><?= htmlspecialchars($rp['name']) ?></h3>
                        </a>
                        <div class="product-card-footer">
                            <span class="product-card-price" style="font-size: var(--text-lg);">
                                Rp <?= number_format($rpPrice, 0, ',', '.') ?>
                                <?php if ($rp['discount_price'] > 0): ?>
                                    <span class="original">Rp <?= number_format($rp['price'], 0, ',', '.') ?></span>
                                <?php endif; ?>
                            </span>
                            <button class="product-card-action" onclick="addToCart(<?= $rp['id'] ?>)" style="position: relative; transform: none; opacity: 1;" aria-label="Tambah ke keranjang">
                                <i class="fas fa-shopping-bag"></i>
                            </button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>
    </div>
</section>

<!-- Image Lightbox Modal -->
<div id="imageLightbox" class="image-lightbox-overlay" onclick="closeImageLightbox(event)">
    <div class="image-lightbox-content">
        <button class="image-lightbox-close" onclick="closeImageLightbox()" aria-label="Tutup">
            <i class="fas fa-times"></i>
        </button>
        <img id="lightboxImage" src="" alt="Perbesar Gambar">
    </div>
</div>

<script>
function updateQty(delta) {
    const input = document.getElementById('product-qty');
    let val = parseInt(input.value) + delta;
    if (val < 1) val = 1;
    if (val > 99) val = 99;
    input.value = val;
}

// Switch product main image
function switchProductImage(thumbEl, imgSrc) {
    // Update main image
    const mainImg = document.getElementById('main-product-image');
    mainImg.src = imgSrc;
    mainImg.style.transform = 'scale(0.95)';
    setTimeout(function() { mainImg.style.transform = 'scale(1)'; }, 200);

    // Update active thumb
    document.querySelectorAll('.product-gallery-thumb').forEach(function(t) {
        t.classList.remove('active');
    });
    thumbEl.classList.add('active');
}

// Open image lightbox
function openImageLightbox(imgSrc) {
    const overlay = document.getElementById('imageLightbox');
    const img = document.getElementById('lightboxImage');
    img.src = imgSrc;
    overlay.classList.add('active');
    document.body.style.overflow = 'hidden';
}

// Close image lightbox
function closeImageLightbox(event) {
    if (event && event.target !== event.currentTarget) return;
    const overlay = document.getElementById('imageLightbox');
    overlay.classList.remove('active');
    document.body.style.overflow = '';
}

// Keyboard support
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeImageLightbox();
    }
});

// Rating bintang interaktif pada form ulasan
(function() {
    var stars = document.querySelectorAll('.review-stars-input .review-star');
    if (!stars.length) return;
    var input = document.getElementById('review-rating');
    function paint(n) {
        stars.forEach(function(s) {
            var v = parseInt(s.getAttribute('data-star'), 10);
            s.style.color = v <= n ? '#D4A030' : '#d1d5db';
        });
    }
    stars.forEach(function(s) {
        s.addEventListener('click', function() {
            var v = parseInt(s.getAttribute('data-star'), 10);
            input.value = v;
            paint(v);
        });
        s.addEventListener('mouseenter', function() {
            paint(parseInt(s.getAttribute('data-star'), 10));
        });
        s.addEventListener('mouseleave', function() {
            paint(parseInt(input.value, 10) || 0);
        });
    });
    paint(parseInt(input.value, 10) || 0);
})();
</script>

<?php include '../includes/footer.php'; ?>
