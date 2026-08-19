<?php
// ============================================
// AJAX - CEK STATUS PEMBAYARAN MIDTRANS
// Website Nadhira Napoleon Pekanbaru
// ============================================
// Menanyakan status transaksi langsung ke API Midtrans (server-side).
// Dipakai saat webhook belum bisa menjangkau server (mis. masih di localhost),
// atau sebagai sinkronisasi status di halaman invoice.
// ============================================

require_once '../config/database.php';
require_once '../config/midtrans.php';

header('Content-Type: application/json');

ensureMidtransSchema();

$conn = getConnection();
$orderNumber = trim($_POST['order'] ?? $_GET['order'] ?? '');
$email = trim($_POST['email'] ?? $_GET['email'] ?? '');

if (!$conn || $orderNumber === '') {
    jsonResponse(['success' => false, 'message' => 'Parameter tidak valid'], 400);
}

$orderNumber = $conn->real_escape_string($orderNumber);
$r = $conn->query("SELECT * FROM orders WHERE order_number = '$orderNumber' LIMIT 1");
if (!$r || $r->num_rows === 0) {
    jsonResponse(['success' => false, 'message' => 'Pesanan tidak ditemukan'], 404);
}
$order = $r->fetch_assoc();

// Otorisasi ringan: email pemesan harus cocok (sama seperti akses halaman invoice)
if ($email === '' || strtolower($email) !== strtolower($order['customer_email'])) {
    jsonResponse(['success' => false, 'message' => 'Akses ditolak'], 403);
}

// Hanya untuk pesanan Midtrans yang belum lunas
if ($order['payment_method'] !== 'midtrans') {
    jsonResponse(['success' => false, 'message' => 'Pesanan bukan metode Midtrans']);
}
if ($order['payment_status'] === 'paid') {
    jsonResponse(['success' => true, 'payment_status' => 'paid', 'order_id' => $order['order_number']]);
}

$status = midtransGetTransactionStatus($order['order_number']);
if (!$status['success']) {
    jsonResponse(['success' => false, 'message' => $status['message']]);
}

$data = $status['data'];

// Validasi tambahan: jumlah harus sesuai total pesanan
$reported = (int)round((float)($data['gross_amount'] ?? 0));
$expected = (int)round((float)$order['total']);
if ($reported !== $expected) {
    jsonResponse(['success' => false, 'message' => 'Jumlah transaksi tidak sesuai']);
}

midtransApplyTransactionStatus($order, $data);

// Ambil status terbaru
$r2 = $conn->query("SELECT payment_status FROM orders WHERE id = {$order['id']} LIMIT 1");
$newStatus = ($r2 && $r2->num_rows > 0) ? $r2->fetch_assoc()['payment_status'] : $order['payment_status'];

jsonResponse([
    'success'        => true,
    'payment_status' => $newStatus,
    'order_id'       => $order['order_number'],
]);
