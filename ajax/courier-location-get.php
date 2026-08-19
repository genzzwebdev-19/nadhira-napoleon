<?php
// ============================================
// AJAX - AMBIL POSISI GPS KURIR TERBARU
// Dipakai oleh halaman tracking customer & admin
// untuk polling posisi kurir secara real-time.
// ============================================
require_once '../config/rbac.php';

header('Content-Type: application/json');

$cid = (int)($_GET['courier_id'] ?? 0);
if ($cid <= 0) {
    jsonResponse(['ok' => false, 'error' => 'courier_id wajib diisi'], 422);
}

// Otorisasi: posisi GPS kurir hanya boleh dilihat oleh
//   1) admin yang login, ATAU
//   2) pemilik pesanan yang kurirnya = courier_id ini (order_number + email cocok),
//   3) user login yang pesanannya terkait kurir tsb.
$orderNum = trim($_GET['order'] ?? '');
$email = trim($_GET['email'] ?? '');
$conn = getConnection();
$authorized = false;

if (isLoggedIn() && isAdminUser()) {
    $authorized = true;
}
if (!$authorized && $conn && $orderNum !== '') {
    $orderNumE = $conn->real_escape_string($orderNum);
    $r = $conn->query("SELECT id, user_id, customer_email, courier_id FROM orders WHERE order_number = '$orderNumE' LIMIT 1");
    if ($r && $r->num_rows > 0) {
        $o = $r->fetch_assoc();
        $emailMatch = $email !== '' && strtolower($email) === strtolower((string)$o['customer_email']);
        $ownerMatch = isLoggedIn() && (int)$_SESSION['user_id'] === (int)$o['user_id'];
        if (($emailMatch || $ownerMatch) && (int)$o['courier_id'] === $cid) {
            $authorized = true;
        }
    }
}
if (!$authorized) {
    jsonResponse(['ok' => false, 'error' => 'Akses ditolak'], 403);
}

$loc = getLatestCourierLocation($cid);
if (!$loc) {
    jsonResponse(['ok' => false, 'error' => 'Belum ada posisi kurir'], 404);
}

$courier = getCourier($cid);

jsonResponse([
    'ok'          => true,
    'courier_id'  => (int)$cid,
    'courier_name'=> $courier ? $courier['name'] : '',
    'latitude'    => (float)$loc['latitude'],
    'longitude'   => (float)$loc['longitude'],
    'accuracy'    => (float)$loc['accuracy'],
    'recorded_at' => $loc['recorded_at'],
]);
