<?php
// ============================================
// BUY PACKAGE - Fallback tanpa JavaScript
// Dipanggil saat form "Pesan Sekarang" (Paket Spesial)
// di-submit normal (tanpa JS). Menambah paket ke keranjang
// lalu mengarahkan ke halaman keranjang.
// Website Nadhira Napoleon Pekanbaru
// ============================================
require_once '../config/database.php';

$conn = getConnection();
$productId = (int)($_POST['product_id'] ?? 0);
$quantity = max(1, (int)($_POST['quantity'] ?? 1));

// Validasi minimal: produk aktif & memang produk paket
if (!$conn || $productId <= 0) {
    header('Location: ' . SITE_URL . '/pages/cart.php');
    exit;
}

$chk = $conn->query("SELECT p.id FROM products p JOIN packages pk ON pk.product_id = p.id
                     WHERE p.id = $productId AND p.is_active = 1 LIMIT 1");
if (!$chk || $chk->num_rows === 0) {
    header('Location: ' . SITE_URL . '/pages/cart.php');
    exit;
}

// Tambah ke keranjang (helper bersama dengan ajax/cart.php)
addProductToCart($productId, $quantity);

header('Location: ' . SITE_URL . '/pages/cart.php');
exit;
