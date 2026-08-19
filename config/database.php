<?php

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'nadhira_napoleon');
define('DB_PORT', '3306');

// ============================================
// KUNCI INSTALLER DATABASE
// ============================================
// Kunci rahasia untuk mengakses database/init.php & run_install.php dari browser.
// ⚠️ JANGAN biarkan file installer bisa diakses publik tanpa kunci ini!
//    - Kosong (default) => installer HANYA bisa dijalankan dari terminal (CLI).
//    - Isi dengan nilai acak (mis. 'x9f2Kp7QzL') => browser butuh ?key=x9f2Kp7QzL.
if (!defined('INSTALL_KEY')) {
    // 🔑 Kunci installer database — ganti nilai ini dengan acak & rahasia!
    // Catatan keamanan: nilai di repo ini BUKAN untuk produksi — file config/database.php
    // di server dikelola manual (lihat DEPLOY.md) dan harus memakai kunci acak sendiri.
    define('INSTALL_KEY', 'Xr7mKp4Qw2Zt9LvB');
}

// ============================================
// SITE CONFIGURATION
// ============================================
define('SITE_NAME', 'Nadhira Napoleon');
define('SITE_TAGLINE', 'Premium Oleh-Oleh Khas Riau');


$siteDocRoot = rtrim(str_replace('\\', '/', (string)@realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''))), '/');
$siteAppRoot = rtrim(str_replace('\\', '/', (string)@realpath(dirname(__DIR__))), '/'); // folder tempat config/ berada
if ($siteDocRoot !== '' && stripos($siteAppRoot . '/', $siteDocRoot . '/') === 0) {
    $siteBase = substr($siteAppRoot, strlen($siteDocRoot));
} else {
    $siteBase = ''; // DOCUMENT_ROOT tidak tersedia (mis. CLI) → anggap di root
}
if (!defined('BASE_PATH')) define('BASE_PATH', $siteBase);

// SITE_URL dinamis: mengikuti host & folder yang mengakses.
// Di laptop tetap localhost, saat dibuka dari HP otomatis memakai IP laptop.
$siteScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$siteHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
define('SITE_URL', $siteScheme . '://' . $siteHost . BASE_PATH);
define('ASSETS_URL', SITE_URL . '/assets');
define('UPLOADS_URL', SITE_URL . '/uploads');

// ============================================
// SESSION & SECURITY
// ============================================
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
// SameSite=Lax: cookie sesi tidak dikirim pada permintaan lintas-situs (perlindungan CSRF dasar
// untuk endpoint AJAX/POST yang tidak memakai token eksplisit).
ini_set('session.cookie_samesite', 'Lax');
// Secure otomatis bila halaman diakses via HTTPS (produksi).
ini_set('session.cookie_secure', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 1 : 0);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================
// REMEMBER ME - auto restore sesi dari cookie
// ============================================
if (!defined('RBAC_REMEMBER_COOKIE')) define('RBAC_REMEMBER_COOKIE', 'nn_remember');

function restoreRememberedLogin() {
    if (isset($_SESSION['user_id'])) return;
    if (empty($_COOKIE[RBAC_REMEMBER_COOKIE])) return;
    static $ran = false;
    if ($ran) return;
    $ran = true;

    $conn = getConnection();
    if (!$conn) return;
    try {
        $token = $conn->real_escape_string($_COOKIE[RBAC_REMEMBER_COOKIE]);
        $r = $conn->query(
            "SELECT COUNT(*) AS c FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_sessions'"
        );
        if (!$r || (int)$r->fetch_assoc()['c'] === 0) return;

        $r2 = $conn->query(
            "SELECT us.user_id, u.full_name FROM user_sessions us
             JOIN users u ON u.id = us.user_id
             WHERE us.session_token = '$token' AND us.is_active = 1
               AND us.expires_at > NOW() AND u.is_active = 1
             LIMIT 1"
        );
        if ($r2 && $r2->num_rows > 0) {
            $row = $r2->fetch_assoc();
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$row['user_id'];
            $_SESSION['user_name'] = $row['full_name'];
            $_SESSION['session_token'] = $_COOKIE[RBAC_REMEMBER_COOKIE];
        }
    } catch (Exception $e) {
        // abaikan: tabel belum tersedia saat instalasi
    }
}

restoreRememberedLogin();

// ============================================
// DATABASE CONNECTION
// ============================================
function getConnection() {
    static $conn = null;
    if ($conn === null) {
        try {
            $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
            $conn->set_charset("utf8mb4");
            if ($conn->connect_error) {
                throw new Exception("Connection failed: " . $conn->connect_error);
            }
            // ============================================
            // SAMAKAN TIMEZONE MYSQL DENGAN PHP
            // ============================================
            // Di hosting bersama (mis. InfinityFree) timezone PHP bisa berbeda dari
            // timezone server MySQL. Tanpa penyelarasan ini, semua perbandingan waktu
            // yang mencampur NOW()/CURRENT_TIMESTAMP (MySQL) dengan date()/time() (PHP)
            // menjadi tidak konsisten — gejalanya: sesi login langsung dianggap
            // "berakhir karena tidak aktif" (idle timeout salah hitung), promo/membership
            // kadaluarsa di waktu yang keliru, dst.
            // date('P') menghasilkan offset zona waktu PHP, mis. "+07:00" atau "+00:00".
            // MySQL menerima offset numerik tanpa memerlukan tabel timezone. Jika host
            // menolak perintah ini (jarang), @ menekan error dan tidak ada efek samping.
            $tzOffset = date('P');
            @$conn->query("SET time_zone = '" . $tzOffset . "'");
        } catch (Exception $e) {
            error_log("Database connection error: " . $e->getMessage());
            return null;
        }
    }
    return $conn;
}

// ============================================
// HELPER FUNCTIONS
// ============================================

// Escape string for safe SQL queries
function esc($str) {
    $conn = getConnection();
    if ($conn) {
        return $conn->real_escape_string($str);
    }
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

// Get site settings
function getSetting($key, $default = '') {
    $conn = getConnection();
    if (!$conn) return $default;
    
    $key = $conn->real_escape_string($key);
    $result = $conn->query("SELECT setting_value FROM settings WHERE setting_key = '$key' LIMIT 1");
    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc()['setting_value'];
    }
    return $default;
}

// Link WhatsApp toko dengan pesan awal.
// Dipakai tombol WhatsApp mengambang (footer) & tombol di halaman detail produk.
function getWhatsAppLink($message = '') {
    $waNum = getSetting('contact_whatsapp', '6282112345678');
    $waNum = preg_replace('/[^0-9]/', '', (string)$waNum);
    if ($waNum === '') { $waNum = '6282112345678'; } // fallback bila nomor belum diisi
    return 'https://wa.me/' . $waNum . '?text=' . rawurlencode($message);
}

// Check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Get current user data
function getCurrentUser() {
    if (!isLoggedIn()) return null;
    
    $conn = getConnection();
    if (!$conn) return null;
    
    $userId = (int)$_SESSION['user_id'];
    $result = $conn->query("SELECT * FROM users WHERE id = $userId LIMIT 1");
    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    return null;
}

// Get wishlist count
function getWishlistCount() {
    $count = 0;
    if (!isLoggedIn()) return 0;
    $conn = getConnection();
    if (!$conn) return 0;
    $userId = (int)$_SESSION['user_id'];
    $result = $conn->query("SELECT COUNT(*) as total FROM wishlists WHERE user_id = $userId");
    if ($result && $result->num_rows > 0) {
        $count = (int)$result->fetch_assoc()['total'];
    }
    return $count;
}

// Get cart count
function getCartCount() {
    $count = 0;
    $conn = getConnection();
    if (!$conn) return 0;
    
    if (isLoggedIn()) {
        $userId = (int)$_SESSION['user_id'];
        $result = $conn->query("SELECT SUM(quantity) as total FROM carts WHERE user_id = $userId");
        if ($result && $result->num_rows > 0) {
            $count = (int)$result->fetch_assoc()['total'];
        }
    } else {
        $sessionId = session_id();
        $sessionId = $conn->real_escape_string($sessionId);
        $result = $conn->query("SELECT SUM(quantity) as total FROM carts WHERE session_id = '$sessionId'");
        if ($result && $result->num_rows > 0) {
            $count = (int)$result->fetch_assoc()['total'];
        }
    }
    return $count;
}

// Tambahkan produk ke keranjang (user login / sesi tamu).
// Dipakai oleh ajax/cart.php & pages/buy-package.php agar logika tetap satu sumber.
// Mengembalikan true jika berhasil. VALIDASI produk (aktif/paket/membership)
// dilakukan oleh pemanggil sesuai konteksnya.
function addProductToCart($productId, $quantity = 1) {
    $conn = getConnection();
    if (!$conn) return false;
    $productId = (int)$productId;
    $quantity = max(1, (int)$quantity);
    if ($productId <= 0) return false;

    if (isLoggedIn()) {
        $userId = (int)$_SESSION['user_id'];
        $check = $conn->query("SELECT id, quantity FROM carts WHERE user_id = $userId AND product_id = $productId");
        if ($check && $check->num_rows > 0) {
            $row = $check->fetch_assoc();
            $conn->query("UPDATE carts SET quantity = " . ((int)$row['quantity'] + $quantity) . " WHERE id = " . (int)$row['id']);
        } else {
            $conn->query("INSERT INTO carts (user_id, product_id, quantity) VALUES ($userId, $productId, $quantity)");
        }
    } else {
        $sessionId = $conn->real_escape_string(session_id());
        $check = $conn->query("SELECT id, quantity FROM carts WHERE session_id = '$sessionId' AND product_id = $productId");
        if ($check && $check->num_rows > 0) {
            $row = $check->fetch_assoc();
            $conn->query("UPDATE carts SET quantity = " . ((int)$row['quantity'] + $quantity) . " WHERE id = " . (int)$row['id']);
        } else {
            $conn->query("INSERT INTO carts (session_id, product_id, quantity) VALUES ('$sessionId', $productId, $quantity)");
        }
    }
    return true;
}

// Gabungkan keranjang sesi (guest) ke keranjang akun user setelah login/registrasi.
// Produk yang sudah ada di keranjang user dijumlahkan kuantitasnya (bukan duplikat baris).
// Dipanggil dari auth/login.php dengan ID sesi LAMA (sebelum session_regenerate_id).
function mergeGuestCartToUser($userId, $sessionId) {
    $conn = getConnection();
    if (!$conn || (int)$userId <= 0 || $sessionId === '') return 0;
    $userId = (int)$userId;
    $sessionId = $conn->real_escape_string($sessionId);
    $moved = 0;
    // Transaksi agar tidak ada baris duplikat/tercecer bila salah satu query gagal
    $conn->begin_transaction();
    try {
        $guest = $conn->query("SELECT id, product_id, quantity, notes FROM carts WHERE session_id = '$sessionId' AND (user_id IS NULL OR user_id = 0)");
        if ($guest && $guest->num_rows > 0) {
            while ($g = $guest->fetch_assoc()) {
                $pid = (int)$g['product_id'];
                $qty = max(1, (int)$g['quantity']);
                $notes_e = $conn->real_escape_string((string)($g['notes'] ?? ''));
                $existing = $conn->query("SELECT id, quantity FROM carts WHERE user_id = $userId AND product_id = $pid LIMIT 1");
                if ($existing && $existing->num_rows > 0) {
                    $ex = $existing->fetch_assoc();
                    if (!$conn->query("UPDATE carts SET quantity = " . ((int)$ex['quantity'] + $qty) . ", notes = '$notes_e', updated_at = NOW() WHERE id = " . (int)$ex['id'])) {
                        throw new Exception('merge update gagal');
                    }
                } else {
                    if (!$conn->query("INSERT INTO carts (user_id, product_id, quantity, notes, created_at, updated_at) VALUES ($userId, $pid, $qty, '$notes_e', NOW(), NOW())")) {
                        throw new Exception('merge insert gagal');
                    }
                }
                $moved++;
            }
            $conn->query("DELETE FROM carts WHERE session_id = '$sessionId' AND (user_id IS NULL OR user_id = 0)");
        }
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        $moved = 0;
    }
    return $moved;
}

// ============================================
// ALAMAT PENGIRIMAN TERSIMPAN (profil user)
// ============================================
// Dipakai agar checkout lebih cepat: alamat tersimpan di profil → prefill otomatis
// di halaman checkout. Self-healing schema (CREATE TABLE IF NOT EXISTS).
function ensureShippingAddressSchema() {
    static $done = false;
    if ($done) return true;
    $conn = getConnection();
    if (!$conn) return false;
    $ok = $conn->query("CREATE TABLE IF NOT EXISTS shipping_addresses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        label VARCHAR(100) DEFAULT 'Utama',
        recipient_name VARCHAR(255) NOT NULL DEFAULT '',
        phone VARCHAR(50) DEFAULT '',
        address TEXT,
        city VARCHAR(150) DEFAULT '',
        province VARCHAR(150) DEFAULT '',
        postal_code VARCHAR(20) DEFAULT '',
        latitude DECIMAL(10,8) NULL,
        longitude DECIMAL(11,8) NULL,
        is_default TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user (user_id),
        INDEX idx_default (user_id, is_default)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Self-healing: tambahkan kolom GPS pada tabel lama yang belum punya
    $r = $conn->query("SELECT COUNT(*) c FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shipping_addresses' AND COLUMN_NAME = 'latitude'");
    if ($r && (int)$r->fetch_assoc()['c'] === 0) {
        $conn->query("ALTER TABLE shipping_addresses ADD COLUMN latitude DECIMAL(10,8) NULL AFTER postal_code");
        $conn->query("ALTER TABLE shipping_addresses ADD COLUMN longitude DECIMAL(11,8) NULL AFTER latitude");
    }
    $done = true;
    return (bool)$ok;
}

// Daftar provinsi Indonesia (satu sumber: checkout & form alamat profil)
function getIndonesiaProvinces() {
    return ['Aceh','Sumatera Utara','Sumatera Barat','Riau','Kepulauan Riau','Jambi','Bengkulu','Sumatera Selatan','Bangka Belitung','Lampung','Banten','Jakarta','Jawa Barat','Jawa Tengah','Jawa Timur','Bali','Nusa Tenggara Barat','Nusa Tenggara Timur','Kalimantan Barat','Kalimantan Tengah','Kalimantan Selatan','Kalimantan Timur','Kalimantan Utara','Sulawesi Utara','Sulawesi Tengah','Sulawesi Selatan','Sulawesi Tenggara','Gorontalo','Sulawesi Barat','Maluku','Maluku Utara','Papua','Papua Barat','Papua Selatan','Papua Tengah','Papua Pegunungan'];
}

// Semua alamat user (default dulu, lalu terbaru)
function getUserShippingAddresses($userId) {
    ensureShippingAddressSchema();
    $conn = getConnection();
    if (!$conn || (int)$userId <= 0) return [];
    $userId = (int)$userId;
    $rows = [];
    $r = $conn->query("SELECT * FROM shipping_addresses WHERE user_id = $userId ORDER BY is_default DESC, updated_at DESC, id DESC");
    if ($r) while ($row = $r->fetch_assoc()) $rows[] = $row;
    return $rows;
}

// Alamat default user (null jika belum ada)
function getDefaultShippingAddress($userId) {
    ensureShippingAddressSchema();
    $conn = getConnection();
    if (!$conn || (int)$userId <= 0) return null;
    $userId = (int)$userId;
    $r = $conn->query("SELECT * FROM shipping_addresses WHERE user_id = $userId AND is_default = 1 LIMIT 1");
    if ($r && $r->num_rows > 0) return $r->fetch_assoc();
    return null;
}

// Simpan/update alamat user. $id > 0 = update. Mengembalikan id (0 jika gagal).
// Alamat pertama otomatis menjadi default; is_default=1 menonaktifkan default lain.
function saveShippingAddress($userId, $data, $id = 0) {
    ensureShippingAddressSchema();
    $conn = getConnection();
    if (!$conn || (int)$userId <= 0) return 0;
    $userId = (int)$userId;
    $id = (int)$id;
    $cut = function ($v, $len) { return function_exists('mb_substr') ? mb_substr(trim($v), 0, $len) : substr(trim($v), 0, $len); };
    $label   = $conn->real_escape_string($cut($data['label'] ?? 'Utama', 100));
    $name    = $conn->real_escape_string($cut($data['recipient_name'] ?? '', 255));
    $phone   = $conn->real_escape_string($cut($data['phone'] ?? '', 50));
    $address = $conn->real_escape_string(trim($data['address'] ?? ''));
    $city    = $conn->real_escape_string($cut($data['city'] ?? '', 150));
    $prov    = $conn->real_escape_string($cut($data['province'] ?? '', 150));
    $postal  = $conn->real_escape_string($cut($data['postal_code'] ?? '', 20));
    // Koordinat GPS (opsional, dari fitur "Gunakan Lokasi Saya")
    $lat = isset($data['latitude']) && $data['latitude'] != '' ? (float)$data['latitude'] : 0;
    $lng = isset($data['longitude']) && $data['longitude'] != '' ? (float)$data['longitude'] : 0;
    $latSql = $lat != 0 ? $lat : 'NULL';
    $lngSql = $lng != 0 ? $lng : 'NULL';
    if ($name === '' || $address === '' || $city === '') return 0;

    $isDefault = !empty($data['is_default']) ? 1 : 0;
    if ($isDefault) {
        $conn->query("UPDATE shipping_addresses SET is_default = 0 WHERE user_id = $userId");
    } else {
        $cntR = $conn->query("SELECT COUNT(*) c FROM shipping_addresses WHERE user_id = $userId");
        if ($cntR && (int)$cntR->fetch_assoc()['c'] === 0) $isDefault = 1; // alamat pertama = default
    }

    if ($id > 0) {
        // Update — pastikan alamat benar-benar milik user
        $ex = $conn->query("SELECT id FROM shipping_addresses WHERE id = $id AND user_id = $userId LIMIT 1");
        if (!$ex || $ex->num_rows === 0) return 0;
        $conn->query("UPDATE shipping_addresses SET label='$label', recipient_name='$name', phone='$phone', address='$address', city='$city', province='$prov', postal_code='$postal', latitude=$latSql, longitude=$lngSql, is_default=$isDefault WHERE id=$id");
        return $id;
    }

    // Cegah duplikat: alamat identik (penerima + alamat + kota + provinsi + pos) cukup di-update
    $dup = $conn->query("SELECT id FROM shipping_addresses WHERE user_id = $userId
        AND recipient_name = '$name' AND address = '$address' AND city = '$city'
        AND province = '$prov' AND postal_code = '$postal' LIMIT 1");
    if ($dup && $dup->num_rows > 0) {
        $dupId = (int)$dup->fetch_assoc()['id'];
        $conn->query("UPDATE shipping_addresses SET label='$label', phone='$phone', latitude=$latSql, longitude=$lngSql, is_default=$isDefault WHERE id = $dupId");
        return $dupId;
    }

    $conn->query("INSERT INTO shipping_addresses (user_id, label, recipient_name, phone, address, city, province, postal_code, latitude, longitude, is_default) VALUES ($userId, '$label', '$name', '$phone', '$address', '$city', '$prov', '$postal', $latSql, $lngSql, $isDefault)");
    return (int)$conn->insert_id;
}

// Hapus alamat milik user; pastikan masih ada satu default setelahnya
function deleteShippingAddress($id, $userId) {
    ensureShippingAddressSchema();
    $conn = getConnection();
    if (!$conn) return false;
    $id = (int)$id;
    $userId = (int)$userId;
    $conn->query("DELETE FROM shipping_addresses WHERE id = $id AND user_id = $userId");
    if ($conn->affected_rows > 0) {
        $r = $conn->query("SELECT COUNT(*) c FROM shipping_addresses WHERE user_id = $userId AND is_default = 1");
        if ($r && (int)$r->fetch_assoc()['c'] === 0) {
            $conn->query("UPDATE shipping_addresses SET is_default = 1 WHERE user_id = $userId ORDER BY id LIMIT 1");
        }
        return true;
    }
    return false;
}

// Format currency
function formatRupiah($amount) {
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

// Format date
function formatDate($date, $format = 'd F Y') {
    $months = [
        'January' => 'Januari',
        'February' => 'Februari',
        'March' => 'Maret',
        'April' => 'April',
        'May' => 'Mei',
        'June' => 'Juni',
        'July' => 'Juli',
        'August' => 'Agustus',
        'September' => 'September',
        'October' => 'Oktober',
        'November' => 'November',
        'December' => 'Desember'
    ];
    
    $formatted = date($format, strtotime($date));
    $englishMonths = array_keys($months);
    foreach ($englishMonths as $engMonth) {
        $formatted = str_replace($engMonth, $months[$engMonth], $formatted);
    }
    return $formatted;
}

// Generate slug
function generateSlug($string) {
    $string = strtolower($string);
    $string = preg_replace('/[^a-z0-9-]/', '-', $string);
    $string = preg_replace('/-+/', '-', $string);
    return trim($string, '-');
}

// Send JSON response
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// ============================================
// RATE LIMITING SEDERHANA (anti-spam / anti-bruteforce)
// ============================================
// Menyimpan counter pemakaian per kunci (mis. IP) di tabel rate_limits.
// Dipakai form kontak, newsletter, registrasi, reset password, konfirmasi
// pembayaran, dan login — area yang sebelumnya tanpa batas.

function ensureRateLimitSchema() {
    static $done = false;
    if ($done) return true;
    $conn = getConnection();
    if (!$conn) return false;
    $done = true;
    return $conn->query("CREATE TABLE IF NOT EXISTS rate_limits (
        id INT AUTO_INCREMENT PRIMARY KEY,
        rkey VARCHAR(120) NOT NULL,
        hit_count INT NOT NULL DEFAULT 1,
        window_start DATETIME NOT NULL,
        UNIQUE KEY uk_rkey (rkey)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

// Cek & catat pemakaian. TRUE = masih diizinkan, FALSE = melebihi batas (harus ditolak).
function rateLimitAllow($key, $max, $windowSeconds = 3600) {
    ensureRateLimitSchema();
    $conn = getConnection();
    if (!$conn) return true; // DB bermasalah -> fail-open agar situs tetap berfungsi
    $key = $conn->real_escape_string(substr((string)$key, 0, 120));
    $max = max(1, (int)$max);
    $windowSeconds = max(1, (int)$windowSeconds);
    $now = date('Y-m-d H:i:s');
    $windowStart = date('Y-m-d H:i:s', time() - $windowSeconds);

    $conn->query("INSERT INTO rate_limits (rkey, hit_count, window_start) VALUES ('$key', 1, '$now')
                  ON DUPLICATE KEY UPDATE
                    hit_count = IF(window_start < '$windowStart', 1, hit_count + 1),
                    window_start = IF(window_start < '$windowStart', '$now', window_start)");
    $r = $conn->query("SELECT hit_count FROM rate_limits WHERE rkey = '$key' LIMIT 1");
    if ($r && $r->num_rows > 0) {
        return (int)$r->fetch_assoc()['hit_count'] <= $max;
    }
    return true;
}

// Variasi berbasis IP (paling umum dipakai).
function rateLimitIp($area, $max, $windowSeconds = 3600) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return rateLimitAllow('rl:' . $area . ':' . $ip, $max, $windowSeconds);
}

// ============================================
// KODE PROMO (diskon di keranjang & checkout)
// ============================================

// Pastikan kolom `code` di promotions & `promo_code` di orders tersedia (self-healing untuk DB lama)
function ensurePromoColumns() {
    $conn = getConnection();
    if (!$conn) return false;
    $ok = true;
    $r = $conn->query("SELECT COUNT(*) c FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'promotions' AND COLUMN_NAME = 'code'");
    if ($r && (int)$r->fetch_assoc()['c'] === 0) {
        $ok = $conn->query("ALTER TABLE promotions ADD COLUMN code VARCHAR(50) NULL AFTER title") && $ok;
        $conn->query("ALTER TABLE promotions ADD UNIQUE INDEX idx_code (code)");
    }
    $r = $conn->query("SELECT COUNT(*) c FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'promo_code'");
    if ($r && (int)$r->fetch_assoc()['c'] === 0) {
        $ok = $conn->query("ALTER TABLE orders ADD COLUMN promo_code VARCHAR(50) NULL AFTER discount") && $ok;
    }
    // Batasi pemakaian promo: max_uses (NULL = tanpa batas) + used_count (jumlah pesanan yang memakai kode ini)
    $r = $conn->query("SELECT COUNT(*) c FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'promotions' AND COLUMN_NAME = 'max_uses'");
    if ($r && (int)$r->fetch_assoc()['c'] === 0) {
        $ok = $conn->query("ALTER TABLE promotions ADD COLUMN max_uses INT NULL AFTER min_purchase") && $ok;
        $ok = $conn->query("ALTER TABLE promotions ADD COLUMN used_count INT NOT NULL DEFAULT 0 AFTER max_uses") && $ok;
    }
    return $ok;
}

// Ambil promo berdasarkan kode (case-insensitive). Null jika tidak ditemukan.
function getPromoByCode($code) {
    $conn = getConnection();
    if (!$conn) return null;
    $code = strtoupper(trim((string)$code));
    if ($code === '') return null;
    $code_e = $conn->real_escape_string($code);
    $r = $conn->query("SELECT * FROM promotions WHERE UPPER(code) = '$code_e' LIMIT 1");
    if ($r && $r->num_rows > 0) return $r->fetch_assoc();
    return null;
}

// Validasi kode promo terhadap subtotal keranjang saat ini.
// Mengembalikan ['ok' => bool, 'promo' => array|null, 'discount' => float, 'error' => string]
function validatePromoCode($code, $subtotal) {
    $subtotal = (float)$subtotal;
    $promo = getPromoByCode($code);
    if (!$promo) {
        return ['ok' => false, 'promo' => null, 'discount' => 0, 'error' => 'Kode promo tidak ditemukan'];
    }
    $now = time();
    $start = strtotime((string)$promo['start_date']);
    $end = strtotime((string)$promo['end_date']);
    if (!$promo['is_active']) {
        return ['ok' => false, 'promo' => $promo, 'discount' => 0, 'error' => 'Kode promo sedang nonaktif'];
    }
    // Batas pemakaian: max_uses terisi & sudah tercapai → tolak
    $maxUses = isset($promo['max_uses']) && $promo['max_uses'] !== null ? (int)$promo['max_uses'] : 0;
    if ($maxUses > 0 && (int)($promo['used_count'] ?? 0) >= $maxUses) {
        return ['ok' => false, 'promo' => $promo, 'discount' => 0, 'error' => 'Kode promo sudah mencapai batas pemakaian'];
    }
    if ($start !== false && $now < $start) {
        return ['ok' => false, 'promo' => $promo, 'discount' => 0, 'error' => 'Kode promo belum berlaku']; 
    }
    if ($end !== false && $now > $end) {
        return ['ok' => false, 'promo' => $promo, 'discount' => 0, 'error' => 'Kode promo sudah kadaluarsa'];
    }
    if ((float)$promo['min_purchase'] > 0 && $subtotal < (float)$promo['min_purchase']) {
        return ['ok' => false, 'promo' => $promo, 'discount' => 0, 'error' => 'Minimal belanja Rp ' . number_format((float)$promo['min_purchase'], 0, ',', '.') . ' untuk memakai kode ini'];
    }
    if ($subtotal <= 0) {
        return ['ok' => false, 'promo' => $promo, 'discount' => 0, 'error' => 'Keranjang belanja kosong'];
    }
    $discount = $promo['discount_type'] === 'nominal'
        ? (float)$promo['discount_value']
        : round($subtotal * (float)$promo['discount_value'] / 100);
    $discount = max(0, min($discount, $subtotal));
    return ['ok' => true, 'promo' => $promo, 'discount' => $discount, 'error' => ''];
}

// --- Kode promo aktif di sesi (keranjang & checkout) ---
function getSessionPromoCode() {
    return isset($_SESSION['promo_code']) ? strtoupper(trim((string)$_SESSION['promo_code'])) : '';
}
function setSessionPromoCode($code) {
    $_SESSION['promo_code'] = strtoupper(trim((string)$code));
}
function clearSessionPromoCode() {
    unset($_SESSION['promo_code']);
}

// Tambah pemakaian kode promo (dipanggil saat pesanan berhasil dibuat)
function incrementPromoUsage($code) {
    $conn = getConnection();
    if (!$conn) return;
    $code = strtoupper(trim((string)$code));
    if ($code === '') return;
    $conn->query("UPDATE promotions SET used_count = used_count + 1 WHERE UPPER(code) = '" . $conn->real_escape_string($code) . "'");
}

// Kurangi pemakaian kode promo (dipanggil saat pesanan dibatalkan)
function decrementPromoUsage($code) {
    $conn = getConnection();
    if (!$conn) return;
    $code = strtoupper(trim((string)$code));
    if ($code === '') return;
    $conn->query("UPDATE promotions SET used_count = GREATEST(used_count - 1, 0) WHERE UPPER(code) = '" . $conn->real_escape_string($code) . "'");
}

// ============================================
// HARGA KHUSUS MEMBER (diskon per level)
// ============================================
// Persentase diskon per level diatur dari Admin > Pengaturan.
// Default: Silver 0%, Gold 5%, Platinum 10%, Diamond 15%.
function getMemberDiscountRate($userId = null) {
    if ($userId === null) {
        if (!isLoggedIn()) return 0;
        $u = getCurrentUser();
        $level = $u['membership'] ?? 'silver';
    } else {
        $userId = (int)$userId;
        if ($userId <= 0) return 0;
        $conn = getConnection();
        if (!$conn) return 0;
        $r = $conn->query("SELECT membership FROM users WHERE id = $userId LIMIT 1");
        if (!$r || $r->num_rows === 0) return 0;
        $level = $r->fetch_assoc()['membership'];
    }
    $levels = getMembershipLevels();
    if (!isset($levels[$level])) $level = 'silver';
    return max(0, (float)getSetting('member_discount_' . $level, 0));
}

// Label level untuk ditampilkan (mis. "Gold")
function getMemberLevelLabel($level) {
    $levels = getMembershipLevels();
    return isset($levels[$level]) ? $levels[$level]['label'] : ucfirst((string)$level);
}

// Besar diskon member dari subtotal (dibulatkan ke rupiah)
function getMemberDiscountForSubtotal($subtotal, $userId = null) {
    $rate = getMemberDiscountRate($userId);
    return round((float)$subtotal * $rate / 100);
}

// ============================================
// MEMBERSHIP & POIN
// ============================================

// Pastikan kolom points_awarded tersedia (self-healing untuk DB lama)
function ensurePointsAwardedColumn() {
    $conn = getConnection();
    if (!$conn) return false;
    $r = $conn->query("SELECT COUNT(*) c FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'points_awarded'");
    if ($r && (int)$r->fetch_assoc()['c'] > 0) return true;
    return $conn->query("ALTER TABLE orders ADD COLUMN points_awarded TINYINT(1) NOT NULL DEFAULT 0 AFTER sold_counted");
}

// Kurs tukar poin: 1 poin = Rp 100 saat checkout
if (!defined('POINT_VALUE')) define('POINT_VALUE', 100);
// Maksimal diskon poin per pesanan: 30% dari subtotal
if (!defined('POINT_MAX_PCT')) define('POINT_MAX_PCT', 30);

// Batas belanja kumulatif per level (sesuai artikel program membership)
function getMembershipLevels() {
    return [
        'silver'   => ['label' => 'Silver',   'min_spend' => 0,       'multiplier' => 1, 'icon' => 'fa-star'],
        'gold'     => ['label' => 'Gold',     'min_spend' => 500000,  'multiplier' => 2, 'icon' => 'fa-medal'],
        'platinum' => ['label' => 'Platinum', 'min_spend' => 2000000, 'multiplier' => 3, 'icon' => 'fa-gem'],
        'diamond'  => ['label' => 'Diamond',  'min_spend' => 5000000, 'multiplier' => 5, 'icon' => 'fa-crown'],
    ];
}

// Level berdasarkan total belanja kumulatif
function getMembershipLevelForSpent($totalSpent) {
    $level = 'silver';
    foreach (getMembershipLevels() as $key => $def) {
        if ((float)$totalSpent >= (float)$def['min_spend']) $level = $key;
    }
    return $level;
}

// Level berikutnya setelah level saat ini (null jika sudah level tertinggi)
function getMembershipNextLevel($currentLevel) {
    $levels = array_keys(getMembershipLevels());
    $idx = array_search($currentLevel, $levels);
    if ($idx !== false && isset($levels[$idx + 1])) return $levels[$idx + 1];
    return null;
}

// Hitung poin dari nilai belanja untuk sebuah level.
// Aturan: 1 poin per Rp 10.000 x multiplier level.
function calculateOrderPoints($level, $amount) {
    $levels = getMembershipLevels();
    $multiplier = $levels[$level]['multiplier'] ?? 1;
    return floor(max(0, (float)$amount) / 10000) * $multiplier;
}

// Beri poin & total belanja setelah transaksi, lalu upgrade level otomatis.
// Level efektif = maksimum antara level langganan berbayar & level dari total belanja.
// Mengembalikan jumlah poin yang didapat (0 jika gagal).
function awardOrderRewards($userId, $orderTotal, $orderNumber = '', $orderId = 0) {
    $conn = getConnection();
    if (!$conn) return 0;
    $userId = (int)$userId;
    if ($userId <= 0) return 0;
    $total = max(0, (float)$orderTotal);
    $orderId = (int)$orderId;

    // Idempoten: poin HANYA diberikan SEKALI per pesanan, yaitu saat pembayaran
    // sudah LUNAS / terverifikasi (webhook Midtrans atau verifikasi admin).
    // Flag diklaim secara ATOMIK agar aman dari balapan (webhook & verifikasi admin bersamaan).
    if ($orderId > 0) {
        ensurePointsAwardedColumn();
        $conn->query("UPDATE orders SET points_awarded = 1 WHERE id = $orderId AND points_awarded = 0");
        if ($conn->affected_rows === 0) return 0; // sudah pernah diberi poin
    }

    syncUserMembership($userId); // pastikan level efektif (langganan aktif) sebelum hitung poin
    $r = $conn->query("SELECT membership, total_spent FROM users WHERE id = $userId LIMIT 1");
    if (!$r || $r->num_rows === 0) return 0;
    $u = $r->fetch_assoc();

    $pointsEarned = calculateOrderPoints($u['membership'], $total);
    $newSpendLevel = getMembershipLevelForSpent((float)$u['total_spent'] + $total);
    $newLevel = membershipLevelRank($u['membership']) >= membershipLevelRank($newSpendLevel) ? $u['membership'] : $newSpendLevel;

    $sql = "UPDATE users SET points = points + $pointsEarned, total_spent = total_spent + $total, membership = '$newLevel' WHERE id = $userId";
    if (!$conn->query($sql)) {
        // Gagal memberi poin → buka kembali kunci agar bisa dicoba ulang
        if ($orderId > 0) $conn->query("UPDATE orders SET points_awarded = 0 WHERE id = $orderId");
        return 0;
    }
    if ($pointsEarned > 0) {
        $label = $orderNumber !== '' ? $orderNumber : ('#' . (int)$orderId);
        logPointHistory($userId, $pointsEarned, 'earned', 'Poin belanja dari pesanan ' . $label, (int)$orderId);
    }
    return $pointsEarned;
}

// Estimasi poin yang akan didapat dari nilai order (untuk tampilan di checkout)
function estimateOrderPoints($userId, $orderTotal) {
    $conn = getConnection();
    if (!$conn || (int)$userId <= 0) return 0;
    $userId = (int)$userId;
    $r = $conn->query("SELECT membership FROM users WHERE id = $userId LIMIT 1");
    if (!$r || $r->num_rows === 0) return 0;
    return calculateOrderPoints($r->fetch_assoc()['membership'], $orderTotal);
}

// Balik reward membership saat pesanan dibatalkan (poin & total belanja dikembalikan).
// Catatan: pengurangan poin memakai multiplier level saat ini (bukan saat pesanan dibuat),
// karena nilai poin asli tidak disimpan per order — akurat untuk kasus umum.
function reverseOrderRewards($userId, $orderTotal, $orderNumber = '', $orderId = 0) {
    $conn = getConnection();
    if (!$conn) return;
    $userId = (int)$userId;
    if ($userId <= 0) return;
    $total = max(0, (float)$orderTotal);

    $r = $conn->query("SELECT membership, total_spent, points FROM users WHERE id = $userId LIMIT 1");
    if (!$r || $r->num_rows === 0) return;
    $u = $r->fetch_assoc();

    $pointsToDeduct = calculateOrderPoints($u['membership'], $total);
    // Catat jumlah yang benar-benar terpotong (saldo dibatasi 0 oleh GREATEST)
    $actualDeduct = min($pointsToDeduct, max(0, (int)$u['points']));
    $conn->query("UPDATE users SET points = GREATEST(points - $pointsToDeduct, 0), total_spent = GREATEST(total_spent - $total, 0) WHERE id = $userId");
    // Buka kunci flag poin agar pesanan bisa diberi poin lagi jika lunas kembali
    if ((int)$orderId > 0) {
        ensurePointsAwardedColumn();
        $conn->query("UPDATE orders SET points_awarded = 0 WHERE id = " . (int)$orderId);
    }
    syncUserMembership($userId); // level disinkronkan ulang; level langganan berbayar tetap dipertahankan
    if ($actualDeduct > 0) {
        $label = $orderNumber !== '' ? $orderNumber : ('#' . (int)$orderId);
        logPointHistory($userId, -$actualDeduct, 'reversed', 'Poin ditarik karena pesanan ' . $label . ' dibatalkan', (int)$orderId);
    }
}

// ============================================
// MEMBERSHIP BERBAYAR (PAKET LANGGANAN)
// ============================================

// Pastikan tabel paket & riwayat langganan tersedia (self-healing untuk DB lama)
function ensureMembershipSchema() {
    static $done = false;
    if ($done) return true;
    $done = true;
    $conn = getConnection();
    if (!$conn) return false;

    $conn->query("CREATE TABLE IF NOT EXISTS membership_plans (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NULL,
        level ENUM('gold','platinum','diamond') NOT NULL,
        period ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly',
        price DECIMAL(15,2) NOT NULL DEFAULT 0,
        duration_days INT NOT NULL DEFAULT 30,
        is_active BOOLEAN DEFAULT TRUE,
        sort_order INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_plan (level, period),
        INDEX idx_product (product_id),
        INDEX idx_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS membership_purchases (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        order_id INT NULL,
        level ENUM('gold','platinum','diamond') NOT NULL,
        period ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly',
        duration_days INT NOT NULL DEFAULT 30,
        price DECIMAL(15,2) NOT NULL DEFAULT 0,
        starts_at DATETIME NULL,
        expires_at DATETIME NULL,
        status ENUM('active','expired','cancelled') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user (user_id),
        INDEX idx_order (order_id),
        INDEX idx_status (status),
        INDEX idx_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS point_redeems (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        order_id INT NULL,
        points INT NOT NULL DEFAULT 0,
        amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        status ENUM('used','refunded') DEFAULT 'used',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id),
        INDEX idx_order (order_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS point_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        order_id INT NULL,
        points INT NOT NULL DEFAULT 0,
        type ENUM('earned','spent','refunded','reversed','adjusted') NOT NULL DEFAULT 'adjusted',
        description VARCHAR(255) DEFAULT '',
        balance_after INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id),
        INDEX idx_order (order_id),
        INDEX idx_type (type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Seeder paket default jika tabel kosong (harga bisa diubah dari admin)
    $r = $conn->query("SELECT COUNT(*) c FROM membership_plans");
    if ($r && (int)$r->fetch_assoc()['c'] === 0) {
        $conn->query("INSERT INTO membership_plans (level, period, price, duration_days, is_active, sort_order) VALUES
            ('gold', 'monthly', 99000, 30, 1, 1),
            ('gold', 'yearly', 990000, 365, 1, 2),
            ('platinum', 'monthly', 199000, 30, 1, 3),
            ('platinum', 'yearly', 1990000, 365, 1, 4),
            ('diamond', 'monthly', 399000, 30, 1, 5),
            ('diamond', 'yearly', 3990000, 365, 1, 6)");
    }
    return true;
}

// Peringkat level (semakin besar semakin tinggi)
function membershipLevelRank($level) {
    $ranks = ['silver' => 0, 'gold' => 1, 'platinum' => 2, 'diamond' => 3];
    return $ranks[$level] ?? 0;
}

// Level langganan berbayar tertinggi yang masih aktif (null jika tidak ada)
function getPaidMembershipLevel($userId) {
    $conn = getConnection();
    if (!$conn) return null;
    $userId = (int)$userId;
    $r = $conn->query("SELECT level FROM membership_purchases WHERE user_id = $userId AND status = 'active' AND expires_at > NOW()");
    $best = null;
    $bestRank = -1;
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $rk = membershipLevelRank($row['level']);
            if ($rk > $bestRank) { $bestRank = $rk; $best = $row['level']; }
        }
    }
    return $best;
}

// Sinkronkan users.membership = maksimum(level langganan aktif, level dari total belanja)
function syncUserMembership($userId) {
    $conn = getConnection();
    if (!$conn) return null;
    $userId = (int)$userId;

    // Kadaluarsakan langganan yang sudah lewat masa aktif
    $conn->query("UPDATE membership_purchases SET status = 'expired' WHERE user_id = $userId AND status = 'active' AND expires_at <= NOW()");

    $paidLevel = getPaidMembershipLevel($userId);
    $u = $conn->query("SELECT total_spent FROM users WHERE id = $userId LIMIT 1");
    if (!$u || $u->num_rows === 0) return null;
    $spendLevel = getMembershipLevelForSpent($u->fetch_assoc()['total_spent']);

    $effective = ($paidLevel !== null && membershipLevelRank($paidLevel) > membershipLevelRank($spendLevel)) ? $paidLevel : $spendLevel;
    $conn->query("UPDATE users SET membership = '$effective' WHERE id = $userId");
    return $effective;
}

// Apakah produk adalah paket membership yang dijual?
function isMembershipProduct($productId) {
    $conn = getConnection();
    if (!$conn) return false;
    $productId = (int)$productId;
    $r = $conn->query("SELECT id FROM membership_plans WHERE product_id = $productId LIMIT 1");
    return $r && $r->num_rows > 0;
}

// Daftar paket membership (dengan info produk terkait)
function getMembershipPlans($activeOnly = true) {
    $conn = getConnection();
    if (!$conn) return [];
    $where = $activeOnly ? 'WHERE mp.is_active = 1' : '';
    $r = $conn->query("SELECT mp.*, p.id AS product_id, p.name AS product_name, p.is_active AS product_active
        FROM membership_plans mp
        LEFT JOIN products p ON p.id = mp.product_id
        $where
        ORDER BY FIELD(mp.level,'gold','platinum','diamond'), FIELD(mp.period,'monthly','yearly'), mp.id");
    $plans = [];
    if ($r) while ($row = $r->fetch_assoc()) $plans[] = $row;
    return $plans;
}

// Pastikan setiap paket punya produk terkait (dibuat otomatis jika belum ada),
// serta harga & status produk sinkron dengan paketnya.
function ensurePlanProducts() {
    $conn = getConnection();
    if (!$conn) return;

    $catId = 0;
    $cat = $conn->query("SELECT id FROM product_categories WHERE slug = 'membership' LIMIT 1");
    if ($cat && $cat->num_rows > 0) {
        $catId = (int)$cat->fetch_assoc()['id'];
    } else {
        $conn->query("INSERT INTO product_categories (name, slug, description, sort_order, is_active) VALUES ('Membership', 'membership', 'Paket langganan membership premium', 99, 1)");
        $catId = $conn->insert_id;
    }

    $levels = ['gold' => 'Gold', 'platinum' => 'Platinum', 'diamond' => 'Diamond'];
    $periods = ['monthly' => 'Bulanan', 'yearly' => 'Tahunan'];

    $plans = $conn->query("SELECT * FROM membership_plans");
    if (!$plans) return;
    while ($p = $plans->fetch_assoc()) {
        $pid = (int)$p['product_id'];
        $exists = false;
        if ($pid > 0) {
            $chk = $conn->query("SELECT id FROM products WHERE id = $pid LIMIT 1");
            if ($chk && $chk->num_rows > 0) $exists = true;
        }
        if ($exists) {
            $conn->query("UPDATE products SET price = {$p['price']}, is_active = {$p['is_active']} WHERE id = $pid");
        } else {
            $name = 'Membership ' . $levels[$p['level']] . ' (' . $periods[$p['period']] . ')';
            $desc = 'Paket langganan membership ' . $levels[$p['level']] . ' ' . $periods[$p['period']] . '. Aktif ' . $p['duration_days'] . ' hari setelah pembayaran terverifikasi.';
            $slug = 'membership-' . $p['level'] . '-' . $p['period'];
            $slugBase = $slug;
            $i = 2;
            while (($chk = $conn->query("SELECT id FROM products WHERE slug = '$slug' LIMIT 1")) && $chk->num_rows > 0) {
                $slug = $slugBase . '-' . ($i++);
            }
            $conn->query("INSERT INTO products (category_id, name, slug, description, price, stock, is_active, meta_title, meta_description) VALUES ($catId, '$name', '$slug', '$desc', {$p['price']}, 999, {$p['is_active']}, '$name', '$desc')");
            $conn->query("UPDATE membership_plans SET product_id = " . $conn->insert_id . " WHERE id = {$p['id']}");
        }
    }
}

// Aktifkan langganan membership dari pesanan yang sudah lunas (dipanggil saat verifikasi pembayaran)
function activateMembershipForOrder($orderId) {
    $conn = getConnection();
    if (!$conn) return 0;
    $orderId = (int)$orderId;
    $order = $conn->query("SELECT * FROM orders WHERE id = $orderId AND payment_status = 'paid' LIMIT 1");
    if (!$order || $order->num_rows === 0) return 0;
    $o = $order->fetch_assoc();
    $userId = (int)($o['user_id'] ?? 0);
    if ($userId <= 0) return 0; // hanya member yang login bisa berlangganan

    // Idempoten: jika pesanan ini sudah mengaktifkan langganan, jangan aktifkan lagi
    // (mencegah perpanjangan ganda saat admin menyimpan ulang status pesanan)
    $already = $conn->query("SELECT id FROM membership_purchases WHERE order_id = $orderId LIMIT 1");
    if ($already && $already->num_rows > 0) return 0;

    $r = $conn->query("SELECT product_id FROM order_items WHERE order_id = $orderId");
    if (!$r) return 0;
    $ids = [];
    while ($i = $r->fetch_assoc()) { if ((int)$i['product_id'] > 0) $ids[] = (int)$i['product_id']; }
    if (empty($ids)) return 0;
    $in = implode(',', array_unique($ids));

    $plans = $conn->query("SELECT * FROM membership_plans WHERE product_id IN ($in)");
    if (!$plans || $plans->num_rows === 0) return 0;

    $activated = 0;
    while ($p = $plans->fetch_assoc()) {
        $level = $conn->real_escape_string($p['level']);
        $period = $conn->real_escape_string($p['period']);
        $dur = (int)$p['duration_days'];
        $price = (float)$p['price'];
        // Jika sudah punya langganan level yang sama, perpanjang dari tanggal berakhir
        $chk = $conn->query("SELECT id, expires_at FROM membership_purchases WHERE user_id = $userId AND level = '$level' AND status = 'active' ORDER BY expires_at DESC LIMIT 1");
        if ($chk && $chk->num_rows > 0) {
            $ex = $chk->fetch_assoc();
            $base = max(time(), strtotime($ex['expires_at']));
            $newExp = date('Y-m-d H:i:s', strtotime("+$dur days", $base));
            $conn->query("UPDATE membership_purchases SET expires_at = '$newExp', period = '$period', price = $price, order_id = $orderId WHERE id = {$ex['id']}");
        } else {
            $newExp = date('Y-m-d H:i:s', strtotime("+$dur days"));
            $conn->query("INSERT INTO membership_purchases (user_id, order_id, level, period, duration_days, price, starts_at, expires_at, status) VALUES ($userId, $orderId, '$level', '$period', $dur, $price, NOW(), '$newExp', 'active')");
        }
        $activated++;
    }
    if ($activated > 0) syncUserMembership($userId);
    return $activated;
}

// Batalkan langganan dari pesanan yang dibatalkan
function cancelMembershipForOrder($orderId) {
    $conn = getConnection();
    if (!$conn) return;
    $orderId = (int)$orderId;
    $order = $conn->query("SELECT user_id FROM orders WHERE id = $orderId LIMIT 1");
    if (!$order || $order->num_rows === 0) return;
    $userId = (int)$order->fetch_assoc()['user_id'];
    $conn->query("UPDATE membership_purchases SET status = 'cancelled' WHERE order_id = $orderId AND status = 'active'");
    if ($userId > 0) syncUserMembership($userId);
}

// ============================================
// TUKAR POIN -> VOUCHER DISKON
// ============================================

// Maksimal poin yang bisa ditukar untuk sebuah pesanan:
// min(poin tersedia, 30% subtotal / nilai poin)
function redeemablePointsForOrder($userId, $subtotal) {
    $conn = getConnection();
    if (!$conn) return 0;
    $userId = (int)$userId;
    $u = $conn->query("SELECT points FROM users WHERE id = $userId LIMIT 1");
    if (!$u || $u->num_rows === 0) return 0;
    $points = (int)$u->fetch_assoc()['points'];
    $maxByPct = floor(((float)$subtotal * POINT_MAX_PCT) / 100 / POINT_VALUE);
    return max(0, min($points, $maxByPct));
}

// Potong poin member & catat penukaran untuk pesanan
function redeemPointsForOrder($orderId, $userId, $points) {
    $conn = getConnection();
    if (!$conn || (int)$points <= 0) return false;
    $orderId = (int)$orderId;
    $userId = (int)$userId;
    $points = (int)$points;
    $amount = $points * POINT_VALUE;
    $orderNum = '';
    $or = $conn->query("SELECT order_number FROM orders WHERE id = $orderId LIMIT 1");
    if ($or && $or->num_rows > 0) $orderNum = $or->fetch_assoc()['order_number'];
    $conn->query("INSERT INTO point_redeems (user_id, order_id, points, amount, status) VALUES ($userId, $orderId, $points, $amount, 'used')");
    $conn->query("UPDATE users SET points = GREATEST(points - $points, 0) WHERE id = $userId");
    logPointHistory($userId, -$points, 'spent', 'Tukar poin jadi diskon pesanan ' . ($orderNum ?: '#' . $orderId), $orderId);
    return true;
}

// Kembalikan poin member saat pesanan yang memakai poin dibatalkan
function refundPointsForOrder($orderId) {
    $conn = getConnection();
    if (!$conn) return;
    $orderId = (int)$orderId;
    $orderNum = '';
    $or = $conn->query("SELECT order_number FROM orders WHERE id = $orderId LIMIT 1");
    if ($or && $or->num_rows > 0) $orderNum = $or->fetch_assoc()['order_number'];
    $r = $conn->query("SELECT id, user_id, points FROM point_redeems WHERE order_id = $orderId AND status = 'used'");
    if (!$r || $r->num_rows === 0) return;
    while ($rd = $r->fetch_assoc()) {
        $conn->query("UPDATE users SET points = points + {$rd['points']} WHERE id = {$rd['user_id']}");
        $conn->query("UPDATE point_redeems SET status = 'refunded' WHERE id = {$rd['id']}");
        logPointHistory((int)$rd['user_id'], (int)$rd['points'], 'refunded', 'Refund poin pesanan ' . ($orderNum ?: '#' . $orderId), $orderId);
    }
}

// ============================================
// RIWAYAT POIN (point_history)
// ============================================

// Catat transaksi poin ke tabel riwayat. Dipanggil SETELAH saldo users.points diperbarui,
// sehingga balance_after = saldo terbaru. $points bisa negatif (pemakaian) atau positif (perolehan).
function logPointHistory($userId, $points, $type, $description, $orderId = 0) {
    $conn = getConnection();
    if (!$conn) return;
    $userId = (int)$userId;
    if ($userId <= 0) return;
    $points = (int)$points;
    if ($points === 0) return;
    $validTypes = ['earned', 'spent', 'refunded', 'reversed', 'adjusted'];
    if (!in_array($type, $validTypes, true)) $type = 'adjusted';
    $orderId = (int)$orderId;
    $desc = $conn->real_escape_string(function_exists('mb_substr') ? mb_substr($description, 0, 255) : substr($description, 0, 255));
    $balance = 0;
    $br = $conn->query("SELECT points FROM users WHERE id = $userId LIMIT 1");
    if ($br && $br->num_rows > 0) $balance = (int)$br->fetch_assoc()['points'];
    $conn->query("INSERT INTO point_history (user_id, order_id, points, type, description, balance_after) VALUES ($userId, $orderId, $points, '$type', '$desc', $balance)");
}

// Riwayat poin terbaru milik seorang member (untuk halaman profil)
function getPointHistory($userId, $limit = 20) {
    $conn = getConnection();
    if (!$conn) return [];
    $userId = (int)$userId;
    $limit = max(1, min(100, (int)$limit));
    $rows = [];
    $r = $conn->query("SELECT * FROM point_history WHERE user_id = $userId ORDER BY id DESC LIMIT $limit");
    if ($r) while ($row = $r->fetch_assoc()) $rows[] = $row;
    return $rows;
}

// ============================================
// PROMO MEMBERSHIP (diskon paket tahunan)
// ============================================

// Baca promo membership dari pengaturan (admin > Pengaturan).
// Mengembalikan null jika nonaktif, sudah berakhir, atau diskon 0.
// Default end date: +7 hari bergulir agar countdown langsung tampil sebelum diatur admin.
function getMembershipPromo() {
    $active = getSetting('membership_promo_active', '1');
    if ($active !== '1') return null;
    $discount = max(0, min(90, (int)getSetting('membership_promo_discount', '20')));
    if ($discount <= 0) return null;
    $end = getSetting('membership_promo_end', '');
    if ($end === '') {
        $end = date('Y-m-d H:i:s', strtotime('+7 days'));
    }
    if (strtotime($end) <= time()) return null;
    return [
        'title'    => getSetting('membership_promo_title', 'Promo Paket Tahunan'),
        'desc'     => getSetting('membership_promo_desc', 'Hemat lebih banyak dengan berlangganan paket tahunan selama masa promo.'),
        'discount' => $discount,
        'end'      => $end,
    ];
}

// Harga diskon paket (hanya berlaku untuk periode tahunan saat promo aktif).
// Mengembalikan array [price, discount, pct] atau null jika tidak kena promo.
function membershipPromoPrice($price, $period, $promo = null) {
    if ($period !== 'yearly') return null;
    $promo = $promo ?: getMembershipPromo();
    if (!$promo) return null;
    $disc = round((float)$price * (int)$promo['discount'] / 100);
    if ($disc <= 0) return null;
    return ['price' => (float)$price - $disc, 'discount' => $disc, 'pct' => (int)$promo['discount']];
}

// Total diskon promo untuk sebuah item paket (jumlah x kuantitas).
function membershipPromoDiscount($price, $period, $quantity = 1, $promo = null) {
    $promoPrice = membershipPromoPrice($price, $period, $promo);
    if (!$promoPrice) return 0;
    return $promoPrice['discount'] * max(1, (int)$quantity);
}

// Total diskon promo untuk seluruh item keranjang (hanya paket tahunan).
// $cartItems: array item keranjang dengan kunci product_id, price, discount_price, quantity.
function membershipPromoCartDiscount($cartItems, $promo = null) {
    $conn = getConnection();
    if (!$conn || empty($cartItems)) return 0;
    $promo = $promo ?: getMembershipPromo();
    if (!$promo || (int)$promo['discount'] <= 0) return 0;
    $total = 0;
    foreach ($cartItems as $item) {
        if ((int)($item['product_id'] ?? 0) <= 0) continue;
        $planChk = $conn->query("SELECT period FROM membership_plans WHERE product_id = " . (int)$item['product_id'] . " LIMIT 1");
        if ($planChk && $planChk->num_rows > 0) {
            $pp = $planChk->fetch_assoc();
            if ($pp['period'] === 'yearly') {
                $price = ($item['discount_price'] ?? 0) > 0 ? $item['discount_price'] : ($item['price'] ?? 0);
                $total += membershipPromoDiscount($price, 'yearly', (int)($item['quantity'] ?? 1), $promo);
            }
        }
    }
    return $total;
}

// ============================================
// PAKET SPESIAL (paket oleh-oleh di homepage)
// ============================================
// Pastikan tabel paket, kategori produk, permission & menu tersedia (self-healing untuk DB lama).
// Setiap paket memiliki produk terkait (product_id) agar bisa ditambahkan ke keranjang & di-checkout
// seperti produk biasa. Produk paket disembunyikan dari katalog produk biasa via query NOT IN.
function ensurePackagesSchema() {
    static $done = false;
    if ($done) return true;
    $done = true;
    $conn = getConnection();
    if (!$conn) return false;

    $conn->query("CREATE TABLE IF NOT EXISTS packages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NULL,
        name VARCHAR(255) NOT NULL,
        description TEXT,
        price DECIMAL(15,2) NOT NULL DEFAULT 0,
        image VARCHAR(500),
        sort_order INT DEFAULT 0,
        is_active BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_product (product_id),
        INDEX idx_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Kategori produk untuk produk paket (agar tidak tercampur kategori lain)
    $catId = 0;
    $cat = $conn->query("SELECT id FROM product_categories WHERE slug = 'paket-spesial' LIMIT 1");
    if ($cat && $cat->num_rows > 0) {
        $catId = (int)$cat->fetch_assoc()['id'];
    } else {
        $conn->query("INSERT INTO product_categories (name, slug, description, sort_order, is_active) VALUES ('Paket Spesial', 'paket-spesial', 'Paket oleh-oleh spesial', 98, 1)");
        $catId = (int)$conn->insert_id;
    }

    // Seeder paket default jika tabel kosong (dapat diubah dari admin > Paket Spesial)
    $r = $conn->query("SELECT COUNT(*) c FROM packages");
    if ($r && (int)$r->fetch_assoc()['c'] === 0) {
        $defaults = [
            ['Paket Keluarga', 'paket-keluarga', 'Berisi Napoleon 2 box + Pancake Durian + Brownies + Mochi. Cocok untuk keluarga besar.', 275000, 'https://images.unsplash.com/photo-1551024601-bec78aea704b?w=600&q=80', 1],
            ['Paket Koleksi Premium', 'paket-koleksi-premium', 'Koleksi lengkap 6 varian produk Nadhira Napoleon dalam kemasan gift box eksklusif.', 450000, 'https://images.unsplash.com/photo-1565958011703-44f9829ba187?w=600&q=80', 2],
            ['Paket Ekonomis', 'paket-ekonomis', 'Pilihan ekonomis berisi Napoleon + Snack Premium. Tetap berkualitas dengan harga bersahabat.', 150000, 'https://images.unsplash.com/photo-1578985545062-69928b1d9587?w=600&q=80', 3],
        ];
        foreach ($defaults as $d) {
            $name_e = $conn->real_escape_string($d[0]);
            $desc_e = $conn->real_escape_string($d[2]);
            $img_e = $conn->real_escape_string($d[4]);
            // Slug produk unik (antisipasi tabrakan dengan produk lama yang memakai slug sama)
            $slug = $conn->real_escape_string($d[1]);
            $slugBase = $slug;
            $slugIdx = 2;
            while (($chk = $conn->query("SELECT id FROM products WHERE slug = '$slug' LIMIT 1")) && $chk->num_rows > 0) {
                $slug = $slugBase . '-' . ($slugIdx++);
            }
            $conn->query("INSERT INTO products (category_id, name, slug, description, price, stock, is_active, meta_title, meta_description) VALUES ($catId, '$name_e', '$slug', '$desc_e', {$d[3]}, 999, 1, '$name_e', '$desc_e')");
            $pid = (int)$conn->insert_id;
            $conn->query("INSERT INTO packages (product_id, name, description, price, image, sort_order, is_active) VALUES ($pid, '$name_e', '$desc_e', {$d[3]}, '$img_e', {$d[5]}, 1)");
            $conn->query("INSERT INTO product_images (product_id, image, is_primary, sort_order) VALUES ($pid, '$img_e', 1, 0)");
        }
    }

    // Permission & menu sidebar (hanya jika tabel RBAC sudah ada)
    $chk = $conn->query("SHOW TABLES LIKE 'permissions'");
    if ($chk && $chk->num_rows > 0) {
        $conn->query("INSERT IGNORE INTO permissions (module, action, name) VALUES
            ('packages', 'view', 'Lihat Paket Spesial'),
            ('packages', 'create', 'Tambah Paket Spesial'),
            ('packages', 'edit', 'Ubah Paket Spesial'),
            ('packages', 'delete', 'Hapus Paket Spesial')");
        $conn->query("INSERT IGNORE INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
            WHERE r.slug IN ('super-admin', 'admin-marketing', 'admin-produk') AND p.module = 'packages'");
    }
    $chk2 = $conn->query("SHOW TABLES LIKE 'menus'");
    if ($chk2 && $chk2->num_rows > 0) {
        $conn->query("INSERT INTO menus (slug, name, url, icon, module, section, sort_order)
            VALUES ('packages', 'Paket Spesial', 'packages.php', 'fa-gift', 'packages', 'Menu Utama', 23)
            ON DUPLICATE KEY UPDATE name = VALUES(name), url = VALUES(url), icon = VALUES(icon),
                module = VALUES(module), section = VALUES(section), sort_order = VALUES(sort_order)");
    }
    return true;
}

// ============================================
// LINK MAPS CABANG - self-healing kolom maps_url
// ============================================
// Pastikan kolom maps_url (link Google Maps kustom per cabang)
// tersedia di tabel branches. Dipanggil dari admin/branches.php
// dan halaman yang menampilkan cabang.
function ensureBranchesMapsUrl() {
    static $done = false;
    if ($done) return true;
    $conn = getConnection();
    if (!$conn) return false;
    $r = $conn->query("SELECT COUNT(*) c FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'branches' AND COLUMN_NAME = 'maps_url'");
    if ($r && (int)$r->fetch_assoc()['c'] > 0) {
        $done = true;
        return true;
    }
    $ok = $conn->query("ALTER TABLE branches ADD COLUMN maps_url VARCHAR(500) DEFAULT NULL AFTER longitude");
    $done = true;
    return (bool)$ok;
}

// ============================================
// JAM BUKA CABANG - self-healing kolom open_time & close_time
// ============================================
// Jam buka/tutup terstruktur per cabang (diatur dari Admin > Cabang).
// Prioritas tampilan: jam buka/tutup terstruktur → teks open_hours (lama) → pengaturan global.
function ensureBranchesOpenHours() {
    static $done = false;
    if ($done) return true;
    $conn = getConnection();
    if (!$conn) return false;
    $r = $conn->query("SELECT COUNT(*) c FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'branches' AND COLUMN_NAME = 'open_time'");
    if ($r && (int)$r->fetch_assoc()['c'] === 0) {
        $conn->query("ALTER TABLE branches ADD COLUMN open_time TIME NULL AFTER open_hours");
    }
    $r2 = $conn->query("SELECT COUNT(*) c FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'branches' AND COLUMN_NAME = 'close_time'");
    if ($r2 && (int)$r2->fetch_assoc()['c'] === 0) {
        $conn->query("ALTER TABLE branches ADD COLUMN close_time TIME NULL AFTER open_time");
    }
    // Buka 24 jam (checkbox per cabang)
    $r3 = $conn->query("SELECT COUNT(*) c FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'branches' AND COLUMN_NAME = 'open_24h'");
    if ($r3 && (int)$r3->fetch_assoc()['c'] === 0) {
        $conn->query("ALTER TABLE branches ADD COLUMN open_24h TINYINT(1) NOT NULL DEFAULT 0 AFTER close_time");
    }
    $done = true;
    return true;
}

// Teks jam operasional cabang untuk ditampilkan ke pengunjung.
// Prioritas: jam buka/tutup terstruktur → teks open_hours → pengaturan global.
function formatBranchHours($branch) {
    if (is_array($branch)) {
        if (!empty($branch['open_24h'])) {
            return 'Buka 24 Jam';
        }
        if (!empty($branch['open_time']) && !empty($branch['close_time'])) {
            $open = date('H.i', strtotime((string)$branch['open_time']));
            $close = date('H.i', strtotime((string)$branch['close_time']));
            return $open . ' - ' . $close . ' WIB';
        }
        if (!empty($branch['open_hours'])) {
            return trim((string)$branch['open_hours']);
        }
    }
    $global = getSetting('operational_hours', '');
    return $global !== '' ? $global : '';
}

// ============================================
// SKEMA STOK PER CABANG (branch_products.stock)
// ============================================
// Menambah kolom stock di tabel branch_products (stok per produk per cabang).
// Backfill: produk aktif × cabang aktif yang belum punya relasi diisi
// dengan stok global produk — agar tidak ada stok yang hilang saat transisi.
function ensureBranchProductsStock() {
    static $done = false;
    if ($done) return true;
    $conn = getConnection();
    if (!$conn) return false;

    // 1) Pastikan kolom stock ada. Catat apakah kolom BARU ditambahkan di request ini.
    $columnJustAdded = false;
    $r = $conn->query("SELECT COUNT(*) c FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'branch_products' AND COLUMN_NAME = 'stock'");
    if ($r && (int)$r->fetch_assoc()['c'] === 0) {
        $conn->query("ALTER TABLE branch_products ADD COLUMN stock INT DEFAULT 0 AFTER is_available");
        $columnJustAdded = true;
    }

    // 2) Backfill: isi relasi yang belum ada (produk aktif × cabang aktif) dengan stok global.
    //    INSERT IGNORE bersifat idempoten — hanya menambah baris yang belum ada.
    $conn->query("INSERT IGNORE INTO branch_products (branch_id, product_id, is_available, stock)
        SELECT b.id, p.id, 1, p.stock
        FROM products p CROSS JOIN branches b
        WHERE p.is_active = 1 AND b.is_active = 1");

    // 3) HANYA saat kolom baru ditambahkan (migrasi sekali jalan): row lama yang stoknya 0
    //    ikut diisi stok global. Tidak dijalankan lagi di request berikutnya, sehingga
    //    stok 0 yang sengaja diatur admin TIDAK akan ditimpa.
    if ($columnJustAdded) {
        $conn->query("UPDATE branch_products bp
            JOIN products p ON p.id = bp.product_id
            JOIN branches b ON b.id = bp.branch_id
            SET bp.stock = p.stock
            WHERE bp.stock = 0 AND p.is_active = 1 AND b.is_active = 1");
    }

    $done = true;
    return true;
}

// Stok produk di cabang tertentu. Mengembalikan int, atau null jika cabang
// tersebut tidak menjual produk ini (fallback ke stok global products.stock).
function getProductStockForBranch($productId, $branchId) {
    $conn = getConnection();
    if (!$conn) return null;
    $productId = (int)$productId;
    $branchId = (int)$branchId;
    if ($productId <= 0 || $branchId <= 0) return null;
    $r = $conn->query("SELECT stock FROM branch_products
        WHERE product_id = $productId AND branch_id = $branchId LIMIT 1");
    if ($r && $r->num_rows > 0) return (int)$r->fetch_assoc()['stock'];
    return null;
}

// ============================================
// JUMLAH TERJUAL (total_sold) - sinkron dengan pesanan
// ============================================
// Kolom sold_counted di tabel orders berfungsi sebagai pengaman agar
// total_sold hanya dihitung SEKALI per pesanan (idempoten), baik saat
// penambahan (order lunas) maupun pengurangan (order dibatalkan).

// Pastikan kolom sold_counted tersedia (self-healing untuk DB lama)
function ensureSoldCountedColumn() {
    $conn = getConnection();
    if (!$conn) return false;
    $r = $conn->query("SELECT COUNT(*) c FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'sold_counted'");
    if ($r && (int)$r->fetch_assoc()['c'] > 0) return true;
    return $conn->query("ALTER TABLE orders ADD COLUMN sold_counted TINYINT(1) NOT NULL DEFAULT 0 AFTER payment_status");
}

// Tambah jumlah terjual produk saat pesanan dinyatakan LUNAS.
// Idempoten: pesanan yang sudah dihitung tidak akan dihitung ulang.
// Mengembalikan jumlah item yang dihitung (0 jika tidak ada / sudah dihitung).
function countOrderSold($orderId) {
    $conn = getConnection();
    if (!$conn) return 0;
    $orderId = (int)$orderId;
    if ($orderId <= 0) return 0;
    ensureSoldCountedColumn();

    $r = $conn->query("SELECT sold_counted FROM orders WHERE id = $orderId LIMIT 1");
    if (!$r || $r->num_rows === 0) return 0;
    if ((int)$r->fetch_assoc()['sold_counted'] === 1) return 0; // sudah pernah dihitung

    $items = $conn->query("SELECT product_id, quantity FROM order_items WHERE order_id = $orderId AND product_id > 0");
    if (!$items || $items->num_rows === 0) return 0;

    $counted = 0;
    while ($it = $items->fetch_assoc()) {
        $conn->query("UPDATE products SET total_sold = total_sold + " . (int)$it['quantity'] . " WHERE id = " . (int)$it['product_id']);
        $counted += (int)$it['quantity'];
    }
    $conn->query("UPDATE orders SET sold_counted = 1 WHERE id = $orderId");
    return $counted;
}

// Kurangi jumlah terjual produk saat pesanan dibatalkan.
// Hanya berjalan jika pesanan sudah pernah dihitung (sold_counted = 1),
// sehingga pesanan yang batal sebelum lunas tidak mengurangi total_sold.
function reverseOrderSold($orderId) {
    $conn = getConnection();
    if (!$conn) return 0;
    $orderId = (int)$orderId;
    if ($orderId <= 0) return 0;
    ensureSoldCountedColumn();

    $r = $conn->query("SELECT sold_counted FROM orders WHERE id = $orderId LIMIT 1");
    if (!$r || $r->num_rows === 0) return 0;
    if ((int)$r->fetch_assoc()['sold_counted'] !== 1) return 0; // belum pernah dihitung

    $items = $conn->query("SELECT product_id, quantity FROM order_items WHERE order_id = $orderId AND product_id > 0");
    if (!$items || $items->num_rows === 0) return 0;

    $reversed = 0;
    while ($it = $items->fetch_assoc()) {
        $conn->query("UPDATE products SET total_sold = GREATEST(total_sold - " . (int)$it['quantity'] . ", 0) WHERE id = " . (int)$it['product_id']);
        $reversed += (int)$it['quantity'];
    }
    $conn->query("UPDATE orders SET sold_counted = 0 WHERE id = $orderId");
    return $reversed;
}

// ============================================
// STOK OTOMATIS (sinkron dengan pesanan)
// ============================================
// Stok produk dikurangi saat pesanan DIBUAT (reserve) dan dikembalikan saat
// pesanan dibatalkan / pembayaran gagal. Kolom stock_deducted di tabel orders
// berfungsi sebagai pengaman agar pengurangan & pengembalian hanya terjadi
// SEKALI per pesanan (idempoten), mengikuti pola sold_counted / points_awarded.
// Stok global (products.stock) selalu dikurangi; jika pesanan memakai cabang
// (orders.branch_id), stok cabang (branch_products.stock) ikut dikurangi.

// Pastikan kolom stock_deducted tersedia (self-healing untuk DB lama)
function ensureStockDeductedColumn() {
    $conn = getConnection();
    if (!$conn) return false;
    $r = $conn->query("SELECT COUNT(*) c FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'stock_deducted'");
    if ($r && (int)$r->fetch_assoc()['c'] > 0) return true;
    return $conn->query("ALTER TABLE orders ADD COLUMN stock_deducted TINYINT(1) NOT NULL DEFAULT 0 AFTER points_awarded");
}

// Kurangi stok produk untuk semua item pesanan (reserve saat order dibuat).
// Idempoten: pesanan yang sudah pernah dikurangi tidak akan dikurangi ulang.
// Mengembalikan jumlah unit yang dikurangi (0 jika tidak ada / sudah dihitung).
function deductOrderStock($orderId) {
    $conn = getConnection();
    if (!$conn) return 0;
    $orderId = (int)$orderId;
    if ($orderId <= 0) return 0;
    ensureStockDeductedColumn();

    $r = $conn->query("SELECT stock_deducted, branch_id FROM orders WHERE id = $orderId LIMIT 1");
    if (!$r || $r->num_rows === 0) return 0;
    $o = $r->fetch_assoc();
    if ((int)$o['stock_deducted'] === 1) return 0; // sudah pernah dikurangi

    $branchId = (int)($o['branch_id'] ?? 0);
    $items = $conn->query("SELECT product_id, quantity FROM order_items WHERE order_id = $orderId AND product_id > 0");
    if (!$items || $items->num_rows === 0) return 0;

    $deducted = 0;
    while ($it = $items->fetch_assoc()) {
        $pid = (int)$it['product_id'];
        $qty = (int)$it['quantity'];
        // Stok global produk
        $conn->query("UPDATE products SET stock = GREATEST(stock - $qty, 0) WHERE id = $pid");
        // Stok per cabang (hanya jika produk dijual di cabang tsb)
        if ($branchId > 0) {
            $conn->query("UPDATE branch_products SET stock = GREATEST(stock - $qty, 0) WHERE product_id = $pid AND branch_id = $branchId");
        }
        $deducted += $qty;
    }
    $conn->query("UPDATE orders SET stock_deducted = 1 WHERE id = $orderId");
    return $deducted;
}

// Kembalikan stok produk saat pesanan dibatalkan / pembayaran gagal.
// Hanya berjalan jika pesanan sudah pernah dikurangi (stock_deducted = 1),
// sehingga pesanan yang batal sebelum dikurangi tidak menambah stok.
function restoreOrderStock($orderId) {
    $conn = getConnection();
    if (!$conn) return 0;
    $orderId = (int)$orderId;
    if ($orderId <= 0) return 0;
    ensureStockDeductedColumn();

    $r = $conn->query("SELECT stock_deducted, branch_id FROM orders WHERE id = $orderId LIMIT 1");
    if (!$r || $r->num_rows === 0) return 0;
    $o = $r->fetch_assoc();
    if ((int)$o['stock_deducted'] !== 1) return 0; // belum pernah dikurangi

    $branchId = (int)($o['branch_id'] ?? 0);
    $items = $conn->query("SELECT product_id, quantity FROM order_items WHERE order_id = $orderId AND product_id > 0");
    if (!$items || $items->num_rows === 0) return 0;

    $restored = 0;
    while ($it = $items->fetch_assoc()) {
        $pid = (int)$it['product_id'];
        $qty = (int)$it['quantity'];
        $conn->query("UPDATE products SET stock = stock + $qty WHERE id = $pid");
        if ($branchId > 0) {
            $conn->query("UPDATE branch_products SET stock = stock + $qty WHERE product_id = $pid AND branch_id = $branchId");
        }
        $restored += $qty;
    }
    $conn->query("UPDATE orders SET stock_deducted = 0 WHERE id = $orderId");
    return $restored;
}

// ============================================
// AKSI PESANAN OLEH USER (BATAL & KONFIRMASI TERIMA)
// ============================================
// User hanya bisa membatalkan pesanan yang statusnya masih 'pending' (belum
// diproses/dikirim) dan belum lunas — pembatalan pesanan yang sudah dibayar
// tetap lewat admin (perlu refund). Konfirmasi terima hanya untuk pesanan
// berstatus 'shipped'. Keduanya memvalidasi kepemilikan (user_id).

// Batalkan pesanan oleh pemiliknya. Mengembalikan array ['ok' => bool, 'message' => string].
function cancelOrderByUser($orderId, $userId) {
    $conn = getConnection();
    if (!$conn) return ['ok' => false, 'message' => 'Koneksi database gagal'];
    $orderId = (int)$orderId;
    $userId = (int)$userId;
    if ($orderId <= 0 || $userId <= 0) {
        return ['ok' => false, 'message' => 'Data pesanan tidak valid'];
    }

    $r = $conn->query("SELECT * FROM orders WHERE id = $orderId AND user_id = $userId LIMIT 1");
    if (!$r || $r->num_rows === 0) {
        return ['ok' => false, 'message' => 'Pesanan tidak ditemukan'];
    }
    $order = $r->fetch_assoc();

    if ($order['order_status'] === 'cancelled') {
        return ['ok' => false, 'message' => 'Pesanan sudah dibatalkan sebelumnya'];
    }
    if ($order['order_status'] !== 'pending') {
        return ['ok' => false, 'message' => 'Pesanan sudah diproses dan tidak bisa dibatalkan sendiri. Hubungi admin via WhatsApp.'];
    }
    if ($order['payment_status'] === 'paid') {
        return ['ok' => false, 'message' => 'Pesanan sudah lunas — pembatalan perlu dilakukan admin (termasuk pengembalian dana).'];
    }

    // Kembalikan semua yang ter-reserve dari pesanan ini (idempoten, aman dipanggil berulang)
    restoreOrderStock($orderId);                 // stok
    reverseOrderSold($orderId);                  // jumlah terjual (jika sudah pernah dihitung)
    // Reward (poin & total belanja) hanya dibalik bila benar-benar pernah diberikan
    // (points_awarded = 1, artinya pesanan pernah lunas) — user hanya bisa batal saat
    // belum lunas, jadi ini hampir selalu no-op; dijaga demi keamanan.
    if ((int)($order['points_awarded'] ?? 0) === 1 && !empty($order['user_id'])) {
        reverseOrderRewards((int)$order['user_id'], (float)$order['subtotal'], $order['order_number'], $orderId);
    }
    refundPointsForOrder($orderId);              // poin yang ditukar jadi diskon
    cancelMembershipForOrder($orderId);          // langganan membership (jika ada)
    if (!empty($order['promo_code'])) {
        decrementPromoUsage($order['promo_code']); // kuota kode promo
    }

    $conn->query("UPDATE orders SET order_status = 'cancelled' WHERE id = $orderId");
    if (function_exists('logActivity')) {
        logActivity('edit', 'orders', "Pesanan #{$order['order_number']} dibatalkan oleh pelanggan");
    }
    return ['ok' => true, 'message' => 'Pesanan berhasil dibatalkan. Stok produk dikembalikan.'];
}

// Konfirmasi pesanan sudah diterima oleh pelanggan (order_status: shipped → delivered).
function confirmReceivedByUser($orderId, $userId) {
    $conn = getConnection();
    if (!$conn) return ['ok' => false, 'message' => 'Koneksi database gagal'];
    $orderId = (int)$orderId;
    $userId = (int)$userId;
    if ($orderId <= 0 || $userId <= 0) {
        return ['ok' => false, 'message' => 'Data pesanan tidak valid'];
    }

    $r = $conn->query("SELECT * FROM orders WHERE id = $orderId AND user_id = $userId LIMIT 1");
    if (!$r || $r->num_rows === 0) {
        return ['ok' => false, 'message' => 'Pesanan tidak ditemukan'];
    }
    $order = $r->fetch_assoc();

    if ($order['order_status'] === 'delivered') {
        return ['ok' => false, 'message' => 'Pesanan ini sudah dikonfirmasi diterima'];
    }
    if ($order['order_status'] !== 'shipped') {
        return ['ok' => false, 'message' => 'Pesanan belum berstatus dikirim — belum bisa konfirmasi terima.'];
    }

    $conn->query("UPDATE orders SET order_status = 'delivered' WHERE id = $orderId");
    if (function_exists('logActivity')) {
        logActivity('edit', 'orders', "Pesanan #{$order['order_number']} dikonfirmasi diterima oleh pelanggan");
    }
    return ['ok' => true, 'message' => 'Terima kasih! Pesanan ditandai sebagai sudah diterima.'];
}

// ============================================
// AUTO-EXPIRE PESANAN PENDING (TIDAK DIBAYAR)
// ============================================
// Pesanan yang dibuat tapi tidak dibayar dalam batas waktu tertentu (default
// 24 jam — sama dengan masa berlaku token Midtrans) otomatis dibatalkan.
// Berfungsi sebagai jaring pengaman bila webhook Midtrans 'expire' tidak
// sampai ke server (URL notifikasi belum diisi / server mati saat itu),
// sehingga stok & kuota promo tidak terkunci selamanya. Semua yang ter-reserve
// dikembalikan (stok, jumlah terjual, poin, kuota promo, membership).

// Batas waktu kedaluwarsa (jam) — diatur di Admin > Pengaturan
function getOrderExpiryHours() {
    $h = (int)getSetting('order_expiry_hours', '24');
    return max(1, min(720, $h)); // 1 jam s/d 30 hari
}

// Kunci rahasia untuk menjalankan auto-expire via HTTP (dipakai cron hosting).
// Dibuat & disimpan otomatis bila belum ada — mengikuti pola autoBackupKey().
function autoExpireKey(): string {
    $key = trim((string)getSetting('auto_expire_key', ''));
    if ($key !== '') return $key;

    $key = 'naex_' . bin2hex(random_bytes(16));
    $conn = getConnection();
    if ($conn) {
        $conn->query("INSERT INTO settings (setting_key, setting_value) VALUES ('auto_expire_key', '" . $conn->real_escape_string($key) . "')
                      ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    }
    return $key;
}

// Jalankan auto-expire bila sudah waktunya (poor man's cron, throttle 1x/jam).
// Dipanggil dari halaman depan & panel admin. Mengembalikan jumlah yang dibatalkan.
function runOrderExpiryIfDue() {
    $conn = getConnection();
    if (!$conn) return 0;

    // Throttle: maksimal 1x per 60 menit agar tidak memberatkan tiap request
    $lastRun = (int)strtotime((string)getSetting('order_expiry_last_run', ''));
    if ($lastRun > time() - 3600) return 0;

    $conn->query("INSERT INTO settings (setting_key, setting_value) VALUES ('order_expiry_last_run', '" . date('Y-m-d H:i:s') . "')
                  ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");

    return expirePendingOrders();
}

// Batalkan semua pesanan yang belum dibayar & melewati batas waktu.
function expirePendingOrders() {
    $conn = getConnection();
    if (!$conn) return 0;
    $hours = getOrderExpiryHours();

    $r = $conn->query("SELECT id FROM orders
        WHERE order_status = 'pending'
          AND payment_status IN ('pending', 'failed')
          AND payment_method != 'cod'
          AND created_at < DATE_SUB(NOW(), INTERVAL $hours HOUR)
        LIMIT 200");
    if (!$r || $r->num_rows === 0) return 0;

    $expired = 0;
    while ($row = $r->fetch_assoc()) {
        if (expireOrder((int)$row['id'])) $expired++;
    }
    return $expired;
}

// Batalkan SATU pesanan karena kedaluwarsa (mirip cancelOrderByUser, tanpa validasi kepemilikan).
function expireOrder($orderId) {
    $conn = getConnection();
    if (!$conn) return false;
    $orderId = (int)$orderId;
    if ($orderId <= 0) return false;

    $r = $conn->query("SELECT * FROM orders WHERE id = $orderId AND order_status = 'pending' LIMIT 1");
    if (!$r || $r->num_rows === 0) return false;
    $order = $r->fetch_assoc();
    if ($order['order_status'] === 'cancelled') return false;

    restoreOrderStock($orderId);                 // kembalikan stok
    reverseOrderSold($orderId);                  // balik jumlah terjual (jika pernah dihitung)
    if ((int)($order['points_awarded'] ?? 0) === 1 && !empty($order['user_id'])) {
        reverseOrderRewards((int)$order['user_id'], (float)$order['subtotal'], $order['order_number'], $orderId);
    }
    refundPointsForOrder($orderId);              // kembalikan poin yang ditukar jadi diskon
    cancelMembershipForOrder($orderId);          // langganan membership (jika ada)
    if (!empty($order['promo_code'])) {
        decrementPromoUsage($order['promo_code']); // kuota kode promo
    }

    $conn->query("UPDATE orders SET order_status = 'cancelled' WHERE id = $orderId");
    if (function_exists('logActivity')) {
        logActivity('edit', 'orders', "Pesanan #{$order['order_number']} otomatis dibatalkan (tidak dibayar dalam " . getOrderExpiryHours() . " jam)");
    }
    return true;
}

// ============================================
// SISTEM LOKASI CUSTOMER (GPS) & ONGKIR BERBASIS JARAK
// ============================================
// Self-healing schema untuk fitur lokasi dari lokasi.md:
// - Kolom latitude/longitude/jarak/cabang/kurir di orders
// - Kolom latitude/longitude di shipping_addresses
// - Tabel tarif ongkir per jarak (shipping_rates)
// - Tabel kurir & riwayat posisi GPS kurir (couriers, courier_locations)

function ensureLocationSchema() {
    static $done = false;
    if ($done) return true;
    $done = true;
    // Pastikan tabel shipping_addresses sudah dibuat sebelum kolom latitude/longitude ditambahkan
    ensureShippingAddressSchema();
    $conn = getConnection();
    if (!$conn) return false;

    $hasCol = function ($table, $col) use ($conn) {
        $r = $conn->query("SELECT COUNT(*) c FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table' AND COLUMN_NAME = '$col'");
        return $r && (int)$r->fetch_assoc()['c'] > 0;
    };
    $addCol = function ($table, $col, $def) use ($conn, $hasCol) {
        if ($hasCol($table, $col)) return true;
        return $conn->query("ALTER TABLE `$table` ADD COLUMN $def");
    };

    $addCol('orders', 'latitude', 'latitude DECIMAL(10,8) NULL AFTER shipping_postal_code');
    $addCol('orders', 'longitude', 'longitude DECIMAL(11,8) NULL AFTER latitude');
    $addCol('orders', 'distance_km', 'distance_km DECIMAL(6,2) NULL AFTER longitude');
    $addCol('orders', 'branch_id', 'branch_id INT NULL AFTER distance_km');
    $addCol('orders', 'courier_id', 'courier_id INT NULL AFTER branch_id');
    $addCol('shipping_addresses', 'latitude', 'latitude DECIMAL(10,8) NULL AFTER postal_code');
    $addCol('shipping_addresses', 'longitude', 'longitude DECIMAL(11,8) NULL AFTER latitude');

    // Tarif ongkir per rentang jarak (km). max_km NULL = rentang terbuka (>10 km).
    $conn->query("CREATE TABLE IF NOT EXISTS shipping_rates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        min_km DECIMAL(6,2) NOT NULL DEFAULT 0,
        max_km DECIMAL(6,2) NULL,
        rate DECIMAL(12,2) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_range (min_km, max_km)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $r = $conn->query("SELECT COUNT(*) c FROM shipping_rates");
    if ($r && (int)$r->fetch_assoc()['c'] === 0) {
        $conn->query("INSERT INTO shipping_rates (min_km, max_km, rate) VALUES
            (0, 3, 10000),
            (3, 5, 15000),
            (5, 10, 20000),
            (10, NULL, 25000)");
    }

    $conn->query("CREATE TABLE IF NOT EXISTS couriers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        name VARCHAR(255) NOT NULL,
        phone VARCHAR(50) DEFAULT '',
        branch_id INT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_user (user_id),
        INDEX idx_branch (branch_id),
        INDEX idx_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS courier_locations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        courier_id INT NOT NULL,
        latitude DECIMAL(10,8) NOT NULL,
        longitude DECIMAL(11,8) NOT NULL,
        accuracy DECIMAL(8,2) DEFAULT 0,
        recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_courier (courier_id, recorded_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Permission & menu sidebar admin (hanya jika tabel RBAC sudah ada)
    $chk = $conn->query("SHOW TABLES LIKE 'permissions'");
    if ($chk && $chk->num_rows > 0) {
        $conn->query("INSERT IGNORE INTO permissions (module, action, name) VALUES
            ('couriers', 'view', 'Lihat Kurir & Tracking'),
            ('couriers', 'create', 'Tambah Kurir'),
            ('couriers', 'edit', 'Ubah Kurir'),
            ('couriers', 'delete', 'Hapus Kurir')");
        $conn->query("INSERT IGNORE INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
            WHERE r.slug IN ('super-admin', 'admin-pengiriman') AND p.module = 'couriers'");
    }
    $chk2 = $conn->query("SHOW TABLES LIKE 'menus'");
    if ($chk2 && $chk2->num_rows > 0) {
        $conn->query("INSERT INTO menus (slug, name, url, icon, module, section, sort_order)
            VALUES ('couriers', 'Kurir & Tracking', 'couriers.php', 'fa-truck-fast', 'couriers', 'Menu Utama', 17)
            ON DUPLICATE KEY UPDATE name = VALUES(name), url = VALUES(url), icon = VALUES(icon),
                module = VALUES(module), section = VALUES(section), sort_order = VALUES(sort_order)");
    }
    return true;
}

// Jarak garis lurus (km) antara dua koordinat — rumus Haversine
function haversineKm($lat1, $lng1, $lat2, $lng2) {
    $lat1 = (float)$lat1; $lng1 = (float)$lng1; $lat2 = (float)$lat2; $lng2 = (float)$lng2;
    if ($lat1 == 0 || $lng1 == 0 || $lat2 == 0 || $lng2 == 0) return null;
    $R = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return round($R * 2 * atan2(sqrt($a), sqrt(1 - $a)), 2);
}

// Daftar cabang aktif (untuk marker peta & perhitungan jarak)
function getActiveBranches() {
    $conn = getConnection();
    if (!$conn) return [];
    $rows = [];
    $r = $conn->query("SELECT * FROM branches WHERE is_active = 1 ORDER BY sort_order ASC, id ASC");
    if ($r) while ($row = $r->fetch_assoc()) $rows[] = $row;
    return $rows;
}

// Cabang terdekat dari koordinat customer.
// Mengembalikan ['branch' => row, 'distance_km' => float] atau null.
function getNearestBranch($lat, $lng) {
    $branches = getActiveBranches();
    $best = null;
    foreach ($branches as $b) {
        if (!(float)$b['latitude'] || !(float)$b['longitude']) continue;
        $d = haversineKm($lat, $lng, $b['latitude'], $b['longitude']);
        if ($d === null) continue;
        if ($best === null || $d < $best['distance_km']) {
            $best = ['branch' => $b, 'distance_km' => $d];
        }
    }
    return $best;
}

// Jarak tempuh via jalan (km) memakai OSRM public server (tanpa API key).
// Fallback: jarak garis lurus x 1.3 bila OSRM tidak terjangkau.
function getRoadDistanceKm($lat1, $lng1, $lat2, $lng2) {
    $url = 'https://router.project-osrm.org/route/v1/driving/'
        . round((float)$lng1, 6) . ',' . round((float)$lat1, 6) . ';'
        . round((float)$lng2, 6) . ',' . round((float)$lat2, 6) . '?overview=false';
    $ctx = stream_context_create(['http' => ['timeout' => 4, 'ignore_errors' => true]]);
    $json = @file_get_contents($url, false, $ctx);
    if ($json) {
        $data = json_decode($json, true);
        if (!empty($data['routes'][0]['distance'])) {
            return round($data['routes'][0]['distance'] / 1000, 2);
        }
    }
    $d = haversineKm($lat1, $lng1, $lat2, $lng2);
    return $d !== null ? round($d * 1.3, 2) : null;
}

// Tarif ongkir berdasarkan jarak (km) dari tabel shipping_rates.
// Rentang tertutup [min_km, max_km] memakai tarif tetap.
// Rentang terbuka (max_km NULL, mis. > 10 km): biaya dasar + per km tambahan
// (setting shipping_per_km_rate), sesuai aturan "> 10 KM dihitung per kilometer tambahan".
// Mengembalikan nominal Rp (null bila tidak bisa dihitung).
function calculateShippingCost($distanceKm) {
    if ($distanceKm === null || $distanceKm < 0) return null;
    $conn = getConnection();
    if (!$conn) return null;
    $d = (float)$distanceKm;

    // Band tertutup (min_km, max_km] — batas atas INKLUSIF & urut ascending agar
    // jarak tepat di batas (mis. 3,0 km / 5,0 km / 10,0 km) masuk ke band BAWAH
    // yang lebih ramah (0-3 -> Rp10.000, 3-5 -> Rp15.000, 5-10 -> Rp20.000).
    $r = $conn->query("SELECT rate FROM shipping_rates
        WHERE min_km <= $d AND max_km IS NOT NULL AND max_km >= $d
        ORDER BY min_km ASC LIMIT 1");
    if ($r && $r->num_rows > 0) return (float)$r->fetch_assoc()['rate'];

    // Band terbuka (max_km IS NULL): biaya dasar + per km di luar batas bawah
    $r2 = $conn->query("SELECT rate, min_km FROM shipping_rates WHERE max_km IS NULL ORDER BY min_km DESC LIMIT 1");
    if ($r2 && $r2->num_rows > 0) {
        $open = $r2->fetch_assoc();
        $perKm = max(0, (float)getSetting('shipping_per_km_rate', 2500));
        $extra = max(0, $d - (float)$open['min_km']);
        return round((float)$open['rate'] + $extra * $perKm, 0);
    }
    return 0;
}

// Estimasi waktu tempuh (menit): 20 menit dasar + kecepatan rata-rata dalam kota ~24 km/jam
function estimateDeliveryMinutes($distanceKm) {
    if ($distanceKm === null || $distanceKm < 0) return null;
    return (int)round(20 + ((float)$distanceKm / 24) * 60);
}

// Format durasi: menit -> "X jam Y menit"
function formatDuration($minutes) {
    if ($minutes === null) return '-';
    $minutes = (int)$minutes;
    if ($minutes < 60) return $minutes . ' menit';
    return floor($minutes / 60) . ' jam ' . ($minutes % 60) . ' menit';
}

// ============================================
// KURIR & TRACKING GPS
// ============================================

// Data kurir milik sebuah akun user (null jika bukan kurir)
function getCourierForUser($userId) {
    $conn = getConnection();
    if (!$conn || (int)$userId <= 0) return null;
    $userId = (int)$userId;
    $r = $conn->query("SELECT * FROM couriers WHERE user_id = $userId AND is_active = 1 LIMIT 1");
    return ($r && $r->num_rows > 0) ? $r->fetch_assoc() : null;
}

function getCourier($courierId) {
    $conn = getConnection();
    if (!$conn) return null;
    $courierId = (int)$courierId;
    $r = $conn->query("SELECT * FROM couriers WHERE id = $courierId LIMIT 1");
    return ($r && $r->num_rows > 0) ? $r->fetch_assoc() : null;
}

// Simpan posisi GPS kurir terbaru (dipanggil berkala dari panel kurir)
function saveCourierLocation($courierId, $lat, $lng, $accuracy = 0) {
    $conn = getConnection();
    if (!$conn) return false;
    $courierId = (int)$courierId;
    $lat = (float)$lat; $lng = (float)$lng;
    if ($courierId <= 0 || !$lat || !$lng) return false;
    $acc = max(0, (float)$accuracy);
    return $conn->query("INSERT INTO courier_locations (courier_id, latitude, longitude, accuracy) VALUES ($courierId, $lat, $lng, $acc)");
}

// Posisi GPS terbaru seorang kurir (null jika belum pernah kirim)
function getLatestCourierLocation($courierId) {
    $conn = getConnection();
    if (!$conn) return null;
    $courierId = (int)$courierId;
    $r = $conn->query("SELECT * FROM courier_locations WHERE courier_id = $courierId ORDER BY recorded_at DESC, id DESC LIMIT 1");
    return ($r && $r->num_rows > 0) ? $r->fetch_assoc() : null;
}

// Aktifkan skema lokasi & ongkir di awal aplikasi
ensureLocationSchema();

// Aktifkan skema membership, paket spesial & alamat pengiriman di awal aplikasi
ensureMembershipSchema();
ensurePackagesSchema();
ensureShippingAddressSchema();

// Aktifkan skema stok per cabang (branch_products.stock)
ensureBranchProductsStock();

// Aktifkan kolom kode promo (promotions.code & orders.promo_code)
ensurePromoColumns();

// Aktifkan permission & menu untuk manajemen ulasan produk (self-healing)
ensureReviewsSchema();

// ============================================
// ULASAN PRODUK DARI PENGUNJUNG
// ============================================
// Pastikan permission & menu "Ulasan Produk" tersedia (self-healing untuk DB lama).
function ensureReviewsSchema() {
    static $done = false;
    if ($done) return true;
    $done = true;
    $conn = getConnection();
    if (!$conn) return false;

    $chk = $conn->query("SHOW TABLES LIKE 'permissions'");
    if ($chk && $chk->num_rows > 0) {
        $conn->query("INSERT IGNORE INTO permissions (module, action, name) VALUES
            ('reviews', 'view', 'Lihat Ulasan Produk'),
            ('reviews', 'create', 'Tambah Ulasan Produk'),
            ('reviews', 'edit', 'Approve/Tolak Ulasan Produk'),
            ('reviews', 'delete', 'Hapus Ulasan Produk')");
        $conn->query("INSERT IGNORE INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
            WHERE r.slug IN ('super-admin', 'admin-penjualan-online', 'admin-marketing', 'admin-produk') AND p.module = 'reviews'");
    }
    $chk2 = $conn->query("SHOW TABLES LIKE 'menus'");
    if ($chk2 && $chk2->num_rows > 0) {
        $conn->query("INSERT INTO menus (slug, name, url, icon, module, section, sort_order)
            VALUES ('reviews', 'Ulasan Produk', 'reviews.php', 'fa-star', 'reviews', 'Menu Utama', 19)
            ON DUPLICATE KEY UPDATE name = VALUES(name), url = VALUES(url), icon = VALUES(icon),
                module = VALUES(module), section = VALUES(section), sort_order = VALUES(sort_order)");
    }
    return true;
}

// Boleh menulis ulasan? Hanya pembeli terverifikasi — sudah punya pesanan
// berstatus 'delivered' yang berisi produk ini.
function userCanReviewProduct($userId, $productId) {
    $conn = getConnection();
    if (!$conn) return false;
    $userId = (int)$userId;
    $productId = (int)$productId;
    if ($userId <= 0 || $productId <= 0) return false;
    $r = $conn->query("SELECT COUNT(*) c FROM order_items oi
        JOIN orders o ON o.id = oi.order_id
        WHERE oi.product_id = $productId AND o.user_id = $userId AND o.order_status = 'delivered'");
    return $r && (int)$r->fetch_assoc()['c'] > 0;
}

// Sudah pernah mengulas produk ini?
function userAlreadyReviewedProduct($userId, $productId) {
    $conn = getConnection();
    if (!$conn) return false;
    $userId = (int)$userId;
    $productId = (int)$productId;
    $r = $conn->query("SELECT COUNT(*) c FROM product_reviews WHERE user_id = $userId AND product_id = $productId");
    return $r && (int)$r->fetch_assoc()['c'] > 0;
}

// Hitung ulang rata-rata rating produk dari ulasan aktif & terverifikasi,
// lalu simpan ke products.rating. Dipanggil saat ulasan disetujui/dihapus.
function recalcProductRating($productId) {
    $conn = getConnection();
    if (!$conn) return 0;
    $productId = (int)$productId;
    $r = $conn->query("SELECT AVG(rating) a, COUNT(*) c FROM product_reviews WHERE product_id = $productId AND is_active = 1 AND is_verified = 1");
    $avg = 0;
    if ($r && $row = $r->fetch_assoc()) {
        $avg = $row['a'] !== null ? round((float)$row['a'], 1) : 0;
    }
    $conn->query("UPDATE products SET rating = $avg WHERE id = $productId");
    return $avg;
}
