<?php
// ============================================
// AJAX - KIRIM POSISI GPS KURIR (real-time)
// Dipanggil berkala dari panel kurir (courier/)
// Hanya kurir yang sedang login yang bisa mengirim.
// ============================================
require_once '../config/database.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    jsonResponse(['ok' => false, 'error' => 'Silakan login terlebih dahulu'], 401);
}

$courier = getCourierForUser((int)$_SESSION['user_id']);
if (!$courier) {
    jsonResponse(['ok' => false, 'error' => 'Akun ini tidak terdaftar sebagai kurir'], 403);
}

$lat = (float)($_POST['latitude'] ?? 0);
$lng = (float)($_POST['longitude'] ?? 0);
$acc = (float)($_POST['accuracy'] ?? 0);

if (!$lat || !$lng || $lat < -11 || $lat > 6 || $lng < 95 || $lng > 141) {
    jsonResponse(['ok' => false, 'error' => 'Koordinat tidak valid'], 422);
}

$ok = saveCourierLocation((int)$courier['id'], $lat, $lng, $acc);
jsonResponse([
    'ok'       => (bool)$ok,
    'saved_at' => date('H:i:s'),
    'courier'  => $courier['name'],
]);
