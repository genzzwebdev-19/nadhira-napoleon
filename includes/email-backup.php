<?php
// ============================================
// EMAIL BACKUP - kirim file .sql ke email admin via Resend API
// Tanpa dependency eksternal (cURL saja).
// Dipakai oleh: includes/backup-helper.php, admin/backup.php
// ============================================

// Kirim file backup sebagai lampiran email.
// Mengembalikan array: ['ok' => bool, 'message' => string]
function emailBackupSend(string $filePath, string $fileName): array {
    if (getSetting('backup_email_enabled', '') !== '1') {
        return ['ok' => false, 'message' => 'Email backup nonaktif di pengaturan.'];
    }

    $apiKey = trim((string)getSetting('backup_email_api_key', ''));
    $from   = trim((string)getSetting('backup_email_from', ''));
    $to     = trim((string)getSetting('backup_email_to', ''));
    if ($apiKey === '' || $from === '' || $to === '') {
        return ['ok' => false, 'message' => 'Kredensial email belum lengkap (API key / email pengirim / email penerima).'];
    }
    if (!is_file($filePath)) {
        return ['ok' => false, 'message' => 'File backup tidak ditemukan untuk dikirim.'];
    }
    if (filesize($filePath) > 40 * 1024 * 1024) {
        return ['ok' => false, 'message' => 'File backup terlalu besar untuk email (maks 40MB).'];
    }

    $sizeKb = number_format(filesize($filePath) / 1024, 1);
    $payload = [
        'from' => $from,
        'to'   => [$to],
        'subject' => 'Backup Database ' . (defined('SITE_NAME') ? SITE_NAME : 'Website') . ' — ' . date('d M Y H:i'),
        'text' => "Backup database otomatis terlampir.\n\n"
            . "File   : {$fileName}\n"
            . "Ukuran : {$sizeKb} KB\n"
            . "Waktu  : " . date('Y-m-d H:i:s') . "\n\n"
            . "Ini adalah cadangan otomatis di luar server. Mohon simpan dengan aman.",
        'attachments' => [
            [
                'filename' => $fileName,
                'content'  => base64_encode((string)file_get_contents($filePath)),
            ],
        ],
    ];

    try {
        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $resp = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err !== '') {
            return ['ok' => false, 'message' => 'cURL error: ' . $err];
        }

        $decoded = json_decode((string)$resp, true);
        if ($httpCode >= 200 && $httpCode < 300 && is_array($decoded) && !empty($decoded['id'])) {
            return ['ok' => true, 'message' => 'Email terkirim ke ' . $to . ' (id ' . substr((string)$decoded['id'], 0, 8) . '…)'];
        }

        // Pesan error dari Resend (mis. sender belum diverifikasi, key salah)
        $errMsg = (is_array($decoded) && !empty($decoded['message']))
            ? $decoded['message']
            : ('HTTP ' . $httpCode);
        return ['ok' => false, 'message' => 'Resend: ' . $errMsg];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
}
