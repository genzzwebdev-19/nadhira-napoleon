<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= isset($meta_description) ? htmlspecialchars($meta_description) : 'Pusat oleh-oleh premium khas Riau. Menghadirkan Napoleon, pancake durian, cake, snack premium, dan berbagai oleh-oleh khas Pekanbaru.' ?>">
    <meta name="keywords" content="nadhira napoleon, oleh-oleh pekanbaru, oleh-oleh riau, napoleon, pancake durian, kue khas riau">
    <meta name="author" content="Nadhira Napoleon Pekanbaru">
    
    <!-- Open Graph -->
    <meta property="og:title" content="<?= isset($meta_title) ? htmlspecialchars($meta_title) : 'Nadhira Napoleon - Premium Oleh-Oleh Khas Riau' ?>">
    <meta property="og:description" content="<?= isset($meta_description) ? htmlspecialchars($meta_description) : 'Pusat oleh-oleh premium khas Riau.' ?>">
    <meta property="og:image" content="<?= isset($meta_image) ? htmlspecialchars($meta_image) : ASSETS_URL . '/images/og-image.jpg' ?>">
    <meta property="og:type" content="website">
    
    <title><?= isset($page_title) ? htmlspecialchars($page_title) . ' - ' . SITE_NAME : SITE_NAME . ' - ' . SITE_TAGLINE ?></title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/jpeg" href="<?= SITE_URL ?>/foto/images.jpg">
    <link rel="apple-touch-icon" href="<?= SITE_URL ?>/foto/images.jpg">
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400;1,500;1,600;1,700&family=Poppins:wght@300;400;500;600;700;800&family=DM+Sans:ital,wght@0,400;0,500;0,700;1,400&family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <!-- GSAP -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/ScrollTrigger.min.js" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/TextPlugin.min.js" defer></script>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <!-- Main Stylesheet -->
    <link rel="stylesheet" href="<?= ASSETS_URL ?>/css/style.css?v=4.2">
    
    <!-- JS Configuration -->
    <script>
        var SITE_URL = '<?= SITE_URL ?>';
        var AJAX_URL = SITE_URL + '/ajax';
        var NN_LOGGED_IN = <?= isLoggedIn() ? 'true' : 'false' ?>;
    </script>
    <style>
        /* User Dropdown */
        .user-dropdown-wrap { position: relative; }
        .user-dropdown {
            position: absolute; right: 0; top: calc(100% + 12px);
            width: 260px; background: #fff; border-radius: 16px;
            box-shadow: 0 12px 40px rgba(0,0,0,0.15);
            overflow: hidden; z-index: 1200; display: none;
            border: 1px solid rgba(0,0,0,0.04);
        }
        .user-dropdown.open { display: block; animation: slideDown 0.25s ease; }
        .user-dropdown-header {
            display: flex; align-items: center; gap: 12px;
            padding: 16px 18px; border-bottom: 1px solid #f0f0f0;
        }
        .user-dropdown-avatar {
            width: 40px; height: 40px; border-radius: 50%;
            background: linear-gradient(135deg, #D4A853, #B8860B);
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-size: 16px; font-weight: 600; flex-shrink: 0;
        }
        .user-dropdown-name { font-weight: 600; font-size: 13px; color: var(--text-dark); }
        .user-dropdown-email { font-size: 11px; color: var(--text-muted); }
        .user-dropdown-menu { padding: 8px; }
        .user-dropdown-menu a {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 14px; border-radius: 10px;
            color: var(--text-primary); font-size: 13px;
            text-decoration: none; transition: all 0.15s ease;
        }
        .user-dropdown-menu a:hover { background: rgba(212,168,83,0.08); }
        .user-dropdown-menu a i { width: 18px; text-align: center; color: var(--text-muted); }
        .user-dropdown-divider { height: 1px; background: #f0f0f0; margin: 4px 0; }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-8px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<?php
// Ukuran & warna bar pengumuman (diatur dari Admin > Pengaturan)
$annSize = getSetting('announcement_size', 'medium');
if (!in_array($annSize, ['small', 'medium', 'large'], true)) { $annSize = 'medium'; }
$annColor = getSetting('announcement_color', 'gold');
if (!in_array($annColor, ['gold', 'dark', 'green', 'white'], true)) { $annColor = 'gold'; }
$annSpeed = getSetting('announcement_speed', 'medium');
if (!in_array($annSpeed, ['slow', 'medium', 'fast'], true)) { $annSpeed = 'medium'; }

// Ukuran logo navbar (diatur dari Admin > Pengaturan)
$logoHeight = (int)getSetting('navbar_logo_height', '90');
if ($logoHeight < 40 || $logoHeight > 200) { $logoHeight = 90; }
$logoWidth = (int)round($logoHeight * 446 / 448); // lebar proporsional (rasio asli foto logo 446:448)
?>
<body class="<?= !empty($is_home) ? 'home-page ' : '' ?>announcement-size-<?= $annSize ?> announcement-color-<?= $annColor ?> announcement-speed-<?= $annSpeed ?>" style="--navbar-logo-height: <?= $logoHeight ?>px; --navbar-total-height: <?= $logoHeight + 32 ?>px;">
    <!-- Toast Container -->
    <div class="toast-container"></div>

    <!-- Navbar Overlay -->
    <div class="navbar-overlay"></div>

    <!-- ============================================
         TOP ANNOUNCEMENT BAR
         (teks, link & jadwal tampil dapat diubah di Admin > Pengaturan)
         ============================================ -->
    <?php
    // Jadwal tampil: aktif/nonaktif + rentang tanggal (kosongkan = tanpa batas)
    $annActive = getSetting('announcement_active', '1');
    $annStart  = trim(getSetting('announcement_start', ''));
    $annEnd    = trim(getSetting('announcement_end', ''));
    $annNow     = time();
    $annStartTs = ($annStart !== '') ? strtotime($annStart) : false;
    $annEndTs   = ($annEnd !== '') ? strtotime($annEnd) : false;
    $annVisible = $annActive === '1';
    if ($annVisible && $annStartTs !== false && $annNow < $annStartTs) {
        $annVisible = false; // belum waktunya tampil
    }
    if ($annVisible && $annEndTs !== false && $annNow > $annEndTs) {
        $annVisible = false; // sudah lewat masa tampil
    }
    if ($annVisible):
    $annText   = trim(getSetting('announcement_text', 'Belanja Online Aja. Krisna Oleh Oleh Bali. Kini Bisa Pesan Via Online'));
    $annMobile = trim(getSetting('announcement_text_mobile', 'Kini Bisa Pesan Via Online'));
    if ($annMobile === '') { $annMobile = $annText; }
    $annLabel  = getSetting('announcement_label', 'SHOP NOW');
    if (trim($annLabel) === '') { $annLabel = 'SHOP NOW'; }
    $annLink   = trim(getSetting('announcement_link', SITE_URL . '/pages/products.php'));
    if ($annLink === '') { $annLink = SITE_URL . '/pages/products.php'; }
    $annMarquee = trim(getSetting('announcement_marquee', '1'));
    $annMarqueeOff = $annMarquee === '1' ? '' : ' marquee-off';
    // Satu salinan isi teks (dipakai 2x agar animasi marquee bersambung tanpa jeda)
    $annTextHtml = '<i class="fas fa-bolt" aria-hidden="true"></i>'
        . '<span class="ta-full">' . htmlspecialchars($annText) . '</span>'
        . '<span class="ta-mobile">' . htmlspecialchars($annMobile) . '</span>';
    ?>
    <div class="top-announcement">
        <div class="top-announcement-inner">
            <div class="top-announcement-marquee<?= $annMarqueeOff ?>" data-announcement-marquee>
                <div class="top-announcement-marquee-track">
                    <span class="top-announcement-text ta-copy"><?= $annTextHtml ?></span>
                    <span class="top-announcement-text ta-copy" aria-hidden="true"><?= $annTextHtml ?></span>
                </div>
            </div>
            <a href="<?= htmlspecialchars($annLink) ?>" class="top-announcement-cta">
                <?= htmlspecialchars($annLabel) ?> <i class="fas fa-arrow-right" aria-hidden="true"></i>
            </a>
        </div>
    </div>
    <?php endif; ?>

    <!-- ============================================
         NAVBAR
         ============================================ -->
    <nav class="navbar" role="navigation" aria-label="Navigasi utama">
        <div class="container">
            <a href="<?= SITE_URL ?>" class="navbar-brand">
                <img src="<?= SITE_URL ?>/foto/images.jpg" alt="Nadhira Napoleon Logo" width="<?= $logoWidth ?>" height="<?= $logoHeight ?>" style="object-fit: contain; border-radius: 8px;">
            </a>

            <div class="navbar-menu">
                <a href="<?= SITE_URL ?>#hero" class="navbar-link active">Home</a>
                <a href="<?= SITE_URL ?>#story" class="navbar-link">Tentang</a>
                <a href="<?= SITE_URL ?>#products" class="navbar-link">Produk</a>
                <a href="<?= SITE_URL ?>#promo" class="navbar-link">Promo</a>
                <a href="<?= SITE_URL ?>#branches" class="navbar-link">Cabang</a>
                <a href="<?= SITE_URL ?>#contact" class="navbar-link">Kontak</a>
                <a href="<?= SITE_URL ?>/pages/tracking.php" class="navbar-link">Tracking</a>
            </div>

            <div class="navbar-actions">
                <?php if (isLoggedIn()):
                    $navUser = getCurrentUser();
                    $navMemberLevel = $navUser['membership'] ?? 'silver';
                    $navLevels = getMembershipLevels();
                    $wishlistCount = getWishlistCount();
                ?>
                <a href="<?= SITE_URL ?>/pages/membership.php" class="navbar-member-badge <?= $navMemberLevel ?>" title="Membership <?= ucfirst($navMemberLevel) ?>" aria-label="Membership saya">
                    <i class="fas <?= $navLevels[$navMemberLevel]['icon'] ?? 'fa-star' ?>"></i>
                    <span><?= ucfirst($navMemberLevel) ?></span>
                </a>
                <a href="<?= SITE_URL ?>/pages/wishlist.php" class="navbar-link cart-badge" aria-label="Wishlist saya">
                    <i class="far fa-heart"></i>
                    <span class="wishlist-count" id="wishlistCount"><?= $wishlistCount > 0 ? $wishlistCount : '' ?></span>
                </a>
                <!-- User Dropdown -->
                <div class="user-dropdown-wrap">
                    <a href="javascript:void(0)" class="navbar-link" onclick="toggleUserDropdown(event)" aria-label="Akun saya">
                        <i class="fas fa-user-circle" style="font-size: 1.3rem;"></i>
                    </a>
                    <div class="user-dropdown" id="userDropdown">
                        <div class="user-dropdown-header">
                            <?php
                            $currentUser = $navUser;
                            $initial = $currentUser ? strtoupper(substr($currentUser['full_name'], 0, 1)) : 'U';
                            ?>
                            <div class="user-dropdown-avatar"><?= $initial ?></div>
                            <div>
                                <div class="user-dropdown-name"><?= htmlspecialchars($currentUser['full_name'] ?? 'User') ?></div>
                                <div class="user-dropdown-email"><?= htmlspecialchars($currentUser['email'] ?? '') ?></div>
                            </div>
                        </div>
                        <div class="user-dropdown-menu">
                            <a href="<?= SITE_URL ?>/pages/membership.php"><i class="fas fa-crown"></i> Membership Saya <span class="user-dropdown-level <?= $navMemberLevel ?>"><?= ucfirst($navMemberLevel) ?></span></a>
                            <a href="<?= SITE_URL ?>/auth/profile.php"><i class="fas fa-user"></i> Profil Saya</a>
                            <a href="<?= SITE_URL ?>/pages/tracking.php"><i class="fas fa-shopping-bag"></i> Pesanan Saya</a>
                            <a href="<?= SITE_URL ?>/pages/wishlist.php"><i class="fas fa-heart"></i> Wishlist</a>
                            <div class="user-dropdown-divider"></div>
                            <a href="<?= SITE_URL ?>/auth/logout.php" style="color: #EF4444;"><i class="fas fa-sign-out-alt"></i> Keluar</a>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <a href="<?= SITE_URL ?>/auth/login.php" class="navbar-link" aria-label="Akun saya">
                    <i class="fas fa-user"></i>
                </a>
                <?php endif; ?>
                <a href="<?= SITE_URL ?>/pages/cart.php" class="navbar-link cart-badge" aria-label="Keranjang belanja">
                    <i class="fas fa-shopping-bag"></i>
                    <?php $cartCount = getCartCount(); ?>
                    <?php if ($cartCount > 0): ?>
                        <span class="cart-count" id="cartCount"><?= $cartCount ?></span>
                    <?php endif; ?>
                </a>
                <button class="navbar-toggle" aria-label="Toggle menu">
                    <span></span>
                    <span></span>
                    <span></span>
                </button>
            </div>
        </div>
    </nav>

    <!-- ============================================
         MOBILE BOTTOM NAVIGATION
         ============================================ -->
    <nav class="bottom-nav" aria-label="Navigasi bawah mobile">
        <a href="<?= SITE_URL ?>" class="bottom-nav-item active">
            <i class="fas fa-home"></i>
            <span>Home</span>
        </a>
        <a href="<?= SITE_URL ?>/pages/products.php" class="bottom-nav-item">
            <i class="fas fa-th-large"></i>
            <span>Produk</span>
        </a>
        <a href="<?= SITE_URL ?>#promo" class="bottom-nav-item">
            <i class="fas fa-percent"></i>
            <span>Promo</span>
        </a>
        <a href="<?= SITE_URL ?>/pages/tracking.php" class="bottom-nav-item">
            <i class="fas fa-truck"></i>
            <span>Tracking</span>
        </a>
        <a href="<?= SITE_URL ?>/pages/wishlist.php" class="bottom-nav-item">
            <i class="far fa-heart"></i>
            <span>Wishlist</span>
        </a>
        <a href="<?= isLoggedIn() ? SITE_URL . '/auth/profile.php' : SITE_URL . '/auth/login.php' ?>" class="bottom-nav-item">
            <i class="fas fa-user"></i>
            <span>Profil</span>
        </a>
    </nav>
