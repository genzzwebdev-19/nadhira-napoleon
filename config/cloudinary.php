<?php
// ============================================
// CLOUDINARY IMAGE STORAGE HELPER
// Website Nadhira Napoleon Pekanbaru
// ============================================
// Menyimpan & mengirim foto (produk, cabang, hero, story) lewat Cloudinary
// (CDN + optimasi otomatis). Memakai cURL langsung ke REST API Cloudinary
// (tanpa dependensi composer), mengikuti pola config/midtrans.php.
//
// Konfigurasi disimpan di tabel settings:
//   cloudinary_cloud_name     -> Cloud Name (dari dashboard Cloudinary)
//   cloudinary_api_key        -> API Key
//   cloudinary_api_secret     -> API Secret
//   cloudinary_enabled        -> '1' = foto baru otomatis ke Cloudinary
// ============================================

require_once __DIR__ . '/database.php';

// ============================================
// SKEMA DATABASE - self-healing
// ============================================
function ensureCloudinarySchema() {
    static $done = false;
    if ($done) return true;
    $conn = getConnection();
    if (!$conn) return false;

    $settings = [
        'cloudinary_cloud_name' => '',
        'cloudinary_api_key'    => '',
        'cloudinary_api_secret' => '',
        'cloudinary_enabled'    => '0',
    ];
    foreach ($settings as $key => $value) {
        $conn->query("INSERT INTO settings (setting_key, setting_value) VALUES ('$key', '$value')
                      ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key)");
    }
    $done = true;
    return true;
}

// ============================================
// KONFIGURASI
// ============================================
function cloudinaryCloudName() { return trim(getSetting('cloudinary_cloud_name', '')); }
function cloudinaryApiKey()     { return trim(getSetting('cloudinary_api_key', '')); }
function cloudinaryApiSecret()  { return trim(getSetting('cloudinary_api_secret', '')); }

// Aktif hanya bila toggle ON dan semua kredensial terisi.
function cloudinaryEnabled() {
    if (getSetting('cloudinary_enabled', '0') !== '1') return false;
    return cloudinaryCloudName() !== '' && cloudinaryApiKey() !== '' && cloudinaryApiSecret() !== '';
}

// Base URL upload API per cloud
function cloudinaryApiUrl($resource = 'image') {
    return 'https://api.cloudinary.com/v1_1/' . rawurlencode(cloudinaryCloudName()) . '/' . $resource . '/upload';
}

// ============================================
// SIGNATURE (untuk operasi destroy)
// ============================================
// SHA1 dari parameter (urut abjad, tanpa nilai kosong) + api_secret
function cloudinarySignature($params) {
    ksort($params);
    $str = '';
    foreach ($params as $k => $v) {
        if ($v === '' || $v === null) continue;
        $str .= $k . '=' . $v . '&';
    }
    $str = rtrim($str, '&');
    return sha1($str . cloudinaryApiSecret());
}

// ============================================
// KOMPRESI GAMBAR OTOMATIS (sebelum upload Cloudinary)
// ============================================
// Cloudinary (plan gratis) membatasi upload maks 10MB per file. Foto besar
// (> $maxBytes) otomatis dikecilkan: di-resize agar dimensi maksimalnya tidak
// melebihi $maxDim px, lalu kualitas JPEG diturunkan bertahap sampai ukurannya
// di bawah batas. File yang sudah kecil dikembalikan apa adanya (tanpa proses).
// Mengembalikan path file yang siap diupload — bila berbeda dari file asli,
// pemanggil wajib menghapus file temp tersebut setelah selesai.
function cloudinaryCompressImage($filePath, $maxBytes = 9 * 1024 * 1024, $maxDim = 2000) {
    if (!file_exists($filePath)) return $filePath;
    if (filesize($filePath) <= $maxBytes) return $filePath; // sudah aman
    if (!function_exists('imagecreatetruecolor')) return $filePath; // tanpa GD, kirim apa adanya

    $info = @getimagesize($filePath);
    if (!$info) return $filePath;
    list($w, $h) = $info;
    $mime = $info['mime'] ?? '';

    // Muat sumber sesuai format
    $src = null;
    switch ($mime) {
        case 'image/jpeg': $src = @imagecreatefromjpeg($filePath); break;
        case 'image/png':  $src = @imagecreatefrompng($filePath); break;
        case 'image/webp': $src = @imagecreatefromwebp($filePath); break;
        case 'image/gif':  $src = @imagecreatefromgif($filePath); break;
    }
    if (!$src) return $filePath;

    // Resize bila dimensi lebih besar dari maxDim (jaga rasio aspek)
    $scale = min(1, $maxDim / max($w, $h));
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    if (!$dst) { imagedestroy($src); return $filePath; }

    // Pertahankan transparansi untuk PNG/GIF/WebP saat resize
    if (in_array($mime, ['image/png', 'image/gif', 'image/webp'], true)) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefill($dst, 0, 0, $transparent);
    }
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($src);

    // Tulis ke file temp sebagai JPEG, turunkan kualitas sampai cukup kecil
    $tmp = tempnam(sys_get_temp_dir(), 'cld_') . '.jpg';
    $written = false;
    for ($quality = 85; $quality >= 30; $quality -= 10) {
        if (imagejpeg($dst, $tmp, $quality) && filesize($tmp) <= $maxBytes) {
            $written = true;
            break;
        }
    }
    imagedestroy($dst);

    if (!$written) {
        if (file_exists($tmp)) @unlink($tmp);
        return $filePath; // tetap gagal kecil — kirim asli (Cloudinary akan menolak dengan pesan)
    }
    return $tmp;
}

// ============================================
// UPLOAD FILE LOKAL KE CLOUDINARY
// ============================================
// $filePath : path absolut file di server (bisa juga hasil upload temporer).
// $folder   : folder di Cloudinary, mis. 'nadhira/products'.
// $publicId : nama unik tanpa ekstensi (opsional; default: nama file tanpa ekstensi).
// Mengembalikan ['success' => bool, 'url' => ..., 'public_id' => ..., 'message' => ...]
function cloudinaryUploadFile($filePath, $folder = 'nadhira', $publicId = '') {
    if (!cloudinaryEnabled()) {
        return ['success' => false, 'message' => 'Cloudinary belum diaktifkan di Pengaturan.'];
    }
    if (!file_exists($filePath)) {
        return ['success' => false, 'message' => 'File tidak ditemukan: ' . basename($filePath)];
    }
    if (!function_exists('curl_init')) {
        return ['success' => false, 'message' => 'Ekstensi cURL tidak tersedia di server.'];
    }

    if ($publicId === '') {
        $publicId = pathinfo($filePath, PATHINFO_FILENAME);
    }
    // Bersihkan public_id: hanya huruf/angka/-/_ (aman untuk URL Cloudinary)
    $publicId = preg_replace('/[^a-zA-Z0-9_-]/', '-', $publicId);
    $folder = trim($folder, '/');
    $publicId = ($folder !== '' ? $folder . '/' : '') . $publicId;

    // Kompres otomatis bila file > 9MB (batas upload Cloudinary 10MB)
    $uploadPath = $filePath;
    $compressed = cloudinaryCompressImage($filePath);
    if ($compressed !== $filePath) {
        $uploadPath = $compressed;
    }

    $ch = curl_init(cloudinaryApiUrl('image'));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, [
        'file'       => new CURLFile($uploadPath),
        'folder'     => $folder,
        'public_id'  => basename($publicId),
        'overwrite'  => 'true',
        'resource_type' => 'image',
    ]);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Basic ' . base64_encode(cloudinaryApiKey() . ':' . cloudinaryApiSecret()),
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    // Bersihkan file temp hasil kompresi
    if ($uploadPath !== $filePath && file_exists($uploadPath)) {
        @unlink($uploadPath);
    }

    if ($response === false) {
        return ['success' => false, 'message' => 'Gagal terhubung ke Cloudinary: ' . $curlError];
    }
    $result = json_decode($response, true);
    if ($httpCode !== 200 || empty($result['secure_url'])) {
        $msg = $result['error']['message'] ?? ('HTTP ' . $httpCode . ' dari Cloudinary');
        return ['success' => false, 'message' => 'Cloudinary menolak upload: ' . $msg];
    }
    return [
        'success'   => true,
        'url'       => $result['secure_url'],
        'public_id' => $result['public_id'] ?? $publicId,
        'message'   => 'Upload berhasil',
    ];
}

// Upload dari file yang dikirim lewat form ($_FILES['x']['tmp_name'])
function cloudinaryUploadFromUploaded($tmpName, $origName, $folder = 'nadhira', $prefix = '') {
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        return ['success' => false, 'message' => 'File upload tidak valid.'];
    }
    $ext = strtolower(pathinfo((string)$origName, PATHINFO_EXTENSION));
    $publicId = ($prefix !== '' ? $prefix . '_' : '') . time() . '_' . uniqid();
    if ($ext !== '') $publicId .= '_' . $ext;
    return cloudinaryUploadFile($tmpName, $folder, $publicId);
}

// ============================================
// HAPUS ASET DARI CLOUDINARY
// ============================================
// $publicId : public_id Cloudinary (mis. 'nadhira/products/xxx_1234_jpg')
function cloudinaryDeletePublicId($publicId) {
    if (!cloudinaryEnabled() || $publicId === '') return false;
    if (!function_exists('curl_init')) return false;

    $params = ['public_id' => $publicId, 'timestamp' => time()];
    $params['signature'] = cloudinarySignature($params);
    $params['api_key'] = cloudinaryApiKey();

    $ch = curl_init('https://api.cloudinary.com/v1_1/' . rawurlencode(cloudinaryCloudName()) . '/image/destroy');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) return false;
    $result = json_decode($response, true);
    return ($result['result'] ?? '') === 'ok';
}

// Ekstrak public_id dari URL Cloudinary yang tersimpan di database.
// Contoh: https://res.cloudinary.com/demo/image/upload/v1234/nadhira/products/x.jpg
//         -> nadhira/products/x
function cloudinaryPublicIdFromUrl($url) {
    if (stripos($url, 'res.cloudinary.com') === false) return '';
    $path = parse_url($url, PHP_URL_PATH);
    if ($path === null) return '';
    $pos = strpos($path, '/image/upload/');
    if ($pos === false) $pos = strpos($path, '/video/upload/');
    if ($pos === false) return '';
    $asset = substr($path, $pos + strlen('/image/upload/'));
    // Buang segmen versi (/v1234/) bila ada
    if (preg_match('#^/v[0-9]+/#', '/' . $asset, $m)) {
        $asset = substr($asset, strlen($m[0]) - 1);
    }
    // Buang ekstensi file
    $asset = preg_replace('/\.[a-zA-Z0-9]{2,5}$/', '', $asset);
    return $asset;
}

// Hapus aset Cloudinary dari URL yang tersimpan di database
function cloudinaryDeleteByUrl($url) {
    $publicId = cloudinaryPublicIdFromUrl($url);
    if ($publicId === '') return false;
    return cloudinaryDeletePublicId($publicId);
}

// Apakah sebuah URL berasal dari Cloudinary?
function isCloudinaryUrl($url) {
    return stripos((string)$url, 'res.cloudinary.com') !== false;
}

// ============================================
// URL TAMPILAN (dengan transformasi opsional)
// ============================================
// $publicId : public_id atau URL Cloudinary penuh
// $opts     : mis. ['w'=>800, 'h'=>800, 'c'=>'fill', 'f'=>'auto', 'q'=>'auto']
function cloudinaryImageUrl($publicId, $opts = []) {
    if ($publicId === '') return '';
    $cloud = cloudinaryCloudName();
    if ($cloud === '') return '';

    // Terima URL penuh, ambil public_id-nya
    if (isCloudinaryUrl($publicId)) {
        $publicId = cloudinaryPublicIdFromUrl($publicId);
    }
    if ($publicId === '') return '';

    $transform = '';
    if (!empty($opts)) {
        $parts = [];
        foreach ($opts as $k => $v) {
            if ($v !== '' && $v !== null) $parts[] = $k . '_' . $v;
        }
        if (!empty($parts)) $transform = implode(',', $parts) . '/';
    }
    return 'https://res.cloudinary.com/' . rawurlencode($cloud) . '/image/upload/' . $transform . $publicId;
}
