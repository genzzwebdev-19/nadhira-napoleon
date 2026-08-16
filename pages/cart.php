<?php
// ============================================
// CART PAGE - Keranjang Belanja
// Menampilkan item keranjang dari database
// Website Nadhira Napoleon Pekanbaru
// ============================================
require_once '../config/database.php';

$conn = getConnection();
$cartItems = [];
$subtotal = 0;

if ($conn) {
    $where = isLoggedIn()
        ? 'c.user_id = ' . (int)$_SESSION['user_id']
        : "c.session_id = '" . $conn->real_escape_string(session_id()) . "'";

    $r = $conn->query("
        SELECT c.id as cart_id, c.quantity, c.notes,
               p.id as product_id, p.name, p.price, p.discount_price, p.stock,
               (SELECT image FROM product_images WHERE product_id = p.id AND is_primary = 1 LIMIT 1) as product_image
        FROM carts c
        JOIN products p ON c.product_id = p.id AND p.is_active = TRUE
        WHERE $where
        ORDER BY c.created_at DESC
    ");

    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $row['display_price'] = $row['discount_price'] > 0 ? $row['discount_price'] : $row['price'];
            $row['item_total'] = $row['display_price'] * $row['quantity'];
            $subtotal += $row['item_total'];
            $cartItems[] = $row;
        }
    }
}

$page_title = 'Keranjang Belanja';
include '../includes/header.php';

// Keranjang berisi HANYA paket membership tidak dikenakan ongkir
$membershipOnlyCart = !empty($cartItems);
foreach ($cartItems as $item) {
    if (!isMembershipProduct($item['product_id'])) {
        $membershipOnlyCart = false;
    }
}

$shippingCost = $membershipOnlyCart ? 0 : (float)getSetting('shipping_cost', 0); // 0 = GRATIS ONGKIR

// Kode promo aktif di sesi — diskon dihitung ulang server-side dari subtotal saat ini
$promoCode = getSessionPromoCode();
$promoDiscount = 0;
$promoApplied = false;
if ($promoCode !== '') {
    $promoResult = validatePromoCode($promoCode, $subtotal);
    if ($promoResult['ok']) {
        $promoDiscount = $promoResult['discount'];
        $promoApplied = true;
    } else {
        clearSessionPromoCode(); // kode tidak berlaku lagi — bersihkan otomatis
    }
}

// Diskon harga khusus member (berdasarkan level akun)
$memberDiscountRate = 0;
$memberDiscount = 0;
$memberLevelLabel = '';
if (isLoggedIn()) {
    $memberDiscountRate = getMemberDiscountRate();
    $memberDiscount = getMemberDiscountForSubtotal($subtotal);
    $memberLevelLabel = getMemberLevelLabel(getCurrentUser()['membership'] ?? '');
}

$total = max(0, $subtotal + $shippingCost - $promoDiscount - $memberDiscount);
$itemCount = array_sum(array_column($cartItems, 'quantity'));
?>

<section class="cart-section">
    <div class="container">
        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="<?= SITE_URL ?>">Home</a>
            <span class="separator">/</span>
            <span class="current">Keranjang Belanja</span>
        </div>

        <h1 style="font-family: var(--font-display); font-size: var(--text-4xl); font-weight: 700; margin-bottom: var(--space-xl);">
            Keranjang <span class="gold-text">Belanja</span>
            <?php if ($itemCount > 0): ?>
                <span style="font-size: var(--text-base); font-family: var(--font-body); font-weight: 400; color: var(--text-muted);">
                    (<?= $itemCount ?> item)
                </span>
            <?php endif; ?>
        </h1>

        <div class="checkout-grid">
            <!-- Cart Items -->
            <div>
                <?php if (empty($cartItems)): ?>
                <div style="text-align: center; padding: var(--space-4xl); background: var(--warm-white); border-radius: var(--radius-xl); box-shadow: var(--shadow-sm);" data-aos="fade-up">
                    <i class="fas fa-shopping-bag" style="font-size: 4rem; color: var(--soft-gold); opacity: 0.4; margin-bottom: var(--space-lg);"></i>
                    <h3 style="font-family: var(--font-display); font-size: var(--text-2xl); font-weight: 600; margin-bottom: var(--space-md);">
                        Keranjang <span class="gold-text">Kosong</span>
                    </h3>
                    <p style="color: var(--text-muted); margin-bottom: var(--space-2xl);">
                        Belum ada produk di keranjang Anda. Yuk, belanja sekarang!
                    </p>
                    <a href="<?= BASE_PATH ?>/pages/products.php" class="btn btn-primary btn-lg">
                        <i class="fas fa-arrow-left"></i>
                        Mulai Belanja
                    </a>
                </div>
                <?php else: ?>
                <?php foreach ($cartItems as $item): ?>
                <div class="cart-item" id="cart-row-<?= $item['cart_id'] ?>" data-aos="fade-up">
                    <img src="<?= htmlspecialchars($item['product_image'] ?: 'https://images.unsplash.com/photo-1551024601-bec78aea704b?w=200&q=80') ?>"
                         alt="<?= htmlspecialchars($item['name']) ?>"
                         class="cart-item-image"
                         loading="lazy">

                    <div>
                        <a href="<?= BASE_PATH ?>/pages/product-detail.php?id=<?= $item['product_id'] ?>" style="text-decoration: none;">
                            <h3 class="cart-item-name"><?= htmlspecialchars($item['name']) ?></h3>
                        </a>
                        <p class="cart-item-price">Rp <?= number_format($item['display_price'], 0, ',', '.') ?></p>
                        <?php if ($item['discount_price'] > 0): ?>
                            <span style="font-size: var(--text-xs); color: var(--text-muted); text-decoration: line-through;">
                                Rp <?= number_format($item['price'], 0, ',', '.') ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="product-qty" style="margin-bottom: 0;">
                        <button class="qty-btn minus" style="width: 32px; height: 32px; font-size: var(--text-sm);"
                                onclick="updateCartQty(<?= $item['cart_id'] ?>, -1)">
                            <i class="fas fa-minus"></i>
                        </button>
                        <input type="number" class="qty-input" value="<?= $item['quantity'] ?>" min="1" max="99"
                               style="width: 60px; padding: 6px; font-size: var(--text-base);"
                               id="qty-<?= $item['cart_id'] ?>"
                               onchange="updateCartQtyDirect(<?= $item['cart_id'] ?>, this.value)">
                        <button class="qty-btn plus" style="width: 32px; height: 32px; font-size: var(--text-sm);"
                                onclick="updateCartQty(<?= $item['cart_id'] ?>, 1)">
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>

                    <div style="text-align: right;">
                        <div style="font-family: var(--font-display); font-size: var(--text-lg); font-weight: 600; color: var(--warm-orange);"
                             id="subtotal-<?= $item['cart_id'] ?>">
                            Rp <?= number_format($item['item_total'], 0, ',', '.') ?>
                        </div>
                        <button class="cart-item-remove" style="background: none; border: none; margin-top: var(--space-sm); font-size: var(--text-sm); color: #EF4444; cursor: pointer;"
                                onclick="removeCartItem(<?= $item['cart_id'] ?>)">
                            <i class="fas fa-trash-alt"></i> Hapus
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>

                <div style="display: flex; justify-content: space-between; margin-top: var(--space-xl);">
                    <a href="<?= BASE_PATH ?>/pages/products.php" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i>
                        Lanjut Belanja
                    </a>
                    <button class="btn btn-outline" style="color: #EF4444; border-color: #EF4444;" onclick="clearCart()">
                        <i class="fas fa-trash-alt"></i>
                        Kosongkan Keranjang
                    </button>
                </div>
                <?php endif; ?>
            </div>

            <!-- Cart Summary -->
            <div>
                <div class="cart-summary" data-aos="fade-left">
                    <h3 class="cart-summary-title">Ringkasan Belanja</h3>

                    <div class="cart-summary-row">
                        <span>Subtotal (<?= $itemCount ?> item)</span>
                        <span id="summary-subtotal">Rp <?= number_format($subtotal, 0, ',', '.') ?></span>
                    </div>
                    <div class="cart-summary-row">
                        <span>Ongkos Kirim</span>
                        <span><?= $shippingCost > 0 ? 'Rp ' . number_format($shippingCost, 0, ',', '.') : '<strong style="color: #059669;">GRATIS</strong>' ?></span>
                    </div>
                    <?php if ($promoApplied): ?>
                    <div class="cart-summary-row" style="color: #059669;">
                        <span>Diskon Promo (<?= htmlspecialchars($promoCode) ?>)</span>
                        <span>-Rp <?= number_format($promoDiscount, 0, ',', '.') ?></span>
                    </div>
                    <?php else: ?>
                    <div class="cart-summary-row">
                        <span>Diskon</span>
                        <span style="color: #10B981;">-Rp 0</span>
                    </div>
                    <?php endif; ?>

                    <?php if ($memberDiscount > 0): ?>
                    <div class="cart-summary-row" style="color: #059669;">
                        <span>Diskon Member (<?= htmlspecialchars($memberLevelLabel) ?> <?= (int)$memberDiscountRate ?>%)</span>
                        <span>-Rp <?= number_format($memberDiscount, 0, ',', '.') ?></span>
                    </div>
                    <?php endif; ?>

                    <?php if ($promoApplied): ?>
                    <div class="promo-box">
                        <span class="promo-box-info">
                            🎟️ Kode <strong><?= htmlspecialchars($promoCode) ?></strong> aktif — hemat <strong class="promo-save">Rp <?= number_format($promoDiscount, 0, ',', '.') ?></strong>
                        </span>
                        <button type="button" class="promo-box-remove" onclick="removePromo()">
                            <i class="fas fa-times"></i> Hapus
                        </button>
                    </div>
                    <?php else: ?>
                    <div class="cart-summary-row">
                        <span>Punya kode promo?</span>
                    </div>
                    <div class="promo-form">
                        <input type="text" id="promo-input" class="form-input" placeholder="Masukkan kode"
                               onkeydown="if(event.key==='Enter'){event.preventDefault();applyPromo();}"
                               aria-label="Kode promo">
                        <button type="button" class="btn btn-outline" onclick="applyPromo()">Gunakan</button>
                    </div>
                    <?php endif; ?>

                    <div class="cart-summary-row total">
                        <span>Total</span>
                        <span id="summary-total">Rp <?= number_format($total, 0, ',', '.') ?></span>
                    </div>

                    <?php if (!empty($cartItems)): ?>
                    <?php if (isLoggedIn()): ?>
                    <a href="<?= BASE_PATH ?>/pages/checkout.php" class="btn btn-primary btn-lg w-full" style="margin-top: var(--space-xl);">
                        <i class="fas fa-shopping-bag"></i>
                        Lanjut ke Pembayaran
                    </a>
                    <?php else: ?>
                    <button type="button" onclick="openLoginModal()" class="btn btn-primary btn-lg w-full" style="margin-top: var(--space-xl);">
                        <i class="fas fa-shopping-bag"></i>
                        Lanjut ke Pembayaran
                    </button>
                    <p style="font-size: var(--text-xs); color: var(--text-muted); text-align: center; margin-top: 10px;">
                        <i class="fas fa-user-lock"></i> Perlu <strong>login</strong> untuk melanjutkan checkout — keranjang Anda aman &amp; ikut pindah ke akun
                    </p>
                    <?php endif; ?>
                    <?php endif; ?>

                    <div style="margin-top: var(--space-lg); text-align: center;">
                        <img src="https://cdn.jsdelivr.net/npm/simple-icons@latest/icons/visa.svg" alt="Visa" style="height: 24px; display: inline; margin: 0 4px; opacity: 0.5;">
                        <img src="https://cdn.jsdelivr.net/npm/simple-icons@latest/icons/mastercard.svg" alt="Mastercard" style="height: 24px; display: inline; margin: 0 4px; opacity: 0.5;">
                        <span style="color: var(--text-light); font-size: var(--text-sm); margin-left: 8px;">Pembayaran Aman</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Modal Silakan Login (guest klik Lanjut ke Pembayaran) -->
<div id="login-required-modal" role="dialog" aria-modal="true" aria-labelledby="login-modal-title" style="display: none; position: fixed; inset: 0; z-index: 99999; background: rgba(0,0,0,.55); align-items: center; justify-content: center; padding: 20px;">
    <div style="background: #fff; border-radius: 20px; max-width: 420px; width: 100%; padding: 32px 28px; text-align: center; box-shadow: 0 20px 60px rgba(0,0,0,.25); position: relative; animation: slideDown .3s ease;">
        <button type="button" onclick="closeLoginModal()" style="position: absolute; top: 12px; right: 14px; border: none; background: none; font-size: 20px; color: #999; cursor: pointer;" aria-label="Tutup">&times;</button>
        <div style="width: 64px; height: 64px; border-radius: 50%; background: var(--soft-gold-gradient); display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; font-size: 26px; color: #fff;">
            <i class="fas fa-user-lock"></i>
        </div>
        <h3 id="login-modal-title" style="font-family: var(--font-display); font-size: 22px; font-weight: 700; margin-bottom: 8px;">Silakan Login Dulu</h3>
        <p style="color: var(--text-muted); font-size: var(--text-sm); line-height: 1.6; margin-bottom: 20px;">
            Anda perlu masuk ke akun untuk melanjutkan checkout. Masuk sekarang, atau daftar gratis — keranjang Anda tetap aman.
        </p>
        <a href="<?= BASE_PATH ?>/auth/login.php?redirect=/pages/checkout.php" class="btn btn-primary btn-lg w-full" style="margin-bottom: 10px;">
            <i class="fas fa-sign-in-alt"></i> Masuk
        </a>
        <a href="<?= BASE_PATH ?>/auth/register.php?redirect=/pages/checkout.php" class="btn btn-outline btn-lg w-full" style="margin-bottom: 14px;">
            <i class="fas fa-user-plus"></i> Daftar Gratis
        </a>
        <p style="font-size: var(--text-xs); color: var(--text-light); margin: 0;">
            <i class="fas fa-shield-alt"></i> Item di keranjang dipindahkan otomatis ke akun Anda setelah login/daftar
        </p>
    </div>
</div>

<script>
function openLoginModal() {
    var m = document.getElementById('login-required-modal');
    if (!m) return;
    m.style.display = 'flex';
    document.body.style.overflow = 'hidden'; // kunci scroll latar
    var first = m.querySelector('a, button');
    if (first) first.focus();
}
function closeLoginModal() {
    var m = document.getElementById('login-required-modal');
    if (!m) return;
    m.style.display = 'none';
    document.body.style.overflow = ''; // buka kembali scroll
}
document.getElementById('login-required-modal')?.addEventListener('click', function (e) {
    if (e.target === this) closeLoginModal();
});

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeLoginModal();
});

function updateCartQty(cartId, delta) {
    const input = document.getElementById('qty-' + cartId);
    let val = parseInt(input.value) + delta;
    if (val < 1) val = 1;
    if (val > 99) val = 99;
    input.value = val;
    ajaxUpdateCart(cartId, val);
}

function updateCartQtyDirect(cartId, val) {
    val = parseInt(val);
    if (val < 1) val = 1;
    if (val > 99) val = 99;
    ajaxUpdateCart(cartId, val);
}

function ajaxUpdateCart(cartId, quantity) {
    fetch('<?= BASE_PATH ?>/ajax/cart.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=update_cart&cart_id=' + cartId + '&quantity=' + quantity
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            // Update cart count badge
            const badge = document.querySelector('.cart-count');
            if (badge) badge.textContent = data.cart_count;
            location.reload(); // Simple approach: reload to get updated totals
        }
    })
    .catch(e => console.error('Cart update failed:', e));
}

function removeCartItem(cartId) {
    if (!confirm('Hapus item ini dari keranjang?')) return;

    fetch('<?= BASE_PATH ?>/ajax/cart.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=remove_from_cart&cart_id=' + cartId
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        }
    })
    .catch(e => console.error('Cart remove failed:', e));
}

function clearCart() {
    if (!confirm('Kosongkan seluruh keranjang?')) return;

    fetch('<?= BASE_PATH ?>/ajax/cart.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=clear_cart'
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        }
    })
    .catch(e => console.error('Cart clear failed:', e));
}

function applyPromo() {
    const input = document.getElementById('promo-input');
    const code = input ? input.value.trim() : '';
    if (!code) {
        showToast('Masukkan kode promo terlebih dahulu', 'error');
        return;
    }
    fetch('<?= BASE_PATH ?>/ajax/promo.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=apply&code=' + encodeURIComponent(code)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showToast(data.message, 'success');
            location.reload();
        } else {
            showToast(data.message || 'Kode promo tidak valid', 'error');
        }
    })
    .catch(() => showToast('Terjadi kesalahan. Silakan coba lagi.', 'error'));
}

function removePromo() {
    fetch('<?= BASE_PATH ?>/ajax/promo.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=remove'
    })
    .then(() => location.reload())
    .catch(() => location.reload());
}
</script>

<?php include '../includes/footer.php'; ?>
