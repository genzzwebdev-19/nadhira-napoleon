<?php
// ============================================
// ADMIN LAYOUT - Shared template (RBAC)
// ============================================
require_once __DIR__ . '/../config/rbac.php';

if (!isLoggedIn()) {
    header('Location: ' . SITE_URL . '/auth/login.php');
    exit;
}

$user = getCurrentUser();
if (!$user) {
    $_SESSION = array();
    session_destroy();
    header('Location: ' . SITE_URL . '/auth/login.php');
    exit;
}

// Tabel RBAC belum ada? Arahkan ke seeder.
if (!tableExists('roles')) {
    echo '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><title>Setup RBAC</title></head>'
       . '<body style="font-family:sans-serif;background:#f5f2ed;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;">'
       . '<div style="background:#fff;padding:40px;border-radius:16px;max-width:560px;box-shadow:0 8px 30px rgba(0,0,0,.1);text-align:center;">'
       . '<h2 style="margin-top:0;color:#1a1a2e;">🔐 Sistem RBAC belum diinstal</h2>'
       . '<p style="color:#666;">Jalankan RBAC Seeder terlebih dahulu melalui browser:</p>'
       . '<p><code style="background:#f0ede8;padding:8px 12px;border-radius:6px;">' . SITE_URL . '/database/rbac-seeder.php?run=1</code></p>'
       . '<p style="font-size:13px;color:#999;">atau instal ulang database via <code>' . SITE_URL . '/database/init.php</code></p>'
       . '</div></body></html>';
    exit;
}

// Hanya user dengan role admin yang boleh masuk panel
if (!isAdminUser()) {
    header('Location: ' . SITE_URL);
    exit;
}

// Verifikasi sesi (token database + idle timeout)
if (!verifyUserSession((int)$user['id'])) {
    destroyUserSession((int)$user['id'], $_SESSION['session_token'] ?? null);
    $_SESSION = array();
    session_destroy();
    header('Location: ' . SITE_URL . '/auth/login.php?expired=1');
    exit;
}

if (!isset($currentPage)) $currentPage = 'dashboard';

// ============================================
// PERMISSION GUARD untuk halaman ini
// Halaman dapat men-set $requiredModule / $requiredAction
// sebelum memanggil layout.php (default: $currentPage : view)
// ============================================
$requiredModule = $requiredModule ?? $currentPage;
$requiredAction = $requiredAction ?? 'view';
if (!hasPermission($requiredModule, $requiredAction)) {
    header('Location: ' . SITE_URL . '/admin/403.php?module=' . urlencode($requiredModule) . '&action=' . urlencode($requiredAction));
    exit;
}

// ============================================
// DATA SIDEBAR & HEADER
// ============================================
$navSections = buildSidebarMenus();
$roleName = getPrimaryRoleName();
$branchIds = getAccessibleBranchIds();
$branchLabel = empty($branchIds) ? 'Semua Cabang' : (count($branchIds) . ' Cabang');

$conn = getConnection();

// ============================================
// PENJADWAL BACKUP OTOMATIS (poor man's cron)
// Cek ringan tiap halaman admin: hanya menjalankan backup
// bila jadwal sudah lewat & fitur aktif (sekali sehari maks).
// ============================================
require_once __DIR__ . '/../includes/backup-helper.php';
runAutoBackupIfDue(false);

$navBadges = [];
if ($conn) {
    if (hasPermission('messages', 'view')) {
        $r = $conn->query("SELECT COUNT(*) as c FROM contacts WHERE is_read = FALSE");
        $navBadges['messages'] = $r ? (int)$r->fetch_assoc()['c'] : 0;
    }
    if (hasPermission('orders', 'view')) {
        $r = $conn->query("SELECT COUNT(*) as c FROM orders WHERE order_status = 'pending'");
        $navBadges['orders'] = $r ? (int)$r->fetch_assoc()['c'] : 0;
        $navBadges['payments'] = 0;
        $checkTable = $conn->query("SHOW TABLES LIKE 'payment_confirmations'");
        if ($checkTable && $checkTable->num_rows > 0) {
            $r = $conn->query("SELECT COUNT(*) as c FROM payment_confirmations WHERE status = 'pending'");
            $navBadges['payments'] = $r ? (int)$r->fetch_assoc()['c'] : 0;
        }
    }
}

$notifCount = getUnreadNotificationCount();
$notifItems = [];
if ($conn) {
    $r = $conn->query("SELECT * FROM notifications WHERE user_id = " . (int)$user['id'] . " ORDER BY created_at DESC LIMIT 8");
    if ($r) while ($row = $r->fetch_assoc()) $notifItems[] = $row;
}

// Role penerima notifikasi suara & desktop (setting, default: Admin Penjualan Online)
$soundNotifyRole = trim(getSetting('sound_notify_role', 'admin-penjualan-online'));
$isSoundNotifyRole = in_array($soundNotifyRole, getUserRoleSlugs(), true);

// Ukuran logo (mengikuti setting Logo Navbar di Admin > Pengaturan)
$adminLogoHeight = (int)getSetting('navbar_logo_height', '90');
if ($adminLogoHeight < 40 || $adminLogoHeight > 200) { $adminLogoHeight = 90; }
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? $pageTitle . ' - Admin ' . SITE_NAME : 'Admin ' . SITE_NAME ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css?v=1.0">
    <style>
        :root { --sidebar-width: 280px; }
        * { box-sizing: border-box; }
        .admin-layout { display: flex; min-height: 100vh; }

        /* Sidebar */
        .admin-sidebar {
            background: linear-gradient(180deg, #1a1a2e 0%, #16213e 100%);
            padding: 0; position: fixed; left: 0; top: 0; bottom: 0;
            width: var(--sidebar-width); overflow-y: auto; z-index: 1000;
            transition: transform 0.3s ease;
        }
        .sidebar-header {
            padding: 20px 24px; border-bottom: 1px solid rgba(255,248,240,0.08);
            display: flex; flex-direction: column; align-items: center;
            gap: 10px; text-align: center;
        }
        .sidebar-logo {
            width: 100%; display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .sidebar-logo img {
            /* Lebar mengikuti setting tinggi logo, tapi dibatasi agar tidak keluar sidebar */
            width: min(calc(var(--navbar-logo-height, 90px) * 0.9955), 100%);
            height: auto;
            object-fit: contain; border-radius: 8px;
        }
        .sidebar-brand-text { line-height: 1.2; }
        .sidebar-brand-text strong {
            font-family: 'Playfair Display', serif; font-size: 16px;
            color: #fff; display: block;
        }
        .sidebar-brand-text small {
            font-size: 11px; color: rgba(255,248,240,0.5);
        }
        .sidebar-nav { padding: 12px 16px; }
        .sidebar-section-title {
            font-size: 10px; text-transform: uppercase; letter-spacing: 1.5px;
            color: rgba(255,248,240,0.3); padding: 16px 12px 8px; font-weight: 600;
        }
        .admin-nav-item {
            display: flex; align-items: center; gap: 12px;
            padding: 11px 14px; border-radius: 10px;
            color: rgba(255,248,240,0.55); transition: all 0.2s ease;
            margin-bottom: 2px; font-size: 13px; text-decoration: none;
            position: relative;
        }
        .admin-nav-item i { width: 20px; text-align: center; font-size: 14px; }
        .admin-nav-item:hover {
            background: rgba(255,248,240,0.08); color: rgba(255,248,240,0.9);
        }
        .admin-nav-item.active {
            background: linear-gradient(135deg, rgba(212,168,83,0.15), rgba(184,134,11,0.1));
            color: #D4A853;
        }
        .admin-nav-item.active::before {
            content: ''; position: absolute; left: 0; top: 50%;
            transform: translateY(-50%); width: 3px; height: 24px;
            background: #D4A853; border-radius: 0 3px 3px 0;
        }
        .admin-nav-child {
            padding-left: 42px; font-size: 12.5px;
            color: rgba(255,248,240,0.42);
        }
        .admin-nav-child i { font-size: 12px; }
        .admin-nav-child:hover { color: rgba(255,248,240,0.85); }
        .nav-badge {
            margin-left: auto; padding: 2px 8px; border-radius: 20px;
            font-size: 10px; font-weight: 600; min-width: 20px; text-align: center;
        }
        .nav-badge.danger { background: #FEE2E2; color: #DC2626; }
        .nav-badge.warning { background: #FEF3C7; color: #D97706; }
        .nav-badge.success { background: #D1FAE5; color: #059669; }
        .sidebar-footer {
            padding: 16px; border-top: 1px solid rgba(255,248,240,0.08);
            margin-top: 12px;
        }

        /* Main Content */
        .admin-main {
            margin-left: var(--sidebar-width); padding: 28px 32px;
            background: #f5f2ed; min-height: 100vh; width: 100%;
            transition: margin-left 0.3s ease;
        }
        .admin-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 28px; flex-wrap: wrap; gap: 16px;
        }
        .admin-header-left { display: flex; align-items: center; gap: 16px; }
        .sidebar-toggle {
            display: none; background: none; border: none;
            font-size: 20px; color: var(--text-primary); cursor: pointer;
            padding: 8px; border-radius: 8px;
        }
        .sidebar-toggle:hover { background: var(--soft-grey); }
        .admin-header h1 {
            font-family: 'Playfair Display', serif; font-size: 26px;
            font-weight: 700; color: var(--text-dark); margin: 0;
        }
        .admin-header-subtitle {
            color: var(--text-muted); font-size: 13px; margin: 4px 0 0;
        }
        .admin-header-right { display: flex; align-items: center; gap: 12px; }

        /* Notification Bell */
        .notif-wrap { position: relative; }
        .notif-bell {
            position: relative; width: 42px; height: 42px; border-radius: 50%;
            border: none; background: var(--warm-white); color: var(--text-primary);
            font-size: 17px; cursor: pointer; box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            transition: all 0.2s ease;
        }
        .notif-bell:hover { transform: translateY(-2px); color: #D4A853; }
        .notif-count {
            position: absolute; top: -4px; right: -4px; min-width: 18px; height: 18px;
            background: #EF4444; color: #fff; border-radius: 20px; font-size: 10px;
            font-weight: 700; display: flex; align-items: center; justify-content: center;
            padding: 0 4px; border: 2px solid #f5f2ed;
        }
        .notif-dropdown {
            position: absolute; right: 0; top: 50px; width: 340px;
            background: #fff; border-radius: 16px; box-shadow: 0 12px 40px rgba(0,0,0,0.15);
            overflow: hidden; z-index: 1200; display: none;
            border: 1px solid rgba(0,0,0,0.04);
        }
        .notif-dropdown.open { display: block; animation: slideDown 0.25s ease; }
        .notif-head {
            padding: 14px 18px; font-weight: 700; font-size: 14px; color: var(--text-dark);
            border-bottom: 1px solid #f0f0f0; display: flex; justify-content: space-between; align-items: center;
        }
        .notif-head a { font-size: 12px; color: #D4A853; text-decoration: none; font-weight: 600; }
        .notif-item {
            display: block; padding: 12px 18px; text-decoration: none; color: inherit;
            border-bottom: 1px solid #f7f7f7; transition: background 0.15s ease;
        }
        .notif-item:hover { background: rgba(212,168,83,0.06); }
        .notif-item.unread { background: #FFFBEB; }
        .notif-item.unread::before {
            content: ''; display: inline-block; width: 8px; height: 8px; border-radius: 50%;
            background: #D4A853; margin-right: 8px;
        }
        .notif-item strong { display: block; font-size: 13px; color: var(--text-dark); }
        .notif-item span { display: block; font-size: 12px; color: var(--text-muted); margin: 2px 0; }
        .notif-item small { font-size: 11px; color: var(--text-light); }
        .notif-empty { padding: 32px; text-align: center; color: var(--text-muted); font-size: 13px; }

        .admin-profile {
            display: flex; align-items: center; gap: 12px;
            padding: 6px 12px 6px 6px; border-radius: 50px;
            background: var(--warm-white); box-shadow: 0 1px 3px rgba(0,0,0,0.06);
        }
        .admin-avatar {
            width: 36px; height: 36px; border-radius: 50%;
            background: linear-gradient(135deg, #D4A853, #B8860B);
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-size: 14px; font-weight: 600; flex-shrink: 0;
        }
        .admin-profile-name { font-weight: 600; font-size: 13px; line-height: 1.2; }
        .admin-profile-role { font-size: 11px; color: var(--text-muted); }

        /* Stats Grid */
        .stats-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px; margin-bottom: 28px;
        }
        .stat-card {
            background: #fff; border-radius: 16px; padding: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06); transition: all 0.3s ease;
            border: 1px solid rgba(0,0,0,0.04);
        }
        .stat-card:hover {
            transform: translateY(-4px); box-shadow: 0 8px 24px rgba(0,0,0,0.1);
        }
        .stat-card-header {
            display: flex; align-items: center; gap: 16px; margin-bottom: 16px;
        }
        .stat-card-icon {
            width: 48px; height: 48px; border-radius: 14px;
            background: linear-gradient(135deg, rgba(212,168,83,0.12), rgba(184,134,11,0.08));
            display: flex; align-items: center; justify-content: center;
            color: #D4A853; font-size: 20px;
        }
        .stat-card-icon.warning {
            background: linear-gradient(135deg, rgba(239,68,68,0.12), rgba(239,68,68,0.08));
            color: #EF4444;
        }
        .stat-card-icon.info {
            background: linear-gradient(135deg, rgba(59,130,246,0.12), rgba(59,130,246,0.08));
            color: #3B82F6;
        }
        .stat-card-icon.success {
            background: linear-gradient(135deg, rgba(16,185,129,0.12), rgba(16,185,129,0.08));
            color: #10B981;
        }
        .stat-card-value {
            font-family: 'Playfair Display', serif; font-size: 28px;
            font-weight: 700; color: var(--text-dark); line-height: 1.1;
        }
        .stat-card-label { font-size: 13px; color: var(--text-muted); }
        .stat-card-change {
            font-size: 11px; font-weight: 500; display: inline-flex;
            align-items: center; gap: 4px; margin-top: 8px;
        }
        .stat-card-change.up { color: #10B981; }
        .stat-card-change.down { color: #EF4444; }

        /* Tables */
        .admin-card {
            background: #fff; border-radius: 16px; padding: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06); margin-bottom: 20px;
            border: 1px solid rgba(0,0,0,0.04);
        }
        .admin-card-title {
            font-family: 'Playfair Display', serif; font-size: 18px;
            font-weight: 600; margin-bottom: 20px; color: var(--text-dark);
        }
        .admin-table {
            width: 100%; border-collapse: separate; border-spacing: 0;
            background: #fff; border-radius: 12px; overflow: hidden;
        }
        .admin-table th {
            text-align: left; padding: 14px 16px;
            background: #f8f6f4; font-size: 12px;
            font-weight: 600; color: var(--text-muted);
            text-transform: uppercase; letter-spacing: 0.5px;
            border-bottom: 1px solid #eee;
        }
        .admin-table td {
            padding: 14px 16px; border-bottom: 1px solid #f0f0f0;
            font-size: 13px; vertical-align: middle;
        }
        .admin-table tr:last-child td { border-bottom: none; }
        .admin-table tbody tr { transition: background 0.15s ease; }
        .admin-table tbody tr:hover td { background: rgba(212,168,83,0.04); }
        .admin-table tbody tr:last-child td:first-child { border-radius: 0 0 0 12px; }
        .admin-table tbody tr:last-child td:last-child { border-radius: 0 0 12px 0; }

        /* Status Badges */
        .status-badge {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 4px 14px; border-radius: 20px;
            font-size: 12px; font-weight: 500; white-space: nowrap;
        }
        .status-badge::before {
            content: ''; width: 6px; height: 6px; border-radius: 50%;
        }
        .status-badge.pending { background: #FEF3C7; color: #D97706; }
        .status-badge.pending::before { background: #D97706; }
        .status-badge.processing { background: #DBEAFE; color: #2563EB; }
        .status-badge.processing::before { background: #2563EB; }
        .status-badge.shipped { background: #E0E7FF; color: #4F46E5; }
        .status-badge.shipped::before { background: #4F46E5; }
        .status-badge.delivered { background: #D1FAE5; color: #059669; }
        .status-badge.delivered::before { background: #059669; }
        .status-badge.cancelled { background: #FEE2E2; color: #DC2626; }
        .status-badge.cancelled::before { background: #DC2626; }
        .status-badge.paid { background: #D1FAE5; color: #059669; }
        .status-badge.paid::before { background: #059669; }
        .status-badge.active { background: #D1FAE5; color: #059669; }
        .status-badge.active::before { background: #059669; }
        .status-badge.inactive { background: #FEE2E2; color: #DC2626; }
        .status-badge.inactive::before { background: #DC2626; }
        .status-badge.silver { background: #F1F5F9; color: #64748B; }
        .status-badge.silver::before { background: #64748B; }
        .status-badge.gold { background: #FEF3C7; color: #D97706; }
        .status-badge.gold::before { background: #D97706; }
        .status-badge.platinum { background: #E0E7FF; color: #4F46E5; }
        .status-badge.platinum::before { background: #4F46E5; }
        .status-badge.diamond { background: #C7D2FE; color: #3730A3; }
        .status-badge.diamond::before { background: #3730A3; }
        .status-badge.refunded { background: #FCE7F3; color: #DB2777; }
        .status-badge.refunded::before { background: #DB2777; }
        .status-badge.failed { background: #FEE2E2; color: #DC2626; }
        .status-badge.failed::before { background: #DC2626; }
        .status-badge.verified { background: #D1FAE5; color: #059669; }
        .status-badge.verified::before { background: #059669; }
        .status-badge.rejected { background: #FEE2E2; color: #DC2626; }
        .status-badge.rejected::before { background: #DC2626; }

        /* Forms */
        .search-box { display: flex; gap: 8px; max-width: 400px; }
        .search-box input { flex: 1; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .form-group { margin-bottom: 16px; }
        .form-group label {
            display: block; font-size: 13px; font-weight: 600;
            color: var(--text-dark); margin-bottom: 6px;
        }
        .form-input, .form-select, .form-textarea {
            width: 100%; padding: 10px 14px; border: 1.5px solid #e5e0db;
            border-radius: 10px; font-size: 13px; transition: all 0.2s;
            background: #fff; color: var(--text-dark);
        }
        .form-input:focus, .form-select:focus, .form-textarea:focus {
            outline: none; border-color: #D4A853; box-shadow: 0 0 0 3px rgba(212,168,83,0.12);
        }
        .form-textarea { min-height: 100px; resize: vertical; }

        /* Alerts */
        .alert {
            padding: 14px 18px; border-radius: 12px; margin-bottom: 20px;
            font-size: 13px; display: flex; align-items: center; gap: 10px;
            animation: slideDown 0.3s ease;
        }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-8px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .alert-success { background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; }
        .alert-error { background: #FEF2F2; color: #DC2626; border: 1px solid #fecaca; }
        .alert-info { background: #EFF6FF; color: #2563EB; border: 1px solid #bfdbfe; }

        /* Buttons */
        .btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 10px 20px; border-radius: 10px; font-size: 13px;
            font-weight: 600; border: none; cursor: pointer;
            transition: all 0.2s ease; text-decoration: none; white-space: nowrap;
        }
        .btn:hover { transform: translateY(-1px); }
        .btn-primary {
            background: linear-gradient(135deg, #D4A853, #B8860B);
            color: #fff;
        }
        .btn-primary:hover { box-shadow: 0 4px 12px rgba(212,168,83,0.4); }
        .btn-outline {
            background: transparent; color: var(--text-primary);
            border: 1.5px solid #e5e0db;
        }
        .btn-outline:hover { border-color: #D4A853; color: #D4A853; }
        .btn-secondary { background: #f0edf5; color: var(--text-dark); }
        .btn-secondary:hover { background: #e5e0eb; }
        .btn-danger { background: #EF4444; color: #fff; }
        .btn-danger:hover { box-shadow: 0 4px 12px rgba(239,68,68,0.35); }
        .btn-sm { padding: 6px 14px; font-size: 12px; border-radius: 8px; }
        .btn-lg { padding: 12px 28px; font-size: 14px; }

        /* Sort Order Arrows */
        .sort-arrow { padding: 2px 6px !important; font-size: 10px !important; line-height: 1 !important; }
        .sort-disabled { opacity: 0.3; pointer-events: none; }
        .w-full { width: 100%; justify-content: center; }

        /* Permission matrix */
        .perm-group {
            border: 1px solid #eee; border-radius: 12px; padding: 16px 18px; margin-bottom: 14px;
            background: #fbfaf8;
        }
        .perm-group-title {
            font-weight: 700; font-size: 14px; color: var(--text-dark);
            display: flex; align-items: center; gap: 10px; margin-bottom: 10px;
        }
        .perm-checkboxes { display: flex; flex-wrap: wrap; gap: 8px 18px; }
        .perm-checkbox {
            display: inline-flex; align-items: center; gap: 6px; font-size: 13px;
            padding: 6px 12px; background: #fff; border: 1.5px solid #e5e0db;
            border-radius: 20px; cursor: pointer; transition: all 0.15s ease;
        }
        .perm-checkbox:hover { border-color: #D4A853; }
        .perm-checkbox input { accent-color: #D4A853; }
        .perm-checkbox.checked { border-color: #D4A853; background: rgba(212,168,83,0.08); }

        /* Filter bar */
        .filter-bar {
            display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end;
            background: #fff; padding: 16px 20px; border-radius: 14px; margin-bottom: 20px;
            border: 1px solid rgba(0,0,0,0.04); box-shadow: 0 1px 3px rgba(0,0,0,0.06);
        }
        .filter-bar .form-group { margin-bottom: 0; min-width: 140px; }

        /* Mobile Overlay */
        .sidebar-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.5); z-index: 999;
            opacity: 0; transition: opacity 0.3s ease;
        }
        .sidebar-overlay.active { opacity: 1; }

        /* Responsive */
        @media (max-width: 1024px) {
            .sidebar-toggle { display: block; }
            .admin-sidebar { transform: translateX(-100%); }
            .admin-sidebar.open { transform: translateX(0); }
            .sidebar-overlay.active { display: block; }
            .admin-main { margin-left: 0; padding: 20px 16px; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .form-row { grid-template-columns: 1fr; }
        }
        @media (max-width: 640px) {
            .stats-grid { grid-template-columns: 1fr; }
            .admin-header { flex-direction: column; align-items: flex-start; }
            .notif-dropdown { width: calc(100vw - 40px); }
            .admin-profile-name { display: none; }
        }
    </style>
</head>
<body style="--navbar-logo-height: <?= $adminLogoHeight ?>px;">
    <!-- Sidebar Overlay for Mobile -->
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

    <div class="admin-layout">
        <!-- Sidebar -->
        <aside class="admin-sidebar" id="adminSidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <img src="<?= SITE_URL ?>/foto/images.jpg" alt="Nadhira Napoleon Logo">
                </div>
                <div class="sidebar-brand-text">
                    <strong>Nadhira Admin</strong>
                    <small>Management Panel</small>
                </div>
            </div>
            <nav class="sidebar-nav">
                <?php foreach ($navSections as $section => $items): ?>
                <div class="sidebar-section-title"><?= htmlspecialchars($section) ?></div>
                <?php foreach ($items as $item):
                    $badge = '';
                    $badgeCount = $navBadges[$item['slug']] ?? 0;
                    if ($item['slug'] === 'orders' && isset($navBadges['payments']) && $navBadges['payments'] > 0) {
                        $badgeCount += $navBadges['payments'];
                    }
                    if ($badgeCount > 0) {
                        $badgeClass = $item['slug'] === 'messages' ? 'danger'
                            : ($item['slug'] === 'orders' && isset($navBadges['payments']) && $navBadges['payments'] > 0 ? 'success' : 'warning');
                        $badge = '<span class="nav-badge ' . $badgeClass . '">' . $badgeCount . '</span>';
                    }
                ?>
                <a href="<?= $item['url'] ?>" class="admin-nav-item <?= $currentPage === $item['slug'] ? 'active' : '' ?>">
                    <i class="fas <?= $item['icon'] ?>"></i>
                    <span><?= htmlspecialchars($item['name']) ?></span>
                    <?= $badge ?>
                </a>
                <?php if (!empty($item['children'])): foreach ($item['children'] as $child): ?>
                <a href="<?= $child['url'] ?>" class="admin-nav-item admin-nav-child <?= $currentPage === $child['slug'] ? 'active' : '' ?>">
                    <i class="fas <?= $child['icon'] ?>"></i>
                    <span><?= htmlspecialchars($child['name']) ?></span>
                </a>
                <?php endforeach; endif; ?>
                <?php endforeach; ?>
                <?php endforeach; ?>

                <div class="sidebar-section-title" style="margin-top: 16px;">Lainnya</div>
                <a href="<?= SITE_URL ?>" class="admin-nav-item" target="_blank">
                    <i class="fas fa-external-link-alt"></i> Lihat Website
                </a>
                <a href="<?= SITE_URL ?>/auth/logout.php" class="admin-nav-item" style="color: #EF4444;">
                    <i class="fas fa-sign-out-alt"></i> Keluar
                </a>
            </nav>
            <div class="sidebar-footer">
                <div style="display: flex; align-items: center; gap: 10px; padding: 4px 8px;">
                    <div class="admin-avatar" style="width: 32px; height: 32px; font-size: 13px;">
                        <?= strtoupper(substr($user['full_name'], 0, 1)) ?>
                    </div>
                    <div style="min-width: 0;">
                        <div style="color: rgba(255,248,240,0.9); font-size: 12px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                            <?= htmlspecialchars($user['full_name']) ?>
                        </div>
                        <div style="color: #D4A853; font-size: 11px;"><?= htmlspecialchars($roleName) ?></div>
                    </div>
                </div>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="admin-main">
            <div class="admin-header">
                <div class="admin-header-left">
                    <button class="sidebar-toggle" onclick="toggleSidebar()" aria-label="Toggle sidebar">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div>
                        <h1><?= $pageTitle ?? 'Dashboard' ?></h1>
                        <p class="admin-header-subtitle">Selamat datang, <?= htmlspecialchars($user['full_name']) ?> &middot; <?= htmlspecialchars($branchLabel) ?></p>
                    </div>
                </div>
                <div class="admin-header-right">
                    <!-- Notification Bell -->
                    <div class="notif-wrap">
                        <button class="notif-bell" onclick="toggleNotif(event)" aria-label="Notifikasi">
                            <i class="fas fa-bell"></i>
                            <?php if ($notifCount > 0): ?>
                                <span class="notif-count"><?= $notifCount > 99 ? '99+' : $notifCount ?></span>
                            <?php endif; ?>
                        </button>
                        <div class="notif-dropdown" id="notifDropdown">
                            <div class="notif-head">
                                Notifikasi
                                <a href="notifications.php">Lihat semua</a>
                            </div>
                            <?php if (empty($notifItems)): ?>
                                <div class="notif-empty"><i class="fas fa-bell-slash" style="font-size: 24px; margin-bottom: 8px; display: block;"></i>Tidak ada notifikasi</div>
                            <?php else: foreach ($notifItems as $n): ?>
                                <a class="notif-item <?= $n['is_read'] ? '' : 'unread' ?>" href="<?= $n['link'] ?: 'notifications.php' ?>">
                                    <strong><?= htmlspecialchars($n['title']) ?></strong>
                                    <span><?= htmlspecialchars(mb_substr($n['message'] ?? '', 0, 90)) ?></span>
                                    <small><?= formatDate($n['created_at'], 'd M Y H:i') ?></small>
                                </a>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>

                    <!-- Desktop Notification Toggle (hanya untuk role penerima suara sound_notify_role) -->
                    <?php if ($isSoundNotifyRole): ?>
                    <button class="notif-bell" id="desktopNotifToggle" onclick="toggleDesktopNotif(event)" aria-label="Notifikasi desktop" title="Aktifkan notifikasi desktop">
                        <i class="fas fa-bell-slash"></i>
                    </button>
                    <button class="notif-bell" id="testNotifBtn" onclick="testNotif(event)" aria-label="Tes notifikasi" title="Tes bunyi & notifikasi desktop">
                        <i class="fas fa-bullhorn"></i>
                    </button>
                    <?php endif; ?>

                    <!-- Profile -->
                    <a href="profile.php" class="admin-profile" style="text-decoration: none; color: inherit;">
                        <div class="admin-avatar"><?= strtoupper(substr($user['full_name'], 0, 1)) ?></div>
                        <div>
                            <div class="admin-profile-name"><?= htmlspecialchars($user['full_name']) ?></div>
                            <div class="admin-profile-role"><?= htmlspecialchars($roleName) ?></div>
                        </div>
                    </a>
                </div>
            </div>

            <script>
            function toggleSidebar() {
                document.getElementById('adminSidebar').classList.toggle('open');
                document.getElementById('sidebarOverlay').classList.toggle('active');
            }
            function toggleNotif(e) {
                e.stopPropagation();
                document.getElementById('notifDropdown').classList.toggle('open');
            }
            document.addEventListener('click', function (event) {
                var dd = document.getElementById('notifDropdown');
                if (dd && !event.target.closest('.notif-wrap')) {
                    dd.classList.remove('open');
                }
            });
            </script>

            <!-- ============================================
                 NOTIFIKASI SUARA - Transaksi Baru
                 Bunyi + toast saat ada notifikasi baru.
                 Suara diaktifkan untuk role Admin Penjualan Online.
                 ============================================ -->
            <script>
            (function () {
                // Role dari setting sound_notify_role — dihitung server-side di atas
                var SOUND_ENABLED = <?= $isSoundNotifyRole ? 'true' : 'false' ?>;
                var DESKTOP_ENABLED = <?= $isSoundNotifyRole ? 'true' : 'false' ?>;
                var POLL_INTERVAL = 10000; // cek tiap 10 detik agar notifikasi lebih cepat
                var lastSeenId = 0;
                var initialized = false;
                var audioCtx = null;
                var SITE_ICON = '<?= SITE_URL ?>/foto/images.jpg';

                function ensureAudio() {
                    if (!audioCtx) {
                        try {
                            audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                        } catch (e) {}
                    }
                    if (audioCtx && audioCtx.state === 'suspended') {
                        audioCtx.resume();
                    }
                    return audioCtx;
                }

                // Bunyi "ding-dong" lembut via Web Audio (tanpa file eksternal)
                function playChime() {
                    var ctx = ensureAudio();
                    if (!ctx) return;
                    try {
                        var t = ctx.currentTime;
                        [880, 1174.66].forEach(function (freq, i) {
                            var osc = ctx.createOscillator();
                            var gain = ctx.createGain();
                            var start = t + i * 0.18;
                            osc.type = 'sine';
                            osc.frequency.value = freq;
                            gain.gain.setValueAtTime(0.0001, start);
                            gain.gain.exponentialRampToValueAtTime(0.22, start + 0.03);
                            gain.gain.exponentialRampToValueAtTime(0.0001, start + 0.6);
                            osc.connect(gain);
                            gain.connect(ctx.destination);
                            osc.start(start);
                            osc.stop(start + 0.65);
                        });
                    } catch (e) {}
                }
                // Ekspos global agar bisa dipakai halaman lain (mis. tombol "Tes Suara" di Pengaturan)
                window.nnPlayChime = playChime;

                function escapeHtml(s) {
                    return String(s == null ? '' : s)
                        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
                }

                function updateBadge(count) {
                    var bell = document.querySelector('.notif-bell');
                    if (!bell) return;
                    var badge = bell.querySelector('.notif-count');
                    if (count > 0) {
                        if (!badge) {
                            badge = document.createElement('span');
                            badge.className = 'notif-count';
                            bell.appendChild(badge);
                        }
                        badge.textContent = count > 99 ? '99+' : count;
                    } else if (badge) {
                        badge.remove();
                    }
                }

                function showToast(n) {
                    if (!n || !n.title) return;
                    var el = document.createElement('div');
                    el.style.cssText = 'position:fixed;top:16px;right:16px;z-index:99999;background:#fff;border-left:4px solid #D4A853;border-radius:12px;box-shadow:0 12px 40px rgba(0,0,0,.16);padding:14px 18px;max-width:340px;font-size:13px;opacity:0;transform:translateY(-8px);transition:all .3s ease;';
                    var linkHtml = n.link
                        ? '<a href="' + escapeHtml(n.link) + '" style="display:inline-block;margin-top:10px;color:#B8860B;font-weight:600;text-decoration:none;">Lihat sekarang &rarr;</a>'
                        : '';
                    el.innerHTML =
                        '<button style="position:absolute;top:8px;right:10px;border:none;background:none;font-size:16px;color:#999;cursor:pointer;" onclick="this.parentElement.remove()">&times;</button>'
                        + '<strong style="display:block;color:#1a1a2e;margin-bottom:4px;font-size:14px;">' + escapeHtml(n.title) + '</strong>'
                        + '<div style="color:#666;line-height:1.5;">' + escapeHtml(n.message) + '</div>'
                        + linkHtml;
                    document.body.appendChild(el);
                    requestAnimationFrame(function () {
                        el.style.opacity = '1';
                        el.style.transform = 'translateY(0)';
                    });
                    setTimeout(function () {
                        el.style.opacity = '0';
                        el.style.transform = 'translateY(-8px)';
                        setTimeout(function () { el.remove(); }, 350);
                    }, 7000);
                }

                // ===== NOTIFIKASI DESKTOP (Browser Notification API) =====
                function desktopSupported() {
                    return 'Notification' in window;
                }

                function updateDesktopToggle() {
                    var btn = document.getElementById('desktopNotifToggle');
                    if (!btn) return;
                    if (!desktopSupported()) {
                        btn.style.display = 'none';
                        return;
                    }
                    btn.style.display = '';
                    var icon = btn.querySelector('i');
                    if (window.Notification.permission === 'granted') {
                        btn.title = 'Notifikasi desktop aktif';
                        icon.className = 'fas fa-bell';
                        btn.style.color = '#10B981';
                    } else if (window.Notification.permission === 'denied') {
                        btn.title = 'Notifikasi desktop diblokir — izinkan lewat pengaturan situs browser';
                        icon.className = 'fas fa-bell-slash';
                        btn.style.color = '#DC2626';
                    } else {
                        btn.title = 'Aktifkan notifikasi desktop';
                        icon.className = 'fas fa-bell-slash';
                        btn.style.color = '';
                    }
                }

                function toggleDesktopNotif(e) {
                    if (!desktopSupported()) {
                        showToast({ title: 'Browser tidak mendukung notifikasi desktop', message: 'Gunakan Chrome, Edge, atau Firefox versi terbaru.' });
                        return;
                    }
                    if (window.Notification.permission === 'granted') {
                        showToast({ title: 'Notifikasi desktop sudah aktif', message: 'Untuk mematikan, ubah izin notifikasi situs ini di pengaturan browser.' });
                        return;
                    }
                    if (window.Notification.permission === 'denied') {
                        showToast({ title: 'Notifikasi diblokir browser', message: 'Klik ikon kunci/lokasi di address bar browser → izinkan Notifikasi, lalu muat ulang halaman.' });
                        return;
                    }
                    window.Notification.requestPermission().then(function (perm) {
                        updateDesktopToggle();
                        if (perm === 'granted') {
                            showToast({ title: '✅ Notifikasi desktop aktif', message: 'Anda akan menerima notifikasi transaksi baru di desktop.' });
                            try {
                                var demo = new window.Notification('🔔 Notifikasi desktop aktif!', {
                                    body: 'Anda akan menerima notifikasi transaksi baru di sini.',
                                    icon: SITE_ICON
                                });
                                setTimeout(function () { demo.close(); }, 4000);
                            } catch (err) {}
                        } else {
                            showToast({ title: 'Notifikasi tidak diizinkan', message: 'Anda bisa mengizinkannya lewat pengaturan situs browser.' });
                        }
                    }).catch(function () {});
                }

                function showDesktopNotif(n) {
                    if (!DESKTOP_ENABLED) return; // hanya role penerima suara (sound_notify_role)
                    if (!desktopSupported() || !n || !n.title) return;
                    if (window.Notification.permission !== 'granted') return;
                    // Tab sedang aktif → toast di dalam panel sudah cukup, hindari popup ganda
                    if (document.hasFocus()) return;
                    try {
                        var notif = new window.Notification(n.title, {
                            body: n.message || '',
                            tag: 'nn-notif-' + (n.id || Date.now()),
                            icon: SITE_ICON
                        });
                        notif.onclick = function () {
                            notif.close();
                            window.focus();
                            // Hanya arahkan ke link http(s) — keamanan ekstra
                            if (n.link && n.link.indexOf('http') === 0) {
                                window.location.href = n.link;
                            }
                        };
                        setTimeout(function () { notif.close(); }, 15000);
                    } catch (e) {}
                }

                // Tombol "Tes Notifikasi" — cek bunyi & notifikasi desktop tanpa menunggu transaksi
                function testNotif() {
                    // 1) Bunyi ding-dong (role penerima suara)
                    if (SOUND_ENABLED) playChime();
                    // 2) Notifikasi desktop (role penerima + izin browser sudah diberikan)
                    if (DESKTOP_ENABLED && desktopSupported()) {
                        if (window.Notification.permission === 'granted') {
                            try {
                                var demo = new window.Notification('🔔 Tes Notifikasi', {
                                    body: 'Bunyi & notifikasi desktop berfungsi — siap menerima notifikasi transaksi baru.',
                                    tag: 'nn-notif-test', // klik berulang mengganti popup lama, tidak menumpuk
                                    icon: SITE_ICON
                                });
                                demo.onclick = function () { demo.close(); window.focus(); };
                                setTimeout(function () { demo.close(); }, 6000);
                            } catch (err) {}
                            showToast({ title: '✅ Tes notifikasi berhasil', message: 'Suara diputar & notifikasi desktop ditampilkan.' });
                        } else if (window.Notification.permission === 'denied') {
                            showToast({ title: 'Notifikasi diblokir browser', message: 'Klik ikon kunci/lokasi di address bar → izinkan Notifikasi, lalu muat ulang halaman.' });
                        } else {
                            showToast({ title: 'Izin belum diberikan', message: 'Klik tombol 🔕 di samping untuk mengaktifkan notifikasi desktop, lalu tes lagi.' });
                        }
                    } else {
                        showToast({ title: '🔔 Tes bunyi', message: 'Suara notifikasi berfungsi dengan baik.' });
                    }
                }

                function poll() {
                    fetch('../ajax/notifications.php', { cache: 'no-store', credentials: 'same-origin' })
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            if (!data || typeof data.count !== 'number') return;
                            updateBadge(data.count);
                            var newId = parseInt(data.last_id, 10) || 0;
                            // Jika id mundur (tabel notifikasi direset), mulai dari nol lagi
                            if (newId < lastSeenId) lastSeenId = 0;
                            if (initialized && newId > 0 && newId !== lastSeenId) {
                                if (SOUND_ENABLED) playChime();
                                if (newId > lastSeenId) {
                                    showToast(data.latest);
                                    if (DESKTOP_ENABLED) showDesktopNotif(data.latest);
                                }
                            }
                            if (newId > lastSeenId) lastSeenId = newId;
                            initialized = true;
                        })
                        .catch(function () {});
                }

                // Buka kunci audio pada interaksi pertama (kebijakan autoplay browser)
                document.addEventListener('click', function () { ensureAudio(); }, { once: true });
                document.addEventListener('keydown', function () { ensureAudio(); }, { once: true });

                // Ekspos fungsi ke global agar atribut onclick inline dapat memanggilnya
                window.toggleDesktopNotif = toggleDesktopNotif;
                window.testNotif = testNotif;

                updateDesktopToggle();
                poll();
                setInterval(poll, POLL_INTERVAL);
            })();
            </script>
