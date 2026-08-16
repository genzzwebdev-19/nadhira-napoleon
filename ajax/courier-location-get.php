<?php
// ============================================
// AJAX - AMBIL POSISI GPS KURIR TERBARU
// Dipakai oleh halaman tracking customer & admin
// untuk polling posisi kurir secara real-time.
// ============================================
require_once '../config/database.php';

header('Content-Type: application/json');

$cid = (int)($_GET['courier_id'] ?? 0);
if ($cid <= 0) {
    jsonResponse(['ok' => false, 'error' => 'courier_id wajib diisi'], 422);
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
