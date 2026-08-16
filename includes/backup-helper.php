<?php
// ============================================
// BACKUP HELPER - fungsi bersama backup otomatis terjadwal
// Dipakai oleh: admin/backup.php, auto-backup.php, admin/layout.php
// ============================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/cloud-upload.php';
require_once __DIR__ . '/email-backup.php';

// ------------------------------------------------------------
// Penyimpanan & daftar backup
// ------------------------------------------------------------

// Folder tempat backup disimpan (uploads/backups)
function backupStorageDir(): string {
    $dir = __DIR__ . '/../uploads/backups';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

// Generate dump SQL lengkap (semua tabel + data)
function generateDatabaseDump($conn = null): string {
    $conn = $conn ?: getConnection();
    if (!$conn) return '';

    $tables = [];
    $r = $conn->query("SHOW TABLES");
    if ($r) while ($row = $r->fetch_array()) $tables[] = $row[0];

    $dump  = "-- ============================================\n";
    $dump .= "-- NADHIRA NAPOLEON DATABASE BACKUP\n";
    $dump .= "-- Tanggal: " . date('Y-m-d H:i:s') . "\n";
    $dump .= "-- Database: " . DB_NAME . "\n";
    $dump .= "-- ============================================\n\n";
    $dump .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

    foreach ($tables as $table) {
        $t = $conn->real_escape_string($table);
        $create = $conn->query("SHOW CREATE TABLE `$t`")->fetch_assoc();
        $dump .= "DROP TABLE IF EXISTS `$t`;\n";
        $dump .= $create['Create Table'] . ";\n\n";

        $rows = $conn->query("SELECT * FROM `$t`");
        if ($rows && $rows->num_rows > 0) {
            while ($row = $rows->fetch_assoc()) {
                $cols = array_map(fn($c) => "`$c`", array_keys($row));
                $vals = array_map(function ($v) use ($conn) {
                    if ($v === null) return 'NULL';
                    return "'" . $conn->real_escape_string($v) . "'";
                }, array_values($row));
                $dump .= "INSERT INTO `$t` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ");\n";
            }
            $dump .= "\n";
        }
    }

    $dump .= "SET FOREIGN_KEY_CHECKS = 1;\n";
    return $dump;
}

// Simpan backup baru ke folder server. Prefix: backup_auto / backup_manual
function saveBackupToDisk(string $prefix = 'backup_auto', $conn = null): ?array {
    $conn = $conn ?: getConnection();
    if (!$conn) return null;
    $dump = generateDatabaseDump($conn);
    if ($dump === '') return null;

    $dir = backupStorageDir();
    // Tambah uniqid agar dua backup di detik yang sama TIDAK saling menimpa
    $filename = $prefix . '_' . DB_NAME . '_' . date('Ymd_His') . '_' . substr(uniqid(), -6) . '.sql';
    $path = $dir . '/' . $filename;
    if (@file_put_contents($path, $dump) === false) return null;

    return ['name' => $filename, 'path' => $path, 'size' => strlen($dump), 'time' => time()];
}

// Daftar backup tersimpan (terbaru dulu)
function listStoredBackups(): array {
    $files = [];
    foreach (glob(backupStorageDir() . '/*.sql') as $file) {
        $files[] = [
            'name' => basename($file),
            'path' => $file,
            'size' => filesize($file),
            'time' => filemtime($file),
        ];
    }
    usort($files, fn($a, $b) => $b['time'] - $a['time']);
    return $files;
}

// Hapus backup lama, sisakan N file terbaru. Kembalikan jumlah yang dihapus.
function pruneOldBackups(int $keep): int {
    $files = listStoredBackups();
    $deleted = 0;
    foreach (array_slice($files, max(0, $keep)) as $f) {
        if (@unlink($f['path'])) $deleted++;
    }
    return $deleted;
}

// ------------------------------------------------------------
// Simpan / baca pengaturan backup otomatis
// ------------------------------------------------------------

function saveSettingValue(string $key, string $value): void {
    $conn = getConnection();
    if (!$conn) return;
    $key = $conn->real_escape_string($key);
    $value = $conn->real_escape_string($value);
    $conn->query("INSERT INTO settings (setting_key, setting_value) VALUES ('$key', '$value')
                  ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
}

function autoBackupDefaults(): array {
    return [
        'auto_backup_enabled'   => '1',
        'auto_backup_frequency' => 'daily',      // daily | weekly | monthly
        'auto_backup_time'      => '02:00',
        'auto_backup_retention' => '14',          // simpan N backup terakhir
        'auto_backup_last_run'  => '',
        'auto_backup_last_status' => '',
    ];
}

function getAutoBackupSettings(): array {
    $out = [];
    foreach (autoBackupDefaults() as $k => $d) {
        $out[$k] = getSetting($k, $d);
    }
    return $out;
}

// Kunci rahasia untuk menjalankan runner via HTTP (dipakai cron hosting)
function autoBackupKey(): string {
    $key = trim((string)getSetting('auto_backup_key', ''));
    if ($key === '') {
        $key = 'nabk_' . bin2hex(random_bytes(16));
        saveSettingValue('auto_backup_key', $key);
    }
    return $key;
}

// ------------------------------------------------------------
// Logika jadwal
// - daily   : setiap hari pukul HH:MM
// - weekly  : setiap Minggu pukul HH:MM
// - monthly : setiap tanggal 1 pukul HH:MM
// ------------------------------------------------------------

function parseBackupTime(string $time): array {
    $t = array_map('intval', explode(':', $time ?: '02:00'));
    return [$t[0] ?? 2, $t[1] ?? 0];
}

// Occurrence jadwal TERAKHIR yang sudah lewat (untuk mengecek "sudah waktunya?")
function lastScheduledOccurrence(string $frequency, string $time): int {
    list($h, $m) = parseBackupTime($time);
    $now = time();
    if ($frequency === 'weekly') {
        $base = strtotime('sunday ' . $h . ':' . $m . ':00');
        if ($base > $now) $base -= 7 * 86400;
        return $base;
    }
    if ($frequency === 'monthly') {
        $base = strtotime(date('Y-m-01') . ' ' . $h . ':' . $m . ':00');
        if ($base > $now) $base = strtotime('first day of last month ' . $h . ':' . $m . ':00');
        return $base;
    }
    // daily
    $base = strtotime(date('Y-m-d') . ' ' . $h . ':' . $m . ':00');
    if ($base > $now) $base -= 86400;
    return $base;
}

// Occurrence jadwal BERIKUTNYA (untuk ditampilkan di panel admin)
function nextScheduledOccurrence(string $frequency, string $time): int {
    list($h, $m) = parseBackupTime($time);
    $now = time();
    if ($frequency === 'weekly') {
        $base = strtotime('sunday ' . $h . ':' . $m . ':00');
        if ($base <= $now) $base += 7 * 86400;
        return $base;
    }
    if ($frequency === 'monthly') {
        $base = strtotime(date('Y-m-01') . ' ' . $h . ':' . $m . ':00');
        if ($base <= $now) $base = strtotime('first day of next month ' . $h . ':' . $m . ':00');
        return $base;
    }
    $base = strtotime(date('Y-m-d') . ' ' . $h . ':' . $m . ':00');
    if ($base <= $now) $base += 86400;
    return $base;
}

// Apakah sudah waktunya backup (sesuai jadwal & aktif)?
function autoBackupDue(array $cfg): bool {
    if (empty($cfg['auto_backup_enabled']) || $cfg['auto_backup_enabled'] === '0') return false;
    $last = (int)strtotime((string)$cfg['auto_backup_last_run']);
    return $last < lastScheduledOccurrence((string)$cfg['auto_backup_frequency'], (string)$cfg['auto_backup_time']);
}

// ------------------------------------------------------------
// Eksekusi
// ------------------------------------------------------------

// Jalankan backup bila sudah waktunya (atau paksa dengan $force)
function runAutoBackupIfDue(bool $force = false): array {
    $cfg = getAutoBackupSettings();
    if (!$force) {
        if (empty($cfg['auto_backup_enabled']) || $cfg['auto_backup_enabled'] === '0') {
            return ['ran' => false, 'reason' => 'disabled'];
        }
        if (!autoBackupDue($cfg)) {
            return ['ran' => false, 'reason' => 'not_due'];
        }
    }
    return runAutoBackupNow($cfg);
}

// Eksekusi backup sekarang + update status + prune file lama
function runAutoBackupNow(array $cfg): array {
    $saved = saveBackupToDisk('backup_auto');
    if (!$saved) {
        saveSettingValue('auto_backup_last_run', date('Y-m-d H:i:s'));
        saveSettingValue('auto_backup_last_status', 'GAGAL menulis file backup');
        return ['ran' => false, 'reason' => 'write_failed', 'file' => null];
    }

    $keep = max(1, (int)($cfg['auto_backup_retention'] ?: 14));
    $deleted = pruneOldBackups($keep);

    saveSettingValue('auto_backup_last_run', date('Y-m-d H:i:s'));
    saveSettingValue('auto_backup_last_status', 'OK: ' . $saved['name'] . ($deleted > 0 ? ' (hapus ' . $deleted . ' lama)' : ''));

    // Backup ke cloud (Google Drive / Dropbox) — hanya bila diaktifkan
    $cloud = ['ok' => false, 'message' => 'Cloud backup nonaktif di pengaturan.'];
    if (getSetting('backup_cloud_enabled', '') === '1') {
        $cloud = cloudUploadBackup($saved['path'], $saved['name']);
        saveSettingValue('backup_cloud_last_status', ($cloud['ok'] ? 'OK: ' : 'GAGAL: ') . $cloud['message']);
        saveSettingValue('backup_cloud_last_run', date('Y-m-d H:i:s'));
    }

    // Backup ke email admin (Resend) — hanya bila diaktifkan
    $email = ['ok' => false, 'message' => 'Email backup nonaktif di pengaturan.'];
    if (getSetting('backup_email_enabled', '') === '1') {
        $email = emailBackupSend($saved['path'], $saved['name']);
        saveSettingValue('backup_email_last_status', ($email['ok'] ? 'OK: ' : 'GAGAL: ') . $email['message']);
        saveSettingValue('backup_email_last_run', date('Y-m-d H:i:s'));
    }

    // Catat ke log aktivitas bila ada sesi (dari panel admin)
    if (function_exists('logActivity')) {
        $extra = $cloud['ok'] ? ' + cloud' : '';
        $extra .= $email['ok'] ? ' + email' : '';
        logActivity('backup', 'backup', "Backup otomatis: " . $saved['name'] . $extra);
    }

    return ['ran' => true, 'file' => $saved['name'], 'deleted' => $deleted, 'cloud' => $cloud, 'email' => $email];
}
