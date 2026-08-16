<?php
// ============================================
// CHECKOUT PAGE
// Memproses pesanan dari keranjang ke order
// Website Nadhira Napoleon Pekanbaru
// ============================================
require_once '../config/database.php';
require_once '../config/midtrans.php';
require_once '../config/rbac.php'; // untuk isLoggedIn()

$conn = getConnection();
ensureMidtransSchema();

// ============================================
// WAJIB LOGIN UNTUK MEMESAN
// Pengguna harus punya akun & sudah login sebelum bisa checkout.
// Setelah login, otomatis dikembalikan ke halaman checkout ini.
// ============================================
if (!isLoggedIn()) {
    header('Location: ' . SITE_URL . '/auth/login.php?redirect=/pages/checkout.php');
    exit;
}

// Hapus kode promo dari sesi (tombol "Hapus" di ringkasan checkout)
if (isset($_GET['remove_promo'])) {
    clearSessionPromoCode();
    header('Location: ' . SITE_URL . '/pages/checkout.php');
    exit;
}

// Default poin yang ditukar (dipakai di POST & tampilan)
$pointsUsed = 0;
$pointsDiscount = 0;

// ============================================
// POST HANDLER - CREATE ORDER
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];

    // Get and validate form data
    $customerName    = trim($_POST['customer_name'] ?? '');
    $customerPhone   = trim($_POST['customer_phone'] ?? '');
    $customerEmail   = trim($_POST['customer_email'] ?? '');
    $shippingAddress = trim($_POST['shipping_address'] ?? '');
    $shippingCity    = trim($_POST['shipping_city'] ?? '');
    $shippingProvince= trim($_POST['shipping_province'] ?? '');
    $shippingPostal  = trim($_POST['shipping_postal_code'] ?? '');
    $notes           = trim($_POST['notes'] ?? '');
    // Metode pembayaran saat ini hanya Midtrans Snap (VA, QRIS, E-Wallet, Kartu Kredit)
    $paymentMethod   = 'midtrans';

    // Validation
    if (empty($customerName))    $errors[] = 'Nama lengkap harus diisi';
    if (empty($customerPhone))   $errors[] = 'No. telepon harus diisi';
    if (empty($customerEmail))   $errors[] = 'Email harus diisi';
    if (!empty($customerEmail) && !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) $errors[] = 'Format email tidak valid';
    if (empty($shippingAddress)) $errors[] = 'Alamat harus diisi';
    if (empty($shippingCity))    $errors[] = 'Kota harus diisi';
    if (empty($shippingProvince))$errors[] = 'Provinsi harus diisi';

    // (semua pesanan baru memakai midtrans; nilai lama transfer_bank/cod/e_wallet tetap
    //  tersimpan di database untuk pesanan historis)

    // Get cart items from DB
    if (!$conn) {
        $errors[] = 'Koneksi database gagal';
    }

    $cartItems = [];
    if ($conn && empty($errors)) {
        if (isLoggedIn()) {
            $userId = (int)$_SESSION['user_id'];
            $r = $conn->query("
                SELECT c.id as cart_id, c.quantity, p.id as product_id, p.name, p.price, p.discount_price, p.stock,
                    (SELECT image FROM product_images WHERE product_id = p.id AND is_primary = 1 LIMIT 1) as product_image
                FROM carts c
                JOIN products p ON c.product_id = p.id AND p.is_active = TRUE
                WHERE c.user_id = $userId
            ");
        } else {
            $sessionId = $conn->real_escape_string(session_id());
            $r = $conn->query("
                SELECT c.id as cart_id, c.quantity, p.id as product_id, p.name, p.price, p.discount_price, p.stock,
                    (SELECT image FROM product_images WHERE product_id = p.id AND is_primary = 1 LIMIT 1) as product_image
                FROM carts c
                JOIN products p ON c.product_id = p.id AND p.is_active = TRUE
                WHERE c.session_id = '$sessionId'
            ");
        }

        if ($r) {
            while ($row = $r->fetch_assoc()) {
                $cartItems[] = $row;
            }
        }
    }

    // Stock validation — pakai stok cabang yang dipilih customer (branch_products.stock),
    // fallback ke stok global produk bila cabang tidak menjual produk tsb / belum diatur.
    $chosenBranchForStock = (int)($_POST['branch_id'] ?? 0);
    $stockErrors = [];
    foreach ($cartItems as $item) {
        $availableStock = (int)$item['stock'];
        if ($chosenBranchForStock > 0) {
            $branchStock = getProductStockForBranch((int)$item['product_id'], $chosenBranchForStock);
            if ($branchStock !== null) $availableStock = $branchStock;
        }
        if ($availableStock < (int)$item['quantity']) {
            $stockErrors[] = htmlspecialchars($item['name']) . ' hanya tersedia ' . $availableStock . ' item di cabang tujuan (diminta ' . (int)$item['quantity'] . ')';
        }
    }
    if (!empty($stockErrors)) {
        $errors = array_merge($errors, $stockErrors);
    }

    if (empty($cartItems)) {
        $errors[] = 'Keranjang belanja kosong';
    }

    // Paket membership wajib login (aktivasi langganan butuh akun member)
    // Keranjang yang berisi HANYA paket membership tidak dikenakan ongkir.
    $membershipOnlyCart = !empty($cartItems);
    foreach ($cartItems as $item) {
        if (isMembershipProduct($item['product_id'])) {
            if (!isLoggedIn()) {
                $errors[] = 'Silakan login terlebih dahulu untuk berlangganan membership';
            }
        } else {
            $membershipOnlyCart = false;
        }
    }

    // Hitung subtotal (juga dipakai untuk validasi tukar poin)
    $subtotal = 0;
    foreach ($cartItems as $item) {
        $displayPrice = $item['discount_price'] > 0 ? $item['discount_price'] : $item['price'];
        $subtotal += $displayPrice * $item['quantity'];
    }

    // Tukar poin menjadi diskon: 1 poin = Rp 100, maks POINT_MAX_PCT% dari subtotal
    if (isLoggedIn()) {
        $reqPoints = max(0, (int)($_POST['points_used'] ?? 0));
        if ($reqPoints > 0) {
            $redeemUser = getCurrentUser();
            $avail = (int)($redeemUser['points'] ?? 0);
            $maxRedeem = redeemablePointsForOrder((int)$redeemUser['id'], $subtotal);
            if ($reqPoints > $avail) {
                $errors[] = 'Poin tidak mencukupi. Poin Anda: ' . number_format($avail);
            } elseif ($reqPoints > $maxRedeem) {
                $errors[] = 'Maksimal ' . number_format($maxRedeem) . ' poin per pesanan (' . POINT_MAX_PCT . '% dari subtotal)';
            } else {
                $pointsUsed = $reqPoints;
                $pointsDiscount = $reqPoints * POINT_VALUE;
            }
        }
    }

    // Diskon promo membership: paket tahunan mendapat diskon % saat promo aktif
    $promoDiscount = 0;
    $activePromo = getMembershipPromo();
    if ($activePromo && (int)$activePromo['discount'] > 0) {
        $promoDiscount = membershipPromoCartDiscount($cartItems, $activePromo);
    }

    // Kode promo dari keranjang (sesi) — divalidasi ulang server-side terhadap subtotal
    $promoCodeDiscount = 0;
    $promoCodeUsed = '';
    $sessionPromo = getSessionPromoCode();
    if ($sessionPromo !== '') {
        $promoRes = validatePromoCode($sessionPromo, $subtotal);
        if ($promoRes['ok']) {
            $promoCodeDiscount = $promoRes['discount'];
            $promoCodeUsed = $promoRes['promo']['code'];
        } else {
            clearSessionPromoCode(); // kode tidak berlaku lagi — lanjut tanpa diskon
        }
    }

    // Diskon harga khusus member (berdasarkan level akun, dari Admin > Pengaturan)
    $memberDiscount = 0;
    $memberDiscountRate = 0;
    if (isLoggedIn()) {
        $memberDiscountRate = getMemberDiscountRate();
        $memberDiscount = getMemberDiscountForSubtotal($subtotal);
    }

    // If no errors, create the order
    if (!empty($errors)) {
        $errorMessage = implode('<br>', $errors);
    } else {
        // Calculate totals ($subtotal sudah dihitung di atas)
        // ===== ONGKIR BERBASIS JARAK (lokasi GPS) =====
        // Jika customer memilih lokasi di peta (latitude/longitude terisi),
        // ongkir dihitung dari jarak cabang terdekat via OSRM. Jika tidak,
        // fallback ke pengaturan flat (shipping_cost; 0 = GRATIS ONGKIR).
        $orderLat  = (float)($_POST['latitude'] ?? 0);
        $orderLng  = (float)($_POST['longitude'] ?? 0);
        $orderDist = null;
        $orderBranchId = null;
        $shippingCost = $membershipOnlyCart ? 0 : (float)getSetting('shipping_cost', 0);

        // Cabang PILIHAN customer (jika ada) — divalidasi milik cabang aktif,
        // agar customer tidak bisa memalsukan ongkir (harga tetap dihitung server).
        $chosenBranchId = (int)($_POST['branch_id'] ?? 0);
        $branch = null;
        if ($chosenBranchId > 0) {
            foreach (getActiveBranches() as $b) {
                if ((int)$b['id'] === $chosenBranchId) { $branch = $b; break; }
            }
        }

        if (!$membershipOnlyCart && $orderLat && $orderLng
            && $orderLat >= -11 && $orderLat <= 6 && $orderLng >= 95 && $orderLng <= 141) {
            // Jika customer belum memilih cabang, pakai cabang terdekat otomatis
            if (!$branch) {
                $nb = getNearestBranch($orderLat, $orderLng);
                if ($nb) $branch = $nb['branch'];
            }
            if ($branch) {
                // Cabang pilihan SELALU tercatat (konsisten dengan UI pemilih),
                // meskipun ongkir berbasis jarak gagal dihitung (fallback tarif flat)
                $orderBranchId = (int)$branch['id'];
                $orderDist = getRoadDistanceKm($orderLat, $orderLng, $branch['latitude'], $branch['longitude']);
                if ($orderDist === null) {
                    $orderDist = haversineKm($orderLat, $orderLng, $branch['latitude'], $branch['longitude']);
                }
                $byDistance = calculateShippingCost($orderDist);
                if ($byDistance !== null) $shippingCost = $byDistance;
            }
        }

        $discount = min($subtotal, $pointsDiscount + $promoDiscount + $promoCodeDiscount + $memberDiscount);
        $total = $subtotal + $shippingCost - $discount;

        // Generate unique order number
        $datePart = date('Ymd');
        $maxAttempts = 20;
        $attempt = 0;
        do {
            $randomPart = str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
            $orderNumber = 'INV-' . $datePart . '-' . $randomPart;
            $check = $conn->query("SELECT id FROM orders WHERE order_number = '$orderNumber'");
            if (++$attempt > $maxAttempts) {
                throw new Exception('Gagal membuat nomor pesanan. Silakan coba lagi.');
            }
        } while ($check && $check->num_rows > 0);

        $conn->begin_transaction();
        try {
            // Insert order
            $escName     = $conn->real_escape_string($customerName);
            $escPhone    = $conn->real_escape_string($customerPhone);
            $escEmail    = $conn->real_escape_string($customerEmail);
            $escAddr     = $conn->real_escape_string($shippingAddress);
            $escCity     = $conn->real_escape_string($shippingCity);
            $escProvince = $conn->real_escape_string($shippingProvince);
            $escPostal   = $conn->real_escape_string($shippingPostal);
            $escNotes    = $conn->real_escape_string($notes);
            $escPayMethod= $conn->real_escape_string($paymentMethod);
            $escPromoCode = $conn->real_escape_string($promoCodeUsed);
            $userId      = isLoggedIn() ? (int)$_SESSION['user_id'] : 'NULL';

            $orderSql = "
                INSERT INTO orders (
                    order_number, user_id,
                    customer_name, customer_email, customer_phone,
                    shipping_address, shipping_city, shipping_province, shipping_postal_code,
                    latitude, longitude, distance_km, branch_id,
                    subtotal, shipping_cost, discount, promo_code, total,
                    payment_method, payment_status, order_status, notes
                ) VALUES (
                    '$orderNumber', $userId,
                    '$escName', '$escEmail', '$escPhone',
                    '$escAddr', '$escCity', '$escProvince', '$escPostal',
                    " . ($orderLat ?: 'NULL') . ", " . ($orderLng ?: 'NULL') . ", " . ($orderDist !== null ? $orderDist : 'NULL') . ", " . ($orderBranchId ?: 'NULL') . ",
                    $subtotal, $shippingCost, $discount, '$escPromoCode', $total,
                    '$escPayMethod', 'pending', 'pending', '$escNotes'
                )
            ";

            if (!$conn->query($orderSql)) {
                throw new Exception('Gagal membuat pesanan: ' . $conn->error);
            }

            $orderId = $conn->insert_id;

            // Insert order items
            foreach ($cartItems as $item) {
                $displayPrice = $item['discount_price'] > 0 ? $item['discount_price'] : $item['price'];
                $itemSubtotal = $displayPrice * $item['quantity'];
                $escProductName = $conn->real_escape_string($item['name']);
                $escProductImg  = $conn->real_escape_string($item['product_image'] ?? '');

                $itemSql = "
                    INSERT INTO order_items (order_id, product_id, product_name, product_image, price, quantity, subtotal)
                    VALUES ($orderId, {$item['product_id']}, '$escProductName', '$escProductImg', $displayPrice, {$item['quantity']}, $itemSubtotal)
                ";

                if (!$conn->query($itemSql)) {
                    throw new Exception('Gagal menyimpan item pesanan: ' . $conn->error);
                }
            }

            // Clear cart
            if (isLoggedIn()) {
                $conn->query("DELETE FROM carts WHERE user_id = " . (int)$_SESSION['user_id']);
            } else {
                $sessionId = $conn->real_escape_string(session_id());
                $conn->query("DELETE FROM carts WHERE session_id = '$sessionId'");
            }

            // Potong poin yang ditukar menjadi diskon
            if ($pointsUsed > 0 && isLoggedIn()) {
                redeemPointsForOrder($orderId, (int)$_SESSION['user_id'], $pointsUsed);
            }

            // Poin & total belanja TIDAK diberikan di sini.
            // Aturan baru: poin baru bertambah setelah pembayaran LUNAS / terverifikasi
            // (dipicu webhook Midtrans atau verifikasi admin saat payment_status = 'paid').

            // Catat pemakaian kode promo (untuk batas pemakaian max_uses)
            if ($promoCodeUsed !== '') {
                incrementPromoUsage($promoCodeUsed);
            }

            $conn->commit();

            // Kode promo sudah dipakai — bersihkan dari sesi
            clearSessionPromoCode();

            // 📧 Email konfirmasi pesanan ke customer.
            // Gagal kirim email TIDAK menggagalkan pesanan — hanya dicatat di error_log.
            require_once __DIR__ . '/../config/mail.php';
            if (function_exists('sendOrderConfirmationEmail')) {
                try {
                    sendOrderConfirmationEmail($orderId);
                } catch (Exception $e) {
                    error_log('[MAIL] Konfirmasi pesanan #' . $orderId . ' gagal: ' . $e->getMessage());
                }
            }

            // 💾 Simpan alamat pengiriman ke profil agar checkout berikutnya lebih cepat
            if (!empty($_POST['save_address']) && function_exists('saveShippingAddress')) {
                saveShippingAddress((int)$_SESSION['user_id'], [
                    'label'          => 'Alamat ' . date('d/m/Y'),
                    'recipient_name' => $customerName,
                    'phone'          => $customerPhone,
                    'address'        => $shippingAddress,
                    'city'           => $shippingCity,
                    'province'       => $shippingProvince,
                    'postal_code'    => $shippingPostal,
                    'latitude'       => $orderLat ?: 0,
                    'longitude'      => $orderLng ?: 0,
                    'is_default'     => 1,
                ]);
            }

            // Notifikasi ke admin TIDAK dikirim di sini — admin hanya diberi tahu
            // saat pembayaran benar-benar LUNAS (di config/midtrans.php, lihat
            // midtransApplyTransactionStatus) agar tidak ada suara untuk order yang belum dibayar.

            // Redirect ke halaman pembayaran Midtrans (atau invoice untuk pesanan non-Midtrans)
            if ($paymentMethod === 'midtrans') {
                $redirectUrl = SITE_URL . '/pages/payment.php?order=' . urlencode($orderNumber) . '&email=' . urlencode($customerEmail);
            } else {
                $redirectUrl = SITE_URL . '/pages/invoice.php?order=' . urlencode($orderNumber) . '&email=' . urlencode($customerEmail);
            }
            header('Location: ' . $redirectUrl);
            exit;

        } catch (Exception $e) {
            $conn->rollback();
            $errorMessage = 'Gagal memproses pesanan: ' . $e->getMessage();
        }
    }
}

// ============================================
// GET - DISPLAY CHECKOUT FORM
// ============================================

// Get cart items for display
$cartItems = [];
$subtotal = 0;
$itemCount = 0;

if ($conn) {
    if (isLoggedIn()) {
        $userId = (int)$_SESSION['user_id'];
        $r = $conn->query("
            SELECT c.id as cart_id, c.quantity, p.id as product_id, p.name, p.price, p.discount_price,
                (SELECT image FROM product_images WHERE product_id = p.id AND is_primary = 1 LIMIT 1) as product_image
            FROM carts c
            JOIN products p ON c.product_id = p.id AND p.is_active = TRUE
            WHERE c.user_id = $userId
        ");
    } else {
        $sessionId = $conn->real_escape_string(session_id());
        $r = $conn->query("
            SELECT c.id as cart_id, c.quantity, p.id as product_id, p.name, p.price, p.discount_price,
                (SELECT image FROM product_images WHERE product_id = p.id AND is_primary = 1 LIMIT 1) as product_image
            FROM carts c
            JOIN products p ON c.product_id = p.id AND p.is_active = TRUE
            WHERE c.session_id = '$sessionId'
        ");
    }

    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $displayPrice = $row['discount_price'] > 0 ? $row['discount_price'] : $row['price'];
            $row['display_price'] = $displayPrice;
            $row['item_total'] = $displayPrice * $row['quantity'];
            $subtotal += $row['item_total'];
            $itemCount += $row['quantity'];
            $cartItems[] = $row;
        }
    }
}

// Keranjang yang berisi HANYA paket membership tidak dikenakan ongkir
$membershipOnlyCart = !empty($cartItems);
foreach ($cartItems as $item) {
    if (!isMembershipProduct($item['product_id'])) {
        $membershipOnlyCart = false;
    }
}

// If cart is empty and not a POST, show empty state
if (empty($cartItems) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $page_title = 'Checkout';
    include '../includes/header.php';
    ?>
    <section style="min-height: 70vh; display: flex; align-items: center; padding-top: calc(var(--navbar-total-height, 80px) + 8px);">
        <div class="container">
            <div style="text-align: center; max-width: 500px; margin: 0 auto;">
                <i class="fas fa-shopping-bag" style="font-size: 4rem; color: var(--soft-gold); opacity: 0.4; margin-bottom: var(--space-lg);"></i>
                <h1 style="font-family: var(--font-display); font-size: var(--text-3xl); font-weight: 700; margin-bottom: var(--space-md);">
                    Keranjang <span class="gold-text">Kosong</span>
                </h1>
                <p style="color: var(--text-muted); margin-bottom: var(--space-2xl);">Tambahkan produk dulu sebelum checkout.</p>
                <a href="<?= BASE_PATH ?>/pages/products.php" class="btn btn-primary btn-lg"><i class="fas fa-arrow-left"></i> Mulai Belanja</a>
            </div>
        </div>
    </section>
    <?php
    include '../includes/footer.php';
    exit;
}

// Pre-fill user data if logged in
$userData = null;
$savedAddresses = [];
$defaultAddress = null;
if (isLoggedIn()) {
    $userData = getCurrentUser();
    $savedAddresses = getUserShippingAddresses((int)$userData['id']);
    $defaultAddress = getDefaultShippingAddress((int)$userData['id']);
}

// Diskon promo membership: paket tahunan mendapat diskon % saat promo aktif
$promoDiscount = 0;
$activePromo = getMembershipPromo();
if ($activePromo && (int)$activePromo['discount'] > 0) {
    $promoDiscount = membershipPromoCartDiscount($cartItems, $activePromo);
}

// Kode promo dari sesi (diterapkan di keranjang) — dihitung ulang dari subtotal saat ini
$promoCode = getSessionPromoCode();
$promoCodeDiscount = 0;
if ($promoCode !== '') {
    $promoRes = validatePromoCode($promoCode, $subtotal);
    if ($promoRes['ok']) {
        $promoCodeDiscount = $promoRes['discount'];
    } else {
        clearSessionPromoCode(); // kode tidak berlaku lagi — bersihkan otomatis
        $promoCode = '';
    }
}

$shippingCost = $membershipOnlyCart ? 0 : (float)getSetting('shipping_cost', 0);

// Diskon harga khusus member (berdasarkan level akun)
$memberDiscountRate = 0;
$memberDiscount = 0;
$memberLevelLabel = '';
if (isLoggedIn()) {
    $memberDiscountRate = getMemberDiscountRate();
    $memberDiscount = getMemberDiscountForSubtotal($subtotal);
    $memberLevelLabel = getMemberLevelLabel(getCurrentUser()['membership'] ?? '');
}

$total = $subtotal + $shippingCost - min($subtotal, $pointsDiscount + $promoDiscount + $promoCodeDiscount + $memberDiscount);

// Data tukar poin untuk ringkasan
$userPoints = 0;
$maxRedeem = 0;
if (isLoggedIn()) {
    $ptUser = getCurrentUser();
    $userPoints = (int)($ptUser['points'] ?? 0);
    $maxRedeem = redeemablePointsForOrder((int)$ptUser['id'], $subtotal);
}

// Data cabang untuk peta checkout
$branchesJson = array_map(function ($b) {
    return [
        'id'        => (int)$b['id'],
        'name'      => $b['name'],
        'address'   => $b['address'],
        'latitude'  => (float)$b['latitude'],
        'longitude' => (float)$b['longitude'],
    ];
}, getActiveBranches());

$page_title = 'Checkout';
include '../includes/header.php';
?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<style>
#checkout-map { z-index: 1; position: relative; }
#checkout-map .leaflet-top,
#checkout-map .leaflet-bottom { z-index: 1; }
</style>

<section class="checkout-section">
    <div class="container">
        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="<?= SITE_URL ?>">Home</a>
            <span class="separator">/</span>
            <a href="<?= BASE_PATH ?>/pages/cart.php">Keranjang</a>
            <span class="separator">/</span>
            <span class="current">Checkout</span>
        </div>

        <h1 style="font-family: var(--font-display); font-size: var(--text-4xl); font-weight: 700; margin-bottom: var(--space-xl);">
            Checkout
        </h1>

        <?php if (isset($errorMessage)): ?>
        <div style="background: #FEF2F2; border: 1px solid #FECACA; border-radius: var(--radius-md); padding: var(--space-lg); margin-bottom: var(--space-xl); color: #DC2626;">
            <i class="fas fa-exclamation-circle"></i>
            <?= $errorMessage ?>
        </div>
        <?php endif; ?>

        <div class="checkout-grid">
            <!-- Checkout Form -->
            <div>
                <form method="POST" action="" id="checkout-form">
                    <!-- Shipping Information -->
                    <div class="checkout-card">
                        <h3 style="font-family: var(--font-display); font-size: var(--text-2xl); font-weight: 600; margin-bottom: var(--space-xl);">
                            <i class="fas fa-truck" style="color: var(--soft-gold); margin-right: var(--space-sm);"></i>
                            Informasi Pengiriman
                        </h3>

                        <!-- ===== LOKASI GPS (Leaflet) ===== -->
                        <div style="background: #fff; border: 1.5px solid var(--soft-grey); border-radius: var(--radius-lg); padding: var(--space-lg); margin-bottom: var(--space-xl);">
                            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 12px;">
                                <div>
                                    <strong style="font-size: var(--text-base);">📍 Pilih Lokasi Pengiriman</strong>
                                    <div style="font-size: var(--text-sm); color: var(--text-muted);">Aktifkan GPS atau klik peta untuk mengisi alamat & menghitung ongkir otomatis.</div>
                                </div>
                                <button type="button" id="btn-use-location" class="btn btn-primary" style="padding: 10px 20px; font-size: var(--text-sm);" onclick="getMyLocation()">
                                    📍 Gunakan Lokasi Saya
                                </button>
                            </div>
                            <div id="location-status" style="font-size: var(--text-sm); color: var(--text-secondary); margin-bottom: 10px; min-height: 20px;"></div>
                            <div id="checkout-map" class="checkout-map" style="border-radius: var(--radius-md); border: 1px solid var(--soft-grey);"></div>
                            <small style="color: var(--text-light); display: block; margin-top: 8px;">
                                <i class="fas fa-info-circle"></i> Geser marker atau klik lokasi lain di peta untuk memperbarui alamat & ongkir. Jika lokasi gagal, silakan isi alamat secara manual di bawah.
                            </small>
                            <input type="hidden" name="latitude" id="latitude" value="">
                            <input type="hidden" name="longitude" id="longitude" value="">
                            <input type="hidden" name="distance_km" id="distance_km" value="">

                            <!-- ===== PILIH CABANG TERDEKAT ===== -->
                            <div id="branch-picker-wrap" class="branch-picker-wrap" style="display:none;">
                                <div class="branch-picker-head">
                                    <strong><i class="fas fa-store" style="color: var(--soft-gold); margin-right: 6px;"></i> Pilih Cabang Terdekat</strong>
                                    <div class="branch-picker-hint">Diurutkan dari yang paling dekat dengan lokasi Anda — bisa diganti sesuai keinginan.</div>
                                </div>
                                <div id="branch-list" class="branch-list"></div>
                            </div>
                        </div>

                        <?php if (!empty($savedAddresses)): ?>
                        <div class="form-group" style="margin-bottom: var(--space-xl);">
                            <label class="form-label" for="saved-address-select">
                                <i class="fas fa-bookmark" style="color: var(--soft-gold); margin-right: 6px;"></i>
                                Alamat Tersimpan
                            </label>
                            <select id="saved-address-select" class="form-select" onchange="fillSavedAddress(this)">
                                <option value="">— Pilih alamat untuk mengisi form otomatis —</option>
                                <?php foreach ($savedAddresses as $sa): ?>
                                <option value="<?= (int)$sa['id'] ?>">
                                    <?= htmlspecialchars(($sa['label'] ?: 'Alamat') . ($sa['is_default'] ? ' ⭐' : '') . ' — ' . $sa['recipient_name'] . ', ' . $sa['city']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <small style="color: var(--text-muted);">Alamat default otomatis terisi — pilih alamat lain untuk menggantinya.</small>
                        </div>
                        <?php endif; ?>

                        <div class="grid grid-2" style="gap: var(--space-lg);">
                            <div class="form-group">
                                <label class="form-label">Nama Lengkap <span style="color: #DC2626;">*</span></label>
                                <input type="text" name="customer_name" class="form-input"
                                       placeholder="Masukkan nama lengkap"
                                       value="<?= htmlspecialchars($_POST['customer_name'] ?? ($userData['full_name'] ?? '')) ?>" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">No. Telepon <span style="color: #DC2626;">*</span></label>
                                <input type="tel" name="customer_phone" class="form-input"
                                       placeholder="Masukkan no. telepon"
                                       value="<?= htmlspecialchars($_POST['customer_phone'] ?? ($userData['phone'] ?? '')) ?>" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Email <span style="color: #DC2626;">*</span></label>
                            <input type="email" name="customer_email" class="form-input"
                                   placeholder="Masukkan email"
                                   value="<?= htmlspecialchars($_POST['customer_email'] ?? ($userData['email'] ?? '')) ?>" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Alamat Lengkap <span style="color: #DC2626;">*</span></label>
                            <textarea name="shipping_address" class="form-textarea"
                                      placeholder="Masukkan alamat lengkap (jalan, nomor, rt/rw, kelurahan, kecamatan)" required><?= htmlspecialchars($_POST['shipping_address'] ?? ($defaultAddress['address'] ?? '')) ?></textarea>
                        </div>

                        <div class="grid grid-3" style="gap: var(--space-lg);">
                            <div class="form-group">
                                <label class="form-label">Kota/Kabupaten <span style="color: #DC2626;">*</span></label>
                                <input type="text" name="shipping_city" class="form-input"
                                       placeholder="Kota"
                                       value="<?= htmlspecialchars($_POST['shipping_city'] ?? ($defaultAddress['city'] ?? '')) ?>" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Provinsi <span style="color: #DC2626;">*</span></label>
                                <select name="shipping_province" class="form-select" required>
                                    <option value="">Pilih Provinsi</option>
                                    <?php
                                    $provinces = getIndonesiaProvinces();
                                    $selectedProv = $_POST['shipping_province'] ?? ($defaultAddress['province'] ?? 'Riau');
                                    foreach ($provinces as $p):
                                        $sel = $p === $selectedProv ? 'selected' : '';
                                    ?>
                                    <option value="<?= htmlspecialchars($p) ?>" <?= $sel ?>><?= htmlspecialchars($p) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Kode Pos</label>
                                <input type="text" name="shipping_postal_code" class="form-input"
                                       placeholder="Kode pos"
                                       value="<?= htmlspecialchars($_POST['shipping_postal_code'] ?? ($defaultAddress['postal_code'] ?? '')) ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Catatan (opsional)</label>
                            <textarea name="notes" class="form-textarea"
                                      placeholder="Catatan untuk pengiriman..." style="min-height: 80px;"><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
                        </div>

                        <label style="display: flex; align-items: center; gap: var(--space-sm); font-size: var(--text-sm); cursor: pointer; padding: 12px 14px; background: var(--soft-gold-gradient); border: 1px dashed var(--soft-gold); border-radius: var(--radius-md);">
                            <input type="checkbox" name="save_address" value="1" style="width: 16px; height: 16px; accent-color: var(--soft-gold);" checked>
                            <span>💾 Simpan alamat ini ke akun saya agar checkout berikutnya otomatis terisi</span>
                        </label>
                    </div>

                    <!-- Payment Method -->
                    <div class="checkout-card">
                        <h3 style="font-family: var(--font-display); font-size: var(--text-2xl); font-weight: 600; margin-bottom: var(--space-xl);">
                            <i class="fas fa-credit-card" style="color: var(--soft-gold); margin-right: var(--space-sm);"></i>
                            Metode Pembayaran
                        </h3>

                        <div style="display: flex; flex-direction: column; gap: var(--space-md);">
                            <label style="display: flex; align-items: center; gap: var(--space-md); padding: var(--space-lg); border: 2px solid var(--soft-gold); background: var(--soft-gold-gradient); border-radius: var(--radius-md); cursor: pointer; transition: var(--transition-base);">
                                <input type="radio" name="payment_method" value="midtrans" checked style="width: 20px; height: 20px; accent-color: var(--soft-gold);">
                                <div style="flex: 1;">
                                    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                        <span style="font-weight: 700;">Bayar Online via Midtrans</span>
                                        <span style="font-size: 11px; background: #2C1810; color: var(--soft-gold); padding: 2px 10px; border-radius: 20px; font-weight: 600;">AMAN &amp; TERENKRIPSI</span>
                                    </div>
                                    <div style="font-size: var(--text-sm); color: var(--text-muted); margin-top: 4px;">
                                        Pilih sendiri cara bayar: Virtual Account (BCA, Mandiri, BNI, BRI, dll), QRIS, GoPay, OVO, DANA, ShopeePay, atau Kartu Kredit
                                    </div>
                                    <div style="display: flex; gap: 6px; flex-wrap: wrap; margin-top: 10px;">
                                        <span style="font-size: 10px; padding: 3px 10px; border: 1px solid var(--soft-grey); border-radius: 20px; color: var(--text-secondary); background: #FFF;">🏦 Virtual Account</span>
                                        <span style="font-size: 10px; padding: 3px 10px; border: 1px solid var(--soft-grey); border-radius: 20px; color: var(--text-secondary); background: #FFF;">📱 QRIS</span>
                                        <span style="font-size: 10px; padding: 3px 10px; border: 1px solid var(--soft-grey); border-radius: 20px; color: var(--text-secondary); background: #FFF;">💳 E-Wallet</span>
                                        <span style="font-size: 10px; padding: 3px 10px; border: 1px solid var(--soft-grey); border-radius: 20px; color: var(--text-secondary); background: #FFF;">💳 Kartu Kredit</span>
                                    </div>
                                </div>
                            </label>
                            <p style="font-size: var(--text-xs); color: var(--text-light); margin: 0;">
                                <i class="fas fa-lock"></i>
                                Setelah pesanan dibuat, Anda akan diarahkan ke halaman pembayaran Midtrans untuk menyelesaikan pembayaran.
                            </p>
                        </div>
                    </div>

                </form>
            </div>

            <!-- Order Summary -->
            <div>
                <div class="cart-summary summary-collapsed" id="cart-summary-wrap" data-aos="fade-left">
                    <div class="cart-summary-head">
                        <h3 class="cart-summary-title" style="margin: 0; padding: 0; border: 0;">
                            Detail Pesanan
                            <?php if ($promoCodeDiscount > 0): ?>
                            <span class="promo-chip" title="Kode promo aktif"><i class="fas fa-ticket-alt"></i> Promo aktif</span>
                            <?php endif; ?>
                        </h3>
                        <button type="button" class="cart-summary-toggle" id="cart-summary-toggle" aria-expanded="false" aria-controls="cart-summary-body">
                            <span id="cart-summary-toggle-text">Tampilkan</span>
                            <i class="fas fa-chevron-down" id="cart-summary-chevron"></i>
                        </button>
                    </div>
                    <div id="cart-summary-body">

                    <div style="margin-bottom: var(--space-lg);">
                        <?php foreach ($cartItems as $item): ?>
                        <div style="display: flex; justify-content: space-between; padding: var(--space-sm) 0; font-size: var(--text-sm);">
                            <span><?= htmlspecialchars($item['name']) ?> x<?= $item['quantity'] ?></span>
                            <span>Rp <?= number_format($item['item_total'], 0, ',', '.') ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="cart-summary-row">
                        <span>Subtotal (<?= $itemCount ?> item)</span>
                        <span>Rp <?= number_format($subtotal, 0, ',', '.') ?></span>
                    </div>
                    <div class="cart-summary-row">
                        <span>Ongkos Kirim</span>
                        <span id="shipping-cost-value"><?= $shippingCost > 0 ? 'Rp ' . number_format($shippingCost, 0, ',', '.') : '<strong style="color: #059669;">GRATIS</strong>' ?></span>
                    </div>
                    <div class="cart-summary-row" id="eta-row" style="display: none;">
                        <span>Estimasi Sampai</span>
                        <span id="eta-value" style="font-size: var(--text-xs); text-align: right;"></span>
                    </div>
                    <div class="cart-summary-row">
                        <span>Biaya Layanan</span>
                        <span>Rp 0</span>
                    </div>

                    <!-- Tukar Poin Jadi Diskon -->
                    <?php if (isLoggedIn()): ?>
                    <div class="points-redeem-box">
                        <div class="points-redeem-title"><i class="fas fa-coins"></i> Tukar Poin Jadi Diskon</div>
                        <div class="points-redeem-info">
                            Poin Anda: <strong><?= number_format($userPoints) ?></strong>
                            <?php if ($maxRedeem > 0): ?>
                            · bisa jadi diskon hingga <strong>Rp <?= number_format($maxRedeem * POINT_VALUE, 0, ',', '.') ?></strong>
                            <?php endif; ?>
                        </div>
                        <div style="display: flex; gap: 8px; align-items: center; margin-top: 8px; flex-wrap: wrap;">
                            <input type="number" name="points_used" id="points-used-input" form="checkout-form"
                                   class="form-input" min="0" max="<?= $maxRedeem ?>"
                                   value="<?= (int)$pointsUsed ?>"
                                   placeholder="Jumlah poin" <?= $maxRedeem > 0 ? '' : 'disabled' ?>
                                   style="width: 130px; padding: 10px 14px; font-size: var(--text-sm);">
                            <span style="font-size: var(--text-xs); color: var(--text-muted);">1 poin = Rp <?= number_format(POINT_VALUE, 0, ',', '.') ?> · maks <?= POINT_MAX_PCT ?>%</span>
                        </div>
                        <div class="points-redeem-preview" id="points-preview" style="<?= $pointsDiscount > 0 ? '' : 'display: none;' ?>">
                            <i class="fas fa-tag"></i>
                            <span>Diskon poin: <strong id="points-discount-value">-Rp <?= number_format($pointsDiscount, 0, ',', '.') ?></strong></span>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($pointsDiscount > 0): ?>
                    <div class="cart-summary-row" style="color: #059669;">
                        <span>Diskon Poin</span>
                        <span>-Rp <?= number_format($pointsDiscount, 0, ',', '.') ?></span>
                    </div>
                    <?php endif; ?>

                    <?php if ($promoDiscount > 0): ?>
                    <div class="cart-summary-row" style="color: #059669;">
                        <span>Diskon Promo Membership</span>
                        <span>-Rp <?= number_format($promoDiscount, 0, ',', '.') ?></span>
                    </div>
                    <?php endif; ?>

                    <?php if ($promoCodeDiscount > 0): ?>
                    <div class="cart-summary-row" style="color: #059669;">
                        <span>Diskon Promo (<?= htmlspecialchars($promoCode) ?>)</span>
                        <span>-Rp <?= number_format($promoCodeDiscount, 0, ',', '.') ?></span>
                    </div>
                    <?php endif; ?>

                    <?php if ($memberDiscount > 0): ?>
                    <div class="cart-summary-row" style="color: #059669;">
                        <span>Diskon Member (<?= htmlspecialchars($memberLevelLabel) ?> <?= (int)$memberDiscountRate ?>%)</span>
                        <span>-Rp <?= number_format($memberDiscount, 0, ',', '.') ?></span>
                    </div>
                    <?php endif; ?>

                    <?php if ($promoCodeDiscount > 0): ?>
                    <div class="promo-box" style="margin: var(--space-md) 0;">
                        <span class="promo-box-info">
                            🎟️ Kode <strong><?= htmlspecialchars($promoCode) ?></strong> aktif
                        </span>
                        <a href="?remove_promo=1" class="promo-box-remove">
                            <i class="fas fa-times"></i> Hapus
                        </a>
                    </div>
                    <?php endif; ?>

                    <div class="cart-summary-row total">
                        <span>Total Pembayaran</span>
                        <span id="points-total-value">Rp <?= number_format($total, 0, ',', '.') ?></span>
                    </div>

                    <?php if (isLoggedIn()):
                        $estPoints = estimateOrderPoints((int)$_SESSION['user_id'], $subtotal); ?>
                    <div style="margin-top: var(--space-lg); padding: var(--space-md); background: var(--soft-gold-gradient); border: 1px dashed var(--soft-gold); border-radius: var(--radius-md); display: flex; align-items: center; gap: var(--space-sm); font-size: var(--text-sm);">
                        <i class="fas fa-coins" style="color: var(--warm-orange);"></i>
                        <span style="color: var(--text-secondary);">
                            Anda akan mendapat <strong style="color: var(--warm-orange);"><?= number_format($estPoints) ?> poin</strong> dari pesanan ini<br>
                            <small style="color: var(--text-muted); font-weight: 400;">(poin diberikan setelah pembayaran lunas / terverifikasi)</small>
                        </span>
                    </div>
                    <?php endif; ?>

                    <button type="submit" form="checkout-form" class="btn btn-primary btn-lg w-full btn-submit-desktop" style="margin-top: var(--space-xl);">
                        <i class="fas fa-lock"></i>
                        Buat Pesanan
                    </button>

                    <p style="font-size: var(--text-xs); color: var(--text-light); text-align: center; margin-top: var(--space-md);">
                        <i class="fas fa-shield-alt"></i>
                        Data Anda aman dan terenkripsi
                    </p>
                    </div><!-- /#cart-summary-body -->
                </div>
            </div>
        </div><!-- /.checkout-grid -->

        <!-- Sticky Bottom Bar (Mobile) -->
        <div class="checkout-mobile-bar">
            <div class="checkout-mobile-total">
                <span class="checkout-mobile-label">Total Pembayaran</span>
                <span class="checkout-mobile-value" id="points-total-value-mobile">Rp <?= number_format($total, 0, ',', '.') ?></span>
            </div>
            <button type="submit" form="checkout-form" class="btn btn-primary checkout-mobile-btn">
                <i class="fas fa-lock"></i> Buat Pesanan
            </button>
        </div>
    </div>
</section>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
// ============================================
// CHECKOUT: LOKASI GPS (Leaflet + Nominatim)
// ============================================
var AJAX_BASE = '<?= SITE_URL ?>';
var NN_BRANCHES = <?= json_encode($branchesJson, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
var MEMBERSHIP_ONLY_CART = <?= $membershipOnlyCart ? 'true' : 'false' ?>;
var NN = { map: null, customerMarker: null, reverseTimer: null };
var userChoseBranch = false; // true setelah customer memilih cabang secara manual
var shippingSeq = 0; // penomoran request ongkir (untuk mengabaikan respons basi)

// Escape HTML untuk teks dari server yang dimasukkan via innerHTML
function escHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}

// Ringkasan pesanan (dipakai untuk update total otomatis)
window.CHECKOUT = {
    subtotal: <?= (int)$subtotal ?>,
    shippingCost: <?= (float)$shippingCost ?>,
    promoDiscount: <?= (float)$promoDiscount ?>,
    promoCodeDiscount: <?= (float)$promoCodeDiscount ?>,
    memberDiscount: <?= (float)$memberDiscount ?>,
    maxRedeem: <?= (int)$maxRedeem ?>,
    pointValue: <?= (int)POINT_VALUE ?>
};

function checkoutTotal() {
    var p = 0;
    var input = document.getElementById('points-used-input');
    if (input && !input.disabled) {
        p = Math.max(0, Math.min(parseInt(input.value, 10) || 0, CHECKOUT.maxRedeem));
    }
    return CHECKOUT.subtotal + CHECKOUT.shippingCost - CHECKOUT.promoDiscount - CHECKOUT.promoCodeDiscount - CHECKOUT.memberDiscount - p * CHECKOUT.pointValue;
}

function refreshCheckoutTotal() {
    var t = 'Rp ' + String(checkoutTotal()).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    var totalEl = document.getElementById('points-total-value');
    if (totalEl) totalEl.textContent = t;
    var totalElM = document.getElementById('points-total-value-mobile');
    if (totalElM) totalElM.textContent = t;
}

// Isi form otomatis dari alamat tersimpan
window.fillSavedAddress = function (sel) {
    var id = sel ? sel.value : '';
    if (!id) return;
    var data = <?= json_encode($savedAddresses, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var a = null;
    for (var i = 0; i < data.length; i++) {
        if (String(data[i].id) === String(id)) { a = data[i]; break; }
    }
    if (!a) return;
    var set = function (name, val) {
        var el = document.querySelector('[name="' + name + '"]');
        if (el) el.value = val || '';
    };
    set('customer_name', a.recipient_name);
    set('customer_phone', a.phone);
    set('shipping_address', a.address);
    set('shipping_city', a.city);
    set('shipping_province', a.province);
    set('shipping_postal_code', a.postal_code);
    if (sel) sel.value = '';
};

// Inisialisasi peta
function initLocationMap() {
    var mapEl = document.getElementById('checkout-map');
    if (!mapEl || typeof L === 'undefined') return;

    NN.map = L.map('checkout-map', { scrollWheelZoom: false }).setView([0.5071, 101.4478], 13);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
    }).addTo(NN.map);

    // Marker cabang toko
    var storeIcon = L.divIcon({
        className: '',
        html: '<div style="background:linear-gradient(135deg,#D4A030,#B8940F);color:#fff;width:30px;height:30px;border-radius:50% 50% 50% 0;transform:rotate(-45deg);display:flex;align-items:center;justify-content:center;box-shadow:0 3px 10px rgba(0,0,0,.3);"><i class="fas fa-store" style="transform:rotate(45deg);font-size:13px;"></i></div>',
        iconSize: [30, 30],
        iconAnchor: [15, 30],
        popupAnchor: [0, -28]
    });
    (NN_BRANCHES || []).forEach(function (b) {
        if (!b.latitude || !b.longitude) return;
        L.marker([b.latitude, b.longitude], { icon: storeIcon })
            .addTo(NN.map)
            .bindPopup('<strong>' + b.name + '</strong><br>' + b.address);
    });

    NN.map.on('click', function (e) {
        setCustomerLocation(e.latlng.lat, e.latlng.lng, true);
    });
}

// Set lokasi customer (marker + geocode + hitung ongkir)
function setCustomerLocation(lat, lng, doReverse) {
    if (!NN.map) return;
    var ll = L.latLng(lat, lng);

    if (!NN.customerMarker) {
        NN.customerMarker = L.marker(ll, { draggable: true }).addTo(NN.map);
        NN.customerMarker.on('dragend', function () {
            var p = NN.customerMarker.getLatLng();
            setCustomerLocation(p.lat, p.lng, true);
        });
    } else {
        NN.customerMarker.setLatLng(ll);
    }

    document.getElementById('latitude').value = lat.toFixed(6);
    document.getElementById('longitude').value = lng.toFixed(6);
    NN.lastLat = lat;
    NN.lastLng = lng;
    userChoseBranch = false; // lokasi berubah → urutkan & pilih ulang cabang terdekat
    NN.map.setView(ll, 14);

    // Debounce reverse geocoding agar geser marker tidak membanjiri Nominatim
    if (doReverse) {
        if (NN.reverseTimer) clearTimeout(NN.reverseTimer);
        NN.reverseTimer = setTimeout(function () { reverseGeocodeAddress(lat, lng); }, 800);
    }
    updateShippingCost(lat, lng);
}

// Reverse geocoding via Nominatim (OpenStreetMap, tanpa API key)
function reverseGeocodeAddress(lat, lng) {
    var statusEl = document.getElementById('location-status');
    if (!statusEl) return;
    statusEl.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Mencari alamat...';
    fetch('https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=' + lat + '&lon=' + lng + '&accept-language=id&addressdetails=1')
        .then(function (r) { return r.json(); })
        .then(function (d) {
            var a = d.address || {};
            var parts = [];
            if (a.road) parts.push(a.road + (a.house_number ? ' No. ' + a.house_number : ''));
            if (a.neighbourhood || a.suburb) parts.push(a.neighbourhood || a.suburb);
            if (a.village || a.town || a.city_district || a.municipality) parts.push(a.village || a.town || a.city_district || a.municipality);
            var address = parts.join(', ');
            var city = a.city || a.town || a.municipality || a.county || '';
            var state = a.state || '';
            var postcode = a.postcode || '';

            var set = function (name, val) {
                var el = document.querySelector('[name="' + name + '"]');
                if (el && val) el.value = val;
            };
            if (address) set('shipping_address', address);
            if (city) set('shipping_city', city);
            if (state) set('shipping_province', state);
            if (postcode) set('shipping_postal_code', postcode);

            statusEl.innerHTML = '<i class="fas fa-check-circle" style="color:#059669;"></i> Lokasi terdeteksi: ' + (address || 'Koordinat ' + lat + ', ' + lng);
        })
        .catch(function () {
            statusEl.innerHTML = '⚠️ Alamat otomatis gagal — silakan cek & sesuaikan form alamat di bawah.';
        });
}

// Ambil lokasi via GPS browser
function getMyLocation() {
    var btn = document.getElementById('btn-use-location');
    var statusEl = document.getElementById('location-status');
    if (!statusEl) return;

    // GPS browser HANYA boleh dipakai di koneksi AMAN (HTTPS atau localhost).
    // Saat situs dibuka dari HP via http://IP-laptop, browser otomatis menolak izin lokasi.
    var isSecureCtx = window.isSecureContext === true;
    var isLocalHost = location.hostname === 'localhost' || location.hostname === '127.0.0.1';
    if (!navigator.geolocation || (!isSecureCtx && !isLocalHost)) {
        statusEl.innerHTML = '⚠️ GPS browser tidak bisa dipakai di koneksi <strong>HTTP</strong> (diblokir browser). ' +
            'Solusinya: <strong>klik lokasi Anda di peta</strong> di atas untuk mengisi alamat & menghitung ongkir otomatis, atau isi alamat secara manual di bawah. ' +
            '(Supaya tombol GPS berfungsi, situs harus dibuka lewat <strong>HTTPS</strong> — mis. tunnel ngrok / hosting ber-SSL).';
        return;
    }
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Mendeteksi lokasi...';
    statusEl.innerHTML = 'Meminta izin lokasi...';

    navigator.geolocation.getCurrentPosition(function (pos) {
        btn.disabled = false;
        btn.innerHTML = '📍 Gunakan Lokasi Saya';
        setCustomerLocation(pos.coords.latitude, pos.coords.longitude, true);
    }, function (err) {
        btn.disabled = false;
        btn.innerHTML = '📍 Gunakan Lokasi Saya';
        var msg = 'Gagal mendapat lokasi.';
        if (err.code === 1) msg = 'Izin lokasi ditolak. Aktifkan izin lokasi browser/perangkat lalu coba lagi, atau klik peta / isi alamat manual.';
        else if (err.code === 2) msg = 'Lokasi tidak tersedia. Pastikan GPS perangkat aktif, atau klik peta di atas.';
        else if (err.code === 3) msg = 'Waktu deteksi lokasi habis. Coba lagi, atau klik peta di atas.';
        statusEl.innerHTML = '⚠️ ' + msg;
    }, { enableHighAccuracy: true, timeout: 12000, maximumAge: 0 });
}

// Render daftar cabang terurut terdekat (data dari server)
function renderBranchList(branches, selectedId) {
    var wrap = document.getElementById('branch-picker-wrap');
    var list = document.getElementById('branch-list');
    if (!wrap || !list) return;
    if (!branches || !branches.length) { wrap.style.display = 'none'; return; }
    wrap.style.display = 'block';
    list.innerHTML = '';
    branches.forEach(function (b, i) {
        var checked = String(b.id) === String(selectedId);
        var el = document.createElement('label');
        el.className = 'branch-option' + (checked ? ' is-selected' : '');
        el.innerHTML =
            '<input type="radio" name="branch_id" value="' + b.id + '"' + (checked ? ' checked' : '') + '>' +
            '<span class="branch-opt-info">' +
                '<span class="branch-opt-name">' + escHtml(b.name) + (i === 0 ? ' <span class="branch-chip">Terdekat</span>' : '') + '</span>' +
                '<span class="branch-opt-addr">' + escHtml(b.address) +
                    (b.open_hours ? '<br><i class="far fa-clock"></i> ' + escHtml(b.open_hours) : '') +
                '</span>' +
            '</span>' +
            '<span class="branch-opt-dist">' + escHtml(b.distance_text || '') + '</span>';
        list.appendChild(el);
    });
}

// Tandai cabang yang aktif/terpilih di daftar
function setSelectedBranch(id) {
    var inputs = document.querySelectorAll('#branch-list input[name="branch_id"]');
    inputs.forEach(function (inp) {
        var on = String(inp.value) === String(id);
        inp.checked = on;
        if (inp.closest) inp.closest('.branch-option').classList.toggle('is-selected', on);
    });
}

// Hitung ongkir dari koordinat via server (branchId opsional = pilihan customer)
function updateShippingCost(lat, lng, branchId) {
    // Keranjang hanya berisi paket membership: ongkir selalu 0 (bukan per jarak)
    if (MEMBERSHIP_ONLY_CART) return;
    var seq = ++shippingSeq;
    var fd = new FormData();
    fd.append('latitude', lat);
    fd.append('longitude', lng);
    if (branchId) fd.append('branch_id', branchId);

    fetch(AJAX_BASE + '/ajax/shipping-cost.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (seq !== shippingSeq) return; // respons basi (lokasi/pilihan berubah lagi) — abaikan
            if (!res.ok) return;
            // Daftar cabang terurut terdekat + tandai cabang yang aktif
            renderBranchList(res.branches, res.branch_id);
            setSelectedBranch(res.branch_id);
            var costEl = document.getElementById('shipping-cost-value');
            if (costEl) {
                costEl.innerHTML = res.cost > 0 ? 'Rp ' + res.cost_formatted : '<strong style="color: #059669;">GRATIS</strong>';
            }
            var etaRow = document.getElementById('eta-row');
            var etaVal = document.getElementById('eta-value');
            if (etaRow && etaVal) {
                etaVal.innerHTML = 'Dari <strong>' + escHtml(res.branch_name) + '</strong> · ' + escHtml(res.distance_text) + ' · ± ' + escHtml(res.eta);
                etaRow.style.display = 'flex';
            }
            document.getElementById('distance_km').value = res.distance_km || '';
            CHECKOUT.shippingCost = res.cost;
            refreshCheckoutTotal();
        })
        .catch(function () {});
}

// Delegasi: pilihan cabang → hitung ulang ongkir dari cabang tersebut (dipasang sekali)
document.getElementById('branch-list')?.addEventListener('change', function (e) {
    if (!e.target || e.target.name !== 'branch_id') return;
    userChoseBranch = true;
    setSelectedBranch(e.target.value);
    updateShippingCost(NN.lastLat, NN.lastLng, e.target.value);
});

// Confirm before submit
document.getElementById('checkout-form')?.addEventListener('submit', function(e) {
    const btn = this.querySelector('button[type="submit"]');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Memproses...';
});

// Toggle ringkasan pesanan (mobile)
(function () {
    var wrap = document.getElementById('cart-summary-wrap');
    var btn = document.getElementById('cart-summary-toggle');
    if (!wrap || !btn) return;
    btn.addEventListener('click', function () {
        var collapsed = wrap.classList.toggle('summary-collapsed');
        var chevron = document.getElementById('cart-summary-chevron');
        var txt = document.getElementById('cart-summary-toggle-text');
        if (chevron) chevron.className = 'fas fa-chevron-' + (collapsed ? 'down' : 'up');
        if (txt) txt.textContent = collapsed ? 'Tampilkan' : 'Sembunyikan';
        btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    });
})();

// Live preview tukar poin jadi diskon (menggunakan total terbaru)
(function () {
    var input = document.getElementById('points-used-input');
    var preview = document.getElementById('points-preview');
    var discVal = document.getElementById('points-discount-value');
    if (!input || input.disabled) {
        refreshCheckoutTotal();
        return;
    }
    function update() {
        var p = parseInt(input.value, 10) || 0;
        if (p < 0) p = 0;
        if (p > CHECKOUT.maxRedeem) { p = CHECKOUT.maxRedeem; input.value = p; }
        if (preview && discVal) {
            if (p > 0) {
                preview.style.display = 'flex';
                discVal.textContent = '-Rp ' + (p * CHECKOUT.pointValue).toLocaleString('id-ID');
            } else {
                preview.style.display = 'none';
            }
        }
        refreshCheckoutTotal();
    }
    input.addEventListener('input', update);
    update();
})();

// Inisialisasi peta saat halaman siap
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initLocationMap);
} else {
    initLocationMap();
}
</script>

<?php include '../includes/footer.php'; ?>
