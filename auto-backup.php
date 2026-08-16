<?php
// ============================================
// AUTO BACKUP RUNNER - penjadwal backup otomatis
// ============================================
// Dipanggil oleh cron / Task Scheduler:
//   CLI : php auto-backup.php            (ikuti jadwal)
//   CLI : php auto-backup.php --force    (paksa sekarang)
//   HTTP: /auto-backup.php?key=KUNCI&force=1
//
// Aman: akses HTTP hanya dengan kunci rahasia (auto_backup_key),
// selain itu ditolak 403. Kunci dapat dilihat di Admin > Backup & Restore.
// ============================================
require_once __DIR__ . '/includes/backup-helper.php';

$isCli = php_sapi_name() === 'cli';

// ---- Proteksi akses ----
if ($isCli) {
    $force = in_array('--force', $argv ?? [], true) || in_array('-f', $argv ?? [], true);
} else {
    $key = (string)($_GET['key'] ?? '');
    if ($key === '' || !hash_equals(autoBackupKey(), $key)) {
        http_response_code(403);
        exit('403 Forbidden — kunci tidak valid.');
    }
    $force = !empty($_GET['force']);
}

// ---- Jalankan ----
$result = runAutoBackupIfDue($force);

if ($isCli) {
    if ($result['ran']) {
        $extra = [];
        if (!empty($result['cloud'])) $extra[] = 'cloud: ' . ($result['cloud']['ok'] ? 'OK' : 'GAGAL - ' . $result['cloud']['message']);
        if (!empty($result['email'])) $extra[] = 'email: ' . ($result['email']['ok'] ? 'OK' : 'GAGAL - ' . $result['email']['message']);
        echo "[OK] Backup otomatis selesai: " . $result['file'] . ($extra ? ' | ' . implode(' | ', $extra) : '') . PHP_EOL;
    } elseif ($result['reason'] === 'disabled') {
        echo "[SKIP] Backup otomatis nonaktif di pengaturan." . PHP_EOL;
    } elseif ($result['reason'] === 'not_due') {
        echo "[SKIP] Belum waktunya backup sesuai jadwal." . PHP_EOL;
    } else {
        echo "[GAGAL] " . ($result['reason'] ?? 'unknown') . PHP_EOL;
    }
} else {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result);
}
