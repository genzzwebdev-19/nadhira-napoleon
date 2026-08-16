<?php
// ============================================
// WISHLIST - NADHIRA NAPOLEON
// Produk favorit customer yang sudah login
// ============================================
require_once '../config/database.php';

if (!isLoggedIn()) {
    header('Location: ' . SITE_URL . '/auth/login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$page_title = 'Wishlist Saya';
$meta_description = 'Produk favorit Anda di Nadhira Napoleon Pekanbaru.';

$conn = getConnection();

// Handle remove from wishlist
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove'])) {
    $productId = (int)$_POST['product_id'];
    $conn->query("DELETE FROM wishlists WHERE user_id = $userId AND product_id = $productId");
    $removed = true;
}

// Handle add all to cart
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_all_to_cart'])) {
    $wishlistItems = $conn->query("SELECT product_id FROM wishlists WHERE user_id = $userId");
    if ($wishlistItems) {
        while ($item = $wishlistItems->fetch_assoc()) {
            $pid = (int)$item['product_id'];
            $check = $conn->query("SELECT id, quantity FROM carts WHERE user_id = $userId AND product_id = $pid LIMIT 1");
            if ($check && $check->num_rows > 0) {
                $row = $check->fetch_assoc();
                $conn->query("UPDATE carts SET quantity = quantity + 1 WHERE id = {$row['id']}");
            } else {
                $conn->query("INSERT INTO carts (user_id, product_id, quantity) VALUES ($userId, $pid, 1)");
            }
        }
        header('Location: ' . SITE_URL . '/pages/cart.php');
        exit;
    }
}

// Get wishlist with product details and primary image
$wishlist = $conn->query("
    SELECT 
        w.id as wishlist_id,
        w.created_at as wishlist_added,
        p.id as product_id,
        p.name,
        p.slug,
        p.price,
        p.discount_price,
        p.stock,
        p.rating,
        p.total_sold,
        p.is_featured,
        p.is_best_seller,
        c.name as category_name,
        (SELECT pi.image FROM product_images pi WHERE pi.product_id = p.id AND pi.is_primary = TRUE LIMIT 1) as product_image
    FROM wishlists w
    JOIN products p ON w.product_id = p.id AND p.is_active = TRUE
    LEFT JOIN product_categories c ON p.category_id = c.id
    WHERE w.user_id = $userId
    ORDER BY w.created_at DESC
");

$totalWishlist = $wishlist ? $wishlist->num_rows : 0;

include '../includes/header.php';
?>

<section style="padding-top: calc(var(--navbar-total-height, 120px) + 8px); min-height: 100vh;">
    <div class="container">
        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="<?= SITE_URL ?>">Home</a>
            <span class="separator">/</span>
            <a href="<?= SITE_URL ?>/auth/profile.php">Profil</a>
            <span class="separator">/</span>
            <span class="current">Wishlist</span>
        </div>

        <!-- Header -->
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: var(--space-md); margin-bottom: var(--space-2xl);">
            <div>
                <h1 style="font-family: var(--font-display); font-size: var(--text-4xl); font-weight: 700;">
                    Wishlist <span class="gold-text">Saya</span>
                </h1>
                <p style="color: var(--text-muted);">
                    <?= $totalWishlist ?> produk favorit Anda
                </p>
            </div>
            <div style="display: flex; gap: var(--space-md);">
                <?php if ($totalWishlist > 0): ?>
                <form method="POST" onsubmit="return confirm('Tambahkan semua produk ke keranjang?')">
                    <button type="submit" name="add_all_to_cart" class="btn btn-primary">
                        <i class="fas fa-shopping-bag"></i>
                        Tambah Semua ke Keranjang
                    </button>
                </form>
                <?php endif; ?>
                <a href="<?= SITE_URL ?>/pages/products.php" class="btn btn-outline">
                    <i class="fas fa-th-large"></i>
                    Belanja Lagi
                </a>
            </div>
        </div>

        <!-- Success message after remove -->
        <?php if (isset($removed)): ?>
            <div class="alert alert-success" style="padding: 12px 16px; background: #D1FAE5; border-radius: var(--radius-md); margin-bottom: var(--space-lg); color: #059669; font-size: var(--text-sm); display: flex; align-items: center; gap: var(--space-sm);">
                <i class="fas fa-check-circle"></i>
                Produk berhasil dihapus dari wishlist
            </div>
        <?php endif; ?>

        <!-- Wishlist Content -->
        <?php if ($totalWishlist > 0): ?>
            <div class="grid grid-4">
                <?php while ($item = $wishlist->fetch_assoc()): ?>
                <div class="product-card" data-aos="fade-up">
                    <!-- Badges -->
                    <?php if ($item['is_best_seller']): ?>
                        <div class="product-card-badge best-seller">Best Seller</div>
                    <?php elseif ($item['discount_price'] > 0): ?>
                        <div class="product-card-badge discount">
                            -<?= round((1 - $item['discount_price'] / $item['price']) * 100) ?>%
                        </div>
                    <?php elseif ($item['is_featured']): ?>
                        <div class="product-card-badge new">Premium</div>
                    <?php endif; ?>

                    <!-- Image -->
                    <a href="<?= SITE_URL ?>/pages/product-detail.php?id=<?= $item['product_id'] ?>" class="product-card-image">
                        <img src="<?= $item['product_image'] ?: 'https://images.unsplash.com/photo-1551024601-bec78aea704b?w=400&q=80' ?>" 
                             alt="<?= htmlspecialchars($item['name']) ?>" 
                             loading="lazy">
                        <div class="product-card-actions">
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="product_id" value="<?= $item['product_id'] ?>">
                                <button type="submit" name="remove" class="product-card-action" 
                                        onclick="return confirm('Hapus <?= htmlspecialchars($item['name']) ?> dari wishlist?')"
                                        title="Hapus dari wishlist" style="background: #FEE2E2; color: #DC2626;">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                            <button class="product-card-action" onclick="addToCart(<?= $item['product_id'] ?>)" title="Tambah ke keranjang">
                                <i class="fas fa-shopping-bag"></i>
                            </button>
                        </div>
                    </a>

                    <!-- Body -->
                    <div class="product-card-body">
                        <div class="product-card-category"><?= htmlspecialchars($item['category_name'] ?? 'Produk') ?></div>
                        <a href="<?= SITE_URL ?>/pages/product-detail.php?id=<?= $item['product_id'] ?>">
                            <h3 class="product-card-name"><?= htmlspecialchars($item['name']) ?></h3>
                        </a>
                        <div class="product-card-rating">
                            <span class="stars"><?= str_repeat('★', (int)$item['rating']) . str_repeat('☆', 5 - (int)$item['rating']) ?></span>
                            <span class="count">(<?= number_format($item['total_sold']) ?> terjual)</span>
                        </div>
                        <div style="font-size: var(--text-xs); color: var(--text-light); margin-bottom: var(--space-sm);">
                            <i class="far fa-clock"></i> Ditambahkan <?= formatDate($item['wishlist_added'], 'd M Y') ?>
                        </div>
                        <div class="product-card-footer">
                            <span class="product-card-price">
                                <?php if ($item['discount_price'] > 0): ?>
                                    Rp <?= number_format($item['discount_price'], 0, ',', '.') ?>
                                    <span class="original">Rp <?= number_format($item['price'], 0, ',', '.') ?></span>
                                <?php else: ?>
                                    Rp <?= number_format($item['price'], 0, ',', '.') ?>
                                <?php endif; ?>
                            </span>
                            <span class="product-card-stock <?= $item['stock'] > 5 ? 'available' : ($item['stock'] > 0 ? 'low' : 'out') ?>">
                                <?= $item['stock'] > 0 ? 'Tersedia' : 'Habis' ?>
                            </span>
                        </div>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>

            <!-- Bottom Actions -->
            <div style="text-align: center; margin-top: var(--space-3xl); display: flex; justify-content: center; gap: var(--space-md); flex-wrap: wrap;">
                <form method="POST" onsubmit="return confirm('Tambahkan semua produk ke keranjang?')">
                    <button type="submit" name="add_all_to_cart" class="btn btn-primary btn-lg">
                        <i class="fas fa-shopping-bag"></i>
                        Tambah Semua ke Keranjang (<?= $totalWishlist ?> produk)
                    </button>
                </form>
                <a href="<?= SITE_URL ?>/pages/products.php" class="btn btn-secondary btn-lg">
                    <i class="fas fa-th-large"></i>
                    Jelajahi Produk Lain
                </a>
            </div>

        <?php else: ?>
            <!-- Empty State -->
            <div style="text-align: center; padding: var(--space-5xl) var(--space-xl);" data-aos="fade-up">
                <div style="font-size: 5rem; margin-bottom: var(--space-xl); color: var(--text-light);">
                    <i class="far fa-heart"></i>
                </div>
                <h2 style="font-family: var(--font-display); font-size: var(--text-3xl); font-weight: 600; margin-bottom: var(--space-md);">
                    Wishlist <span class="gold-text">Kosong</span>
                </h2>
                <p style="color: var(--text-muted); font-size: var(--text-lg); max-width: 400px; margin: 0 auto var(--space-2xl);">
                    Anda belum menambahkan produk favorit. Klik ikon hati pada produk untuk menyimpannya di sini!
                </p>
                <div style="display: flex; justify-content: center; gap: var(--space-md); flex-wrap: wrap;">
                    <a href="<?= SITE_URL ?>/pages/products.php" class="btn btn-primary btn-lg">
                        <i class="fas fa-th-large"></i>
                        Lihat Produk
                    </a>
                    <a href="<?= SITE_URL ?>" class="btn btn-secondary btn-lg">
                        <i class="fas fa-home"></i>
                        Kembali ke Beranda
                    </a>
                </div>
            </div>

            <!-- Help Cards -->
            <div class="help-grid">
                <div style="text-align: center; padding: var(--space-2xl); background: var(--warm-white); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm);">
                    <div style="width: 64px; height: 64px; margin: 0 auto var(--space-lg); background: var(--soft-gold-gradient); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; color: var(--soft-gold);">
                        <i class="fas fa-heart"></i>
                    </div>
                    <h4 style="font-family: var(--font-display); font-size: var(--text-lg); font-weight: 600; margin-bottom: var(--space-sm);">Klik Ikon Hati</h4>
                    <p style="font-size: var(--text-sm); color: var(--text-muted);">Klik ikon hati pada produk yang Anda sukai untuk menyimpannya</p>
                </div>
                <div style="text-align: center; padding: var(--space-2xl); background: var(--warm-white); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm);">
                    <div style="width: 64px; height: 64px; margin: 0 auto var(--space-lg); background: var(--soft-gold-gradient); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; color: var(--soft-gold);">
                        <i class="fas fa-shopping-bag"></i>
                    </div>
                    <h4 style="font-family: var(--font-display); font-size: var(--text-lg); font-weight: 600; margin-bottom: var(--space-sm);">Belanja Nanti</h4>
                    <p style="font-size: var(--text-sm); color: var(--text-muted);">Simpan produk favorit dan belanja nanti dengan satu klik</p>
                </div>
                <div style="text-align: center; padding: var(--space-2xl); background: var(--warm-white); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm);">
                    <div style="width: 64px; height: 64px; margin: 0 auto var(--space-lg); background: var(--soft-gold-gradient); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; color: var(--soft-gold);">
                        <i class="fas fa-bell"></i>
                    </div>
                    <h4 style="font-family: var(--font-display); font-size: var(--text-lg); font-weight: 600; margin-bottom: var(--space-sm);">Dapatkan Notifikasi</h4>
                    <p style="font-size: var(--text-sm); color: var(--text-muted);">Kami akan memberi tahu jika ada promo untuk produk wishlist Anda</p>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php include '../includes/footer.php'; ?>
