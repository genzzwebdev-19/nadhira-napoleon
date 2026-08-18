<?php
// ============================================
// AJAX HANDLER - AKSI PESANAN OLEH USER
// Batalkan pesanan & konfirmasi pesanan diterima
// Website Nadhira Napoleon Pekanbaru
// ============================================
require_once '../config/database.php';
require_once '../config/rbac.php'; // untuk verifyCsrf()

header('Content-Type: application/json');

// Harus login — semua aksi ini milik akun
if (!isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'Silakan login terlebih dahulu']);
}

$action = $_POST['action'] ?? '';

switch ($action) {
    case 'cancel_order':
        // CSRF ringan (sama seperti aksi tulis admin)
        if (!verifyCsrf()) exit;
        $orderId = (int)($_POST['order_id'] ?? 0);
        $result = cancelOrderByUser($orderId, (int)$_SESSION['user_id']);
        jsonResponse(['success' => $result['ok'], 'message' => $result['message']]);
        break;

    case 'confirm_received':
        if (!verifyCsrf()) exit;
        $orderId = (int)($_POST['order_id'] ?? 0);
        $result = confirmReceivedByUser($orderId, (int)$_SESSION['user_id']);
        jsonResponse(['success' => $result['ok'], 'message' => $result['message']]);
        break;

    default:
        jsonResponse(['success' => false, 'message' => 'Aksi tidak valid'], 400);
}
