<?php
// ============================================
// RBAC ENGINE - Role Based Access Control
// Nadhira Napoleon Pekanbaru
// ============================================
// Pusat seluruh logika hak akses. Tidak ada permission
// yang ditulis hardcoded di halaman — semuanya mengalir
// dari tabel roles / permissions / user_roles.
// ============================================

require_once __DIR__ . '/database.php';

// ============================================
// CONSTANTS
// ============================================
define('RBAC_SESSION_LIFETIME', 60 * 60 * 24 * 7);   // 7 hari (absolute)
define('RBAC_IDLE_TIMEOUT', 60 * 30);                 // 30 menit idle -> auto logout
define('RBAC_MAX_FAILED_ATTEMPTS', 5);
define('RBAC_LOCK_MINUTES', 15);

// ============================================
// CLIENT DETECTION
// ============================================
function getClientIp() {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim(explode(',', $_SERVER[$key])[0]);
            if ($ip && $ip !== 'unknown') return $ip;
        }
    }
    return '0.0.0.0';
}

function getUserAgent() {
    return $_SERVER['HTTP_USER_AGENT'] ?? '';
}

function getBrowserName($ua = null) {
    if ($ua === null) $ua = getUserAgent();
    $ua = strtolower($ua);
    $browsers = [
        'edg' => 'Edge', 'chrome' => 'Chrome', 'firefox' => 'Firefox',
        'safari' => 'Safari', 'opera' => 'Opera', 'msie' => 'IE',
    ];
    foreach ($browsers as $needle => $name) {
        if (strpos($ua, $needle) !== false) return $name;
    }
    return 'Unknown';
}

function getDeviceName($ua = null) {
    if ($ua === null) $ua = getUserAgent();
    $ua = strtolower($ua);
    if (strpos($ua, 'ipad') !== false || (strpos($ua, 'tablet') !== false)) return 'Tablet';
    if (preg_match('/(mobile|android|iphone|ipod)/', $ua)) return 'Mobile';
    return 'Desktop';
}

function tableExists($table) {
    $conn = getConnection();
    if (!$conn) return false;
    $table = $conn->real_escape_string($table);
    $r = $conn->query("SHOW TABLES LIKE '$table'");
    return $r && $r->num_rows > 0;
}

// ============================================
// ROLE HELPERS
// ============================================
function getCurrentUserId() {
    return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
}

// Daftar slug role aktif milik user
function getUserRoleSlugs($userId = null) {
    static $cache = [];
    if ($userId === null) $userId = getCurrentUserId();
    $userId = (int)$userId;
    if (isset($cache[$userId])) return $cache[$userId];

    $slugs = [];
    $conn = getConnection();
    if ($conn && $userId > 0) {
        $r = $conn->query(
            "SELECT r.slug FROM roles r
             JOIN user_roles ur ON ur.role_id = r.id
             WHERE ur.user_id = $userId AND r.is_active = 1"
        );
        if ($r) while ($row = $r->fetch_assoc()) $slugs[] = $row['slug'];
    }
    $cache[$userId] = $slugs;
    return $slugs;
}

// Detail role milik user (id, name, slug, description)
function getUserRoles($userId = null) {
    static $cache = [];
    if ($userId === null) $userId = getCurrentUserId();
    $userId = (int)$userId;
    if (isset($cache[$userId])) return $cache[$userId];

    $roles = [];
    $conn = getConnection();
    if ($conn && $userId > 0) {
        $r = $conn->query(
            "SELECT r.* FROM roles r
             JOIN user_roles ur ON ur.role_id = r.id
             WHERE ur.user_id = $userId ORDER BY r.id ASC"
        );
        if ($r) while ($row = $r->fetch_assoc()) $roles[] = $row;
    }
    $cache[$userId] = $roles;
    return $roles;
}

// True bila user adalah Super Admin (atau legacy role='admin')
function isSuperAdmin($userId = null) {
    if ($userId === null) $userId = getCurrentUserId();
    $userId = (int)$userId;
    if ($userId <= 0) return false;

    $slugs = getUserRoleSlugs($userId);
    if (in_array('super-admin', $slugs, true)) return true;

    // Backward compatibility: user lama dengan kolom role='admin'
    $conn = getConnection();
    if ($conn) {
        $r = $conn->query("SELECT role FROM users WHERE id = $userId LIMIT 1");
        if ($r && $r->num_rows > 0 && $r->fetch_assoc()['role'] === 'admin') return true;
    }
    return false;
}

// True bila user punya hak akses ke panel admin
function isAdminUser($userId = null) {
    if ($userId === null) $userId = getCurrentUserId();
    $userId = (int)$userId;
    if ($userId <= 0) return false;
    if (isSuperAdmin($userId)) return true;
    return count(getUserRoleSlugs($userId)) > 0;
}

// Nama role utama untuk ditampilkan di header
function getPrimaryRoleName($userId = null) {
    if ($userId === null) $userId = getCurrentUserId();
    $roles = getUserRoles($userId);
    if (!empty($roles)) return $roles[0]['name'];
    return 'Administrator';
}

// ============================================
// PERMISSION HELPERS
// ============================================
// Kumpulan key "module:action" yang dimiliki user
function getUserPermissionSet($userId = null) {
    static $cache = [];
    if ($userId === null) $userId = getCurrentUserId();
    $userId = (int)$userId;
    if (isset($cache[$userId])) return $cache[$userId];

    $set = [];
    $conn = getConnection();
    if ($conn && $userId > 0) {
        $sql =
            "SELECT DISTINCT CONCAT(p.module, ':', p.action) AS perm_key
             FROM permissions p
             JOIN role_permissions rp ON rp.permission_id = p.id
             JOIN user_roles ur ON ur.role_id = rp.role_id
             WHERE ur.user_id = $userId AND p.is_active = 1
             UNION
             SELECT DISTINCT CONCAT(p.module, ':', p.action) AS perm_key
             FROM permissions p
             JOIN user_permissions up ON up.permission_id = p.id
             WHERE up.user_id = $userId AND p.is_active = 1";
        $r = $conn->query($sql);
        if ($r) while ($row = $r->fetch_assoc()) $set[] = $row['perm_key'];
    }
    $cache[$userId] = $set;
    return $set;
}

function hasPermission($module, $action = 'view', $userId = null) {
    if ($userId === null) $userId = getCurrentUserId();
    $userId = (int)$userId;
    if ($userId <= 0) return false;
    if (isSuperAdmin($userId)) return true;
    return in_array($module . ':' . $action, getUserPermissionSet($userId), true);
}

// Blokir akses bila tidak punya permission
function requirePermission($module, $action = 'view') {
    if (!hasPermission($module, $action)) {
        http_response_code(403);
        if (!headers_sent()) {
            header('Location: ' . SITE_URL . '/admin/403.php?module=' . urlencode($module) . '&action=' . urlencode($action));
            exit;
        }
        die('403 - Akses ditolak. Anda tidak memiliki permission ' . htmlspecialchars($module) . ':' . htmlspecialchars($action));
    }
}

// ============================================
// BRANCH ACCESS
// ============================================
// Kosong = akses semua cabang. Terisi = hanya cabang tsb.
function getAccessibleBranchIds($userId = null) {
    static $cache = [];
    if ($userId === null) $userId = getCurrentUserId();
    $userId = (int)$userId;
    if (isset($cache[$userId])) return $cache[$userId];

    $ids = [];
    $conn = getConnection();
    if ($conn && $userId > 0) {
        $r = $conn->query("SELECT branch_id FROM user_branches WHERE user_id = $userId");
        if ($r) while ($row = $r->fetch_assoc()) $ids[] = (int)$row['branch_id'];
    }
    $cache[$userId] = $ids;
    return $ids;
}

function hasBranchAccess($branchId, $userId = null) {
    if (isSuperAdmin($userId)) return true;
    $ids = getAccessibleBranchIds($userId);
    if (empty($ids)) return true; // tanpa batasan = semua cabang
    return in_array((int)$branchId, $ids, true);
}

// SQL fragment: WHERE branch terbatas (dipakai halaman yang punya kolom branch)
function branchScopeSql($alias = 'b') {
    if (isSuperAdmin()) return '1=1';
    $ids = getAccessibleBranchIds();
    if (empty($ids)) return '1=1';
    return $alias . '.branch_id IN (' . implode(',', array_map('intval', $ids)) . ')';
}

// ============================================
// AUDIT LOG
// ============================================
function logActivity($action, $module = '', $description = '') {
    if (!tableExists('activity_logs')) return;
    $conn = getConnection();
    if (!$conn) return;

    $userId = getCurrentUserId();
    $userName = $_SESSION['user_name'] ?? '';
    $uid = $userId > 0 ? $userId : 'NULL';
    $action_e = $conn->real_escape_string(mb_substr($action, 0, 50));
    $module_e = $conn->real_escape_string(mb_substr($module, 0, 100));
    $desc_e = $conn->real_escape_string(mb_substr($description, 0, 1000));
    $ip = $conn->real_escape_string(getClientIp());
    $ua = $conn->real_escape_string(mb_substr(getUserAgent(), 0, 500));

    $conn->query(
        "INSERT INTO activity_logs (user_id, user_name, action, module, description, ip_address, user_agent)
         VALUES ($uid, '$userName', '$action_e', '$module_e', '$desc_e', '$ip', '$ua')"
    );
}

function logLoginAttempt($userId, $email, $success) {
    if (!tableExists('login_history')) return;
    $conn = getConnection();
    if (!$conn) return;

    $uid = $userId > 0 ? (int)$userId : 'NULL';
    $email_e = $conn->real_escape_string(mb_substr($email, 0, 255));
    $successI = $success ? 1 : 0;
    $ip = $conn->real_escape_string(getClientIp());
    $ua = $conn->real_escape_string(mb_substr(getUserAgent(), 0, 500));
    $browser = $conn->real_escape_string(getBrowserName($ua));
    $device = $conn->real_escape_string(getDeviceName($ua));

    $conn->query(
        "INSERT INTO login_history (user_id, email, success, ip_address, user_agent, device, browser)
         VALUES ($uid, '$email_e', $successI, '$ip', '$ua', '$device', '$browser')"
    );
}

// ============================================
// SESSION MANAGEMENT
// ============================================
function createUserSession($userId) {
    $conn = getConnection();
    if (!$conn || !tableExists('user_sessions')) return null;

    $userId = (int)$userId;
    $token = bin2hex(random_bytes(32));
    $ua = getUserAgent();
    $ip = getClientIp();
    $browser = getBrowserName($ua);
    $device = getDeviceName($ua);
    $expires = date('Y-m-d H:i:s', time() + RBAC_SESSION_LIFETIME);

    $token_e = $conn->real_escape_string($token);
    $ua_e = $conn->real_escape_string(mb_substr($ua, 0, 500));
    $ip_e = $conn->real_escape_string($ip);
    $browser_e = $conn->real_escape_string($browser);
    $device_e = $conn->real_escape_string($device);

    $conn->query(
        "INSERT INTO user_sessions (user_id, session_token, ip_address, user_agent, device, browser, expires_at)
         VALUES ($userId, '$token_e', '$ip_e', '$ua_e', '$browser_e', '$device_e', '$expires')"
    );
    if ($conn->insert_id > 0) {
        $_SESSION['session_token'] = $token;
        return $token;
    }
    return null;
}

// Verifikasi token sesi di DB + idle timeout. Return false = harus logout.
function verifyUserSession($userId) {
    $userId = (int)$userId;
    if ($userId <= 0) return false;
    if (!tableExists('user_sessions')) return true; // belum migrasi -> izinkan

    $conn = getConnection();
    if (!$conn) return false;

    $token = $_SESSION['session_token'] ?? '';
    $uid = (int)$userId;

    // Tanpa token = sesi lawas dari sebelum RBAC -> tetap izinkan sekali
    if ($token === '') return true;

    $token_e = $conn->real_escape_string($token);
    $r = $conn->query(
        "SELECT id, last_activity, expires_at FROM user_sessions
         WHERE user_id = $uid AND session_token = '$token_e' AND is_active = 1 LIMIT 1"
    );
    if (!$r || $r->num_rows === 0) return false;

    $s = $r->fetch_assoc();

    // Pastikan user masih aktif & tidak dikunci (suspend/lock berlaku seketika)
    $rUser = $conn->query("SELECT is_active, is_locked FROM users WHERE id = $uid LIMIT 1");
    if ($rUser && $rUser->num_rows > 0) {
        $uRow = $rUser->fetch_assoc();
        if (!(int)$uRow['is_active'] || (int)$uRow['is_locked']) return false;
    }

    // Absolute expiry
    if (strtotime($s['expires_at']) < time()) return false;

    // Idle timeout
    $lastActivity = $s['last_activity'] ? strtotime($s['last_activity']) : time();
    if ((time() - $lastActivity) > RBAC_IDLE_TIMEOUT) return false;

    // Refresh last_activity (jangan setiap request - cukup jika > 60 detik lalu)
    if ((time() - $lastActivity) > 60) {
        $conn->query("UPDATE user_sessions SET last_activity = NOW() WHERE id = " . (int)$s['id']);
    }
    return true;
}

function destroyUserSession($userId, $token = null) {
    $conn = getConnection();
    if (!$conn || !tableExists('user_sessions')) return;
    $uid = (int)$userId;
    if ($token !== null && $token !== '') {
        $token_e = $conn->real_escape_string($token);
        $conn->query("UPDATE user_sessions SET is_active = 0 WHERE user_id = $uid AND session_token = '$token_e'");
    } else {
        $conn->query("UPDATE user_sessions SET is_active = 0 WHERE user_id = $uid");
    }
}

// Logout semua sesi user lain (untuk "logout semua perangkat")
function revokeOtherSessions($userId, $keepToken) {
    $conn = getConnection();
    if (!$conn || !tableExists('user_sessions')) return;
    $uid = (int)$userId;
    $keep_e = $conn->real_escape_string($keepToken);
    $conn->query("UPDATE user_sessions SET is_active = 0 WHERE user_id = $uid AND session_token <> '$keep_e'");
}

// ============================================
// REMEMBER ME (helper; restore dipanggil di config/database.php)
// ============================================
// Cookie preferensi 'ingat saya' user: 1 = aktif (default), 0 = nonaktif.
// Disimpan terpisah dari token login agar pilihan user tetap diingat
// meskipun ia logout atau belum login.
if (!defined('RBAC_REMEMBER_PREF_COOKIE')) define('RBAC_REMEMBER_PREF_COOKIE', 'nn_remember_pref');

function setRememberCookie($token) {
    setcookie(RBAC_REMEMBER_COOKIE, $token, [
        'expires' => time() + RBAC_SESSION_LIFETIME,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function clearRememberCookie() {
    setcookie(RBAC_REMEMBER_COOKIE, '', time() - 3600, '/');
}

// Simpan preferensi 'ingat saya' (1 tahun).
function setRememberPreference($on) {
    setcookie(RBAC_REMEMBER_PREF_COOKIE, $on ? '1' : '0', [
        'expires' => time() + 60 * 60 * 24 * 365,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// Preferensi 'ingat saya' — DEFAULT AKTIF: guest yang belum pernah memilih
// dianggap ingin tetap login, sehingga tidak perlu login ulang tiap kunjungan.
function rememberPrefEnabled() {
    return !isset($_COOKIE[RBAC_REMEMBER_PREF_COOKIE]) || $_COOKIE[RBAC_REMEMBER_PREF_COOKIE] === '1';
}

// ============================================
// NOTIFICATIONS
// ============================================
function notify($userId, $title, $message = '', $type = 'info', $link = '') {
    $conn = getConnection();
    if (!$conn || !tableExists('notifications')) return;
    $uid = (int)$userId;
    if ($uid <= 0) return;
    $title_e = $conn->real_escape_string(mb_substr($title, 0, 255));
    $msg_e = $conn->real_escape_string(mb_substr($message, 0, 1000));
    $type_e = $conn->real_escape_string(mb_substr($type, 0, 50));
    $link_e = $conn->real_escape_string(mb_substr($link, 0, 255));
    $conn->query(
        "INSERT INTO notifications (user_id, title, message, type, link)
         VALUES ($uid, '$title_e', '$msg_e', '$type_e', '$link_e')"
    );
}

// Kirim notifikasi ke semua user yang memegang role tertentu (+ Super Admin)
function notifyRole($roleSlug, $title, $message = '', $type = 'info', $link = '') {
    $conn = getConnection();
    if (!$conn || !tableExists('notifications')) return;
    $slug_e = $conn->real_escape_string($roleSlug);

    $users = [];
    $r = $conn->query(
        "SELECT DISTINCT ur.user_id FROM user_roles ur
         JOIN roles r ON r.id = ur.role_id
         JOIN users u ON u.id = ur.user_id
         WHERE r.slug = '$slug_e' AND r.is_active = 1 AND u.is_active = 1"
    );
    if ($r) while ($row = $r->fetch_assoc()) $users[] = (int)$row['user_id'];

    // Super admin juga selalu dapat
    $r2 = $conn->query(
        "SELECT DISTINCT ur.user_id FROM user_roles ur
         JOIN roles r ON r.id = ur.role_id
         JOIN users u ON u.id = ur.user_id
         WHERE r.slug = 'super-admin' AND u.is_active = 1"
    );
    if ($r2) while ($row = $r2->fetch_assoc()) $users[] = (int)$row['user_id'];

    $users = array_unique($users);
    foreach ($users as $uid) {
        notify($uid, $title, $message, $type, $link);
    }
}

// Kirim notifikasi ke semua user yang punya akses view pada module
// Notifikasi admin saat pembayaran LUNAS — dipakai SEMUA jalur pembayaran lunas:
// webhook Midtrans & polling (config/midtrans.php) + verifikasi manual (admin/payments.php,
// admin/order-detail.php). Target: role dari setting sound_notify_role + Super Admin.
function notifyPaymentPaid($orderId, $orderNumber, $total, $label = '') {
    if (empty($orderId) || $orderNumber === '') return;
    $soundRole = trim(getSetting('sound_notify_role', 'admin-penjualan-online'));
    if ($soundRole === '') return;
    $msg = 'Pembayaran sebesar Rp ' . number_format((float)$total, 0, ',', '.');
    if ($label !== '') $msg .= ' (' . $label . ')';
    $msg .= ' telah lunas — invoice otomatis terverifikasi.';
    notifyRole(
        $soundRole,
        '✅ Pembayaran LUNAS #' . $orderNumber,
        $msg,
        'success',
        SITE_URL . '/admin/order-detail.php?id=' . (int)$orderId
    );
}

function notifyModule($module, $title, $message = '', $type = 'info', $link = '') {
    $conn = getConnection();
    if (!$conn || !tableExists('notifications')) return;
    $module_e = $conn->real_escape_string($module);

    $r = $conn->query(
        "SELECT DISTINCT ur.user_id FROM user_roles ur
         JOIN role_permissions rp ON rp.role_id = ur.role_id
         JOIN permissions p ON p.id = rp.permission_id
         WHERE p.module = '$module_e' AND p.action = 'view'"
    );
    $users = [];
    if ($r) while ($row = $r->fetch_assoc()) $users[] = (int)$row['user_id'];

    // Super admin juga selalu dapat
    $r2 = $conn->query(
        "SELECT DISTINCT ur.user_id FROM user_roles ur
         JOIN roles r ON r.id = ur.role_id WHERE r.slug = 'super-admin'"
    );
    if ($r2) while ($row = $r2->fetch_assoc()) $users[] = (int)$row['user_id'];

    $users = array_unique($users);
    foreach ($users as $uid) {
        notify($uid, $title, $message, $type, $link);
    }
}

function getUnreadNotificationCount($userId = null) {
    if ($userId === null) $userId = getCurrentUserId();
    $userId = (int)$userId;
    if ($userId <= 0 || !tableExists('notifications')) return 0;
    $conn = getConnection();
    if (!$conn) return 0;
    $r = $conn->query("SELECT COUNT(*) AS c FROM notifications WHERE user_id = $userId AND is_read = 0");
    return $r ? (int)$r->fetch_assoc()['c'] : 0;
}

// ============================================
// DYNAMIC SIDEBAR
// ============================================
// Mengembalikan menu yang terlihat oleh user, dikelompokkan per section.
// Mendukung submenu (parent_id) dengan nesting satu tingkat.
function buildSidebarMenus($userId = null) {
    if ($userId === null) $userId = getCurrentUserId();
    $conn = getConnection();
    $sections = [];
    if (!$conn || !tableExists('menus')) return $sections;

    $r = $conn->query("SELECT * FROM menus WHERE is_active = 1 ORDER BY section, sort_order ASC, id ASC");
    if (!$r) return $sections;

    $visible = [];
    while ($m = $r->fetch_assoc()) {
        $module = $m['module'] !== '' && $m['module'] !== null ? $m['module'] : $m['slug'];
        if (hasPermission($module, 'view', $userId)) {
            $visible[$m['id']] = $m;
        }
    }

    // Petakan anak ke parent (dua pass agar urutan data tidak masalah)
    $childrenMap = [];
    foreach ($visible as $id => $m) {
        $pid = (int)$m['parent_id'];
        if ($pid > 0 && isset($visible[$pid])) {
            $childrenMap[$pid][] = $id;
        }
    }

    foreach ($visible as $id => $m) {
        $pid = (int)$m['parent_id'];
        // Anak: ditampilkan lewat parent. Anak yatim (parent tidak terlihat /
        // tidak punya permission) TIDAK ditampilkan sendiri — konsisten dengan
        // prinsip "menu tidak boleh muncul bila tidak punya akses".
        if ($pid > 0) continue;
        if (!empty($childrenMap[$id])) {
            foreach ($childrenMap[$id] as $cid) {
                $m['children'][] = $visible[$cid];
            }
        }
        $sections[$m['section']][] = $m;
    }
    return $sections;
}

// ============================================
// DASHBOARD WIDGETS
// ============================================
// Widget yang boleh tampil di dashboard user
function getUserWidgetSlugs($userId = null) {
    static $cache = [];
    if ($userId === null) $userId = getCurrentUserId();
    $userId = (int)$userId;
    if (isset($cache[$userId])) return $cache[$userId];

    if (isSuperAdmin($userId)) {
        $cache[$userId] = null; // null = semua widget
        return $cache[$userId];
    }

    $slugs = [];
    $conn = getConnection();
    if ($conn && tableExists('role_widgets')) {
        $r = $conn->query(
            "SELECT DISTINCT w.slug FROM widgets w
             JOIN role_widgets rw ON rw.widget_id = w.id
             JOIN user_roles ur ON ur.role_id = rw.role_id
             WHERE ur.user_id = $userId AND w.is_active = 1
             ORDER BY w.slug ASC"
        );
        if ($r) while ($row = $r->fetch_assoc()) $slugs[] = $row['slug'];
    }
    $cache[$userId] = $slugs;
    return $slugs;
}

function getAllWidgets() {
    $conn = getConnection();
    $widgets = [];
    if ($conn && tableExists('widgets')) {
        $r = $conn->query("SELECT * FROM widgets WHERE is_active = 1 ORDER BY sort_order, id");
        if ($r) while ($row = $r->fetch_assoc()) $widgets[] = $row;
    }
    return $widgets;
}

// ============================================
// CSRF LIGHT (untuk aksi berbahaya admin)
// ============================================
function csrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf_token'];
}

function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . csrfToken() . '">';
}

function verifyCsrf() {
    $token = $_POST['csrf_token'] ?? ($_GET['csrf_token'] ?? '');
    if ($token === '' || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(419);
        die('Session kadaluarsa. Silakan muat ulang halaman dan coba lagi.');
    }
    return true;
}
