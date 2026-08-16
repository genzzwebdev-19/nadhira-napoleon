<?php
// ============================================
// AJAX HANDLER - KODE PROMO
// Terapkan / hapus kode promo dari keranjang.
// Kode disimpan di sesi, diskon dihitung ulang
// server-side setiap permintaan (aman dari manipulasi).
// Nadhira Napoleon Pekanbaru
// ============================================
require_once '../config/database.php';

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';

// Subtotal keranjang saat ini (harga diskon produk dipakai bila ada)
function promoCartSubtotal() {
    $conn = getConnection();
    if (!$conn) return 0;
    $where = isLoggedIn()
        ? 'c.user_id = ' . (int)$_SESSION['user_id']
        : "c.session_id = '" . $conn->real_escape_string(session_id()) . "'";
    $r = $conn->query("SELECT COALESCE(SUM(IF(p.discount_price > 0, p.discount_price, p.price) * c.quantity), 0) s
        FROM carts c JOIN products p ON c.product_id = p.id AND p.is_active = TRUE
        WHERE $where");
    return $r ? (float)$r->fetch_assoc()['s'] : 0;
}

switch ($action) {
    case 'apply':
        $code = trim($_POST['code'] ?? '');
        if ($code === '') {
            jsonResponse(['success' => false, 'message' => 'Masukkan kode promo terlebih dahulu']);
        }
        $subtotal = promoCartSubtotal();
        $result = validatePromoCode($code, $subtotal);
        if ($result['ok']) {
            setSessionPromoCode($result['promo']['code']);
            jsonResponse([
                'success'  => true,
                'message'  => 'Kode promo ' . $result['promo']['code'] . ' berhasil diterapkan!',
                'discount' => $result['discount'],
                'subtotal' => $subtotal
            ]);
        }
        jsonResponse(['success' => false, 'message' => $result['error']]);
        break;

    case 'remove':
        clearSessionPromoCode();
        jsonResponse(['success' => true, 'message' => 'Kode promo dihapus']);
        break;

    default:
        jsonResponse(['success' => false, 'message' => 'Aksi tidak valid'], 400);
}
