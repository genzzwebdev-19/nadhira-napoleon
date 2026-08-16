<?php
// ============================================
// AJAX - DAFTAR POSISI SEMUA KURIR (untuk admin)
// Dipakai halaman admin/couriers.php untuk
// menyegarkan posisi kurir di peta secara berkala.
// ============================================
require_once '../config/database.php';

header('Content-Type: application/json');

$conn = getConnection();
$couriers = [];
$r = $conn->query("SELECT c.id, c.name, c.is_active FROM couriers c ORDER BY c.name ASC");
if ($r) {
    while ($c = $r->fetch_assoc()) {
        $loc = getLatestCourierLocation((int)$c['id']);
        $couriers[] = [
            'id'     => (int)$c['id'],
            'name'   => $c['name'],
            'active' => (int)$c['is_active'],
            'loc'    => $loc ? [
                'lat' => (float)$loc['latitude'],
                'lng' => (float)$loc['longitude'],
                'at'  => $loc['recorded_at'],
            ] : null,
        ];
    }
}

jsonResponse(['ok' => true, 'couriers' => $couriers]);
