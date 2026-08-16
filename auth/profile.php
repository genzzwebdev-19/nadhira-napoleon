<?php
require_once '../config/database.php';

if (!isLoggedIn()) {
    header('Location: ' . BASE_PATH . '/auth/login.php');
    exit;
}

$conn = getConnection();
$user = getCurrentUser();
$errors = [];
$success = '';

// ============================================
// HANDLE PROFILE UPDATE
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $fullName = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $userId = (int)$user['id'];

    // Validation
    if (empty($fullName)) $errors[] = 'Nama lengkap wajib diisi';
    if (empty($username)) $errors[] = 'Username wajib diisi';
    if (empty($email)) $errors[] = 'Email wajib diisi';
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Format email tidak valid';

    if (empty($errors)) {
        $fn_e = $conn->real_escape_string($fullName);
        $un_e = $conn->real_escape_string($username);
        $em_e = $conn->real_escape_string($email);
        $ph_e = $conn->real_escape_string($phone);

        // Check if username already taken by another user
        $check = $conn->query("SELECT id FROM users WHERE (username = '$un_e' OR email = '$em_e') AND id != $userId LIMIT 1");
        if ($check && $check->num_rows > 0) {
            $errors[] = 'Username atau email sudah digunakan pengguna lain';
        } else {
            $sql = "UPDATE users SET full_name='$fn_e', username='$un_e', email='$em_e', phone='$ph_e' WHERE id=$userId";
            if ($conn->query($sql)) {
                $success = 'Profil berhasil diperbarui!';
                // Reload user data
                $user = getCurrentUser();
            } else {
                $errors[] = 'Gagal memperbarui profil: ' . $conn->error;
            }
        }
    }
}

// ============================================
// HANDLE PASSWORD CHANGE
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $currentPw = $_POST['current_password'] ?? '';
    $newPw = $_POST['new_password'] ?? '';
    $confirmPw = $_POST['confirm_password'] ?? '';
    $userId = (int)$user['id'];

    if (empty($currentPw)) $errors[] = 'Password saat ini wajib diisi';
    elseif (!password_verify($currentPw, $user['password'])) $errors[] = 'Password saat ini salah';

    if (empty($newPw)) $errors[] = 'Password baru wajib diisi';
    elseif (strlen($newPw) < 6) $errors[] = 'Password baru minimal 6 karakter';
    elseif ($newPw !== $confirmPw) $errors[] = 'Konfirmasi password baru tidak cocok';

    if (empty($errors)) {
        $hashedPw = password_hash($newPw, PASSWORD_BCRYPT);
        if ($conn->query("UPDATE users SET password = '$hashedPw' WHERE id = $userId")) {
            $success = 'Password berhasil diubah!';
        } else {
            $errors[] = 'Gagal mengubah password: ' . $conn->error;
        }
    }
}

// ============================================
// HANDLE SHIPPING ADDRESS (simpan / update)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_address'])) {
    $addrId = (int)($_POST['address_id'] ?? 0);
    $newId = saveShippingAddress((int)$user['id'], [
        'label'          => trim($_POST['address_label'] ?? 'Utama'),
        'recipient_name' => trim($_POST['recipient_name'] ?? ''),
        'phone'          => trim($_POST['address_phone'] ?? ''),
        'address'        => trim($_POST['address'] ?? ''),
        'city'           => trim($_POST['address_city'] ?? ''),
        'province'       => trim($_POST['address_province'] ?? ''),
        'postal_code'    => trim($_POST['address_postal_code'] ?? ''),
        'is_default'     => !empty($_POST['address_default']),
    ], $addrId);
    if ($newId > 0) {
        $success = 'Alamat pengiriman berhasil disimpan!';
    } else {
        $errors[] = 'Gagal menyimpan alamat. Nama penerima, alamat, dan kota wajib diisi.';
    }
}

// Hapus alamat
if (isset($_GET['delete_address'])) {
    if (deleteShippingAddress((int)$_GET['delete_address'], (int)$user['id'])) {
        $success = 'Alamat pengiriman dihapus.';
    }
    header('Location: ' . BASE_PATH . '/auth/profile.php');
    exit;
}

// Jadikan alamat default
if (isset($_GET['set_default_address'])) {
    $conn->query("UPDATE shipping_addresses SET is_default = 0 WHERE user_id = " . (int)$user['id']);
    $conn->query("UPDATE shipping_addresses SET is_default = 1 WHERE id = " . (int)$_GET['set_default_address'] . " AND user_id = " . (int)$user['id']);
    header('Location: ' . BASE_PATH . '/auth/profile.php');
    exit;
}

// Data membership untuk ringkasan di profil
// Sinkronkan level efektif (langganan berbayar / total belanja) lalu muat ulang data user
if ($user) {
    syncUserMembership((int)$user['id']);
    $user = getCurrentUser();
}

$levels = getMembershipLevels();
$myLevel = $user['membership'] ?? 'silver';
$myLevelDef = $levels[$myLevel] ?? $levels['silver'];
$nextLevel = getMembershipNextLevel($myLevel);
$progressPct = 100;
$remaining = 0;
if ($nextLevel) {
    $spent = (float)($user['total_spent'] ?? 0);
    $nextLevelDef = $levels[$nextLevel];
    $span = (float)$nextLevelDef['min_spend'] - (float)$myLevelDef['min_spend'];
    $progressPct = $span > 0 ? min(100, max(0, (($spent - (float)$myLevelDef['min_spend']) / $span) * 100)) : 100;
    $remaining = max(0, (float)$nextLevelDef['min_spend'] - $spent);
}

// Status langganan berbayar yang sedang aktif
$activeSub = null;
if ($user) {
    $connSub = getConnection();
    if ($connSub) {
        $subR = $connSub->query("SELECT * FROM membership_purchases WHERE user_id = " . (int)$user['id'] . " AND status = 'active' AND expires_at > NOW() ORDER BY expires_at DESC LIMIT 1");
        if ($subR && $subR->num_rows > 0) $activeSub = $subR->fetch_assoc();
    }
}

// Riwayat poin member
$pointHistory = $user ? getPointHistory((int)$user['id'], 12) : [];

// Alamat pengiriman tersimpan (dipakai prefill otomatis di checkout)
$addresses = $user ? getUserShippingAddresses((int)$user['id']) : [];
$provinces = getIndonesiaProvinces();
$editAddr = null;
if (isset($_GET['edit_address'])) {
    foreach ($addresses as $ad) {
        if ((int)$ad['id'] === (int)$_GET['edit_address']) { $editAddr = $ad; break; }
    }
}

// ============================================
// RIWAYAT PESANAN (order history milik user)
// ============================================
$myOrders = [];
if ($user) {
    $orderR = $conn->query("
        SELECT o.*,
            (SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi WHERE oi.order_id = o.id) AS total_qty
        FROM orders o
        WHERE o.user_id = " . (int)$user['id'] . "
        ORDER BY o.created_at DESC
        LIMIT 15
    ");
    if ($orderR) {
        while ($row = $orderR->fetch_assoc()) { $myOrders[] = $row; }
    }
}

// Meta badge status pesanan & pembayaran
$orderStatusMeta = [
    'pending'    => ['label' => 'Pesanan Dibuat',  'cls' => 'status-pending',    'icon' => 'fa-file-invoice'],
    'processing' => ['label' => 'Diproses',        'cls' => 'status-processing', 'icon' => 'fa-box'],
    'shipped'    => ['label' => 'Dikirim',         'cls' => 'status-shipped',    'icon' => 'fa-truck'],
    'delivered'  => ['label' => 'Selesai',         'cls' => 'status-delivered',  'icon' => 'fa-check-circle'],
    'cancelled'  => ['label' => 'Dibatalkan',      'cls' => 'status-cancelled',  'icon' => 'fa-times-circle'],
];
$payStatusMeta = [
    'paid'     => ['label' => 'Lunas',          'cls' => 'pay-paid',     'icon' => 'fa-check-circle'],
    'pending'  => ['label' => 'Menunggu Bayar', 'cls' => 'pay-pending',  'icon' => 'fa-clock'],
    'failed'   => ['label' => 'Gagal',          'cls' => 'pay-failed',   'icon' => 'fa-times-circle'],
    'refunded' => ['label' => 'Dikembalikan',   'cls' => 'pay-refunded', 'icon' => 'fa-rotate-left'],
];

$page_title = 'Profil Saya';
include '../includes/header.php';
?>

<section style="padding-top: calc(var(--navbar-total-height, 120px) + 8px); min-height: 100vh;">
    <div class="container">
        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="<?= SITE_URL ?>">Home</a>
            <span class="separator">/</span>
            <span class="current">Profil Saya</span>
        </div>

        <!-- Alert Messages -->
        <?php if ($success): ?>
            <div class="alert alert-success" style="padding: 14px 18px; background: #ecfdf5; border-radius: var(--radius-md); margin-bottom: var(--space-xl); color: #059669; border: 1px solid #a7f3d0; display: flex; align-items: center; gap: var(--space-sm); animation: slideDown 0.3s ease;">
                <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>
        <?php foreach ($errors as $err): ?>
            <div class="alert alert-error" style="padding: 14px 18px; background: #FEF2F2; border-radius: var(--radius-md); margin-bottom: var(--space-md); color: #DC2626; border: 1px solid #fecaca; display: flex; align-items: center; gap: var(--space-sm); animation: slideDown 0.3s ease;">
                <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($err) ?>
            </div>
        <?php endforeach; ?>

        <div class="profile-grid">
            <!-- Sidebar -->
            <div class="profile-sidebar">
                <div class="profile-sidebar-header" style="text-align: center; margin-bottom: var(--space-xl);">
                    <div style="width: 80px; height: 80px; border-radius: 50%; background: var(--luxury-gradient); display: flex; align-items: center; justify-content: center; margin: 0 auto var(--space-md); font-size: 2rem; color: var(--text-white); font-weight: 600;">
                        <?= strtoupper(substr($user['full_name'], 0, 1)) ?>
                    </div>
                    <h3 style="font-family: var(--font-display); font-size: var(--text-lg); font-weight: 600;"><?= htmlspecialchars($user['full_name']) ?></h3>
                    <p style="font-size: var(--text-sm); color: var(--text-muted);"><?= htmlspecialchars($user['email']) ?></p>
                    <a href="<?= SITE_URL ?>/pages/membership.php" class="profile-level-chip <?= $myLevel ?>">
                        <i class="fas <?= $myLevelDef['icon'] ?>"></i> <?= $myLevelDef['label'] ?> · <?= number_format((int)$user['points']) ?> poin
                    </a>

                </div>

                <nav style="display: flex; flex-direction: column; gap: 4px;">
                    <a href="#" class="admin-nav-item active" style="color: var(--text-primary); background: var(--soft-gold-gradient); border-radius: var(--radius-md); padding: 12px 16px; display: flex; align-items: center; gap: var(--space-md); font-size: var(--text-sm);">
                        <i class="fas fa-user"></i> Profil Saya
                    </a>
                    <a href="#pesanan" style="color: var(--text-muted); border-radius: var(--radius-md); padding: 12px 16px; display: flex; align-items: center; gap: var(--space-md); font-size: var(--text-sm); transition: var(--transition-base);">
                        <i class="fas fa-shopping-bag"></i> Pesanan Saya
                    </a>
                    <a href="<?= SITE_URL ?>/pages/wishlist.php" style="color: var(--text-muted); border-radius: var(--radius-md); padding: 12px 16px; display: flex; align-items: center; gap: var(--space-md); font-size: var(--text-sm); transition: var(--transition-base);">
                        <i class="fas fa-heart"></i> Wishlist
                    </a>
                    <a href="<?= SITE_URL ?>/pages/membership.php" style="color: var(--text-muted); border-radius: var(--radius-md); padding: 12px 16px; display: flex; align-items: center; gap: var(--space-md); font-size: var(--text-sm); transition: var(--transition-base);">
                        <i class="fas fa-trophy"></i> Membership & Poin
                    </a>
                    <a href="#" style="color: var(--text-muted); border-radius: var(--radius-md); padding: 12px 16px; display: flex; align-items: center; gap: var(--space-md); font-size: var(--text-sm); transition: var(--transition-base);">
                        <i class="fas fa-cog"></i> Pengaturan
                    </a>
                    <a href="<?= BASE_PATH ?>/auth/logout.php" style="color: #EF4444; border-radius: var(--radius-md); padding: 12px 16px; display: flex; align-items: center; gap: var(--space-md); font-size: var(--text-sm); transition: var(--transition-base);">
                        <i class="fas fa-sign-out-alt"></i> Keluar
                    </a>
                </nav>
            </div>

            <!-- Main Content -->
            <div>
                <!-- Membership Summary -->
                <div style="background: linear-gradient(135deg, #2C1810 0%, #5C3A21 100%); border-radius: var(--radius-xl); padding: var(--space-2xl); box-shadow: var(--shadow-md); margin-bottom: var(--space-xl); color: #FFF;">
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: var(--space-lg);">
                        <div style="display: flex; align-items: center; gap: var(--space-lg);">
                            <div style="width: 56px; height: 56px; border-radius: 50%; background: rgba(255,228,0,0.15); border: 1px solid rgba(255,228,0,0.35); display: flex; align-items: center; justify-content: center; font-size: 1.4rem; color: var(--soft-gold);">
                                <i class="fas <?= $myLevelDef['icon'] ?>"></i>
                            </div>
                            <div>
                                <p style="font-size: var(--text-xs); color: rgba(255,255,255,0.65); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 2px;">Membership Anda</p>
                                <h4 style="font-family: var(--font-display); font-size: var(--text-2xl); font-weight: 700; color: var(--soft-gold); margin: 0;"><?= $myLevelDef['label'] ?></h4>
                            </div>
                        </div>
                        <div style="display: flex; gap: var(--space-2xl);">
                            <div style="text-align: center;">
                                <div style="font-family: var(--font-display); font-size: var(--text-2xl); font-weight: 700; color: #FFF;"><?= number_format((int)$user['points']) ?></div>
                                <div style="font-size: var(--text-xs); color: rgba(255,255,255,0.65);">Poin</div>
                            </div>
                            <div style="text-align: center;">
                                <div style="font-family: var(--font-display); font-size: var(--text-2xl); font-weight: 700; color: #FFF;">Rp <?= number_format((float)$user['total_spent'], 0, ',', '.') ?></div>
                                <div style="font-size: var(--text-xs); color: rgba(255,255,255,0.65);">Total Belanja</div>
                            </div>
                        </div>
                    </div>
                    <?php if ($activeSub): ?>
                    <div style="margin-top: var(--space-lg); display: flex; align-items: center; gap: var(--space-sm); padding: 10px 16px; background: rgba(255,228,0,0.1); border: 1px solid rgba(255,228,0,0.3); border-radius: var(--radius-md); font-size: var(--text-sm); color: var(--soft-gold); flex-wrap: wrap;">
                        <i class="fas fa-calendar-check"></i>
                        <span>Langganan <strong><?= ucfirst($activeSub['level']) ?></strong> (<?= $activeSub['period'] === 'yearly' ? 'Tahunan' : 'Bulanan' ?>) aktif s.d. <?= formatDate($activeSub['expires_at'], 'd F Y') ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($nextLevel): ?>
                    <div style="margin-top: var(--space-xl);">
                        <div style="display: flex; justify-content: space-between; font-size: var(--text-xs); color: rgba(255,255,255,0.7); margin-bottom: var(--space-sm);">
                            <span><i class="fas fa-arrow-up"></i> Menuju <?= $nextLevelDef['label'] ?></span>
                            <span>Sisa belanja Rp <?= number_format($remaining, 0, ',', '.') ?> lagi</span>
                        </div>
                        <div style="height: 8px; background: rgba(255,255,255,0.15); border-radius: 999px; overflow: hidden;">
                            <div style="height: 100%; width: <?= $progressPct ?>%; background: var(--luxury-gradient); border-radius: 999px; transition: width 0.6s ease;"></div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <a href="<?= SITE_URL ?>/pages/membership.php" class="btn btn-primary btn-sm" style="margin-top: var(--space-lg);">
                        <i class="fas fa-crown"></i> Lihat Detail Membership
                    </a>
                </div>

                <!-- Riwayat Pesanan -->
                <div id="pesanan" class="checkout-card" style="scroll-margin-top: 110px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-lg); flex-wrap: wrap; gap: var(--space-md);">
                        <h3 style="font-family: var(--font-display); font-size: var(--text-2xl); font-weight: 600; margin: 0;">
                            <i class="fas fa-shopping-bag" style="color: var(--soft-gold); margin-right: var(--space-sm);"></i>
                            Pesanan Saya
                        </h3>
                        <a href="<?= SITE_URL ?>/pages/tracking.php" style="font-size: var(--text-sm); color: var(--warm-orange);">Lacak semua pesanan &rarr;</a>
                    </div>

                    <?php if ($myOrders): ?>
                    <div class="order-list">
                        <?php foreach ($myOrders as $ord):
                            $om = $orderStatusMeta[$ord['order_status']] ?? ['label' => ucfirst(str_replace('_', ' ', $ord['order_status'])), 'cls' => 'status-pending', 'icon' => 'fa-circle'];
                            $pm = $payStatusMeta[$ord['payment_status']] ?? ['label' => ucfirst(str_replace('_', ' ', $ord['payment_status'])), 'cls' => 'pay-pending', 'icon' => 'fa-circle'];
                        ?>
                        <div class="order-card">
                            <a class="order-card-main" href="<?= SITE_URL ?>/pages/tracking.php?order_number=<?= urlencode($ord['order_number']) ?>&email=<?= urlencode($ord['customer_email']) ?>">
                                <div class="order-card-top">
                                    <span class="order-card-number"><?= htmlspecialchars($ord['order_number']) ?></span>
                                    <span class="order-card-date"><i class="far fa-calendar-alt"></i> <?= formatDate($ord['created_at'], 'd M Y') ?></span>
                                </div>
                                <div class="order-card-meta">
                                    <span class="status-badge <?= $om['cls'] ?>"><i class="fas <?= $om['icon'] ?>"></i> <?= $om['label'] ?></span>
                                    <span class="status-badge <?= $pm['cls'] ?>"><i class="fas <?= $pm['icon'] ?>"></i> <?= $pm['label'] ?></span>
                                </div>
                            </a>
                            <div class="order-card-side">
                                <div class="order-card-items"><?= (int)$ord['total_qty'] ?> item</div>
                                <div class="order-card-total">Rp <?= number_format((float)$ord['total'], 0, ',', '.') ?></div>
                                <div class="order-card-actions">
                                    <a class="order-action-btn" href="<?= SITE_URL ?>/pages/tracking.php?order_number=<?= urlencode($ord['order_number']) ?>&email=<?= urlencode($ord['customer_email']) ?>"><i class="fas fa-truck"></i> Lacak</a>
                                    <a class="order-action-btn order-action-btn-gold" href="<?= SITE_URL ?>/pages/invoice.php?order=<?= urlencode($ord['order_number']) ?>"><i class="fas fa-file-invoice"></i> Invoice</a>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div style="text-align: center; padding: var(--space-xl) 0; color: var(--text-light); border: 1px dashed var(--soft-grey); border-radius: var(--radius-md);">
                        <i class="fas fa-shopping-bag" style="font-size: 2.5rem; opacity: 0.4; display: block; margin-bottom: var(--space-md);"></i>
                        <p style="font-size: var(--text-sm); margin: 0;">Belum ada pesanan. <a href="<?= SITE_URL ?>/pages/products.php" style="color: var(--warm-orange);">Mulai belanja</a> sekarang!</p>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Riwayat Poin -->
                <div style="background: var(--warm-white); border-radius: var(--radius-xl); padding: var(--space-2xl); box-shadow: var(--shadow-sm); margin-bottom: var(--space-xl);">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-lg); flex-wrap: wrap; gap: var(--space-md);">
                        <h3 style="font-family: var(--font-display); font-size: var(--text-2xl); font-weight: 600; margin: 0;">
                            <i class="fas fa-coins" style="color: var(--soft-gold); margin-right: var(--space-sm);"></i>
                            Riwayat Poin
                        </h3>
                        <a href="<?= SITE_URL ?>/pages/membership.php" style="font-size: var(--text-sm); color: var(--warm-orange);">Cara dapat & tukar poin &rarr;</a>
                    </div>

                    <?php if ($pointHistory): ?>
                    <div class="point-history-list">
                        <?php foreach ($pointHistory as $ph):
                            $isPlus = (int)$ph['points'] > 0;
                            $typeMap = [
                                'earned'   => ['Poin Belanja', 'fa-arrow-down'],
                                'spent'    => ['Tukar Diskon', 'fa-tag'],
                                'refunded' => ['Refund', 'fa-rotate-left'],
                                'reversed' => ['Ditarik', 'fa-undo'],
                                'adjusted' => ['Penyesuaian', 'fa-sliders-h'],
                            ];
                            $typeInfo = $typeMap[$ph['type']] ?? ['Transaksi', 'fa-circle'];
                        ?>
                        <div class="point-history-item">
                            <div class="point-history-icon <?= $isPlus ? 'plus' : 'minus' ?>"><i class="fas <?= $typeInfo[1] ?>"></i></div>
                            <div class="point-history-body">
                                <div class="point-history-desc"><?= htmlspecialchars($ph['description'] ?: 'Transaksi poin') ?></div>
                                <div class="point-history-meta">
                                    <?= formatDate($ph['created_at'], 'd M Y, H:i') ?> &middot; <?= $typeInfo[0] ?> &middot; Saldo <?= number_format((int)$ph['balance_after']) ?> poin
                                </div>
                            </div>
                            <div class="point-history-amount <?= $isPlus ? 'plus' : 'minus' ?>">
                                <?= $isPlus ? '+' : '-' ?><?= number_format(abs((int)$ph['points'])) ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div style="text-align: center; padding: var(--space-xl) 0; color: var(--text-light);">
                        <i class="fas fa-coins" style="font-size: 2.5rem; opacity: 0.4; display: block; margin-bottom: var(--space-md);"></i>
                        <p style="font-size: var(--text-sm); margin: 0;">Belum ada riwayat poin. <a href="<?= SITE_URL ?>/pages/products.php" style="color: var(--warm-orange);">Belanja sekarang</a> untuk mulai mengumpulkan poin!</p>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Alamat Pengiriman -->
                <div style="background: var(--warm-white); border-radius: var(--radius-xl); padding: var(--space-2xl); box-shadow: var(--shadow-sm); margin-bottom: var(--space-xl);">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-lg); flex-wrap: wrap; gap: var(--space-md);">
                        <h3 style="font-family: var(--font-display); font-size: var(--text-2xl); font-weight: 600; margin: 0;">
                            <i class="fas fa-truck" style="color: var(--soft-gold); margin-right: var(--space-sm);"></i>
                            Alamat Pengiriman
                        </h3>
                        <span style="font-size: var(--text-xs); color: var(--text-muted);">Alamat default otomatis terisi di halaman checkout</span>
                    </div>

                    <?php if ($addresses): ?>
                    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: var(--space-lg); margin-bottom: var(--space-xl);">
                        <?php foreach ($addresses as $ad): ?>
                        <div style="border: 1px solid var(--soft-grey); border-radius: var(--radius-md); padding: var(--space-lg); background: #FFF; position: relative;">
                            <?php if ($ad['is_default']): ?>
                            <span style="position: absolute; top: 10px; right: 10px; font-size: 10px; background: var(--soft-gold-gradient); color: var(--text-white); padding: 2px 8px; border-radius: 20px; font-weight: 600;">DEFAULT</span>
                            <?php endif; ?>
                            <strong style="display: block; font-size: var(--text-md); margin-bottom: 4px;">
                                <?= htmlspecialchars($ad['label'] ?: 'Alamat') ?>
                            </strong>
                            <p style="font-size: var(--text-sm); color: var(--text-secondary); margin: 0 0 2px;">
                                <?= htmlspecialchars($ad['recipient_name']) ?>
                                <?php if ($ad['phone']): ?> &middot; <?= htmlspecialchars($ad['phone']) ?><?php endif; ?>
                            </p>
                            <p style="font-size: var(--text-sm); color: var(--text-secondary); margin: 0 0 2px;"><?= htmlspecialchars($ad['address']) ?></p>
                            <p style="font-size: var(--text-sm); color: var(--text-muted); margin: 0 0 var(--space-md);">
                                <?= htmlspecialchars($ad['city']) ?><?php if ($ad['province']): ?>, <?= htmlspecialchars($ad['province']) ?><?php endif; ?>
                                <?php if ($ad['postal_code']): ?> <?= htmlspecialchars($ad['postal_code']) ?><?php endif; ?>
                            </p>
                            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                <?php if (!$ad['is_default']): ?>
                                <a href="?set_default_address=<?= (int)$ad['id'] ?>" class="btn btn-outline btn-sm" title="Jadikan alamat default">
                                    <i class="fas fa-star"></i> Default
                                </a>
                                <?php endif; ?>
                                <a href="?edit_address=<?= (int)$ad['id'] ?>" class="btn btn-outline btn-sm" title="Edit alamat">
                                    <i class="fas fa-edit"></i> Edit
                                </a>
                                <a href="?delete_address=<?= (int)$ad['id'] ?>" class="btn btn-outline btn-sm" style="color: #DC2626; border-color: #fecaca;" onclick="return confirm('Hapus alamat ini?')" title="Hapus alamat">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div style="text-align: center; padding: var(--space-xl) 0; color: var(--text-light); border: 1px dashed var(--soft-grey); border-radius: var(--radius-md); margin-bottom: var(--space-xl);">
                        <i class="fas fa-truck" style="font-size: 2.5rem; opacity: 0.4; display: block; margin-bottom: var(--space-md);"></i>
                        <p style="font-size: var(--text-sm); margin: 0;">Belum ada alamat tersimpan. Tambahkan di bawah, atau centang "💾 Simpan alamat" saat checkout.</p>
                    </div>
                    <?php endif; ?>

                    <!-- Tambah / Edit Alamat -->
                    <form method="POST" action="">
                        <input type="hidden" name="save_address" value="1">
                        <input type="hidden" name="address_id" value="<?= $editAddr ? (int)$editAddr['id'] : 0 ?>">
                        <h4 style="font-size: var(--text-md); font-weight: 600; margin-bottom: var(--space-md);">
                            <?= $editAddr ? 'Edit Alamat' : 'Tambah Alamat Baru' ?>
                        </h4>
                        <div class="grid grid-2" style="gap: var(--space-lg);">
                            <div class="form-group">
                                <label class="form-label">Label <span style="color: var(--text-light);">(mis. Rumah, Kantor)</span></label>
                                <input type="text" name="address_label" class="form-input" placeholder="Rumah / Kantor"
                                       value="<?= htmlspecialchars($editAddr['label'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Nama Penerima <span style="color: #EF4444;">*</span></label>
                                <input type="text" name="recipient_name" class="form-input" placeholder="Nama penerima" required
                                       value="<?= htmlspecialchars($editAddr['recipient_name'] ?? $user['full_name']) ?>">
                            </div>
                        </div>
                        <div class="grid grid-2" style="gap: var(--space-lg);">
                            <div class="form-group">
                                <label class="form-label">No. Telepon Penerima</label>
                                <input type="tel" name="address_phone" class="form-input" placeholder="No. telepon penerima"
                                       value="<?= htmlspecialchars($editAddr['phone'] ?? ($user['phone'] ?? '')) ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Kode Pos</label>
                                <input type="text" name="address_postal_code" class="form-input" placeholder="Kode pos"
                                       value="<?= htmlspecialchars($editAddr['postal_code'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Alamat Lengkap <span style="color: #EF4444;">*</span></label>
                            <textarea name="address" class="form-textarea" placeholder="Jalan, nomor, rt/rw, kelurahan, kecamatan" required><?= htmlspecialchars($editAddr['address'] ?? '') ?></textarea>
                        </div>
                        <div class="grid grid-2" style="gap: var(--space-lg);">
                            <div class="form-group">
                                <label class="form-label">Kota/Kabupaten <span style="color: #EF4444;">*</span></label>
                                <input type="text" name="address_city" class="form-input" placeholder="Kota" required
                                       value="<?= htmlspecialchars($editAddr['city'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Provinsi</label>
                                <select name="address_province" class="form-select">
                                    <option value="">Pilih Provinsi</option>
                                    <?php foreach ($provinces as $p): ?>
                                    <option value="<?= htmlspecialchars($p) ?>" <?= ($editAddr['province'] ?? 'Riau') === $p ? 'selected' : '' ?>><?= htmlspecialchars($p) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <label style="display: flex; align-items: center; gap: var(--space-sm); font-size: var(--text-sm); cursor: pointer; margin-bottom: var(--space-lg);">
                            <input type="checkbox" name="address_default" value="1" style="width: 16px; height: 16px; accent-color: var(--soft-gold);"
                                   <?= (!$addresses || !empty($editAddr['is_default'])) ? 'checked' : '' ?>>
                            Jadikan alamat default (otomatis terisi di checkout)
                        </label>
                        <div style="display: flex; gap: var(--space-md); flex-wrap: wrap;">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> <?= $editAddr ? 'Simpan Perubahan' : 'Tambah Alamat' ?>
                            </button>
                            <?php if ($editAddr): ?>
                            <a href="<?= BASE_PATH ?>/auth/profile.php" class="btn btn-outline">Batal</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <!-- Profile Information Form -->
                <div style="background: var(--warm-white); border-radius: var(--radius-xl); padding: var(--space-2xl); box-shadow: var(--shadow-sm);">
                    <h3 style="font-family: var(--font-display); font-size: var(--text-2xl); font-weight: 600; margin-bottom: var(--space-xl);">
                        <i class="fas fa-user-edit" style="color: var(--soft-gold); margin-right: var(--space-sm);"></i>
                        Informasi Profil
                    </h3>
                    
                    <form method="POST" action="">
                        <input type="hidden" name="update_profile" value="1">
                        <div class="grid grid-2" style="gap: var(--space-lg);">
                            <div class="form-group">
                                <label class="form-label">Nama Lengkap <span style="color: #EF4444;">*</span></label>
                                <input type="text" name="full_name" class="form-input" 
                                       value="<?= htmlspecialchars($user['full_name']) ?>" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Username <span style="color: #EF4444;">*</span></label>
                                <input type="text" name="username" class="form-input" 
                                       value="<?= htmlspecialchars($user['username']) ?>" required>
                            </div>
                        </div>

                        <div class="grid grid-2" style="gap: var(--space-lg);">
                            <div class="form-group">
                                <label class="form-label">Email <span style="color: #EF4444;">*</span></label>
                                <input type="email" name="email" class="form-input" 
                                       value="<?= htmlspecialchars($user['email']) ?>" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">No. Telepon</label>
                                <input type="tel" name="phone" class="form-input" 
                                       value="<?= htmlspecialchars($user['phone'] ?? '') ?>" 
                                       placeholder="Contoh: 0821-1234-5678">
                            </div>
                        </div>

                        <div style="margin-top: var(--space-xl);">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Simpan Perubahan
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Password Change -->
                <div style="background: var(--warm-white); border-radius: var(--radius-xl); padding: var(--space-2xl); box-shadow: var(--shadow-sm); margin-top: var(--space-xl);">
                    <h3 style="font-family: var(--font-display); font-size: var(--text-2xl); font-weight: 600; margin-bottom: var(--space-xl);">
                        <i class="fas fa-lock" style="color: var(--soft-gold); margin-right: var(--space-sm);"></i>
                        Ubah Password
                    </h3>
                    
                    <form method="POST" action="">
                        <input type="hidden" name="change_password" value="1">
                        <div class="grid grid-2" style="gap: var(--space-lg);">
                            <div class="form-group">
                                <label class="form-label">Password Saat Ini <span style="color: #EF4444;">*</span></label>
                                <input type="password" name="current_password" class="form-input" placeholder="Masukkan password saat ini" required minlength="6">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Password Baru <span style="color: #EF4444;">*</span></label>
                                <input type="password" name="new_password" class="form-input" placeholder="Buat password baru" required minlength="6">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Konfirmasi Password Baru <span style="color: #EF4444;">*</span></label>
                                <input type="password" name="confirm_password" class="form-input" placeholder="Ulangi password baru" required minlength="6">
                            </div>
                        </div>

                        <div style="margin-top: var(--space-xl);">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-key"></i> Ubah Password
                            </button>
                        </div>
                    </form>
                </div>

            </div>
        </div>
    </div>
</section>

<?php include '../includes/footer.php'; ?>
