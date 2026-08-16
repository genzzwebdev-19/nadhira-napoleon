<?php
// ============================================
// AJAX - HITUNG ONGKIR BERDASARKAN LOKASI GPS
// Dipanggil dari halaman checkout saat customer
// memilih lokasi di peta (Leaflet).
//
// Jika POST branch_id diisi, ongkir dihitung dari
// cabang PILIHAN customer. Jika tidak, dipakai
// cabang terdekat otomatis. Respons menyertakan
// daftar semua cabang aktif terurut dari yang
// terdekat (jarak garis lurus) untuk UI pemilih.
// ============================================
require_once '../config/database.php';

header('Content-Type: application/json');

$lat = (float)($_POST['latitude'] ?? 0);
$lng = (float)($_POST['longitude'] ?? 0);

// Validasi koordinat (wilayah Indonesia)
if (!$lat || !$lng || $lat < -11 || $lat > 6 || $lng < 95 || $lng > 141) {
    jsonResponse(['ok' => false, 'error' => 'Koordinat tidak valid'], 422);
}

// Semua cabang aktif + jarak garis lurus (haversine), urut terdekat dulu
$allBranches = getActiveBranches();
$list = [];
foreach ($allBranches as $b) {
    $d = haversineKm($lat, $lng, $b['latitude'], $b['longitude']);
    if ($d === null) continue; // cabang tanpa koordinat dilewati
    $list[] = [
        'id'            => (int)$b['id'],
        'name'          => $b['name'],
        'address'       => $b['address'],
        'open_hours'    => formatBranchHours($b),
        'distance_km'   => (float)$d,
        'distance_text' => number_format($d, 2, ',', '.') . ' km',
    ];
}
usort($list, function ($a, $b) {
    return $a['distance_km'] <=> $b['distance_km'];
});

if (empty($list)) {
    jsonResponse(['ok' => false, 'error' => 'Belum ada cabang aktif'], 422);
}

// Cabang terpilih: pilihan customer (jika valid) atau terdekat otomatis
$chosenId = (int)($_POST['branch_id'] ?? 0);
$branch = null;
if ($chosenId > 0) {
    foreach ($list as $l) {
        if ($l['id'] === $chosenId) { $branch = $l; break; }
    }
}
if (!$branch) $branch = $list[0];

// Jarak tempuh via jalan (OSRM) hanya untuk cabang TERPILIH
$branchRow = null;
foreach ($allBranches as $b) {
    if ((int)$b['id'] === $branch['id']) { $branchRow = $b; break; }
}
$roadKm = $branchRow ? getRoadDistanceKm($lat, $lng, $branchRow['latitude'], $branchRow['longitude']) : null;
if ($roadKm === null) $roadKm = $branch['distance_km'];

$cost = calculateShippingCost($roadKm);
$cost = $cost === null ? 0 : $cost;

jsonResponse([
    'ok'             => true,
    'branches'       => $list,
    'branch_id'      => (int)$branch['id'],
    'branch_name'    => $branch['name'],
    'distance_km'    => (float)$roadKm,
    'distance_text'  => number_format($roadKm, 2, ',', '.') . ' km',
    'cost'           => (float)$cost,
    'cost_formatted' => number_format($cost, 0, ',', '.'),
    'eta'            => formatDuration(estimateDeliveryMinutes($roadKm)),
]);
