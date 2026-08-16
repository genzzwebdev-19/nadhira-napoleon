<?php
// ============================================
// LOGOUT
// Nadhira Napoleon Pekanbaru
// ============================================
require_once __DIR__ . '/../config/rbac.php';

// Audit log & revoke session di database
if (isLoggedIn()) {
    $userId = getCurrentUserId();
    logActivity('logout', 'auth', 'Logout');
    destroyUserSession($userId, $_SESSION['session_token'] ?? null);
}

// Hapus cookie remember me (token login).
// Catatan: cookie PREFERENSI 'ingat saya' (nn_remember_pref) sengaja TIDAK dihapus —
// pilihan user (aktif/nonaktif) tetap diingat untuk login berikutnya.
clearRememberCookie();

// Clear all session data
$_SESSION = array();

// Destroy the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy session
session_destroy();

// Redirect to login
header('Location: ' . SITE_URL . '/auth/login.php');
exit;
