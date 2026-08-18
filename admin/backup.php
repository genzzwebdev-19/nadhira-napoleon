<?php
$currentPage = 'backup';
$pageTitle = 'Backup & Restore';
require_once __DIR__ . '/../config/rbac.php';
require_once __DIR__ . '/../includes/backup-helper.php';

requirePermission('backup', 'view');
$conn = getConnection();

$errors = [];
$success = '';
$info = '';

// ============================================
// ACTION: Buat & unduh backup
// ============================================
if (isset($_GET['backup'])) {
    verifyCsrf();
    requirePermission('backup', 'backup');

    $dump = generateDatabaseDump($conn);
    $filename = 'backup_' . DB_NAME . '_' . date('Ymd_His') . '.sql';
    logActivity('backup', 'backup', "Membuat backup database ($filename)");

    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($dump));
    echo $dump;
    exit;
}

// ============================================
// ACTION: Simpan backup ke server (uploads/backups)
// ============================================
if (isset($_GET['save_disk'])) {
    verifyCsrf();
    requirePermission('backup', 'backup');

    $saved = saveBackupToDisk('backup_manual');
    if ($saved) {
        $success = 'Backup tersimpan ke server: <strong>' . htmlspecialchars($saved['name']) . '</strong>';
        logActivity('backup', 'backup', "Menyimpan backup ke server (" . $saved['name'] . ")");
    } else {
        $errors[] = 'Gagal menyimpan backup ke server. Periksa izin tulis folder uploads/backups.';
    }
}

// ============================================
// ACTION: Simpan pengaturan backup otomatis
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_auto_backup'])) {
    verifyCsrf();
    requirePermission('backup', 'backup');

    $freq = in_array($_POST['auto_backup_frequency'] ?? '', ['daily', 'weekly', 'monthly'], true)
        ? $_POST['auto_backup_frequency'] : 'daily';
    $time = preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', (string)($_POST['auto_backup_time'] ?? ''))
        ? $_POST['auto_backup_time'] : '02:00';
    $retention = max(1, min(365, (int)($_POST['auto_backup_retention'] ?? 14)));
    $enabled = isset($_POST['auto_backup_enabled']) ? '1' : '0';

    saveSettingValue('auto_backup_enabled', $enabled);
    saveSettingValue('auto_backup_frequency', $freq);
    saveSettingValue('auto_backup_time', $time);
    saveSettingValue('auto_backup_retention', (string)$retention);

    $success = 'Pengaturan backup otomatis disimpan!';
    logActivity('backup', 'backup', "Mengubah pengaturan backup otomatis (aktif: " . ($enabled === '1' ? 'ya' : 'tidak') . ", frekuensi: $freq)");
}

// ============================================
// ACTION: Simpan pengaturan cloud backup (Google Drive / Dropbox)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_cloud_backup'])) {
    verifyCsrf();
    requirePermission('backup', 'backup');

    $provider = in_array($_POST['backup_cloud_provider'] ?? '', ['none', 'google_drive', 'dropbox'], true)
        ? $_POST['backup_cloud_provider'] : 'none';
    $enabled = isset($_POST['backup_cloud_enabled']) ? '1' : '0';

    saveSettingValue('backup_cloud_provider', $provider);
    saveSettingValue('backup_cloud_enabled', $enabled);
    saveSettingValue('backup_cloud_google_json', trim((string)($_POST['backup_cloud_google_json'] ?? '')));
    saveSettingValue('backup_cloud_google_folder', trim((string)($_POST['backup_cloud_google_folder'] ?? '')));
    saveSettingValue('backup_cloud_dropbox_token', trim((string)($_POST['backup_cloud_dropbox_token'] ?? '')));

    $success = 'Pengaturan cloud backup disimpan!';
    logActivity('backup', 'backup', "Mengubah pengaturan cloud backup (provider: $provider)");
}

// ============================================
// ACTION: Simpan pengaturan email backup (Resend)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_email_backup'])) {
    verifyCsrf();
    requirePermission('backup', 'backup');

    $enabled = isset($_POST['backup_email_enabled']) ? '1' : '0';
    $from = trim((string)($_POST['backup_email_from'] ?? ''));
    $to   = trim((string)($_POST['backup_email_to'] ?? ''));

    if ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Email pengirim (from) tidak valid.';
    } elseif ($to !== '' && !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Email penerima (to) tidak valid.';
    } else {
        saveSettingValue('backup_email_enabled', $enabled);
        saveSettingValue('backup_email_api_key', trim((string)($_POST['backup_email_api_key'] ?? '')));
        saveSettingValue('backup_email_from', $from);
        saveSettingValue('backup_email_to', $to);

        $success = 'Pengaturan email backup disimpan!';
        logActivity('backup', 'backup', "Mengubah pengaturan email backup (aktif: " . ($enabled === '1' ? 'ya' : 'tidak') . ")");
    }
}

// ============================================
// ACTION: Tes kirim email backup (file tes kecil)
// ============================================
if (isset($_GET['test_email'])) {
    verifyCsrf();
    requirePermission('backup', 'backup');

    if (getSetting('backup_email_enabled', '') !== '1') {
        $errors[] = 'Aktifkan email backup terlebih dahulu (centang Aktifkan & simpan) sebelum tes kirim.';
    } else {
        $tmpFile = backupStorageDir() . '/test-email-' . uniqid() . '.txt';
        @file_put_contents($tmpFile, 'Tes kirim email backup — ' . date('Y-m-d H:i:s'));
        $res = emailBackupSend($tmpFile, 'test-email-' . date('Ymd_His') . '.txt');
        @unlink($tmpFile);

        if ($res['ok']) {
            $success = 'Tes email OK — ' . htmlspecialchars($res['message']);
        } else {
            $errors[] = 'Tes email gagal: ' . htmlspecialchars($res['message']);
        }
    }
}

// ============================================
// ACTION: Tes koneksi cloud (upload file tes kecil)
// ============================================
if (isset($_GET['test_cloud'])) {
    verifyCsrf();
    requirePermission('backup', 'backup');

    $tmpFile = backupStorageDir() . '/test-cloud-' . uniqid() . '.txt';
    @file_put_contents($tmpFile, 'Tes koneksi cloud — ' . date('Y-m-d H:i:s'));
    $res = cloudUploadBackup($tmpFile, 'test-cloud-' . date('Ymd_His') . '.txt');
    @unlink($tmpFile);

    if ($res['ok']) {
        $success = 'Koneksi cloud OK — ' . htmlspecialchars($res['message']);
    } else {
        $errors[] = 'Tes koneksi cloud gagal: ' . htmlspecialchars($res['message']);
    }
}

// ============================================
// ACTION: Jalankan backup otomatis sekarang
// ============================================
if (isset($_GET['run_auto'])) {
    verifyCsrf();
    requirePermission('backup', 'backup');

    $res = runAutoBackupIfDue(true);
    if ($res['ran']) {
        $success = 'Backup otomatis berhasil dibuat: <strong>' . htmlspecialchars($res['file']) . '</strong>'
            . (!empty($res['deleted']) ? ' (' . $res['deleted'] . ' backup lama dihapus)' : '');
    } else {
        $errors[] = 'Gagal membuat backup otomatis: ' . htmlspecialchars((string)($res['reason'] ?? 'unknown'));
    }
}

// ============================================
// ACTION: Unduh backup tersimpan
// ============================================
if (isset($_GET['download'])) {
    verifyCsrf();
    requirePermission('backup', 'backup');

    $name = basename((string)$_GET['download']);
    $path = backupStorageDir() . '/' . $name;
    if ($name !== '' && is_file($path)) {
        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        logActivity('backup', 'backup', "Mengunduh backup $name");
        exit;
    }
    $errors[] = 'File backup tidak ditemukan.';
}

// ============================================
// ACTION: Hapus backup tersimpan
// ============================================
if (isset($_GET['delete'])) {
    verifyCsrf();
    requirePermission('backup', 'restore');

    $name = basename((string)$_GET['delete']);
    $path = backupStorageDir() . '/' . $name;
    if ($name !== '' && is_file($path)) {
        @unlink($path);
        $success = 'Backup dihapus: <strong>' . htmlspecialchars($name) . '</strong>';
        logActivity('backup', 'backup', "Menghapus backup $name");
    } else {
        $errors[] = 'File backup tidak ditemukan.';
    }
}

// ============================================
// ACTION: Restore dari file .sql
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore'])) {
    verifyCsrf();
    requirePermission('backup', 'restore');

    if (isset($_FILES['sql_file']) && $_FILES['sql_file']['error'] === UPLOAD_ERR_OK) {
        $tmp = $_FILES['sql_file']['tmp_name'];
        $size = filesize($tmp);
        if ($size > 50 * 1024 * 1024) {
            $errors[] = 'File terlalu besar (maks 50MB)';
        } else {
            $sql = file_get_contents($tmp);
            if ($conn->multi_query($sql)) {
                do {
                    if ($result = $conn->store_result()) $result->free();
                } while ($conn->next_result());
            }
            if ($conn->errno) {
                $errors[] = 'Restore gagal: ' . $conn->error;
            } else {
                $success = 'Database berhasil di-restore!';
                logActivity('restore', 'backup', "Restore database dari file " . $_FILES['sql_file']['name']);
            }
        }
    } else {
        $errors[] = 'Pilih file .sql terlebih dahulu';
    }
}

// Data untuk panel
$backupFiles = listStoredBackups();
$autoCfg = getAutoBackupSettings();
$cloudCfg = [
    'enabled'    => getSetting('backup_cloud_enabled', '') === '1',
    'provider'   => getSetting('backup_cloud_provider', 'none'),
    'google_json'    => (string)getSetting('backup_cloud_google_json', ''),
    'google_folder'  => (string)getSetting('backup_cloud_google_folder', ''),
    'dropbox_token'  => (string)getSetting('backup_cloud_dropbox_token', ''),
    'last_status'    => (string)getSetting('backup_cloud_last_status', ''),
    'last_run'       => (string)getSetting('backup_cloud_last_run', ''),
];
$cloudMaskedJson = $cloudCfg['google_json'] !== '' ? 'Terisi (' . strlen($cloudCfg['google_json']) . ' karakter)' : 'Belum diisi';
$cloudMaskedToken = $cloudCfg['dropbox_token'] !== '' ? substr($cloudCfg['dropbox_token'], 0, 8) . '••••••••' : 'Belum diisi';
$emailCfg = [
    'enabled'      => getSetting('backup_email_enabled', '') === '1',
    'api_key'      => (string)getSetting('backup_email_api_key', ''),
    'from'         => (string)getSetting('backup_email_from', ''),
    'to'           => (string)getSetting('backup_email_to', ''),
    'last_status'  => (string)getSetting('backup_email_last_status', ''),
    'last_run'     => (string)getSetting('backup_email_last_run', ''),
];
$emailMaskedKey = $emailCfg['api_key'] !== '' ? substr($emailCfg['api_key'], 0, 10) . '••••••' : 'Belum diisi';
$autoKey = autoBackupKey();
$autoEnabled = !empty($autoCfg['auto_backup_enabled']) && $autoCfg['auto_backup_enabled'] !== '0';
$freqLabels = ['daily' => 'Harian (setiap hari)', 'weekly' => 'Mingguan (hari Minggu)', 'monthly' => 'Bulanan (tanggal 1)'];
$freqLabel = $freqLabels[$autoCfg['auto_backup_frequency']] ?? $freqLabels['daily'];
$nextRun = nextScheduledOccurrence((string)$autoCfg['auto_backup_frequency'], (string)$autoCfg['auto_backup_time']);
$cronUrl = SITE_URL . '/auto-backup.php?key=' . urlencode($autoKey);
$cronCli = PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/../auto-backup.php');

// Auto-expire pesanan belum dibayar (runner & kunci cron-nya)
$expireKey = autoExpireKey();
$expireCronUrl = SITE_URL . '/auto-expire.php?key=' . urlencode($expireKey);
$expireCli = PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/../auto-expire.php');

require_once __DIR__ . '/layout.php';
?>

<?php if ($success): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $success ?></div>
<?php endif; ?>
<?php if ($info): ?>
    <div class="alert alert-info"><i class="fas fa-info-circle"></i> <?= $info ?></div>
<?php endif; ?>
<?php foreach ($errors as $err): ?>
    <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($err) ?></div>
<?php endforeach; ?>

<!-- ============================================
     BACKUP OTOMATIS TERJADWAL
     ============================================ -->
<div class="admin-card" style="border-top: 3px solid var(--soft-gold);">
    <h3 class="admin-card-title"><i class="fas fa-clock" style="color: var(--soft-gold);"></i> Backup Otomatis Terjadwal
        <?php if ($autoEnabled): ?>
            <span class="status-badge active">Aktif</span>
        <?php else: ?>
            <span class="status-badge inactive">Nonaktif</span>
        <?php endif; ?>
    </h3>
    <p style="color: var(--text-muted); font-size: 13px; line-height: 1.7;">
        Database otomatis disalin ke server (<code>uploads/backups</code>) sesuai jadwal di bawah — tanpa perlu
        melakukan apa pun. Backup juga otomatis dicek setiap Anda membuka halaman admin, jadi tetap berjalan
        meski belum ada cron di hosting.
    </p>

    <div class="form-row" style="grid-template-columns: 2fr 1fr; margin-bottom: 20px;">
        <div>
            <form method="POST" style="display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end;">
                <input type="hidden" name="save_auto_backup" value="1">
                <?= csrfField() ?>
                <div class="form-group" style="margin-bottom: 0; min-width: 160px;">
                    <label class="form-label" style="display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" name="auto_backup_enabled" value="1" <?= $autoEnabled ? 'checked' : '' ?>
                               style="accent-color: #D4A853; width: 16px; height: 16px;">
                        Aktifkan
                    </label>
                </div>
                <div class="form-group" style="margin-bottom: 0; min-width: 200px;">
                    <label class="form-label">Frekuensi</label>
                    <select name="auto_backup_frequency" class="form-select">
                        <option value="daily" <?= $autoCfg['auto_backup_frequency'] === 'daily' ? 'selected' : '' ?>>Harian (setiap hari)</option>
                        <option value="weekly" <?= $autoCfg['auto_backup_frequency'] === 'weekly' ? 'selected' : '' ?>>Mingguan (hari Minggu)</option>
                        <option value="monthly" <?= $autoCfg['auto_backup_frequency'] === 'monthly' ? 'selected' : '' ?>>Bulanan (tanggal 1)</option>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom: 0; width: 110px;">
                    <label class="form-label">Jam</label>
                    <input type="time" name="auto_backup_time" class="form-input" value="<?= htmlspecialchars($autoCfg['auto_backup_time']) ?>">
                </div>
                <div class="form-group" style="margin-bottom: 0; width: 130px;">
                    <label class="form-label">Simpan (file)</label>
                    <input type="number" name="auto_backup_retention" class="form-input" min="1" max="365"
                           value="<?= (int)$autoCfg['auto_backup_retention'] ?>" title="Jumlah backup terakhir yang disimpan; yang lebih lama otomatis dihapus">
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Simpan Pengaturan</button>
            </form>
        </div>
        <div style="background: #f8f6f4; border-radius: 12px; padding: 16px 20px; font-size: 13px;">
            <div style="margin-bottom: 8px;"><i class="fas fa-history" style="color: var(--soft-gold); width: 20px;"></i>
                Terakhir: <?= $autoCfg['auto_backup_last_run'] ? date('d M Y H:i', strtotime($autoCfg['auto_backup_last_run'])) : '— belum pernah' ?>
            </div>
            <div style="margin-bottom: 8px;"><i class="fas fa-calendar-check" style="color: var(--soft-gold); width: 20px;"></i>
                Berikutnya: <strong><?= date('d M Y H:i', $nextRun) ?></strong>
            </div>
            <div><i class="fas fa-info-circle" style="color: var(--soft-gold); width: 20px;"></i>
                Status: <?= htmlspecialchars($autoCfg['auto_backup_last_status'] ?: 'Belum ada backup otomatis') ?>
            </div>
        </div>
    </div>

    <?php if (hasPermission('backup', 'backup')): ?>
    <a href="?run_auto=1&csrf_token=<?= csrfToken() ?>" class="btn btn-secondary" style="margin-right: 8px;">
        <i class="fas fa-play"></i> Jalankan Backup Sekarang
    </a>
    <?php endif; ?>

    <details style="margin-top: 16px; border: 1px solid #eee; border-radius: 12px; padding: 14px 18px; background: #fbfaf8;">
        <summary style="cursor: pointer; font-weight: 600; font-size: 13px; color: var(--text-dark);">
            <i class="fas fa-cog"></i> Setup cron di hosting / Task Scheduler Windows <span style="color: var(--text-muted); font-weight: 400;">(opsional — untuk ketepatan waktu penuh)</span>
        </summary>
        <div style="margin-top: 14px; font-size: 13px; line-height: 1.8;">
            <p><strong>Metode 1 — Hosting cPanel (Cron Jobs):</strong> buka <em>cPanel &rarr; Cron Jobs</em>, lalu masukkan perintah berikut (sesuaikan jadwal, mis. setiap hari pukul 02.00):</p>
            <code style="display: block; background: #1a1a2e; color: #D4A853; padding: 10px 14px; border-radius: 8px; margin-bottom: 12px; word-break: break-all;">
                wget -q -O /dev/null "<?= htmlspecialchars($cronUrl) ?>"
            </code>
            <p><strong>Metode 2 — Windows / Laragon (Task Scheduler):</strong> buat tugas baru yang menjalankan:</p>
            <code style="display: block; background: #1a1a2e; color: #D4A853; padding: 10px 14px; border-radius: 8px; margin-bottom: 12px; word-break: break-all;">
                <?= htmlspecialchars($cronCli) ?>
            </code>
            <p style="color: var(--text-muted);">Tanpa cron pun backup tetap berjalan karena otomatis dicek setiap halaman admin dibuka (bila jadwal sudah lewat).</p>
        </div>
    </details>
</div>

<!-- ============================================
     AUTO-EXPIRE PESANAN (CRON)
     ============================================ -->
<div class="admin-card" style="border-top: 3px solid #EF4444;">
    <h3 class="admin-card-title"><i class="fas fa-hourglass-end" style="color: #EF4444;"></i> Auto-Expire Pesanan Belum Dibayar
        <span class="status-badge active">Aktif</span>
    </h3>
    <p style="color: var(--text-muted); font-size: 13px; line-height: 1.7;">
        Pesanan berstatus <strong>pending</strong> yang tidak dibayar dalam batas waktu (default 24 jam — diatur di
        <strong>Pengaturan &rarr; Midtrans</strong>) otomatis dibatalkan; stok &amp; kuota promo dikembalikan.
        Fitur sudah berjalan otomatis (dicek setiap halaman depan/admin dibuka, throttle 1x/jam) — cron di bawah
        hanya untuk ketepatan waktu penuh.
    </p>

    <details style="margin-top: 12px; border: 1px solid #eee; border-radius: 12px; padding: 14px 18px; background: #fbfaf8;">
        <summary style="cursor: pointer; font-weight: 600; font-size: 13px; color: var(--text-dark);">
            <i class="fas fa-cog"></i> Setup cron auto-expire di hosting / Task Scheduler Windows <span style="color: var(--text-muted); font-weight: 400;">(opsional — untuk ketepatan waktu penuh)</span>
        </summary>
        <div style="margin-top: 14px; font-size: 13px; line-height: 1.8;">
            <p><strong>Metode 1 — Hosting cPanel (Cron Jobs):</strong> tambahkan perintah berikut (misal setiap jam):</p>
            <code style="display: block; background: #1a1a2e; color: #D4A853; padding: 10px 14px; border-radius: 8px; margin-bottom: 12px; word-break: break-all;">
                wget -q -O /dev/null "<?= htmlspecialchars($expireCronUrl) ?>"
            </code>
            <p><strong>Metode 2 — Windows / Laragon (Task Scheduler):</strong> buat tugas baru yang menjalankan:</p>
            <code style="display: block; background: #1a1a2e; color: #D4A853; padding: 10px 14px; border-radius: 8px; margin-bottom: 12px; word-break: break-all;">
                <?= htmlspecialchars($expireCli) ?>
            </code>
            <p style="color: var(--text-muted);">Tanpa cron pun fitur tetap berjalan otomatis lewat halaman depan &amp; panel admin.</p>
        </div>
    </details>
</div>

<!-- ============================================
     CLOUD BACKUP (Google Drive / Dropbox)
     ============================================ -->
<div class="admin-card" style="border-top: 3px solid #3B82F6;">
    <h3 class="admin-card-title"><i class="fas fa-cloud-upload-alt" style="color: #3B82F6;"></i> Cloud Backup (Google Drive / Dropbox)
        <?php if ($cloudCfg['enabled']): ?>
            <span class="status-badge active">Aktif &middot; <?= $cloudCfg['provider'] === 'google_drive' ? 'Google Drive' : ($cloudCfg['provider'] === 'dropbox' ? 'Dropbox' : 'Belum pilih') ?></span>
        <?php else: ?>
            <span class="status-badge inactive">Nonaktif</span>
        <?php endif; ?>
    </h3>
    <p style="color: var(--text-muted); font-size: 13px; line-height: 1.7;">
        Setiap backup otomatis dibuat di server, salinannya <strong>juga diunggah ke cloud</strong> sebagai cadangan
        ekstra (aman jika server bermasalah). Tanpa perlu library tambahan — cukup masukkan kredensial di bawah.
    </p>

    <form method="POST" style="margin-bottom: 16px;">
        <input type="hidden" name="save_cloud_backup" value="1">
        <?= csrfField() ?>
        <div style="display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end; margin-bottom: 16px;">
            <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label" style="display: flex; align-items: center; gap: 8px;">
                    <input type="checkbox" name="backup_cloud_enabled" value="1" <?= $cloudCfg['enabled'] ? 'checked' : '' ?>
                           style="accent-color: #3B82F6; width: 16px; height: 16px;">
                    Aktifkan cloud backup
                </label>
            </div>
            <div class="form-group" style="margin-bottom: 0; min-width: 200px;">
                <label class="form-label">Penyedia</label>
                <select name="backup_cloud_provider" id="cloudProvider" class="form-select">
                    <option value="none" <?= $cloudCfg['provider'] === 'none' ? 'selected' : '' ?>>— Pilih penyedia —</option>
                    <option value="google_drive" <?= $cloudCfg['provider'] === 'google_drive' ? 'selected' : '' ?>>Google Drive</option>
                    <option value="dropbox" <?= $cloudCfg['provider'] === 'dropbox' ? 'selected' : '' ?>>Dropbox</option>
                </select>
            </div>
        </div>

        <div id="cloudGoogleFields" style="display: none; background: #f8f6f4; border-radius: 12px; padding: 16px 20px; margin-bottom: 16px;">
            <div class="form-group">
                <label class="form-label">JSON Service Account Google <span style="color: var(--text-muted); font-weight: 400;">(status: <?= htmlspecialchars($cloudMaskedJson) ?>)</span></label>
                <textarea name="backup_cloud_google_json" class="form-textarea" rows="5"
                          placeholder='Paste seluruh isi file JSON service account (berisi client_email & private_key)'><?= htmlspecialchars($cloudCfg['google_json']) ?></textarea>
            </div>
            <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label">ID Folder Google Drive <span style="color: var(--text-muted); font-weight: 400;">(opsional — kosongkan untuk folder root)</span></label>
                <input type="text" name="backup_cloud_google_folder" class="form-input"
                       value="<?= htmlspecialchars($cloudCfg['google_folder']) ?>"
                       placeholder="Contoh: 1A2bC3dE4fG5h... (dari URL folder di Google Drive)">
            </div>
        </div>

        <div id="cloudDropboxFields" style="display: none; background: #f8f6f4; border-radius: 12px; padding: 16px 20px; margin-bottom: 16px;">
            <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label">Dropbox Access Token <span style="color: var(--text-muted); font-weight: 400;">(status: <?= htmlspecialchars($cloudMaskedToken) ?>)</span></label>
                <input type="text" name="backup_cloud_dropbox_token" class="form-input"
                       value="<?= htmlspecialchars($cloudCfg['dropbox_token']) ?>"
                       placeholder="sl.B2xxxxxxxx...">
            </div>
        </div>

        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Simpan Pengaturan</button>
            <?php if (hasPermission('backup', 'backup')): ?>
            <a href="?test_cloud=1&csrf_token=<?= csrfToken() ?>" class="btn btn-secondary">
                <i class="fas fa-plug"></i> Tes Koneksi &amp; Upload
            </a>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($cloudCfg['last_run'] !== ''): ?>
    <div style="background: #f8f6f4; border-radius: 12px; padding: 14px 20px; font-size: 13px; margin-bottom: 16px;">
        <i class="fas fa-history" style="color: #3B82F6; width: 20px;"></i>
        Terakhir upload cloud: <?= date('d M Y H:i', strtotime($cloudCfg['last_run'])) ?> &middot;
        Status: <?= htmlspecialchars($cloudCfg['last_status'] ?: '—') ?>
    </div>
    <?php endif; ?>

    <details style="border: 1px solid #eee; border-radius: 12px; padding: 14px 18px; background: #fbfaf8;">
        <summary style="cursor: pointer; font-weight: 600; font-size: 13px; color: var(--text-dark);">
            <i class="fas fa-question-circle"></i> Cara mendapatkan kredensial
        </summary>
        <div style="margin-top: 14px; font-size: 13px; line-height: 1.8;">
            <p><strong>Google Drive (disarankan):</strong></p>
            <ol style="margin: 8px 0 14px 20px; padding: 0;">
                <li>Buka <code>console.cloud.google.com</code> &rarr; buat Project &rarr; aktifkan <strong>Google Drive API</strong> (Library).</li>
                <li>Menu <strong>IAM &amp; Admin &rarr; Service Accounts</strong> &rarr; Buat service account &rarr; <strong>Keys</strong> &rarr; <em>Add Key &rarr; JSON</em> (file terunduh berisi <code>client_email</code> &amp; <code>private_key</code>) &rarr; paste seluruh isinya di atas.</li>
                <li>(Opsional) Buat folder di Google Drive, lalu <strong>share folder itu ke email service account</strong> (nilai <code>client_email</code> di JSON) dengan akses <strong>Editor</strong>, dan isi ID folder-nya (bagian acak di URL folder).</li>
            </ol>
            <p><strong>Dropbox:</strong></p>
            <ol style="margin: 8px 0 14px 20px; padding: 0;">
                <li>Buka <code>dropbox.com/developers/apps</code> &rarr; <strong>Create app</strong> &rarr; pilih <em>Scoped access</em> &rarr; <em>App folder</em>.</li>
                <li>Di tab <strong>Permissions</strong>, centang <code>files.content.write</code> &rarr; <strong>Save</strong>.</li>
                <li>Di tab <strong>Settings</strong>, klik <strong>Generate access token</strong> (token tidak perlu di-refresh) &rarr; paste di atas.</li>
            </ol>
        </div>
    </details>

    <script>
    (function () {
        var sel = document.getElementById('cloudProvider');
        var g = document.getElementById('cloudGoogleFields');
        var d = document.getElementById('cloudDropboxFields');
        function toggleCloud() {
            g.style.display = (sel.value === 'google_drive') ? 'block' : 'none';
            d.style.display = (sel.value === 'dropbox') ? 'block' : 'none';
        }
        if (sel) { sel.addEventListener('change', toggleCloud); toggleCloud(); }
    })();
    </script>
</div>

<!-- ============================================
     EMAIL BACKUP (Resend)
     ============================================ -->
<div class="admin-card" style="border-top: 3px solid #10B981;">
    <h3 class="admin-card-title"><i class="fas fa-envelope" style="color: #10B981;"></i> Email Backup (kirim .sql ke email admin)
        <?php if ($emailCfg['enabled']): ?>
            <span class="status-badge active">Aktif</span>
        <?php else: ?>
            <span class="status-badge inactive">Nonaktif</span>
        <?php endif; ?>
    </h3>
    <p style="color: var(--text-muted); font-size: 13px; line-height: 1.7;">
        Setiap backup otomatis juga <strong>dikirim sebagai lampiran .sql ke email Anda</strong> — cadangan di luar
        server yang tidak mungkin hilang. Menggunakan <strong>Resend</strong> (email API gratis, tanpa library tambahan).
    </p>

    <form method="POST" style="margin-bottom: 16px;">
        <input type="hidden" name="save_email_backup" value="1">
        <?= csrfField() ?>
        <div style="display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end; margin-bottom: 16px;">
            <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label" style="display: flex; align-items: center; gap: 8px;">
                    <input type="checkbox" name="backup_email_enabled" value="1" <?= $emailCfg['enabled'] ? 'checked' : '' ?>
                           style="accent-color: #10B981; width: 16px; height: 16px;">
                    Aktifkan email backup
                </label>
            </div>
        </div>
        <div class="form-row" style="grid-template-columns: 1fr 1fr;">
            <div class="form-group">
                <label class="form-label">Resend API Key <span style="color: var(--text-muted); font-weight: 400;">(status: <?= htmlspecialchars($emailMaskedKey) ?>)</span></label>
                <input type="text" name="backup_email_api_key" class="form-input"
                       value="<?= htmlspecialchars($emailCfg['api_key']) ?>"
                       placeholder="re_xxxxxxxxxxxx">
            </div>
            <div class="form-group">
                <label class="form-label">Email Pengirim (from)</label>
                <input type="email" name="backup_email_from" class="form-input"
                       value="<?= htmlspecialchars($emailCfg['from']) ?>"
                       placeholder="backup@namadomain.com">
            </div>
            <div class="form-group">
                <label class="form-label">Email Penerima (to) — admin</label>
                <input type="email" name="backup_email_to" class="form-input"
                       value="<?= htmlspecialchars($emailCfg['to'] ?: getSetting('contact_email', '')) ?>"
                       placeholder="admin@emailanda.com">
            </div>
            <div class="form-group" style="display: flex; align-items: flex-end;">
                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Simpan Pengaturan</button>
                    <?php if (hasPermission('backup', 'backup')): ?>
                    <a href="?test_email=1&csrf_token=<?= csrfToken() ?>" class="btn btn-secondary">
                        <i class="fas fa-paper-plane"></i> Tes Kirim Email
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </form>

    <?php if ($emailCfg['last_run'] !== ''): ?>
    <div style="background: #f8f6f4; border-radius: 12px; padding: 14px 20px; font-size: 13px; margin-bottom: 16px;">
        <i class="fas fa-history" style="color: #10B981; width: 20px;"></i>
        Terakhir kirim email: <?= date('d M Y H:i', strtotime($emailCfg['last_run'])) ?> &middot;
        Status: <?= htmlspecialchars($emailCfg['last_status'] ?: '—') ?>
    </div>
    <?php endif; ?>

    <details style="border: 1px solid #eee; border-radius: 12px; padding: 14px 18px; background: #fbfaf8;">
        <summary style="cursor: pointer; font-weight: 600; font-size: 13px; color: var(--text-dark);">
            <i class="fas fa-question-circle"></i> Cara setup (gratis, ±5 menit)
        </summary>
        <div style="margin-top: 14px; font-size: 13px; line-height: 1.8;">
            <ol style="margin: 8px 0 14px 20px; padding: 0;">
                <li>Daftar gratis di <code>resend.com</code> (paket gratis 3.000 email/bulan — cukup untuk backup harian).</li>
                <li>Buat <strong>API Key</strong> di <code>resend.com/api-keys</code> &rarr; paste di kolom API Key di atas.</li>
                <li><strong>Verifikasi domain</strong> Anda di <code>resend.com/domains</code> (tambah catatan DNS SPF/DKIM sesuai petunjuk) — lalu gunakan alamat seperti <code>backup@namadomain.com</code> di kolom Pengirim.</li>
                <li>Isi Email Penerima (email admin Anda), simpan, lalu klik <strong>Tes Kirim Email</strong>.</li>
            </ol>
            <p style="color: var(--text-muted);">Untuk pengujian awal tanpa verifikasi domain, gunakan <code>onboarding@resend.dev</code> sebagai pengirim — email hanya bisa masuk ke akun yang Anda daftarkan di Resend.</p>
        </div>
    </details>
</div>

<div class="form-row" style="grid-template-columns: 1fr 1fr;">
    <!-- Backup -->
    <div class="admin-card">
        <h3 class="admin-card-title"><i class="fas fa-database" style="color: var(--soft-gold);"></i> Buat Backup Manual</h3>
        <p style="color: var(--text-muted); font-size: 13px; line-height: 1.7;">
            Salinan lengkap seluruh tabel dan data dalam satu file <code>.sql</code>.
        </p>
        <?php if (hasPermission('backup', 'backup')): ?>
        <div style="display: flex; flex-wrap: wrap; gap: 10px;">
            <a href="?backup=1&csrf_token=<?= csrfToken() ?>" class="btn btn-primary">
                <i class="fas fa-download"></i> Backup & Unduh
            </a>
            <a href="?save_disk=1&csrf_token=<?= csrfToken() ?>" class="btn btn-outline" onclick="return confirm('Simpan backup ke server (uploads/backups)?')">
                <i class="fas fa-hdd"></i> Simpan ke Server
            </a>
        </div>
        <?php else: ?>
        <p style="color: #EF4444; font-size: 13px;">Anda tidak memiliki permission backup:backup.</p>
        <?php endif; ?>
    </div>

    <!-- Restore -->
    <div class="admin-card">
        <h3 class="admin-card-title"><i class="fas fa-undo" style="color: var(--soft-gold);"></i> Restore Database</h3>
        <div class="alert alert-error" style="font-size: 12px; padding: 10px 14px;">
            <i class="fas fa-exclamation-triangle"></i> <strong>Peringatan!</strong> Restore akan menimpa seluruh data yang ada saat ini.
        </div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="restore" value="1">
            <?= csrfField() ?>
            <div class="form-group">
                <label class="form-label">File Backup (.sql)</label>
                <input type="file" name="sql_file" class="form-input" accept=".sql" required>
            </div>
            <button type="submit" class="btn btn-danger" onclick="return confirm('Yakin restore database? Semua data saat ini akan DITIMPA!')">
                <i class="fas fa-upload"></i> Restore
            </button>
        </form>
    </div>
</div>

<?php if (!empty($backupFiles)): ?>
<div class="admin-card">
    <h3 class="admin-card-title">
        <i class="fas fa-folder-open" style="color: var(--soft-gold);"></i> Backup Tersimpan (uploads/backups)
        <span style="font-size: 12px; color: var(--text-muted); font-family: 'Inter', sans-serif; font-weight: 400; margin-left: 6px;">
            <?= count($backupFiles) ?> file &middot; otomatis menyisakan <?= (int)$autoCfg['auto_backup_retention'] ?> terbaru
        </span>
    </h3>
    <table class="admin-table">
        <thead><tr><th>Nama File</th><th>Ukuran</th><th>Dibuat</th><th style="text-align: right;">Aksi</th></tr></thead>
        <tbody>
        <?php foreach ($backupFiles as $bf): ?>
            <tr>
                <td><i class="fas fa-file-archive" style="color: var(--soft-gold);"></i> <strong><?= htmlspecialchars($bf['name']) ?></strong></td>
                <td><?= number_format($bf['size'] / 1024, 1) ?> KB</td>
                <td><?= date('d M Y H:i', $bf['time']) ?></td>
                <td style="text-align: right; white-space: nowrap;">
                    <?php if (hasPermission('backup', 'backup')): ?>
                    <a href="?download=<?= urlencode($bf['name']) ?>&csrf_token=<?= csrfToken() ?>" class="btn btn-sm btn-secondary" title="Unduh">
                        <i class="fas fa-download"></i>
                    </a>
                    <?php endif; ?>
                    <?php if (hasPermission('backup', 'restore')): ?>
                    <a href="?delete=<?= urlencode($bf['name']) ?>&csrf_token=<?= csrfToken() ?>" class="btn btn-sm btn-danger"
                       onclick="return confirm('Hapus backup <?= htmlspecialchars(addslashes($bf['name'])) ?>?')" title="Hapus">
                        <i class="fas fa-trash"></i>
                    </a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php else: ?>
<div class="admin-card">
    <h3 class="admin-card-title"><i class="fas fa-folder-open" style="color: var(--soft-gold);"></i> Backup Tersimpan (uploads/backups)</h3>
    <p style="color: var(--text-muted); font-size: 13px;">
        <i class="fas fa-info-circle"></i> Belum ada backup tersimpan di server. Gunakan tombol <strong>Simpan ke Server</strong> atau aktifkan backup otomatis di atas.
    </p>
</div>
<?php endif; ?>
        </main>
    </div>
</body>
</html>
