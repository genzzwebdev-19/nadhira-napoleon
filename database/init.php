<?php
// ============================================
// DATABASE INSTALLER - NADHIRA NAPOLEON
// One-click database setup script
// ============================================
// ⚠️ IMPORTANT: Hapus file ini SETELAH instalasi selesai!
// Jangan biarkan file ini ada di server production.
// ============================================
// Cara akses: http://localhost/nad/database/init.php
// ============================================

require_once __DIR__ . '/../config/database.php';

// ============================================
// INSTALLATION ENGINE
// ============================================

function runInstall() {
    $results = [];
    $hasError = false;

    // Step 1: Connect to MySQL without selecting database
    $results[] = ['step' => 'Koneksi ke MySQL Server', 'status' => 'processing'];
    try {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, '', DB_PORT);
        if ($conn->connect_error) {
            throw new Exception($conn->connect_error);
        }
        $conn->set_charset("utf8mb4");
        $results[count($results) - 1]['status'] = 'success';
        $results[count($results) - 1]['message'] = 'Terhubung ke MySQL server ' . DB_HOST . ':' . DB_PORT;
    } catch (Exception $e) {
        return fail($results, count($results) - 1, 'Gagal koneksi: ' . $e->getMessage());
    }

    // Step 2: Siapkan database.
    // Di hosting bersama (mis. InfinityFree) database & usernya dibuat lewat panel
    // kontrol, dan user MySQL TIDAK punya izin CREATE/DROP DATABASE.
    // Karena itu: jika database sudah ada → langsung dipakai (tabel tetap di-reset
    // oleh schema.sql yang memakai DROP TABLE IF EXISTS). Jika belum ada → coba buat.
    $results[] = ['step' => 'Menyiapkan database ' . DB_NAME, 'status' => 'processing'];
    try {
        $dbReady = @$conn->select_db(DB_NAME);
        if (!$dbReady) {
            // Database belum ada → coba buat (butuh izin CREATE DATABASE)
            $conn->query("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            if ($conn->errno) {
                throw new Exception('Database ' . DB_NAME . ' belum ada dan user MySQL tidak dapat membuatnya (' . $conn->error . '). Buat database-nya dulu lewat panel hosting, lalu ulangi instalasi.');
            }
            $conn->select_db(DB_NAME);
        }
        // Konten di-reset oleh schema.sql (DROP TABLE IF EXISTS + CREATE)
        $results[count($results) - 1]['status'] = 'success';
        $results[count($results) - 1]['message'] = 'Database ' . DB_NAME . ' siap (konten akan di-reset oleh schema)';
    } catch (Exception $e) {
        return fail($results, count($results) - 1, 'Gagal menyiapkan database: ' . $e->getMessage());
    }

    // Step 3: Execute schema.sql using multi_query
    $results[] = ['step' => 'Menjalankan schema.sql', 'status' => 'processing'];
    try {
        $schemaPath = __DIR__ . '/schema.sql';
        if (!file_exists($schemaPath)) {
            throw new Exception('File schema.sql tidak ditemukan di: ' . $schemaPath);
        }

        $sql = file_get_contents($schemaPath);
        if ($sql === false || trim($sql) === '') {
            throw new Exception('File schema.sql kosong atau tidak bisa dibaca');
        }

        // Buang statement "CREATE DATABASE ..." & "USE ..." dari schema.sql.
        // Koneksi sudah diarahkan ke database yang benar (select_db) di Step 2,
        // dan di hosting bersama (mis. InfinityFree) nama database berbeda dari
        // nilai hardcoded di schema.sql serta user MySQL tidak punya izin CREATE DATABASE.
        $sql = preg_replace('/^\s*CREATE\s+DATABASE[^;]*;\s*$/mi', '', $sql);
        $sql = preg_replace('/^\s*USE\s+[^;]*;\s*$/mi', '', $sql);

        // Use multi_query to handle the entire SQL properly
        if ($conn->multi_query($sql)) {
            // Consume all result sets
            do {
                if ($result = $conn->store_result()) {
                    $result->free();
                }
            } while ($conn->next_result());
        }

        // Check for errors
        if ($conn->errno) {
            throw new Exception('Error SQL: ' . $conn->error);
        }

        $results[count($results) - 1]['status'] = 'success';
        $results[count($results) - 1]['message'] = 'Semua tabel dan data awal berhasil dibuat';
    } catch (Exception $e) {
        return fail($results, count($results) - 1, 'Gagal: ' . $e->getMessage());
    }

    // Step 3b: Execute rbac.sql (tabel RBAC)
    $results[] = ['step' => 'Menjalankan rbac.sql (tabel RBAC)', 'status' => 'processing'];
    try {
        $rbacPath = __DIR__ . '/rbac.sql';
        if (!file_exists($rbacPath)) {
            throw new Exception('File rbac.sql tidak ditemukan di: ' . $rbacPath);
        }
        $rbacSql = file_get_contents($rbacPath);
        if ($conn->multi_query($rbacSql)) {
            do {
                if ($result = $conn->store_result()) $result->free();
            } while ($conn->next_result());
        }
        if ($conn->errno) {
            throw new Exception('Error SQL RBAC: ' . $conn->error);
        }
        $results[count($results) - 1]['status'] = 'success';
        $results[count($results) - 1]['message'] = '13 tabel RBAC berhasil dibuat (roles, permissions, menus, dll)';
    } catch (Exception $e) {
        return fail($results, count($results) - 1, 'Gagal: ' . $e->getMessage());
    }

    // Step 3c: Execute modules.sql (tabel modul operasional)
    $results[] = ['step' => 'Menjalankan modules.sql (tabel modul)', 'status' => 'processing'];
    try {
        $modPath = __DIR__ . '/modules.sql';
        if (!file_exists($modPath)) {
            throw new Exception('File modules.sql tidak ditemukan di: ' . $modPath);
        }
        $modSql = file_get_contents($modPath);
        if ($conn->multi_query($modSql)) {
            do {
                if ($result = $conn->store_result()) $result->free();
            } while ($conn->next_result());
        }
        if ($conn->errno) {
            throw new Exception('Error SQL modules: ' . $conn->error);
        }
        $results[count($results) - 1]['status'] = 'success';
        $results[count($results) - 1]['message'] = '8 tabel modul dibuat (stock_movements, marketing_campaigns, affiliates, support_tickets, membership_plans, membership_purchases, point_redeems, point_history)';
    } catch (Exception $e) {
        return fail($results, count($results) - 1, 'Gagal: ' . $e->getMessage());
    }

    // Step 4: Verify all tables
    $results[] = ['step' => 'Verifikasi tabel database', 'status' => 'processing'];
    try {
        $tables = [];
        $result = $conn->query("SHOW TABLES");
        if (!$result) {
            throw new Exception('Gagal membaca daftar tabel');
        }
        while ($row = $result->fetch_array()) {
            $tables[] = $row[0];
        }

        $expectedTables = [
            'users', 'product_categories', 'products', 'product_images', 'product_videos',
            'product_reviews', 'wishlists', 'carts', 'orders', 'order_items',
            'branches', 'branch_products', 'promotions', 'articles', 'testimonials',
            'faq', 'contacts', 'newsletter_subscribers', 'settings', 'membership_benefits',
            'payment_confirmations', 'video_gallery', 'hero_slides',
            // RBAC
            'roles', 'permissions', 'role_permissions', 'menus', 'user_roles',
            'user_permissions', 'user_branches', 'activity_logs', 'login_history',
            'user_sessions', 'notifications', 'widgets', 'role_widgets',
            // Modul operasional
            'stock_movements', 'marketing_campaigns', 'affiliates', 'support_tickets',
            'membership_plans', 'membership_purchases', 'point_redeems', 'point_history'
        ];

        $missingTables = array_diff($expectedTables, $tables);

        if (count($missingTables) > 0) {
            $results[count($results) - 1]['status'] = 'warning';
            $results[count($results) - 1]['message'] = count($tables) . ' tabel ditemukan. Tabel hilang: ' . implode(', ', $missingTables);
            $hasError = true;
        } else {
            $results[count($results) - 1]['status'] = 'success';
            $results[count($results) - 1]['message'] = 'Semua ' . count($tables) . ' tabel berhasil terverifikasi';
        }
    } catch (Exception $e) {
        $results[count($results) - 1]['status'] = 'error';
        $results[count($results) - 1]['message'] = 'Gagal verifikasi: ' . $e->getMessage();
        $hasError = true;
    }

    // Step 5: Ensure admin user exists with known password
    $results[] = ['step' => 'Memastikan akun admin', 'status' => 'processing'];
    try {
        // Generate fresh bcrypt hash for 'password'
        $hashedPassword = password_hash('password', PASSWORD_BCRYPT);
        
        // Delete existing admin first to avoid duplicate
        $conn->query("DELETE FROM users WHERE username = 'admin'");
        
        $stmt = $conn->prepare("INSERT INTO users (username, email, password, full_name, role) VALUES (?, ?, ?, ?, ?)");
        $adminUser = 'admin';
        $adminEmail = 'admin@nadhiranapoleon.com';
        $adminName = 'Admin Nadhira Napoleon';
        $adminRole = 'admin';
        $stmt->bind_param("sssss", $adminUser, $adminEmail, $hashedPassword, $adminName, $adminRole);
        
        if ($stmt->execute()) {
            $results[count($results) - 1]['status'] = 'success';
            $results[count($results) - 1]['message'] = 'Admin: admin@nadhiranapoleon.com / password';
        } else {
            throw new Exception('Gagal membuat admin: ' . $stmt->error);
        }
        $stmt->close();
    } catch (Exception $e) {
        $results[count($results) - 1]['status'] = 'error';
        $results[count($results) - 1]['message'] = 'Gagal: ' . $e->getMessage();
        $hasError = true;
    }

    // Step 5b: Jalankan RBAC Seeder (role, permission, assign admin -> super admin)
    $results[] = ['step' => 'Menjalankan RBAC Seeder', 'status' => 'processing'];
    try {
        $_GET['run'] = '1';
        ob_start();
        $rbacSeederPath = __DIR__ . '/rbac-seeder.php';
        if (!file_exists($rbacSeederPath)) {
            throw new Exception('File rbac-seeder.php tidak ditemukan');
        }
        require $rbacSeederPath;
        ob_end_clean();

        $verifyConn = getConnection();
        $roleCount = $verifyConn->query("SELECT COUNT(*) as c FROM roles")->fetch_assoc()['c'];
        $permCount = $verifyConn->query("SELECT COUNT(*) as c FROM permissions")->fetch_assoc()['c'];
        $results[count($results) - 1]['status'] = 'success';
        $results[count($results) - 1]['message'] = "{$roleCount} role dan {$permCount} permission berhasil diinstal";
    } catch (Exception $e) {
        ob_end_clean();
        $results[count($results) - 1]['status'] = 'warning';
        $results[count($results) - 1]['message'] = 'RBAC Seeder tidak lengkap: ' . $e->getMessage() . '. Jalankan manual: /database/rbac-seeder.php?run=1';
    }

    // Step 6: Verify settings
    $results[] = ['step' => 'Verifikasi data pengaturan', 'status' => 'processing'];
    try {
        $result = $conn->query("SELECT COUNT(*) as total FROM settings");
        if ($result) {
            $count = $result->fetch_assoc()['total'];
            $results[count($results) - 1]['status'] = 'success';
            $results[count($results) - 1]['message'] = $count . ' pengaturan website berhasil dimuat';
        } else {
            throw new Exception('Tabel settings tidak ditemukan');
        }
    } catch (Exception $e) {
        $results[count($results) - 1]['status'] = 'error';
        $results[count($results) - 1]['message'] = 'Gagal: ' . $e->getMessage();
        $hasError = true;
    }

    // Step 7: Test connection using the app's config
    $results[] = ['step' => 'Test koneksi dengan konfigurasi aplikasi', 'status' => 'processing'];
    try {
        $testConn = getConnection();
        if ($testConn && $testConn->ping()) {
            $results[count($results) - 1]['status'] = 'success';
            $results[count($results) - 1]['message'] = 'Koneksi database berhasil — aplikasi siap digunakan!';
        } else {
            throw new Exception('Gagal terhubung menggunakan getConnection()');
        }
    } catch (Exception $e) {
        $results[count($results) - 1]['status'] = 'error';
        $results[count($results) - 1]['message'] = 'Gagal: ' . $e->getMessage();
        $hasError = true;
    }

    // Step 8: Run product seeder (add sample products, articles, testimonials)
    $results[] = ['step' => 'Menambahkan data contoh (produk, artikel, testimoni)', 'status' => 'processing'];
    try {
        // Set flag so seeder runs without confirmation page
        $_GET['run'] = '1';
        
        // Capture seeder output
        ob_start();
        $seederPath = __DIR__ . '/seeder.php';
        if (!file_exists($seederPath)) {
            throw new Exception('File seeder.php tidak ditemukan');
        }
        require $seederPath;
        ob_end_clean();

        // Verify the data was inserted
        $verifyConn = getConnection();
        if ($verifyConn) {
            $prodCount = $verifyConn->query("SELECT COUNT(*) as c FROM products")->fetch_assoc()['c'];
            $artCount = $verifyConn->query("SELECT COUNT(*) as c FROM articles")->fetch_assoc()['c'];
            $testCount = $verifyConn->query("SELECT COUNT(*) as c FROM testimonials")->fetch_assoc()['c'];
            $revCount = $verifyConn->query("SELECT COUNT(*) as c FROM product_reviews")->fetch_assoc()['c'];
            $results[count($results) - 1]['status'] = 'success';
            $results[count($results) - 1]['message'] = "{$prodCount} produk, {$revCount} review, {$artCount} artikel, {$testCount} testimoni berhasil ditambahkan!";
        } else {
            throw new Exception('Gagal memverifikasi data setelah seeder');
        }
    } catch (Exception $e) {
        ob_end_clean();
        $results[count($results) - 1]['status'] = 'warning';
        $results[count($results) - 1]['message'] = 'Seeder tidak lengkap: ' . $e->getMessage() . '. Data dasar tetap tersedia.';
        // Not critical, so don't set $hasError
    }

    $conn->close();
    return ['results' => $results, 'hasError' => $hasError];
}

function fail($results, $index, $message) {
    $results[$index]['status'] = 'error';
    $results[$index]['message'] = $message;
    return ['results' => $results, 'hasError' => true];
}

// ============================================
// HANDLE INSTALLATION
// ============================================
// 🔒 PROTEKSI: installer hanya bisa dijalankan dari TERMINAL (CLI),
//    atau dari browser dengan kunci rahasia INSTALL_KEY (config/database.php).
//    Ini mencegah siapa pun menghapus seluruh database lewat URL.
$isCli = php_sapi_name() === 'cli';
$keyOk = defined('INSTALL_KEY') && INSTALL_KEY !== ''
    && isset($_GET['key']) && hash_equals(INSTALL_KEY, (string)$_GET['key']);
if (!$isCli && !$keyOk) {
    http_response_code(403);
    die('403 Forbidden — Akses installer ditolak. Jalankan dari terminal: php database/init.php');
}

$confirmed = $isCli || (isset($_GET['confirm']) && $_GET['confirm'] === 'yes');
$installResult = null;
$ran = false;

if ($confirmed) {
    $installResult = runInstall();
    $ran = true;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installer Database - Nadhira Napoleon</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #FFF8F0 0%, #FFF5E6 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
        }
        .container { max-width: 760px; width: 100%; }
        
        .card {
            background: #FFFAF5;
            border-radius: 24px;
            box-shadow: 0 8px 40px rgba(92,58,33,0.1);
            overflow: hidden;
        }
        .card-header {
            background: linear-gradient(135deg, #2C1810 0%, #5C3A21 100%);
            padding: 40px;
            text-align: center;
        }
        .card-header h1 {
            font-family: 'Playfair Display', serif;
            font-size: 32px;
            font-weight: 700;
            color: #FFF;
            margin-bottom: 8px;
        }
        .card-header h1 span { color: #D4A853; }
        .card-header p { color: rgba(255,255,255,0.7); font-size: 14px; }
        .card-body { padding: 32px 40px; }
        
        .step {
            display: flex;
            align-items: flex-start;
            gap: 16px;
            padding: 14px 20px;
            border-radius: 12px;
            margin-bottom: 10px;
        }
        .step-icon {
            width: 28px; height: 28px; min-width: 28px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 14px; font-weight: 600; margin-top: 2px;
        }
        .step-icon.success { background: #D1FAE5; color: #059669; }
        .step-icon.error { background: #FEE2E2; color: #DC2626; }
        .step-icon.warning { background: #FEF3C7; color: #D97706; }
        .step-icon.processing { background: #DBEAFE; color: #2563EB; }
        
        .step-content { flex: 1; }
        .step-title { font-weight: 600; font-size: 14px; margin-bottom: 4px; }
        .step-title.success { color: #059669; }
        .step-title.error { color: #DC2626; }
        .step-title.warning { color: #D97706; }
        .step-message { font-size: 13px; color: #8B6F47; line-height: 1.5; }
        
        .summary {
            padding: 24px; border-radius: 16px; text-align: center; margin-bottom: 24px;
        }
        .summary.success { background: #D1FAE5; color: #059669; }
        .summary.error { background: #FEE2E2; color: #DC2626; }
        .summary-icon { font-size: 48px; margin-bottom: 12px; }
        
        .footer { text-align: center; padding: 24px 40px; border-top: 1px solid #F0EDE8; }
        .btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 14px 32px; font-family: 'Inter', sans-serif;
            font-size: 14px; font-weight: 500; border: none;
            border-radius: 50px; cursor: pointer; text-decoration: none;
            transition: all 0.3s ease;
        }
        .btn-primary {
            background: linear-gradient(135deg, #D4A853 0%, #E8853B 100%);
            color: #FFF; box-shadow: 0 4px 20px rgba(212,168,83,0.3);
        }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 30px rgba(212,168,83,0.4); }
        .btn-danger {
            background: #DC2626; color: #FFF; box-shadow: 0 4px 20px rgba(220,38,38,0.3);
        }
        .btn-danger:hover { transform: translateY(-2px); box-shadow: 0 8px 30px rgba(220,38,38,0.4); }
        .btn-secondary {
            background: transparent; color: #5C3A21; border: 2px solid #D4A853;
        }
        .btn-secondary:hover { background: linear-gradient(135deg, #D4A853 0%, #E8853B 100%); color: #FFF; border-color: transparent; }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; transform: none !important; }
        
        .btn-group { display: flex; justify-content: center; gap: 12px; flex-wrap: wrap; }
        
        .info-box {
            background: #FFF5E6; border: 1px solid #F5E6CC;
            border-radius: 16px; padding: 24px; margin-top: 20px;
        }
        .info-box.danger { border-color: #FEE2E2; background: #FEF2F2; }
        .info-box h4 { font-family: 'Playfair Display', serif; font-size: 16px; margin-bottom: 12px; color: #5C3A21; }
        .info-box.danger h4 { color: #DC2626; }
        .info-box table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .info-box td { padding: 6px 0; color: #8B6F47; }
        .info-box td:first-child { font-weight: 500; color: #5C3A21; width: 120px; }
        .info-box ul, .info-box ol { font-size: 13px; color: #8B6F47; padding-left: 20px; line-height: 2; }
        
        .warning-banner {
            background: #FEF3C7; border: 1px solid #F59E0B;
            padding: 16px 20px; border-radius: 12px; margin-bottom: 20px;
            display: flex; align-items: flex-start; gap: 12px;
        }
        .warning-banner .icon { font-size: 24px; }
        .warning-banner .text { font-size: 14px; color: #92400E; flex: 1; }

        @media (max-width: 600px) {
            .card-header { padding: 24px; }
            .card-body { padding: 20px; }
            .step { padding: 10px 14px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="card-header">
                <h1>Nadhira <span>Napoleon</span></h1>
                <p>Database Installation Wizard</p>
            </div>

            <div class="card-body">
                <?php if (!$ran): ?>
                    <!-- === CONFIRMATION SCREEN === -->
                    <div class="warning-banner">
                        <div class="icon">⚠️</div>
                        <div class="text">
                            <strong>Peringatan!</strong> Proses ini akan <strong>menghapus semua data</strong> yang ada di database 
                            <code style="background: #F0EDE8; padding: 2px 6px; border-radius: 4px; font-size: 12px;"><?= DB_NAME ?></code> 
                            dan membuatnya dari awal. Pastikan Anda sudah memiliki backup jika diperlukan.
                        </div>
                    </div>

                    <div class="info-box" style="margin-bottom: 24px;">
                        <h4>📋 Informasi yang akan diinstal:</h4>
                        <table>
                            <tr><td>Database</td><td><strong><?= DB_NAME ?></strong></td></tr>
                            <tr><td>Tabel</td><td>35 tabel (termasuk 13 tabel RBAC)</td></tr>
                            <tr><td>Data Awal</td><td>Admin, kategori, cabang, FAQ, testimoni, dll</td></tr>
                            <tr><td>Host/Port</td><td><?= DB_HOST ?>:<?= DB_PORT ?></td></tr>
                            <tr><td>Schema File</td><td><code style="background: #F0EDE8; padding: 2px 6px; border-radius: 4px; font-size: 12px;">database/schema.sql</code></td></tr>
                        </table>
                    </div>

                    <div class="btn-group">
                        <a href="?confirm=yes" class="btn btn-danger" onclick="return confirm('⚠️ PERINGATAN!\n\nSemua data di database \'<?= DB_NAME ?>\' akan DIHAPUS!\n\nLanjutkan instalasi?')">
                            🚀 Mulai Instalasi
                        </a>
                        <a href="<?= SITE_URL ?>" class="btn btn-secondary">
                            ↩️ Batal
                        </a>
                    </div>

                <?php elseif ($installResult): ?>
                    <?php $results = $installResult['results']; $hasError = $installResult['hasError']; ?>

                    <!-- Summary -->
                    <?php if (!$hasError): ?>
                        <div class="summary success">
                            <div class="summary-icon">✅</div>
                            <div style="font-size: 22px; font-weight: 700; margin-bottom: 4px;">Instalasi Berhasil!</div>
                            <div style="font-weight: 400; font-size: 14px;">Database Nadhira Napoleon siap digunakan</div>
                        </div>
                    <?php else: ?>
                        <div class="summary error">
                            <div class="summary-icon">❌</div>
                            <div style="font-size: 22px; font-weight: 700; margin-bottom: 4px;">Instalasi Gagal</div>
                            <div style="font-weight: 400; font-size: 14px;">Ada kesalahan. Periksa detail di bawah.</div>
                        </div>
                    <?php endif; ?>

                    <!-- Steps -->
                    <?php foreach ($results as $index => $step): ?>
                        <?php 
                        $bgColor = match($step['status']) {
                            'success' => '#F0FDF4',
                            'error' => '#FEF2F2',
                            'warning' => '#FFFBEB',
                            default => '#EFF6FF'
                        };
                        ?>
                        <div class="step" style="background: <?= $bgColor ?>;">
                            <div class="step-icon <?= $step['status'] ?>">
                                <?= $step['status'] === 'success' ? '✓' : ($step['status'] === 'error' ? '✕' : ($step['status'] === 'warning' ? '⚠' : '⋯')) ?>
                            </div>
                            <div class="step-content">
                                <div class="step-title <?= $step['status'] ?>">
                                    <?= ($index + 1) ?>. <?= $step['step'] ?>
                                </div>
                                <div class="step-message"><?= $step['message'] ?? '' ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <!-- Access Info on Success -->
                    <?php if (!$hasError): ?>
                        <div class="info-box">
                            <h4>📋 Informasi Akses</h4>
                            <table>
                                <tr>
                                    <td>Website</td>
                                    <td><a href="<?= SITE_URL ?>" style="color: #D4A853; text-decoration: none; font-weight: 500;"><?= SITE_URL ?></a></td>
                                </tr>
                                <tr>
                                    <td>Admin Panel</td>
                                    <td><a href="<?= SITE_URL ?>/admin/index.php" style="color: #D4A853; text-decoration: none; font-weight: 500;"><?= SITE_URL ?>/admin/index.php</a></td>
                                </tr>
                                <tr>
                                    <td>Email Admin</td>
                                    <td><code style="background: #F0EDE8; padding: 2px 8px; border-radius: 4px;">admin@nadhiranapoleon.com</code></td>
                                </tr>
                                <tr>
                                    <td>Password</td>
                                    <td><code style="background: #F0EDE8; padding: 2px 8px; border-radius: 4px;">password</code></td>
                                </tr>
                                <tr>
                                    <td>Database</td>
                                    <td><strong><?= DB_NAME ?></strong></td>
                                </tr>
                            </table>
                        </div>

                        <!-- Post-install warning -->
                        <div class="info-box danger">
                            <h4>🔒 Setelah Instalasi</h4>
                            <ol>
                                <li><strong>Hapus file ini:</strong> <code style="background: #F0EDE8; padding: 2px 6px; border-radius: 4px; font-size: 12px;">database/init.php</code></li>
                                <li>Ganti password admin default: <code style="background: #F0EDE8; padding: 2px 6px; border-radius: 4px; font-size: 12px;">password</code> → password baru yang kuat</li>
                                <li>Konfigurasi <code style="background: #F0EDE8; padding: 2px 6px; border-radius: 4px; font-size: 12px;">config/database.php</code> jika perlu</li>
                            </ol>
                        </div>
                    <?php endif; ?>

                    <!-- Troubleshooting on Failure -->
                    <?php if ($hasError): ?>
                        <div class="info-box danger">
                            <h4>🔧 Troubleshooting</h4>
                            <ol>
                                <li>Pastikan MySQL server berjalan di <strong><?= DB_HOST ?>:<?= DB_PORT ?></strong></li>
                                <li>Periksa kredensial di <code style="background: #F0EDE8; padding: 2px 6px; border-radius: 4px;">config/database.php</code></li>
                                <li>Refresh halaman ini dengan <a href="?confirm=yes" style="color: #D4A853;">?confirm=yes</a></li>
                            </ol>
                        </div>
                        <div style="text-align: center; margin-top: 16px;">
                            <a href="?confirm=yes" class="btn btn-danger" onclick="return confirm('Ulangi instalasi? Semua data akan dihapus!')">
                                🔄 Coba Lagi
                            </a>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <div class="footer">
                <p style="font-size: 12px; color: #A0886A;">
                    Nadhira Napoleon Pekanbaru — Premium Oleh-Oleh Khas Riau
                </p>
            </div>
        </div>
    </div>
</body>
</html>
