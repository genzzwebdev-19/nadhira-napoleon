<?php
// ============================================
// LANDING PAGE - NADHIRA NAPOLEON PEKANBARU
// Premium Oleh-Oleh Khas Riau
// ============================================
require_once 'config/database.php';
$is_home = true; // flag for hero-specific navbar styling
$page_title = 'Premium Oleh-Oleh Khas Riau';
$meta_description = 'Pusat oleh-oleh premium khas Riau. Nikmati Napoleon, Pancake Durian, Cake, dan berbagai oleh-oleh khas Pekanbaru dengan cita rasa terbaik.';
include 'includes/header.php';
?>

<!-- ============================================
     HERO SECTION - SLIDER (kelola di Admin > Hero Slider)
     ============================================ -->
<section id="hero" class="hero">
    <?php
    // Ambil slide hero dari database (fallback ke pengaturan lama jika kosong)
    $connHero = getConnection();
    $heroSlides = [];
    if ($connHero) {
        $rHero = $connHero->query("SELECT * FROM hero_slides WHERE is_active = 1 ORDER BY sort_order ASC, id ASC");
        if ($rHero) {
            while ($hs = $rHero->fetch_assoc()) { $heroSlides[] = $hs; }
        }
    }
    if (empty($heroSlides)) {
        $heroSlides = [[
            'image' => getSetting('hero_background_image', ASSETS_URL . '/images/hero-bg.jpg'),
            'image_mobile' => getSetting('hero_background_image_mobile', ASSETS_URL . '/images/hero-bg-mobile.jpg'),
        ]];
    }
    ?>
    <div class="hero-slider" id="heroSlider">
        <?php foreach ($heroSlides as $i => $hs): ?>
        <div class="hero-slide<?= $i === 0 ? ' active' : '' ?>" data-hero-slide>
            <picture class="hero-bg-picture">
                <!-- Mobile: portrait 9:16 crop (HP) -->
                <source media="(max-width: 768px)" srcset="<?= htmlspecialchars($hs['image_mobile'] ?: $hs['image']) ?>">
                <!-- Desktop: landscape 16:9 crop -->
                <img class="hero-bg" src="<?= htmlspecialchars($hs['image']) ?>" alt="Nadhira Napoleon - Premium Oleh-Oleh Khas Riau" <?= $i === 0 ? 'fetchpriority="high"' : 'loading="lazy"' ?>>
            </picture>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="hero-overlay"></div>
    
    <!-- Decorative Elements -->
    <div class="hero-decoration hero-decoration-1"></div>
    <div class="hero-decoration hero-decoration-2"></div>

    <?php if (count($heroSlides) > 1): ?>
    <!-- Navigasi slider -->
    <button class="hero-slider-arrow hero-slider-prev" id="heroSliderPrev" aria-label="Slide sebelumnya">
        <i class="fas fa-chevron-left"></i>
    </button>
    <button class="hero-slider-arrow hero-slider-next" id="heroSliderNext" aria-label="Slide berikutnya">
        <i class="fas fa-chevron-right"></i>
    </button>
    <div class="hero-slider-dots" id="heroSliderDots"></div>
    <?php endif; ?>

    <div class="hero-content">
        <div class="hero-badge" data-aos="fade-up">Premium Oleh-Oleh Khas Riau</div>
        
        <h1 class="hero-title" data-aos="fade-up" data-aos-delay="100">
            NADHIRA<br>
            NAPOLEON
            <span class="gold-text">Pekanbaru</span>
        </h1>
        
        <p class="hero-tagline" data-aos="fade-up" data-aos-delay="200">
            "<?= htmlspecialchars(getSetting('footer_tagline', 'Membawa Cita Rasa Khas Riau Dalam Setiap Gigitan')) ?>"
        </p>
        
        <p class="hero-description" data-aos="fade-up" data-aos-delay="300">
            Nikmati pengalaman berbelanja oleh-oleh premium dengan cita rasa autentik Melayu Riau. 
            Dibuat dengan bahan-bahan terbaik dan resep tradisional yang telah disempurnakan.
        </p>
        
        <div class="hero-actions" data-aos="fade-up" data-aos-delay="400">
            <a href="<?= BASE_PATH ?>/pages/products.php" class="btn btn-primary btn-lg">
                <i class="fas fa-shopping-bag"></i>
                Belanja Sekarang
            </a>
            <a href="#story" class="btn btn-secondary btn-lg">
                <i class="fas fa-play-circle"></i>
                Lihat Produk
            </a>
            <a href="#contact" class="btn btn-outline btn-lg">
                <i class="fas fa-headset"></i>
                Hubungi Kami
            </a>
        </div>

        <?php
        // Media sosial dari pengaturan (Admin > Pengaturan > Sosial Media)
        $hsSocials = [];
        $hsIg = trim((string)getSetting('social_instagram', ''));
        $hsFb = trim((string)getSetting('social_facebook', ''));
        $hsTt = trim((string)getSetting('social_tiktok', ''));
        if ($hsIg !== '') $hsSocials[] = ['href' => 'https://instagram.com/' . urlencode(ltrim($hsIg, '@')), 'icon' => 'fa-instagram', 'label' => 'Instagram'];
        if ($hsFb !== '') $hsSocials[] = ['href' => 'https://facebook.com/' . urlencode(ltrim($hsFb, '@')), 'icon' => 'fa-facebook-f', 'label' => 'Facebook'];
        if ($hsTt !== '') $hsSocials[] = ['href' => 'https://tiktok.com/@' . urlencode(ltrim($hsTt, '@')), 'icon' => 'fa-tiktok', 'label' => 'TikTok'];
        ?>
        <?php if (!empty($hsSocials)): ?>
        <div class="hero-social" data-aos="fade-up" data-aos-delay="450">
            <span class="hero-social-label">Ikuti Kami</span>
            <?php foreach ($hsSocials as $hsS): ?>
            <a href="<?= htmlspecialchars($hsS['href']) ?>" class="hero-social-link" aria-label="<?= htmlspecialchars($hsS['label']) ?>" target="_blank" rel="noopener">
                <i class="fab <?= $hsS['icon'] ?>"></i>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php
        // Statistik dinamis dari database (fallback ke angka bawaan bila kosong)
        $hsConn = getConnection();
        $hsStats = ['sold' => 0, 'customers' => 0, 'rating' => 0, 'branches' => 0, 'days' => 7];
        if ($hsConn) {
            $hsR = $hsConn->query("SELECT COALESCE(SUM(total_sold),0) s FROM products");
            if ($hsR) $hsStats['sold'] = (int)$hsR->fetch_assoc()['s'];
            // Pelanggan = jumlah email unik yang pernah menyelesaikan pesanan (lebih akurat)
            $hsR = $hsConn->query("SELECT COUNT(DISTINCT customer_email) c FROM orders WHERE payment_status = 'paid'");
            if ($hsR) $hsStats['customers'] = (int)$hsR->fetch_assoc()['c'];
            $hsR = $hsConn->query("SELECT COUNT(*) c FROM branches WHERE is_active = 1");
            if ($hsR) $hsStats['branches'] = (int)$hsR->fetch_assoc()['c'];
            $hsR = $hsConn->query("SELECT AVG(rating) a FROM product_reviews WHERE is_active = 1 AND is_verified = 1");
            if ($hsR) { $hsStats['rating'] = (int)round((float)$hsR->fetch_assoc()['a']); }
        }
        $hsSold = $hsStats['sold'] > 0 ? $hsStats['sold'] : 500000;
        $hsCustomers = $hsStats['customers'] > 0 ? $hsStats['customers'] : 15000;
        $hsRating = $hsStats['rating'] > 0 ? $hsStats['rating'] : 5;
        $hsBranches = $hsStats['branches'] > 0 ? $hsStats['branches'] : 3;
        $hsDays = max(1, min(7, (int)getSetting('hero_open_days', '7')));
        ?>
        <div class="hero-stats" data-aos="fade-up" data-aos-delay="500">
            <div class="hero-stat">
                <span class="hero-stat-value" data-counter="<?= $hsSold ?>">0</span>
                <span class="hero-stat-label">Produk Terjual</span>
            </div>
            <div class="hero-stat">
                <span class="hero-stat-value" data-counter="<?= $hsCustomers ?>">0</span>
                <span class="hero-stat-label">Pelanggan Puas</span>
            </div>
            <div class="hero-stat">
                <span class="hero-stat-value" data-counter="<?= $hsRating ?>">0</span>
                <span class="hero-stat-label">Rating 5/5</span>
            </div>
            <div class="hero-stat">
                <span class="hero-stat-value" data-counter="<?= $hsBranches ?>">0</span>
                <span class="hero-stat-label">Cabang Kami</span>
            </div>
            <div class="hero-stat">
                <span class="hero-stat-value" data-counter="<?= $hsDays ?>">0</span>
                <span class="hero-stat-label">Hari Buka</span>
            </div>
        </div>
    </div>
</section>

<!-- Strip pola songket — transisi hero ke konten -->
<div class="songket-strip" aria-hidden="true"></div>

<section id="products" class="bg-songket">
    <div class="container">
        <div class="section-tag" data-aos="fade-up">Best Seller</div>
        <h2 class="section-title" data-aos="fade-up" data-aos-delay="100">Produk <span class="gold-text">Terlaris</span></h2>
        <p class="section-subtitle" data-aos="fade-up" data-aos-delay="150">Koleksi produk favorit yang menjadi pilihan pelanggan setia kami</p>

        <?php
        $conn_prod = getConnection();
        $bestProducts = null;
        if ($conn_prod) {
            $bestProducts = $conn_prod->query("
                SELECT p.*, c.name as category_name,
                    (SELECT pi.image FROM product_images pi WHERE pi.product_id = p.id AND pi.is_primary = TRUE LIMIT 1) as product_image
                FROM products p
                LEFT JOIN product_categories c ON p.category_id = c.id
                WHERE p.is_active = TRUE AND (p.is_best_seller = TRUE OR p.is_featured = TRUE)
                AND p.id NOT IN (SELECT product_id FROM membership_plans WHERE product_id IS NOT NULL)
                AND p.id NOT IN (SELECT product_id FROM packages WHERE product_id IS NOT NULL)
                ORDER BY p.is_best_seller DESC, p.total_sold DESC, p.rating DESC
                LIMIT 4
            ");
        }

        if ($bestProducts && $bestProducts->num_rows > 0):
        $prodDelay = 0;
        ?>
        <div class="grid grid-4">
            <?php while ($prod = $bestProducts->fetch_assoc()): 
                $displayPrice = $prod['discount_price'] > 0 ? $prod['discount_price'] : $prod['price'];
                $hasDiscount = $prod['discount_price'] > 0;
                $discountPercent = $hasDiscount ? round((1 - $prod['discount_price'] / $prod['price']) * 100) : 0;
                $stockStatus = $prod['stock'] > 5 ? 'available' : ($prod['stock'] > 0 ? 'low' : 'out');
                $stockLabel = $prod['stock'] > 5 ? 'Tersedia' : ($prod['stock'] > 0 ? 'Sisa ' . $prod['stock'] : 'Habis');
                $stars = str_repeat('★', (int)$prod['rating']) . str_repeat('☆', 5 - (int)$prod['rating']);
                $imgSrc = $prod['product_image'] ?: 'https://images.unsplash.com/photo-1551024601-bec78aea704b?w=400&q=80';
            ?>
            <div class="product-card" data-aos="fade-up" data-aos-delay="<?= $prodDelay ?>">
                <?php if ($prod['is_best_seller']): ?>
                    <div class="product-card-badge best-seller">Best Seller</div>
                <?php elseif ($hasDiscount): ?>
                    <div class="product-card-badge discount">-<?= $discountPercent ?>%</div>
                <?php elseif ($prod['is_featured']): ?>
                    <div class="product-card-badge new">Premium</div>
                <?php endif; ?>
                
                <a href="<?= BASE_PATH ?>/pages/product-detail.php?id=<?= $prod['id'] ?>" class="product-card-image">
                    <img src="<?= htmlspecialchars($imgSrc) ?>" 
                         alt="<?= htmlspecialchars($prod['name']) ?>" 
                         loading="lazy">
                    <div class="product-card-actions">
                        <button class="product-card-action" onclick="event.preventDefault(); addToCart(<?= $prod['id'] ?>)" aria-label="Tambah ke keranjang">
                            <i class="fas fa-shopping-bag"></i>
                        </button>
                        <button class="product-card-action wishlist-btn" onclick="event.preventDefault(); toggleWishlist(<?= $prod['id'] ?>, this)" aria-label="Tambah ke wishlist" data-product-id="<?= $prod['id'] ?>">
                            <i class="far fa-heart wishlist-icon"></i>
                        </button>
                    </div>
                </a>
                <div class="product-card-body">
                    <div class="product-card-category"><?= htmlspecialchars($prod['category_name'] ?: 'Produk') ?></div>
                    <a href="<?= BASE_PATH ?>/pages/product-detail.php?id=<?= $prod['id'] ?>" style="text-decoration: none; color: inherit;">
                        <h3 class="product-card-name"><?= htmlspecialchars($prod['name']) ?></h3>
                    </a>
                    <div class="product-card-rating">
                        <span class="stars"><?= $stars ?></span>
                        <span class="count">(<?= number_format($prod['total_sold']) ?>)</span>
                    </div>
                    <div class="product-card-footer">
                        <span class="product-card-price">
                            Rp <?= number_format($displayPrice, 0, ',', '.') ?>
                            <?php if ($hasDiscount): ?>
                                <span class="original">Rp <?= number_format($prod['price'], 0, ',', '.') ?></span>
                            <?php endif; ?>
                        </span>
                        <span class="product-card-stock <?= $stockStatus ?>"><?= $stockLabel ?></span>
                    </div>
                </div>
            </div>
            <?php $prodDelay += 100; endwhile; ?>
        </div>
        <?php else: ?>
        <div style="text-align: center; padding: var(--space-3xl); color: var(--text-muted);">
            <i class="fas fa-box-open" style="font-size: 3rem; margin-bottom: var(--space-md); opacity: 0.5;"></i>
            <p>Belum ada produk. Admin akan segera menambahkan produk terbaru!</p>
        </div>
        <?php endif; ?>
        
        <div style="text-align: center; margin-top: var(--space-3xl);" data-aos="fade-up">
            <a href="<?= BASE_PATH ?>/pages/products.php" class="btn btn-primary btn-lg">
                <i class="fas fa-th-large"></i>
                Lihat Semua Produk
            </a>
        </div>
    </div>
</section>

<!-- ============================================
     OUR STORY SECTION
     ============================================ -->
<section id="story" class="story-section songket-texture">
    <div class="container">
        <div class="section-tag" data-aos="fade-up">Our Story</div>
        <h2 class="section-title" data-aos="fade-up" data-aos-delay="100">Cerita <span class="gold-text">Kami</span></h2>
        <p class="section-subtitle" data-aos="fade-up" data-aos-delay="150"><?= htmlspecialchars(getSetting('story_subtitle', 'Perjalanan Nadhira Napoleon dalam menghadirkan oleh-oleh premium khas Riau')) ?></p>
        
        <div class="story-content">
            <div class="story-image" data-aos="fade-right">
                <img src="<?= htmlspecialchars(getSetting('story_image', 'https://images.unsplash.com/photo-1551024601-bec78aea704b?w=800&q=80')) ?>" alt="Nadhira Napoleon Story" loading="lazy">
            </div>
            <div class="story-text" data-aos="fade-left">
                <?php
                $storyTitle = getSetting('story_title', 'Warisan Rasa');
                $storyTitleSuffix = getSetting('story_title_suffix', 'Nusantara');
                $storyContent = getSetting('story_content', '');
                $storySignature = getSetting('story_signature', '— Nadhira Napoleon, Founder');
                ?>
                <h2><?= htmlspecialchars($storyTitle) ?><br><span class="gold-text"><?= htmlspecialchars($storyTitleSuffix) ?></span></h2>
                <?php
                if (!empty($storyContent)):
                    $paragraphs = explode("\n", trim($storyContent));
                    foreach ($paragraphs as $para) {
                        $para = trim($para);
                        if (!empty($para)) {
                            echo '<p>' . htmlspecialchars($para) . '</p>';
                        }
                    }
                else:
                ?>
                <p>
                    Berawal dari kecintaan terhadap kuliner khas Melayu Riau, Nadhira Napoleon hadir untuk 
                    membawa cita rasa otentik Pekanbaru ke seluruh Nusantara. Setiap produk kami dibuat 
                    dengan resep turun-temurun yang telah disempurnakan, menggunakan bahan-bahan premium pilihan.
                </p>
                <p>
                    Kami percaya bahwa setiap gigitan harus menghadirkan pengalaman yang tak terlupakan. 
                    Dari Napoleon yang renyah berlapis, Pancake Durian yang lembut dengan durian asli, 
                    hingga aneka cake dan snack premium — semuanya dibuat dengan penuh cinta dan dedikasi.
                </p>
                <p>
                    Dengan standar kualitas tertinggi dan inovasi yang berkelanjutan, kami telah menjadi 
                    destinasi utama oleh-oleh premium di Pekanbaru, dipercaya oleh ribuan pelanggan 
                    dari berbagai kota di Indonesia.
                </p>
                <?php endif; ?>
                <div class="story-signature"><?= htmlspecialchars($storySignature) ?></div>
            </div>
        </div>
    </div>
</section>

<!-- ============================================
     WHY NADHIRA NAPOLEON - FEATURES
     ============================================ -->
<section id="why-us" class="songket-texture">
    <div class="container">
        <div class="section-tag" data-aos="fade-up">Why Us</div>
        <h2 class="section-title" data-aos="fade-up" data-aos-delay="100">Kenapa Memilih <span class="gold-text">Kami?</span></h2>
        <p class="section-subtitle" data-aos="fade-up" data-aos-delay="150">Kami berkomitmen memberikan yang terbaik untuk setiap pelanggan</p>
        
        <?php
        // Fitur Why Us dikelola di Admin > Pengaturan > Why Us
        $whyusDefaults = [
            ['icon' => 'fa-award', 'title' => 'Bahan Premium', 'text' => 'Hanya menggunakan bahan-bahan berkualitas terbaik untuk memastikan cita rasa yang sempurna.'],
            ['icon' => 'fa-leaf', 'title' => 'Fresh Product', 'text' => 'Produk fresh dibuat setiap hari untuk menjaga kualitas dan kesegaran terbaik.'],
            ['icon' => 'fa-gem', 'title' => 'Kemasan Premium', 'text' => 'Kemasan eksklusif yang elegan, cocok untuk oleh-oleh dan hadiah istimewa.'],
            ['icon' => 'fa-truck', 'title' => 'Pengiriman Nasional', 'text' => 'Melayani pengiriman ke seluruh Indonesia dengan kemasan khusus yang terjaga.'],
        ];
        $whyusFeatures = [];
        for ($w = 1; $w <= 4; $w++) {
            $d = $whyusDefaults[$w - 1];
            // Fallback ke teks bawaan bila nilai tersimpan kosong (mis. belum diisi admin)
            $whyusFeatures[] = [
                'icon'  => getSetting('whyus_' . $w . '_icon', $d['icon']) ?: $d['icon'],
                'title' => getSetting('whyus_' . $w . '_title', $d['title']) ?: $d['title'],
                'text'  => getSetting('whyus_' . $w . '_text', $d['text']) ?: $d['text'],
            ];
        }
        ?>
        <div class="features-grid">
            <?php foreach ($whyusFeatures as $wfi => $wf): ?>
            <div class="feature-card" data-aos="fade-up" data-aos-delay="<?= $wfi * 100 ?>">
                <div class="feature-icon">
                    <i class="fas <?= htmlspecialchars($wf['icon']) ?>"></i>
                </div>
                <h3 class="feature-title"><?= htmlspecialchars($wf['title']) ?></h3>
                <p class="feature-text"><?= htmlspecialchars($wf['text']) ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ============================================
     MEMBERSHIP PREMIUM - BELI LANGSUNG (di atas produk)
     (partial: includes/membership-section.php, hanya ditampilkan di homepage)
     ============================================ -->
<?php include __DIR__ . '/includes/membership-section.php'; ?>

<!-- ============================================
     BEST SELLER PRODUCTS - FROM DATABASE
     ============================================ -->

<!-- ============================================
     PROMO HARI INI - FROM DATABASE
     ============================================ -->
<section id="promo" class="promo-section">
    <div class="container">
        <div class="section-tag" data-aos="fade-up" style="color: var(--soft-gold);">Promo Hari Ini</div>
        <h2 class="section-title" data-aos="fade-up" data-aos-delay="100">Promo <span class="gold-text">Spesial</span></h2>
        <p class="section-subtitle" data-aos="fade-up" data-aos-delay="150">Jangan lewatkan penawaran spesial yang terbatas!</p>

        <?php
        $connPromo = getConnection();
        $promos = $connPromo ? $connPromo->query("
            SELECT * FROM promotions 
            WHERE is_active = 1 AND end_date >= NOW() 
            ORDER BY end_date ASC, start_date DESC
            LIMIT 3
        ") : null;

        if ($promos && $promos->num_rows > 0):
        $promoDelay = 0;
        ?>
        <div class="grid grid-3">
            <?php while ($p = $promos->fetch_assoc()): 
                $discountLabel = '';
                if ($p['discount_type'] === 'percentage') {
                    $discountLabel = 'Diskon ' . (int)$p['discount_value'] . '%';
                } else {
                    $discountLabel = 'Diskon Rp ' . number_format($p['discount_value'], 0, ',', '.');
                }
            ?>
            <div class="promo-card" data-aos="fade-up" data-aos-delay="<?= $promoDelay ?>">
                <div style="display: inline-block; padding: 6px 14px; margin-bottom: var(--space-md); background: var(--luxury-gradient); border-radius: var(--radius-full); color: var(--text-white); font-size: var(--text-sm); font-weight: 600;">
                    <?= $discountLabel ?>
                </div>
                <h3 style="font-family: var(--font-display); font-size: var(--text-2xl); margin-bottom: var(--space-md); color: var(--text-white);">
                    <?= htmlspecialchars($p['title']) ?>
                </h3>
                <?php if (!empty($p['description'])): ?>
                <p style="color: rgba(255,248,240,0.7); margin-bottom: var(--space-lg);">
                    <?= htmlspecialchars($p['description']) ?>
                </p>
                <?php endif; ?>
                <?php if ($p['min_purchase'] > 0): ?>
                <div style="display: inline-block; padding: 8px 16px; background: rgba(212,168,83,0.15); border-radius: var(--radius-full); color: var(--soft-gold); font-size: var(--text-xs); font-weight: 500; letter-spacing: 0.5px;">
                    <i class="fas fa-shopping-cart" style="margin-right: 4px;"></i>
                    Min. Rp <?= number_format($p['min_purchase'], 0, ',', '.') ?>
                </div>
                <?php endif; ?>
                <div class="promo-card-timer" data-end="<?= htmlspecialchars($p['end_date']) ?>">
                    <div class="promo-timer-item">
                        <span class="promo-timer-value" data-timer="days">00</span>
                        <span class="promo-timer-label">Hari</span>
                    </div>
                    <div class="promo-timer-item">
                        <span class="promo-timer-value" data-timer="hours">00</span>
                        <span class="promo-timer-label">Jam</span>
                    </div>
                    <div class="promo-timer-item">
                        <span class="promo-timer-value" data-timer="minutes">00</span>
                        <span class="promo-timer-label">Menit</span>
                    </div>
                    <div class="promo-timer-item">
                        <span class="promo-timer-value" data-timer="seconds">00</span>
                        <span class="promo-timer-label">Detik</span>
                    </div>
                </div>
            </div>
            <?php $promoDelay += 100; endwhile; ?>
        </div>
        <?php else: ?>
        <div style="text-align: center; padding: var(--space-3xl); color: var(--text-muted);">
            <i class="fas fa-tags" style="font-size: 3rem; margin-bottom: var(--space-md); opacity: 0.5;"></i>
            <p>Belum ada promo saat ini. Pantau terus website kami untuk penawaran spesial!</p>
        </div>
        <?php endif; ?>
    </div>
</section>

<!-- ============================================
     PAKET OLEH-OLEH
     ============================================ -->
<section id="paket" class="songket-texture">
    <div class="container">
        <div class="section-tag" data-aos="fade-up">Paket Oleh-Oleh</div>
        <h2 class="section-title" data-aos="fade-up" data-aos-delay="100">Paket <span class="gold-text">Spesial</span></h2>
        <p class="section-subtitle" data-aos="fade-up" data-aos-delay="150">Koleksi paket oleh-oleh lengkap untuk keluarga dan kerabat tercinta</p>
        
        <?php
        $connPkg = getConnection();
        $packages = $connPkg ? $connPkg->query("SELECT * FROM packages WHERE is_active = 1 ORDER BY sort_order ASC, id ASC") : null;
        if ($packages && $packages->num_rows > 0):
        $pkgDelay = 0;
        ?>
        <div class="grid grid-3">
            <?php while ($pkg = $packages->fetch_assoc()): ?>
            <div class="card" data-aos="fade-up" data-aos-delay="<?= $pkgDelay ?>">
                <img src="<?= htmlspecialchars($pkg['image'] ?: 'https://images.unsplash.com/photo-1551024601-bec78aea704b?w=600&q=80') ?>"
                     alt="<?= htmlspecialchars($pkg['name']) ?>" class="card-image" style="height: 250px;" loading="lazy">
                <div class="card-body">
                    <h3 class="card-title"><?= htmlspecialchars($pkg['name']) ?></h3>
                    <p class="card-text"><?= htmlspecialchars($pkg['description']) ?></p>
                    <div class="flex-between">
                        <span class="card-price">Rp <?= number_format((float)$pkg['price'], 0, ',', '.') ?></span>
                        <!-- Progressive enhancement: jika JS aktif -> toast + redirect; jika tidak -> POST langsung ke buy-package.php -->
                        <form action="<?= SITE_URL ?>/pages/buy-package.php" method="POST" style="display: inline;"
                              onsubmit="if (typeof buyPackage === 'function') { buyPackage(<?= (int)$pkg['product_id'] ?>); return false; }">
                            <input type="hidden" name="product_id" value="<?= (int)$pkg['product_id'] ?>">
                            <input type="hidden" name="quantity" value="1">
                            <button type="submit" class="btn btn-primary btn-sm">Pesan Sekarang</button>
                        </form>
                    </div>
                </div>
            </div>
            <?php $pkgDelay += 100; endwhile; ?>
        </div>
        <?php else: ?>
        <div style="text-align: center; padding: var(--space-3xl); color: var(--text-muted);">
            <i class="fas fa-gift" style="font-size: 3rem; margin-bottom: var(--space-md); opacity: 0.5;"></i>
            <p>Belum ada paket spesial. Admin akan segera menambahkan!</p>
        </div>
        <?php endif; ?>
    </div>
</section>

<!-- ============================================
     VIDEO GALLERY
     ============================================ -->
<section id="video" class="bg-songket">
    <div class="container">
        <div class="section-tag" data-aos="fade-up">Video Gallery</div>
        <h2 class="section-title" data-aos="fade-up" data-aos-delay="100">Dibalik <span class="gold-text">Dapur Kami</span></h2>
        <p class="section-subtitle" data-aos="fade-up" data-aos-delay="150">Saksikan bagaimana produk premium kami dibuat dengan penuh cinta</p>
        
        <?php
        $connVid = getConnection();
        $videos = $connVid ? $connVid->query("SELECT * FROM video_gallery WHERE is_active = 1 ORDER BY sort_order ASC, created_at DESC") : null;
        if ($videos && $videos->num_rows > 0):
            $videoList = [];
            while ($v = $videos->fetch_assoc()) { $videoList[] = $v; }
            $mainVideo = $videoList[0];
            $sideVideos = array_slice($videoList, 1);
            // Build JSON array of all video URLs for modal navigation
            $allVideoUrls = [];
            foreach ($videoList as $v) {
                $allVideoUrls[] = $v['video_url'];
            }
            $videoUrlsJson = htmlspecialchars(json_encode($allVideoUrls), ENT_QUOTES, 'UTF-8');
        ?>
        <?php
        // Helper function to detect video platform and get icon
        function getVideoPlatform($url) {
            if (preg_match('/instagram\\.com/', $url)) return 'instagram';
            if (preg_match('/youtube\\.com|youtu\\.be/', $url)) return 'youtube';
            return 'other';
        }
        $mainPlatform = getVideoPlatform($mainVideo['video_url']);
        ?>
        <div class="video-gallery" data-video-list='<?= $videoUrlsJson ?>'>
            <div class="video-main" data-aos="fade-right" data-video-url="<?= htmlspecialchars($mainVideo['video_url'], ENT_QUOTES) ?>" data-video-index="0" style="cursor: pointer;">
                <?php 
                $thumb = $mainVideo['thumbnail'];
                $isInstagramFallback = ($mainPlatform === 'instagram' && empty($thumb));
                $thumb = $thumb ?: 'https://images.unsplash.com/photo-1551024601-bec78aea704b?w=800&q=80'; 
                ?>
                <div class="video-main-bg <?= $isInstagramFallback ? 'video-main-bg-instagram' : '' ?>"></div>
                <img src="<?= htmlspecialchars($thumb) ?>" alt="<?= htmlspecialchars($mainVideo['title']) ?>" loading="lazy">
                <div class="video-play-btn <?= $mainPlatform === 'instagram' ? 'video-play-btn-instagram' : '' ?>">
                    <?php if ($mainPlatform === 'instagram'): ?>
                        <i class="fab fa-instagram"></i>
                    <?php else: ?>
                        <i class="fas fa-play"></i>
                    <?php endif; ?>
                </div>
                <div class="video-platform-badge video-platform-<?= $mainPlatform ?>">
                    <i class="fab fa-<?= $mainPlatform ?>"></i>
                    <span><?= ucfirst($mainPlatform) ?></span>
                </div>
                <div class="video-main-badge">
                    <i class="fas fa-crown"></i> Video Utama
                </div>
                <?php if ($mainVideo['title']): ?>
                <div style="position: absolute; bottom: 0; left: 0; right: 0; padding: 20px; background: linear-gradient(transparent, rgba(0,0,0,0.7)); color: #fff; font-weight: 600; font-size: var(--text-lg);">
                    <?= htmlspecialchars($mainVideo['title']) ?>
                </div>
                <?php endif; ?>
            </div>
            <?php if (!empty($sideVideos)): ?>
            <div class="video-side">
                <?php $vidDelay = 0; foreach ($sideVideos as $sv): 
                    $svPlatform = getVideoPlatform($sv['video_url']);
                    $svThumb = $sv['thumbnail'];
                    $svIsIgFallback = ($svPlatform === 'instagram' && empty($svThumb));
                    $svThumb = $svThumb ?: 'https://images.unsplash.com/photo-1565958011703-44f9829ba187?w=400&q=80';
                ?>
                <div class="video-thumb" data-aos="fade-up" data-aos-delay="<?= $vidDelay ?>" data-video-url="<?= htmlspecialchars($sv['video_url'], ENT_QUOTES) ?>" data-video-index="<?= $vidDelay / 100 + 1 ?>" style="cursor: pointer;">
                    <?php if ($svIsIgFallback): ?>
                    <div class="video-thumb-bg-instagram"></div>
                    <?php endif; ?>
                    <img src="<?= htmlspecialchars($svThumb) ?>" alt="<?= htmlspecialchars($sv['title']) ?>" loading="lazy">
                    <div class="video-play-btn <?= $svPlatform === 'instagram' ? 'video-play-btn-instagram' : '' ?>" style="width: 40px; height: 40px; font-size: 1rem;">
                        <?php if ($svPlatform === 'instagram'): ?>
                            <i class="fab fa-instagram"></i>
                        <?php else: ?>
                            <i class="fas fa-play"></i>
                        <?php endif; ?>
                    </div>
                    <div class="video-platform-badge video-platform-<?= $svPlatform ?>" style="top: 4px; right: 4px; font-size: 10px; padding: 2px 8px;">
                        <i class="fab fa-<?= $svPlatform ?>"></i>
                    </div>
                </div>
                <?php $vidDelay += 100; endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div style="text-align: center; padding: var(--space-3xl); color: var(--text-muted);">
            <i class="fas fa-video" style="font-size: 3rem; margin-bottom: var(--space-md); opacity: 0.5;"></i>
            <p>Belum ada video gallery. Admin akan segera menambahkan video terbaru!</p>
        </div>
        <?php endif; ?>
    </div>
</section>

<!-- ============================================
     VIDEO MODAL / LIGHTBOX
     ============================================ -->
<div id="videoModal" class="video-modal-overlay" onclick="closeVideoModal(event)">
    <div class="video-modal" onclick="event.stopPropagation()">
        <button class="video-modal-close" onclick="closeVideoModal()" aria-label="Tutup video">
            <i class="fas fa-times"></i>
        </button>
        <div class="video-modal-content" id="videoModalContent"></div>
        <div class="video-modal-footer">
            <span id="videoModalTitle"></span>
        </div>
    </div>
</div>

<!-- ============================================
     OUR BRANCH - FROM DATABASE
     ============================================ -->
<section id="branches" class="bg-songket">
    <div class="container">
        <div class="section-tag" data-aos="fade-up">Our Branch</div>
        <h2 class="section-title" data-aos="fade-up" data-aos-delay="100">Cabang <span class="gold-text">Kami</span></h2>
        <p class="section-subtitle" data-aos="fade-up" data-aos-delay="150">Kunjungi toko kami yang tersebar di beberapa lokasi strategis Pekanbaru</p>
        
        <?php
        $connBr = getConnection();
        ensureBranchesMapsUrl(); // pastikan kolom maps_url tersedia (link maps kustom per cabang)
        ensureBranchesOpenHours(); // pastikan kolom jam buka/tutup tersedia
        $branches = $connBr ? $connBr->query("SELECT * FROM branches WHERE is_active = 1 ORDER BY sort_order ASC, name ASC") : null;
        if ($branches && $branches->num_rows > 0):
        $brDelay = 0;
        ?>
        <div class="grid grid-3">
            <?php while ($b = $branches->fetch_assoc()): 
                // Link maps: pakai link kustom dari admin bila ada, fallback ke link otomatis
                $mapsUrl = '#';
                if (!empty($b['maps_url'])) {
                    $mapsUrl = trim($b['maps_url']);
                } elseif (!empty($b['latitude']) && !empty($b['longitude'])) {
                    $mapsUrl = 'https://www.google.com/maps?q=' . $b['latitude'] . ',' . $b['longitude'];
                } elseif (!empty($b['address'])) {
                    $mapsUrl = 'https://www.google.com/maps?q=' . urlencode($b['address']);
                }
                $waNumber = $b['whatsapp'] ?: '';
                $waUrl = $waNumber ? 'https://wa.me/' . preg_replace('/[^0-9]/', '', $waNumber) : '#';
                $phoneDisplay = $b['phone'] ?: '-';
                $imgSrc = $b['image'] ?: 'https://images.unsplash.com/photo-1441986300917-64674bd600d8?w=600&q=80';
            ?>
            <div class="branch-card" data-aos="fade-up" data-aos-delay="<?= $brDelay ?>">
                <img src="<?= htmlspecialchars($imgSrc) ?>" alt="<?= htmlspecialchars($b['name']) ?>" class="branch-image" loading="lazy">
                <div class="branch-body">
                    <h3 class="branch-name"><?= htmlspecialchars($b['name']) ?></h3>
                    <div class="branch-info">
                        <i class="fas fa-map-marker-alt"></i>
                        <span><?= htmlspecialchars($b['address']) ?></span>
                    </div>
                    <div class="branch-info">
                        <i class="fas fa-phone-alt"></i>
                        <span><?= htmlspecialchars($phoneDisplay) ?></span>
                    </div>
                    <?php $branchHours = formatBranchHours($b); ?>
                    <?php if ($branchHours !== ''): ?>
                    <div class="branch-info">
                        <i class="fas fa-clock"></i>
                        <span><?= htmlspecialchars($branchHours) ?></span>
                    </div>
                    <?php endif; ?>
                    <div style="margin-top: var(--space-md); display: flex; gap: var(--space-sm);">
                        <?php if ($waUrl !== '#'): ?>
                        <a href="<?= $waUrl ?>" class="btn btn-primary btn-sm" target="_blank" rel="noopener">
                            <i class="fab fa-whatsapp"></i> Chat
                        </a>
                        <?php endif; ?>
                        <?php if ($mapsUrl !== '#'): ?>
                        <a href="<?= htmlspecialchars($mapsUrl, ENT_QUOTES) ?>" class="btn btn-outline btn-sm" target="_blank" rel="noopener">
                            <i class="fas fa-map"></i> Maps
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php $brDelay += 100; endwhile; ?>
        </div>
        <?php else: ?>
        <div style="text-align: center; padding: var(--space-3xl); color: var(--text-muted);">
            <i class="fas fa-store" style="font-size: 2rem; margin-bottom: var(--space-md); opacity: 0.5;"></i>
            <p>Belum ada cabang yang tersedia.</p>
        </div>
        <?php endif; ?>
    </div>
</section>

<!-- ============================================
     CUSTOMER REVIEW / TESTIMONIALS
     ============================================ -->
<section id="reviews" class="songket-texture">
    <div class="container">
        <div class="section-tag" data-aos="fade-up">Testimonials</div>
        <h2 class="section-title" data-aos="fade-up" data-aos-delay="100">Apa Kata <span class="gold-text">Pelanggan?</span></h2>
        <p class="section-subtitle" data-aos="fade-up" data-aos-delay="150">Kepercayaan dan kepuasan pelanggan adalah prioritas utama kami</p>
        
        <?php
        $conn2 = getConnection();
        $testimonials = $conn2 ? $conn2->query("SELECT * FROM testimonials WHERE is_active = 1 AND is_featured = 1 ORDER BY sort_order ASC, created_at DESC LIMIT 10") : null;
        if ($testimonials && $testimonials->num_rows > 0):
        ?>
        <div class="testimonial-slider" id="testimonialSlider" data-aos="fade-up">
            <div class="testimonial-track" id="testimonialTrack">
                <?php while ($t = $testimonials->fetch_assoc()): 
                    $stars = str_repeat('★', (int)$t['rating']) . str_repeat('☆', 5 - (int)$t['rating']);
                ?>
                <div class="testimonial-slide">
                    <div class="testimonial-card">
                        <div class="testimonial-stars"><?= $stars ?></div>
                        <p class="testimonial-content">"<?= htmlspecialchars($t['content']) ?>"</p>
                        <div class="testimonial-author">
                            <img src="<?= htmlspecialchars($t['customer_avatar'] ?: 'https://i.pravatar.cc/100?img=' . $t['id']) ?>" alt="<?= htmlspecialchars($t['customer_name']) ?>" class="testimonial-avatar" loading="lazy">
                            <div>
                                <div class="testimonial-name"><?= htmlspecialchars($t['customer_name']) ?></div>
                                <div class="testimonial-verified">
                                    <i class="fas fa-check-circle"></i> Verified Purchase
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>

            <!-- Navigation Dots + Play/Pause Toggle -->
            <div class="testimonial-controls">
                <button class="testimonial-toggle" id="testToggle" aria-label="Jeda / Putar otomatis">
                    <i class="fas fa-pause"></i>
                </button>
                <div class="testimonial-dots" id="testimonialDots"></div>
            </div>

            <!-- Arrow Buttons -->
            <button class="testimonial-arrow testimonial-arrow-prev" id="testPrev" aria-label="Testimonial sebelumnya">
                <i class="fas fa-chevron-left"></i>
            </button>
            <button class="testimonial-arrow testimonial-arrow-next" id="testNext" aria-label="Testimonial selanjutnya">
                <i class="fas fa-chevron-right"></i>
            </button>
        </div>
        <?php else: ?>
        <div style="text-align: center; padding: var(--space-2xl); color: var(--text-muted);">
            <i class="fas fa-comment-dots" style="font-size: 2rem; margin-bottom: var(--space-md);"></i>
            <p>Belum ada testimonial. Jadilah yang pertama!</p>
        </div>
        <?php endif; ?>
    </div>
</section>

<!-- ============================================
     ARTICLE SECTION
     ============================================ -->
<section id="articles" class="bg-songket">
    <div class="container">
        <div class="section-tag" data-aos="fade-up">Artikel</div>
        <h2 class="section-title" data-aos="fade-up" data-aos-delay="100">Artikel <span class="gold-text">Terbaru</span></h2>
        <p class="section-subtitle" data-aos="fade-up" data-aos-delay="150">Informasi menarik seputar produk dan kuliner khas Riau</p>
        
        <?php
        $conn = getConnection();
        $articles = $conn ? $conn->query("SELECT * FROM articles WHERE is_published = 1 ORDER BY published_at DESC LIMIT 3") : null;
        if ($articles && $articles->num_rows > 0):
        ?>
        <div class="grid grid-3">
            <?php $artDelay = 0; while ($art = $articles->fetch_assoc()): ?>
            <div class="card" data-aos="fade-up" data-aos-delay="<?= $artDelay ?>">
                <img src="<?= htmlspecialchars($art['image'] ?: 'https://images.unsplash.com/photo-1551024601-bec78aea704b?w=600&q=80') ?>" alt="<?= htmlspecialchars($art['title']) ?>" class="card-image" style="height: 200px;" loading="lazy">
                <div class="card-body">
                    <small style="color: var(--soft-gold); font-size: var(--text-xs); text-transform: uppercase; letter-spacing: 1px;">
                        <?= htmlspecialchars($art['author'] ?: 'Nadhira Napoleon') ?> • 
                        <?= date('d M Y', strtotime($art['published_at'])) ?>
                    </small>
                    <h3 class="card-title" style="font-size: var(--text-lg); margin-top: var(--space-sm);"><?= htmlspecialchars($art['title']) ?></h3>
                    <p class="card-text"><?= htmlspecialchars($art['excerpt'] ?: substr(strip_tags($art['content']), 0, 120) . '...') ?></p>
                    <a href="<?= SITE_URL ?>/pages/artikel.php?slug=<?= urlencode($art['slug']) ?>" class="btn btn-outline btn-sm">
                        Baca Selengkapnya
                    </a>
                </div>
            </div>
            <?php $artDelay += 100; endwhile; ?>
        </div>
        <?php else: ?>
        <div style="text-align: center; padding: var(--space-2xl); color: var(--text-muted);">
            <i class="fas fa-newspaper" style="font-size: 2rem; margin-bottom: var(--space-md);"></i>
            <p>Belum ada artikel. Kunjungi kami lagi nanti!</p>
        </div>
        <?php endif; ?>
    </div>
</section>

<!-- ============================================
     FAQ SECTION
     ============================================ -->
<section id="faq" class="songket-texture">
    <div class="container container-narrow">
        <div class="section-tag" data-aos="fade-up">FAQ</div>
        <h2 class="section-title" data-aos="fade-up" data-aos-delay="100">Pertanyaan <span class="gold-text">Umum</span></h2>
        <p class="section-subtitle" data-aos="fade-up" data-aos-delay="150">Temukan jawaban untuk pertanyaan yang sering diajukan</p>
        
        <?php
        // FAQ dimuat dari database (kelola di Admin > FAQ).
        // Fallback: bila belum ada data, tampilkan FAQ bawaan.
        $connFaq = getConnection();
        $faqList = [];
        if ($connFaq) {
            $rFaq = $connFaq->query("SELECT question, answer FROM faq WHERE is_active = 1 ORDER BY sort_order ASC, created_at DESC LIMIT 20");
            if ($rFaq) {
                while ($fq = $rFaq->fetch_assoc()) { $faqList[] = $fq; }
            }
        }
        if (empty($faqList)) {
            $faqList = [
                ['question' => 'Apa saja produk yang tersedia di Nadhira Napoleon?', 'answer' => 'Kami menyediakan berbagai produk premium seperti Napoleon, Pancake Durian, Mochi, Cake, Brownies, Snack Premium, dan berbagai Oleh-Oleh Khas Riau lainnya. Semua produk kami dibuat dengan bahan-bahan berkualitas terbaik.'],
                ['question' => 'Apakah Nadhira Napoleon melayani pengiriman ke luar kota?', 'answer' => 'Ya, kami melayani pengiriman ke seluruh Indonesia. Produk kami dikemas dengan khusus menggunakan teknologi pengemasan yang menjaga kualitas dan kesegaran produk selama pengiriman.'],
                ['question' => 'Bagaimana cara menyimpan produk Napoleon?', 'answer' => 'Produk Napoleon sebaiknya disimpan di dalam kulkas pada suhu 2-8°C untuk menjaga kesegaran dan kualitas terbaik. Napoleon bisa bertahan hingga 7 hari dalam kulkas.'],
                ['question' => 'Apakah bisa memesan secara online?', 'answer' => 'Tentu! Anda dapat memesan melalui website ini dengan mudah. Pilih produk yang diinginkan, tambahkan ke keranjang, dan selesaikan pembayaran. Kami juga melayani pickup di cabang terdekat.'],
                ['question' => 'Apakah tersedia gift box untuk acara spesial?', 'answer' => 'Ya, kami melayani pemesanan gift box dan hampers untuk berbagai acara seperti pernikahan, ulang tahun, corporate gift, dan acara spesial lainnya. Hubungi kami untuk konsultasi custom hampers.'],
            ];
        }
        ?>
        <div class="faq-list" data-aos="fade-up">
            <?php foreach ($faqList as $fi => $fq): ?>
            <div class="faq-item<?= $fi === 0 ? ' active' : '' ?>">
                <div class="faq-question">
                    <span><?= htmlspecialchars($fq['question']) ?></span>
                    <span class="icon">+</span>
                </div>
                <div class="faq-answer">
                    <p><?= nl2br(htmlspecialchars($fq['answer'])) ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ============================================
     CONTACT US / NEWSLETTER
     ============================================ -->
<section id="contact" class="bg-songket">
    <div class="container">
        <div class="contact-grid">
            <div data-aos="fade-right">
                <div class="section-tag">Contact Us</div>
                <h2 style="font-family: var(--font-display); font-size: var(--text-4xl); font-weight: 700; margin-bottom: var(--space-lg);">
                    Hubungi <span class="gold-text">Kami</span>
                </h2>
                <p style="font-size: var(--text-lg); color: var(--text-muted); margin-bottom: var(--space-2xl);">
                    Punya pertanyaan atau ingin memesan? Jangan ragu untuk menghubungi kami!
                </p>

                <div class="contact-info-item">
                    <div class="contact-info-icon">
                        <i class="fas fa-map-marker-alt"></i>
                    </div>
                    <div>
                        <h4 class="contact-info-title">Alamat</h4>
                        <p class="contact-info-text"><?= htmlspecialchars(getSetting('contact_address', 'Jl. Jenderal Sudirman No. 123, Pekanbaru, Riau')) ?></p>
                    </div>
                </div>

                <div class="contact-info-item">
                    <div class="contact-info-icon">
                        <i class="fas fa-phone-alt"></i>
                    </div>
                    <div>
                        <h4 class="contact-info-title">Telepon</h4>
                        <p class="contact-info-text"><?= htmlspecialchars(getSetting('contact_phone', '0821-1234-5678')) ?></p>
                    </div>
                </div>

                <div class="contact-info-item">
                    <div class="contact-info-icon">
                        <i class="fas fa-envelope"></i>
                    </div>
                    <div>
                        <h4 class="contact-info-title">Email</h4>
                        <p class="contact-info-text"><?= htmlspecialchars(getSetting('contact_email', 'info@nadhiranapoleon.com')) ?></p>
                    </div>
                </div>

                <div class="contact-info-item">
                    <div class="contact-info-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div>
                        <h4 class="contact-info-title">Jam Operasional</h4>
                        <p class="contact-info-text"><?= htmlspecialchars(getSetting('operational_hours', 'Setiap Hari, 08.00 - 21.00 WIB')) ?></p>
                    </div>
                </div>
            </div>

            <div data-aos="fade-left">
                <form class="contact-form" method="POST" action="<?= SITE_URL ?>/pages/contact.php" style="background: var(--warm-white); padding: var(--space-2xl); border-radius: var(--radius-xl); box-shadow: var(--shadow-md);">
                    <h3 style="font-family: var(--font-display); font-size: var(--text-2xl); margin-bottom: var(--space-xl);">Kirim Pesan</h3>
                    
                    <div class="form-group">
                        <label class="form-label" for="name">Nama Lengkap</label>
                        <input type="text" id="name" name="name" class="form-input" placeholder="Masukkan nama Anda" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="email">Email</label>
                        <input type="email" id="email" name="email" class="form-input" placeholder="Masukkan email Anda" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="phone">Nomor Telepon</label>
                        <input type="tel" id="phone" name="phone" class="form-input" placeholder="Masukkan nomor telepon">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="message">Pesan</label>
                        <textarea id="message" name="message" class="form-textarea" placeholder="Tulis pesan Anda..." required></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary btn-lg w-full">
                        <i class="fas fa-paper-plane"></i>
                        Kirim Pesan
                    </button>
                </form>
            </div>
        </div>
    </div>
</section>

<!-- ============================================
     NEWSLETTER SECTION
     ============================================ -->
<section style="background: var(--bg-dark); padding: var(--space-3xl) 0;">
    <div class="container">
        <div style="text-align: center; max-width: 600px; margin: 0 auto;">
            <h3 style="font-family: var(--font-display); font-size: var(--text-3xl); font-weight: 700; color: var(--text-white); margin-bottom: var(--space-md);" data-aos="fade-up">
                Dapatkan Info <span class="gold-text">Promo Terbaru</span>
            </h3>
            <p style="color: rgba(247, 247, 243, 0.7); margin-bottom: var(--space-xl);" data-aos="fade-up" data-aos-delay="100">
                Berlangganan newsletter kami dan dapatkan info promo, diskon, dan produk terbaru langsung di email Anda!
            </p>                <form method="POST" action="<?= SITE_URL ?>/pages/newsletter.php" style="display: flex; gap: var(--space-md); max-width: 500px; margin: 0 auto;" data-aos="fade-up" data-aos-delay="200">
                <input type="email" name="email" class="form-input" placeholder="Masukkan email Anda" style="flex: 1; background: rgba(255,248,240,0.08); border-color: rgba(255,248,240,0.15); color: var(--text-white);" required>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-paper-plane"></i>
                    Subscribe
                </button>
            </form>
        </div>
    </div>
</section>

<?php include 'includes/footer.php'; ?>
