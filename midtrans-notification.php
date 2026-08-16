<?php
// ============================================
// MIDTRANS PAYMENT NOTIFICATION (WEBHOOK)
// Website Nadhira Napoleon Pekanbaru
// ============================================
// URL webhook ini diisi di dashboard Midtrans:
//   Settings > Configuration > Payment Notification URL
//   Contoh: https://domain-anda.com/nad/midtrans-notification.php
//
// Midtrans akan mengirim POST JSON setiap kali status transaksi berubah.
// Keamanan: signature_key diverifikasi dengan SHA512(order_id+status_code+gross_amount+ServerKey).
// ============================================

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/midtrans.php';

ensureMidtransSchema();

$rawBody = file_get_contents('php://input');
$notification = json_decode($rawBody, true);

if (!is_array($notification)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Invalid payload']);
    exit;
}

$orderId       = (string)($notification['order_id'] ?? '');
$statusCode    = (string)($notification['status_code'] ?? '');
$grossAmount   = (string)($notification['gross_amount'] ?? '');
$signatureKey  = (string)($notification['signature_key'] ?? '');

// 1) Verifikasi tanda tangan
if (!midtransVerifySignature($orderId, $statusCode, $grossAmount, $signatureKey)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Invalid signature']);
    exit;
}

// 2) Cari pesanan
$conn = getConnection();
if (!$conn) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database unavailable']);
    exit;
}

$orderIdEsc = $conn->real_escape_string($orderId);
$r = $conn->query("SELECT * FROM orders WHERE order_number = '$orderIdEsc' LIMIT 1");
if (!$r || $r->num_rows === 0) {
    // Pesanan tidak ditemukan — balas 404 agar Midtrans tahu
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Order not found']);
    exit;
}
$order = $r->fetch_assoc();

// 3) Pastikan jumlah yang dibayar sesuai (anti-manipulasi tambahan)
$expectedTotal = (int)round((float)$order['total']);
$reportedTotal = (int)round((float)$grossAmount);
if ($expectedTotal !== $reportedTotal) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Gross amount mismatch']);
    exit;
}

// 4) Terapkan status transaksi ke pesanan
$applied = midtransApplyTransactionStatus($order, $notification);

http_response_code(200);
header('Content-Type: application/json');
echo json_encode([
    'status'  => 'ok',
    'order_id' => $orderId,
    'transaction_status' => $notification['transaction_status'] ?? '',
]);
exit;
