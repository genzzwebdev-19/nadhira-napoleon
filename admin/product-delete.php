<?php
// ============================================
// PRODUCT DELETE HANDLER (RBAC)
// ============================================
require_once __DIR__ . '/../config/rbac.php';

requirePermission('products', 'delete');

$conn = getConnection();
if (!$conn) {
    die('Koneksi database gagal');
}

$id = (int)($_GET['id'] ?? 0);
$referer = $_SERVER['HTTP_REFERER'] ?? 'products.php';

if ($id > 0) {
    // Soft delete - set is_active to false
    $conn->query("UPDATE products SET is_active = FALSE WHERE id = $id");
    logActivity('delete', 'products', "Menghapus (soft) produk #$id");
}

header('Location: ' . $referer);
exit;
