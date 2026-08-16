<?php
// ============================================
// AJAX HANDLER - NOTIFIKASI (polling)
// Dipakai oleh panel admin untuk mendeteksi
// notifikasi baru (transaksi, dll.) secara berkala
// ============================================
require_once '../config/rbac.php';

header('Content-Type: application/json');

// Hanya admin yang login
if (!isLoggedIn() || !isAdminUser()) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

$uid = (int)$_SESSION['user_id'];
$count = getUnreadNotificationCount($uid);

$lastId = 0;
$latest = null;
$conn = getConnection();
if ($conn) {
    $r = $conn->query(
        "SELECT id, title, message, type, link, created_at
         FROM notifications WHERE user_id = $uid
         ORDER BY id DESC LIMIT 1"
    );
    if ($r && $r->num_rows > 0) {
        $row = $r->fetch_assoc();
        $lastId = (int)$row['id'];
        $latest = [
            'id'         => $lastId,
            'title'      => $row['title'],
            'message'    => $row['message'],
            'type'       => $row['type'],
            'link'       => $row['link'],
            'created_at' => $row['created_at'],
        ];
    }
}

echo json_encode([
    'success' => true,
    'count'   => $count,
    'last_id' => $lastId,
    'latest'  => $latest,
]);
