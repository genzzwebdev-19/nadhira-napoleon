<?php
// ============================================
// AUTO EXPIRE RUNNER - pembatalan otomatis pesanan pending yang tidak dibayar
// ============================================
// Dipanggil oleh cron / Task Scheduler:
//   CLI : php auto-expire.php          (ikuti throttle internal)
//   CLI : php auto-expire.php --force  (paksa jalankan sekarang)
//   HTTP: /auto-expire.php?key=KUNCI&force=1
//
// Aman: akses HTTP hanya dengan kunci rahasia (auto_expire_key),
// selain itu ditolak 403. Kunci dapat dilihat di Admin > Backup & Restore
// (sama seperti kunci auto-backup) atau setting auto_expire_key.
//
// Tanpa cron sekalipun, fitur tetap berjalan otomatis: halaman depan (index.php)
// dan panel admin memanggil runOrderExpiryIfDue() yang throttle 1x/jam.
// ============================================
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/backup-helper.php';

$isCli = php_sapi_name() === 'cli';

// ---- Proteksi akses ----
if ($isCli) {
    $force = in_array('--force', $argv ?? [], true) || in_array('-f', $argv ?? [], true);
} else {
    $key = (string)($_GET['key'] ?? '');
    $expected = autoExpireKey(); // otomatis dibuat bila belum ada (lihat Admin > Backup & Restore)
    if ($key === '' || $expected === '' || !hash_equals($expected, $key)) {
        http_response_code(403);
        exit('403 Forbidden — kunci tidak valid.');
    }
    $force = !empty($_GET['force']);
}

// ---- Jalankan ----
$expired = 0;
if ($force) {
    $expired = expirePendingOrders();
} else {
    $expired = runOrderExpiryIfDue();
}

if ($isCli) {
    echo "[OK] Auto-expire selesai: $expired pesanan dibatalkan." . PHP_EOL;
} else {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'expired' => $expired]);
}
