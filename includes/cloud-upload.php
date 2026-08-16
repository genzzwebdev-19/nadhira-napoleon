<?php
// ============================================
// CLOUD UPLOAD - backup ke Google Drive / Dropbox
// Tanpa dependency eksternal (cURL + OpenSSL).
// Dipakai oleh: includes/backup-helper.php, admin/backup.php
// ============================================

// ------------------------------------------------------------
// HTTP POST helper (cURL)
// ------------------------------------------------------------
function cloudHttpPost(string $url, string $body, array $headers = [], int $timeout = 60): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err !== '') {
        throw new RuntimeException('cURL error: ' . $err);
    }
    return (string)$resp;
}

// ------------------------------------------------------------
// GOOGLE DRIVE (Service Account)
// Alur: tanda tangan JWT RS256 dengan private key service account
//  → tukar jadi access token → upload via Drive API multipart.
// ------------------------------------------------------------

function gdriveGetAccessToken(array $sa): ?string {
    $now = time();
    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $claims = [
        'iss'   => $sa['client_email'] ?? '',
        'scope' => 'https://www.googleapis.com/auth/drive.file',
        'aud'   => $sa['token_uri'] ?? 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ];
    if ($claims['iss'] === '') return null;

    $b64 = fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    $signingInput = $b64(json_encode($header)) . '.' . $b64(json_encode($claims));

    $pkey = openssl_pkey_get_private($sa['private_key'] ?? '');
    if (!$pkey) return null;
    if (!openssl_sign($signingInput, $signature, $pkey, OPENSSL_ALGO_SHA256)) return null;
    $jwt = $signingInput . '.' . $b64($signature);

    $post = http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion'  => $jwt,
    ]);
    $resp = cloudHttpPost($claims['aud'], $post, ['Content-Type: application/x-www-form-urlencoded']);
    $json = json_decode($resp, true);
    return is_array($json) && !empty($json['access_token']) ? $json['access_token'] : null;
}

function gdriveUploadFile(string $token, string $filePath, string $fileName, string $folderId = ''): bool {
    $meta = ['name' => $fileName];
    if ($folderId !== '') {
        $meta['parents'] = [$folderId];
    }
    $boundary = 'nn_' . uniqid();
    $body  = "--$boundary\r\n";
    $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
    $body .= json_encode($meta) . "\r\n";
    $body .= "--$boundary\r\n";
    $body .= "Content-Type: application/octet-stream\r\n\r\n";
    $body .= file_get_contents($filePath) . "\r\n";
    $body .= "--$boundary--\r\n";

    $headers = [
        'Authorization: Bearer ' . $token,
        'Content-Type: multipart/related; boundary=' . $boundary,
    ];
    $resp = cloudHttpPost('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart', $body, $headers, 120);
    $json = json_decode($resp, true);
    return is_array($json) && !empty($json['id']);
}

// ------------------------------------------------------------
// DROPBOX (Access Token)
// ------------------------------------------------------------

function dropboxUploadFile(string $token, string $filePath, string $fileName): bool {
    $arg = json_encode(['path' => '/' . $fileName, 'mode' => 'add', 'autorename' => true]);
    $headers = [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/octet-stream',
        'Dropbox-API-Arg: ' . $arg,
    ];
    $resp = cloudHttpPost('https://content.dropbox.com/2/files/upload', (string)file_get_contents($filePath), $headers, 120);
    $json = json_decode($resp, true);
    return is_array($json) && (!empty($json['id']) || !empty($json['name']));
}

// ------------------------------------------------------------
// Fungsi utama: upload backup ke cloud sesuai pengaturan
// Mengembalikan array: ['ok' => bool, 'message' => string]
// ------------------------------------------------------------

function cloudUploadBackup(string $filePath, string $fileName): array {
    if (getSetting('backup_cloud_enabled', '') !== '1') {
        return ['ok' => false, 'message' => 'Cloud backup nonaktif di pengaturan.'];
    }

    $provider = getSetting('backup_cloud_provider', 'none');
    try {
        if ($provider === 'google_drive') {
            $raw = trim((string)getSetting('backup_cloud_google_json', ''));
            if ($raw === '') {
                return ['ok' => false, 'message' => 'Kredensial Google Drive (JSON service account) belum diisi.'];
            }
            $sa = json_decode($raw, true);
            if (!is_array($sa) || empty($sa['client_email']) || empty($sa['private_key'])) {
                return ['ok' => false, 'message' => 'JSON service account tidak valid.'];
            }
            $token = gdriveGetAccessToken($sa);
            if ($token === null) {
                return ['ok' => false, 'message' => 'Gagal mendapatkan token Google Drive — periksa JSON service account & pastikan Google Drive API aktif.'];
            }
            $folder = trim((string)getSetting('backup_cloud_google_folder', ''));
            $ok = gdriveUploadFile($token, $filePath, $fileName, $folder);
            return $ok
                ? ['ok' => true, 'message' => 'Berhasil diupload ke Google Drive (' . $fileName . ')']
                : ['ok' => false, 'message' => 'Upload ke Google Drive gagal — periksa izin folder (share ke email service account) & file service account.'];
        }

        if ($provider === 'dropbox') {
            $token = trim((string)getSetting('backup_cloud_dropbox_token', ''));
            if ($token === '') {
                return ['ok' => false, 'message' => 'Token Dropbox belum diisi.'];
            }
            $ok = dropboxUploadFile($token, $filePath, $fileName);
            return $ok
                ? ['ok' => true, 'message' => 'Berhasil diupload ke Dropbox (' . $fileName . ')']
                : ['ok' => false, 'message' => 'Upload ke Dropbox gagal — periksa token & jenis app (App folder).'];
        }

        return ['ok' => false, 'message' => 'Penyedia cloud belum dipilih (Google Drive / Dropbox).'];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
}
