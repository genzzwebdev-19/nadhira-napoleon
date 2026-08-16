<?php
// ============================================
// RBAC SEEDER - Nadhira Napoleon
// Mengisi tabel RBAC: roles, permissions, menus,
// widgets, role_permissions, role_widgets, dan
// menetapkan admin lama menjadi Super Admin.
// ============================================
// Akses: http://localhost/nad/database/rbac-seeder.php?run=1
// ============================================

require_once __DIR__ . '/../config/rbac.php';

// Only allow via CLI or with INSTALL_KEY (browser blocked by default)
$isCLI = (php_sapi_name() === 'cli');
$keyOk = defined('INSTALL_KEY') && INSTALL_KEY !== ''
    && isset($_GET['key']) && hash_equals(INSTALL_KEY, (string)$_GET['key']);
if (!$isCLI && !$keyOk) {
    http_response_code(403);
    die('403 Forbidden - Akses ditolak. Jalankan dari terminal: php database/rbac-seeder.php');
}

if (!$isCLI && !isset($_GET['run'])) {
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>RBAC Seeder - Nadhira Napoleon</title>
        <style>
            body { font-family: system-ui, sans-serif; max-width: 720px; margin: 40px auto; padding: 20px; background: #f8f5f0; color: #1a1a2e; }
            h1 { font-family: Georgia, serif; }
            .warning { background: #FEF3C7; border-left: 4px solid #F59E0B; padding: 16px; border-radius: 8px; margin: 20px 0; }
            .warning h3 { margin: 0 0 8px 0; color: #92400E; }
            .warning p { margin: 0; color: #78350F; font-size: 14px; }
            .btn { display: inline-block; padding: 12px 32px; background: linear-gradient(135deg, #D4A853, #B8860B); color: #fff; text-decoration: none; border-radius: 8px; font-weight: 600; border: none; cursor: pointer; }
            .btn:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(212,168,83,0.4); }
            ul { line-height: 1.9; }
        </style>
    </head>
    <body>
        <h1>🔐 RBAC Seeder</h1>
        <div class="warning">
            <h3>⚠️ Perhatian!</h3>
            <p>Seeder ini akan membuat tabel RBAC (jika belum ada), mengisi 15 role, seluruh permission,
               menu sidebar dinamis, widget dashboard, lalu menetapkan user admin lama sebagai <strong>Super Admin</strong>.
               Mapping permission role akan di-reset sesuai bawaan. Aman dijalankan ulang (idempotent).</p>
        </div>
        <p style="margin: 24px 0;">
            <a href="?run=1" class="btn">🚀 Jalankan RBAC Seeder</a>
        </p>
    </body>
    </html>
    <?php
    exit;
}

// ============================================
// EXECUTION
// ============================================
$conn = getConnection();
if (!$conn) {
    die("❌ Gagal terhubung ke database.\n");
}

$log = function ($msg) use ($isCLI) {
    echo $isCLI ? $msg . "\n" : htmlspecialchars($msg) . "<br>\n";
};

echo $isCLI ? "🔐 NADHIRA RBAC SEEDER\n========================\n\n" : "<pre style='background:#1a1a2e;color:#e0e0e0;padding:20px;border-radius:8px;max-width:720px;margin:20px auto;font-family:monospace;'>\n";

// Jalankan schema rbac.sql bila tabel roles belum ada
if (!tableExists('roles')) {
    $log("📦 Menjalankan rbac.sql...");
    $sql = file_get_contents(__DIR__ . '/rbac.sql');
    if ($sql !== false && $conn->multi_query($sql)) {
        do {
            if ($result = $conn->store_result()) $result->free();
        } while ($conn->next_result());
    }
    $log($conn->errno ? "⚠️  Error schema: " . $conn->error : "✅ Tabel RBAC dibuat");
}

// ============================================
// PASTIKAN KOLOM TAMBAHAN DI TABEL users
// ============================================
function ensureColumn($conn, $table, $column, $definition) {
    $r = $conn->query(
        "SELECT COUNT(*) AS c FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table' AND COLUMN_NAME = '$column'"
    );
    if ($r && (int)$r->fetch_assoc()['c'] === 0) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

ensureColumn($conn, 'users', 'is_locked', "TINYINT(1) NOT NULL DEFAULT 0");
ensureColumn($conn, 'users', 'failed_attempts', "INT NOT NULL DEFAULT 0");
ensureColumn($conn, 'users', 'locked_until', "DATETIME NULL");
$log("✅ Kolom keamanan tabel users dipastikan (is_locked, failed_attempts, locked_until)");

// ============================================
// DATA MASTER
// ============================================

// ---- ROLES ----
$roles = [
    ['Super Admin', 'super-admin', 'Akses penuh ke seluruh sistem. Mengelola semua modul, role, permission, cabang, backup, dan pengaturan.', 1],
    ['Owner', 'owner', 'Dashboard eksekutif: membaca laporan penjualan, revenue, profit, cabang, produk terlaris, dan membership. Hanya baca.', 1],
    ['General Manager', 'general-manager', 'Mengawasi operasional: approval promo & produk, monitoring cabang, penjualan, gudang, dan customer.', 1],
    ['Admin Produk', 'admin-produk', 'Mengelola produk: CRUD produk, kategori, harga, promo, diskon, SKU, berat, komposisi, kadaluarsa, related product.', 1],
    ['Admin Gudang', 'admin-gudang', 'Mengelola inventory: stock masuk/keluar, opname, batch, expired, transfer stock, riwayat stock.', 1],
    ['Admin Pesanan', 'admin-pesanan', 'Mengelola transaksi: order baru, packing, shipping, invoice, refund, retur, tracking.', 1],
    ['Admin Penjualan Online', 'admin-penjualan-online', 'Mengelola penjualan online: pesanan, pembayaran, pengiriman/resi, invoice, pelanggan, produk & promo (lihat), pesan masuk, laporan penjualan.', 1],
    ['Admin Cabang', 'admin-cabang', 'Mengelola cabang tertentu. Hanya dapat melihat data cabang miliknya.', 1],
    ['Admin Marketing', 'admin-marketing', 'Mengelola pemasaran: banner, promo, flash sale, voucher, coupon, landing page, SEO, popup, campaign.', 1],
    ['Admin Customer Service', 'admin-customer-service', 'Mengelola pelayanan pelanggan: live chat, WhatsApp, komplain, refund, FAQ, ticket support.', 1],
    ['Admin Membership', 'admin-membership', 'Mengelola program loyalitas: member, point, cashback, voucher, level membership, birthday reward.', 1],
    ['Admin Content', 'admin-content', 'Mengelola CMS: artikel, blog, FAQ, gallery, banner, about us, contact, footer.', 1],
    ['Finance', 'finance', 'Mengelola pembayaran: verifikasi pembayaran, invoice, refund, export laporan, rekonsiliasi. Tidak dapat mengubah produk.', 1],
    ['Admin Pengiriman', 'admin-pengiriman', 'Mengelola logistik: kurir, resi, tracking, shipping, estimasi.', 1],
    ['Affiliate Manager', 'affiliate-manager', 'Mengelola affiliate, reseller, komisi, withdraw, referral.', 1],
    ['Developer / IT Support', 'developer-it-support', 'Monitoring server, error log, API, backup, cache, queue, cron job, database maintenance.', 1],
];

// ---- ACTION SETS PER MODULE ----
$actionSets = [
    'dashboard'      => ['view'],
    'products'       => ['view', 'create', 'edit', 'delete', 'approve', 'export', 'import'],
    'categories'     => ['view', 'create', 'edit', 'delete'],
    'orders'         => ['view', 'create', 'edit', 'delete', 'approve', 'export'],
    'customers'      => ['view', 'edit', 'export'],
    'promo'          => ['view', 'create', 'edit', 'delete', 'approve', 'publish'],
    'testimonials'   => ['view', 'create', 'edit', 'delete', 'publish'],
    'articles'       => ['view', 'create', 'edit', 'delete', 'publish'],
    'branches'       => ['view', 'create', 'edit', 'delete'],
    'faq'            => ['view', 'create', 'edit', 'delete'],
    'videos'         => ['view', 'create', 'edit', 'delete'],
    'messages'       => ['view', 'delete'],
    'hero_slides'    => ['view', 'create', 'edit', 'delete', 'publish'],
    'packages'       => ['view', 'create', 'edit', 'delete'],
    'notifications'  => ['view', 'delete'],
    'changelog'      => ['view'],
    'profile'        => ['view', 'edit'],
    'roles'          => ['view', 'create', 'edit', 'delete', 'settings'],
    'users'          => ['view', 'create', 'edit', 'delete', 'settings'],
    'activity_logs'  => ['view', 'export', 'delete'],
    'login_history'  => ['view', 'export', 'delete'],
    'sessions'       => ['view', 'delete'],
    'backup'         => ['view', 'backup', 'restore'],
    'api'            => ['view', 'settings'],
    'settings'       => ['view', 'settings'],
    'stock'          => ['view', 'create', 'edit', 'delete', 'export'],
    'payments'       => ['view', 'verify', 'export'],
    'shipping'       => ['view', 'create', 'edit', 'delete'],
    'membership'     => ['view', 'create', 'edit', 'delete', 'export'],
    'marketing'      => ['view', 'create', 'edit', 'delete', 'publish', 'export'],
    'affiliate'      => ['view', 'create', 'edit', 'delete', 'export'],
    'reports'        => ['view', 'export'],
    'support'        => ['view', 'create', 'edit', 'delete'],
    'invoices'       => ['view', 'export'],
    'security'       => ['view', 'settings'],
    'menus'          => ['view', 'create', 'edit', 'delete'],
    'widgets'        => ['view', 'create', 'edit', 'delete'],
];

$moduleNames = [
    'dashboard' => 'Dashboard', 'products' => 'Produk', 'categories' => 'Kategori',
    'orders' => 'Pesanan', 'customers' => 'Pelanggan', 'promo' => 'Promo',
    'testimonials' => 'Testimoni', 'articles' => 'Artikel', 'branches' => 'Cabang',
    'faq' => 'FAQ', 'videos' => 'Video Gallery', 'messages' => 'Pesan Masuk',
    'hero_slides' => 'Hero Slider', 'packages' => 'Paket Spesial', 'notifications' => 'Notifikasi', 'changelog' => 'Changelog',
    'profile' => 'Profil', 'roles' => 'Role Management', 'users' => 'User Management',
    'activity_logs' => 'Audit Log', 'login_history' => 'Riwayat Login', 'sessions' => 'Sesi Aktif',
    'backup' => 'Backup & Restore', 'api' => 'API Integrasi', 'settings' => 'Pengaturan',
    'stock' => 'Inventory / Gudang', 'payments' => 'Pembayaran', 'shipping' => 'Pengiriman',
    'membership' => 'Membership', 'marketing' => 'Marketing', 'affiliate' => 'Affiliate',
    'reports' => 'Laporan', 'support' => 'Ticket Support', 'invoices' => 'Invoice',
    'security' => 'Keamanan', 'menus' => 'Menu Management', 'widgets' => 'Widget Dashboard',
];

$actionNames = [
    'view' => 'Lihat', 'create' => 'Tambah', 'edit' => 'Ubah', 'delete' => 'Hapus',
    'approve' => 'Approve', 'publish' => 'Publikasi', 'export' => 'Export',
    'import' => 'Import', 'print' => 'Print', 'restore' => 'Restore',
    'backup' => 'Backup', 'settings' => 'Pengaturan', 'verify' => 'Verifikasi',
];

// ---- ROLE -> PERMISSION MAPPING ----
$roleDefs = [
    'super-admin' => '*',

    'owner' => [
        'dashboard' => ['view'], 'orders' => ['view', 'export'], 'products' => ['view'],
        'customers' => ['view', 'export'], 'branches' => ['view'], 'reports' => ['view', 'export'],
        'membership' => ['view'], 'activity_logs' => ['view'], 'login_history' => ['view'],
        'invoices' => ['view', 'export'],
    ],

    'general-manager' => [
        'dashboard' => ['view'], 'orders' => ['view', 'approve'], 'products' => ['view', 'approve'],
        'promo' => ['view', 'approve'], 'branches' => ['view'], 'customers' => ['view'],
        'stock' => ['view'], 'reports' => ['view'], 'activity_logs' => ['view'],
    ],

    'admin-produk' => [
        'dashboard' => ['view'],
        'products' => ['view', 'create', 'edit', 'delete', 'approve', 'export', 'import'],
        'categories' => ['view', 'create', 'edit', 'delete'],
        'packages' => ['view', 'create', 'edit', 'delete'],
        'promo' => ['view', 'create', 'edit', 'delete'],
        'videos' => ['view', 'create', 'edit', 'delete'],
        'stock' => ['view'],
    ],

    'admin-gudang' => [
        'dashboard' => ['view'], 'products' => ['view'], 'categories' => ['view'],
        'stock' => ['view', 'create', 'edit', 'delete', 'export'],
    ],

    'admin-pesanan' => [
        'dashboard' => ['view'],
        'orders' => ['view', 'create', 'edit', 'approve', 'export'],
        'payments' => ['view'], 'customers' => ['view'], 'shipping' => ['view'],
        'invoices' => ['view'],
    ],

    'admin-penjualan-online' => [
        'dashboard' => ['view'],
        'orders' => ['view', 'create', 'edit', 'approve', 'export'],
        'payments' => ['view'],
        'shipping' => ['view', 'create', 'edit'],
        'invoices' => ['view'], 'customers' => ['view'],
        'products' => ['view'], 'promo' => ['view'], 'messages' => ['view'],
        'reports' => ['view', 'export'],
    ],

    'admin-cabang' => [
        'dashboard' => ['view'], 'orders' => ['view'], 'products' => ['view'],
        'customers' => ['view'], 'branches' => ['view'], 'stock' => ['view'],
        'reports' => ['view'],
    ],

    'admin-marketing' => [
        'dashboard' => ['view'],
        'hero_slides' => ['view', 'create', 'edit', 'delete', 'publish'],
        'packages' => ['view', 'create', 'edit', 'delete'],
        'promo' => ['view', 'create', 'edit', 'delete', 'publish'],
        'marketing' => ['view', 'create', 'edit', 'delete', 'publish', 'export'],
        'testimonials' => ['view'], 'reports' => ['view'],
    ],

    'admin-customer-service' => [
        'dashboard' => ['view'], 'messages' => ['view', 'delete'],
        'support' => ['view', 'create', 'edit', 'delete'],
        'faq' => ['view', 'create', 'edit', 'delete'], 'customers' => ['view'],
    ],

    'admin-membership' => [
        'dashboard' => ['view'],
        'membership' => ['view', 'create', 'edit', 'delete', 'export'],
        'customers' => ['view', 'edit'], 'promo' => ['view'],
    ],

    'admin-content' => [
        'dashboard' => ['view'],
        'articles' => ['view', 'create', 'edit', 'delete', 'publish'],
        'testimonials' => ['view', 'create', 'edit', 'delete', 'publish'],
        'faq' => ['view', 'create', 'edit', 'delete'],
        'videos' => ['view', 'create', 'edit', 'delete'],
        'messages' => ['view'],
    ],

    'finance' => [
        'dashboard' => ['view'],
        'payments' => ['view', 'verify', 'export'],
        'orders' => ['view', 'approve'], 'reports' => ['view', 'export'],
        'invoices' => ['view', 'export'], 'customers' => ['view'],
    ],

    'admin-pengiriman' => [
        'dashboard' => ['view'],
        'shipping' => ['view', 'create', 'edit', 'delete'],
        'orders' => ['view', 'edit'], 'customers' => ['view'],
    ],

    'affiliate-manager' => [
        'dashboard' => ['view'],
        'affiliate' => ['view', 'create', 'edit', 'delete', 'export'],
        'customers' => ['view'], 'reports' => ['view'],
    ],

    'developer-it-support' => [
        'dashboard' => ['view'],
        'backup' => ['view', 'backup', 'restore'],
        'activity_logs' => ['view', 'export', 'delete'],
        'login_history' => ['view', 'export', 'delete'],
        'sessions' => ['view', 'delete'],
        'api' => ['view', 'settings'],
        'settings' => ['view', 'settings'],
        'changelog' => ['view'], 'security' => ['view', 'settings'],
        'reports' => ['view'],
    ],
];

// Permission yang pasti dimiliki SEMUA role
$alwaysGrant = [
    'dashboard' => ['view'],
    'profile' => ['view', 'edit'],
    'notifications' => ['view', 'delete'],
    'changelog' => ['view'],
];

// ---- MENUS ----
$menus = [
    // [slug, name, url, icon, module, sort, section]
    ['dashboard', 'Dashboard', 'index.php', 'fa-dashboard', 'dashboard', 1, 'Menu Utama'],
    ['orders', 'Pesanan', 'orders.php', 'fa-shopping-cart', 'orders', 2, 'Menu Utama'],
    ['products', 'Produk', 'products.php', 'fa-shopping-bag', 'products', 3, 'Menu Utama'],
    ['categories', 'Kategori', 'categories.php', 'fa-list', 'categories', 4, 'Menu Utama'],
    ['customers', 'Pelanggan', 'customers.php', 'fa-users', 'customers', 5, 'Menu Utama'],
    ['promo', 'Promo', 'promo.php', 'fa-percent', 'promo', 6, 'Menu Utama'],
    ['testimonials', 'Testimoni', 'testimonials.php', 'fa-star', 'testimonials', 7, 'Menu Utama'],
    ['articles', 'Artikel', 'articles.php', 'fa-file-alt', 'articles', 8, 'Menu Utama'],
    ['videos', 'Video Gallery', 'videos.php', 'fa-video', 'videos', 9, 'Menu Utama'],
    ['faq', 'FAQ', 'faq.php', 'fa-question-circle', 'faq', 10, 'Menu Utama'],
    ['hero_slides', 'Hero Slider', 'hero-slides.php', 'fa-images', 'hero_slides', 11, 'Menu Utama'],
    ['messages', 'Pesan Masuk', 'messages.php', 'fa-envelope', 'messages', 12, 'Menu Utama'],
    ['branches', 'Cabang', 'branches.php', 'fa-building', 'branches', 13, 'Menu Utama'],
    ['stock', 'Stock / Gudang', 'stock.php', 'fa-boxes-stacked', 'stock', 14, 'Menu Utama'],
    ['branch-stock', 'Stok per Cabang', 'branch-stock.php', 'fa-warehouse', 'stock', 15, 'Menu Utama'],
    ['payments', 'Pembayaran', 'payments.php', 'fa-money-bill-wave', 'payments', 15, 'Menu Utama'],
    ['shipping', 'Pengiriman', 'shipping.php', 'fa-truck', 'shipping', 16, 'Menu Utama'],
    ['membership', 'Membership', 'membership.php', 'fa-crown', 'membership', 17, 'Menu Utama'],
    ['marketing', 'Marketing', 'marketing.php', 'fa-bullhorn', 'marketing', 18, 'Menu Utama'],
    ['affiliate', 'Affiliate', 'affiliate.php', 'fa-handshake', 'affiliate', 19, 'Menu Utama'],
    ['reports', 'Laporan', 'reports.php', 'fa-chart-bar', 'reports', 20, 'Menu Utama'],
    ['support', 'Ticket Support', 'support.php', 'fa-headset', 'support', 21, 'Menu Utama'],
    ['invoices', 'Invoice', 'invoices.php', 'fa-file-invoice-dollar', 'invoices', 22, 'Menu Utama'],
    ['packages', 'Paket Spesial', 'packages.php', 'fa-gift', 'packages', 23, 'Menu Utama'],

    ['roles', 'Manajemen Role', 'roles.php', 'fa-user-shield', 'roles', 1, 'Akses & Keamanan'],
    ['users', 'Manajemen User', 'users.php', 'fa-user-cog', 'users', 2, 'Akses & Keamanan'],
    ['menus', 'Menu Management', 'menus.php', 'fa-bars', 'menus', 3, 'Akses & Keamanan'],
    ['widgets', 'Widget Dashboard', 'widgets.php', 'fa-th-large', 'widgets', 4, 'Akses & Keamanan'],
    ['activity_logs', 'Audit Log', 'activity-logs.php', 'fa-clipboard-list', 'activity_logs', 5, 'Akses & Keamanan'],
    ['login_history', 'Riwayat Login', 'login-history.php', 'fa-fingerprint', 'login_history', 6, 'Akses & Keamanan'],
    ['sessions', 'Sesi Aktif', 'sessions.php', 'fa-plug', 'sessions', 7, 'Akses & Keamanan'],
    ['backup', 'Backup & Restore', 'backup.php', 'fa-database', 'backup', 8, 'Akses & Keamanan'],
    ['api', 'API Integrasi', 'api.php', 'fa-code', 'api', 9, 'Akses & Keamanan'],
    ['settings', 'Pengaturan', 'settings.php', 'fa-cog', 'settings', 10, 'Akses & Keamanan'],
    ['notifications', 'Notifikasi', 'notifications.php', 'fa-bell', 'notifications', 11, 'Akses & Keamanan'],
    ['profile', 'Profil Saya', 'profile.php', 'fa-user', 'profile', 12, 'Akses & Keamanan'],
];

// ---- WIDGETS ----
$widgets = [
    ['stats_revenue', 'Pendapatan Bulan Ini', 'fa-dollar-sign', 'small', 'Total pendapatan bulan berjalan', 1],
    ['stats_orders', 'Total Pesanan', 'fa-shopping-cart', 'small', 'Jumlah pesanan masuk', 2],
    ['stats_products', 'Total Produk', 'fa-shopping-bag', 'small', 'Jumlah produk aktif', 3],
    ['stats_customers', 'Pelanggan', 'fa-users', 'small', 'Jumlah pelanggan terdaftar', 4],
    ['low_stock', 'Stok Menipis', 'fa-exclamation-triangle', 'small', 'Produk dengan stok hampir habis', 5],
    ['unread_messages', 'Pesan Baru', 'fa-envelope', 'small', 'Pesan masuk belum dibaca', 6],
    ['pending_payments', 'Pembayaran Menunggu', 'fa-money-bill-wave', 'small', 'Konfirmasi pembayaran pending', 7],
    ['promo_status', 'Promo Aktif', 'fa-percent', 'small', 'Jumlah promo berjalan', 8],
    ['recent_orders', 'Pesanan Terbaru', 'fa-clock', 'large', 'Daftar pesanan terbaru', 9],
    ['top_products', 'Produk Terlaris', 'fa-trophy', 'medium', 'Produk dengan penjualan tertinggi', 10],
    ['sales_chart', 'Grafik Penjualan', 'fa-chart-line', 'full', 'Pendapatan 7 hari terakhir', 11],
    ['membership_stats', 'Membership', 'fa-crown', 'medium', 'Distribusi level membership', 12],
    ['dashboard_summary', 'Ringkasan Dashboard', 'fa-chart-pie', 'small', 'Sambutan & ringkasan akses role', 13],
    ['profile_summary', 'Profil Saya', 'fa-user-circle', 'small', 'Kartu profil admin yang sedang login', 14],
    ['notifications_list', 'Notifikasi Terbaru', 'fa-bell', 'medium', 'Daftar notifikasi terbaru untuk admin', 15],
];

// Widget yang pasti dimiliki SEMUA role (sama dengan $alwaysGrant permission)
$alwaysWidgets = ['dashboard_summary', 'profile_summary', 'notifications_list'];

// ---- ROLE -> WIDGET MAPPING ----
$roleWidgets = [
    'super-admin' => '*',
    'owner' => ['stats_revenue', 'stats_orders', 'stats_customers', 'sales_chart', 'top_products', 'membership_stats'],
    'general-manager' => ['stats_orders', 'stats_products', 'stats_revenue', 'sales_chart', 'low_stock', 'recent_orders'],
    'admin-produk' => ['stats_products', 'top_products', 'promo_status'],
    'admin-gudang' => ['low_stock', 'stats_products'],
    'admin-pesanan' => ['stats_orders', 'recent_orders', 'pending_payments'],
    'admin-penjualan-online' => ['stats_orders', 'stats_revenue', 'recent_orders', 'pending_payments'],
    'admin-cabang' => ['stats_orders', 'stats_products', 'recent_orders'],
    'admin-marketing' => ['promo_status', 'sales_chart', 'stats_orders'],
    'admin-customer-service' => ['unread_messages', 'recent_orders'],
    'admin-membership' => ['membership_stats', 'stats_customers'],
    'admin-content' => ['unread_messages', 'stats_products'],
    'finance' => ['stats_revenue', 'pending_payments', 'sales_chart', 'recent_orders'],
    'admin-pengiriman' => ['stats_orders', 'recent_orders'],
    'affiliate-manager' => ['stats_orders', 'stats_customers'],
    'developer-it-support' => ['low_stock', 'stats_orders'],
];

// ============================================
// INSERT MASTER DATA
// ============================================

// Roles
$log("👥 Memasukkan " . count($roles) . " role...");
$roleIds = [];
foreach ($roles as [$name, $slug, $desc, $isSystem]) {
    $n = $conn->real_escape_string($name);
    $s = $conn->real_escape_string($slug);
    $d = $conn->real_escape_string($desc);
    $conn->query(
        "INSERT INTO roles (name, slug, description, is_system)
         VALUES ('$n', '$s', '$d', $isSystem)
         ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), is_system = VALUES(is_system)"
    );
    $r = $conn->query("SELECT id FROM roles WHERE slug = '$s' LIMIT 1");
    $roleIds[$slug] = $r ? (int)$r->fetch_assoc()['id'] : 0;
}

// Permissions
$log("🔑 Memasukkan permission...");
$permIds = [];
foreach ($actionSets as $module => $actions) {
    $moduleName = $moduleNames[$module] ?? ucfirst($module);
    foreach ($actions as $action) {
        $m = $conn->real_escape_string($module);
        $a = $conn->real_escape_string($action);
        $name = ($actionNames[$action] ?? ucfirst($action)) . ' ' . $moduleName;
        $conn->query(
            "INSERT INTO permissions (module, action, name)
             VALUES ('$m', '$a', '" . $conn->real_escape_string($name) . "')
             ON DUPLICATE KEY UPDATE name = VALUES(name)"
        );
        $r = $conn->query("SELECT id FROM permissions WHERE module = '$m' AND action = '$a' LIMIT 1");
        if ($r) $permIds[$module][$action] = (int)$r->fetch_assoc()['id'];
    }
}

// Role -> Permissions mapping (reset dulu)
$log("🔗 Memasang role_permissions...");
$conn->query("DELETE FROM role_permissions");

$grantToRole = function ($roleSlug, $module, $action) use ($conn, $roleIds, $permIds) {
    $rid = $roleIds[$roleSlug] ?? 0;
    $pid = $permIds[$module][$action] ?? 0;
    if ($rid > 0 && $pid > 0) {
        $conn->query("INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES ($rid, $pid)");
    }
};

foreach ($roleDefs as $roleSlug => $def) {
    if ($def === '*') {
        // Semua permission
        foreach ($permIds as $module => $actions) {
            foreach ($actions as $action => $pid) $grantToRole($roleSlug, $module, $action);
        }
    } else {
        foreach ($def as $module => $actions) {
            foreach ($actions as $action) $grantToRole($roleSlug, $module, $action);
        }
    }
    // Permission wajib untuk semua role
    foreach ($alwaysGrant as $module => $actions) {
        foreach ($actions as $action) $grantToRole($roleSlug, $module, $action);
    }
}

// Menus
$log("📂 Memasukkan " . count($menus) . " menu sidebar...");
foreach ($menus as [$slug, $name, $url, $icon, $module, $sort, $section]) {
    $s = $conn->real_escape_string($slug);
    $n = $conn->real_escape_string($name);
    $u = $conn->real_escape_string($url);
    $i = $conn->real_escape_string($icon);
    $m = $conn->real_escape_string($module);
    $sec = $conn->real_escape_string($section);
    $conn->query(
        "INSERT INTO menus (slug, name, url, icon, module, section, sort_order)
         VALUES ('$s', '$n', '$u', '$i', '$m', '$sec', $sort)
         ON DUPLICATE KEY UPDATE name = VALUES(name), url = VALUES(url), icon = VALUES(icon),
             module = VALUES(module), section = VALUES(section), sort_order = VALUES(sort_order)"
    );
}

// Widgets
$log("📊 Memasukkan " . count($widgets) . " widget dashboard...");
$widgetIds = [];
foreach ($widgets as [$slug, $title, $icon, $size, $desc, $sort]) {
    $s = $conn->real_escape_string($slug);
    $t = $conn->real_escape_string($title);
    $i = $conn->real_escape_string($icon);
    $d = $conn->real_escape_string($desc);
    $conn->query(
        "INSERT INTO widgets (slug, title, icon, size, description, sort_order)
         VALUES ('$s', '$t', '$i', '$size', '$d', $sort)
         ON DUPLICATE KEY UPDATE title = VALUES(title), icon = VALUES(icon), sort_order = VALUES(sort_order)"
    );
    $r = $conn->query("SELECT id FROM widgets WHERE slug = '$s' LIMIT 1");
    if ($r) $widgetIds[$slug] = (int)$r->fetch_assoc()['id'];
}

// Role -> Widgets mapping (reset dulu)
$log("🧩 Memasang role_widgets...");
$conn->query("DELETE FROM role_widgets");
foreach ($roleWidgets as $roleSlug => $def) {
    $rid = $roleIds[$roleSlug] ?? 0;
    if ($rid <= 0) continue;
    if ($def === '*') $def = array_keys($widgetIds);
    $sort = 1;
    foreach ($def as $widgetSlug) {
        $wid = $widgetIds[$widgetSlug] ?? 0;
        if ($wid > 0) {
            $conn->query("INSERT IGNORE INTO role_widgets (role_id, widget_id, sort_order) VALUES ($rid, $wid, $sort)");
            $sort++;
        }
    }
    // Widget wajib untuk semua role (dashboard, profil, notifikasi)
    foreach ($alwaysWidgets as $widgetSlug) {
        $wid = $widgetIds[$widgetSlug] ?? 0;
        if ($wid > 0) {
            $conn->query("INSERT IGNORE INTO role_widgets (role_id, widget_id, sort_order) VALUES ($rid, $wid, $sort)");
            $sort++;
        }
    }
}

// Assign admin lama -> Super Admin
$log("👑 Menetapkan admin menjadi Super Admin...");
$r = $conn->query("SELECT id FROM users WHERE username = 'admin' OR role = 'admin'");
$adminIds = [];
if ($r) while ($row = $r->fetch_assoc()) $adminIds[] = (int)$row['id'];
if (empty($adminIds) && isset($roleIds['super-admin'])) {
    // fallback: user pertama
    $r = $conn->query("SELECT id FROM users ORDER BY id ASC LIMIT 1");
    if ($r && $r->num_rows > 0) $adminIds[] = (int)$r->fetch_assoc()['id'];
}
foreach ($adminIds as $uid) {
    if (isset($roleIds['super-admin'])) {
        $conn->query("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES ($uid, {$roleIds['super-admin']})");
    }
}

// Notifikasi selamat datang untuk admin
if (!empty($adminIds)) {
    foreach ($adminIds as $uid) {
        $cnt = $conn->query("SELECT COUNT(*) AS c FROM notifications WHERE user_id = $uid")->fetch_assoc()['c'];
        if ((int)$cnt === 0) {
            $conn->query(
                "INSERT INTO notifications (user_id, title, message, type, link)
                 VALUES ($uid, 'Selamat datang di Panel Admin', 'Sistem RBAC aktif. Anda login sebagai Super Admin dengan akses penuh.', 'success', 'index.php')"
            );
        }
    }
}

// ============================================
// RINGKASAN
// ============================================
$cRoles = $conn->query("SELECT COUNT(*) AS c FROM roles")->fetch_assoc()['c'];
$cPerms = $conn->query("SELECT COUNT(*) AS c FROM permissions")->fetch_assoc()['c'];
$cMenus = $conn->query("SELECT COUNT(*) AS c FROM menus")->fetch_assoc()['c'];
$cWidgets = $conn->query("SELECT COUNT(*) AS c FROM widgets")->fetch_assoc()['c'];
$cRolePerms = $conn->query("SELECT COUNT(*) AS c FROM role_permissions")->fetch_assoc()['c'];

$log("\n========================================");
$log("✅ RBAC Seeder selesai!");
$log("   Role: $cRoles | Permission: $cPerms | Menu: $cMenus | Widget: $cWidgets");
$log("   Mapping role_permissions: $cRolePerms");
$log("   Super Admin user: " . implode(', ', $adminIds));
$log("========================================\n");
if (!$isCLI) echo "</pre>";
