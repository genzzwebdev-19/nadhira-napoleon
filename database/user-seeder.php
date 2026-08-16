<?php
// ============================================
// USER ACCOUNT SEEDER - Nadhira Napoleon
// Membuat user akun untuk setiap role (kecuali Super Admin)
// dengan password acak, username sesuai role slug
// ============================================
require_once __DIR__ . '/../config/rbac.php';

// Only allow via CLI or with INSTALL_KEY (browser blocked by default)
$isCLI = (php_sapi_name() === 'cli');
$keyOk = defined('INSTALL_KEY') && INSTALL_KEY !== ''
    && isset($_GET['key']) && hash_equals(INSTALL_KEY, (string)$_GET['key']);
if (!$isCLI && !$keyOk) {
    http_response_code(403);
    die('403 Forbidden - Akses ditolak. Jalankan dari terminal: php database/user-seeder.php');
}

if (!$isCLI) {
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>User Account Seeder - Nadhira Napoleon</title>
        <style>
            body { font-family: system-ui, sans-serif; max-width: 720px; margin: 40px auto; padding: 20px; background: #f8f5f0; color: #1a1a2e; }
            h1 { font-family: Georgia, serif; }
            .warning { background: #FEF3C7; border-left: 4px solid #F59E0B; padding: 16px; border-radius: 8px; margin: 20px 0; }
            .btn { display: inline-block; padding: 12px 32px; background: linear-gradient(135deg, #D4A853, #B8860B); color: #fff; text-decoration: none; border-radius: 8px; font-weight: 600; }
            .btn:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(212,168,83,0.4); }
        </style>
    </head>
    <body>
        <h1>👤 User Account Seeder</h1>
        <div class="warning">
            <h3>⚠️ Perhatian!</h3>
            <p>Script ini akan membuat akun baru untuk setiap role yang ada (kecuali Super Admin).
            Password setiap akun akan ditampilkan SATU KALI setelah proses selesai.
            Aman dijalankan berulang — akun yang sudah ada tidak akan diubah.</p>
        </div>
        <p><a href="?run=1" class="btn">🚀 Buat Akun Role</a></p>
    </body>
    </html>
    <?php
    exit;
}

$conn = getConnection();
if (!$conn) die("❌ Koneksi database gagal.\n");

$log = function ($msg) use ($isCLI) {
    echo ($isCLI ? $msg : htmlspecialchars($msg)) . "\n";
};

echo $isCLI ? "👤 NADHIRA USER ACCOUNT SEEDER\n=============================\n\n" : "<pre style='background:#1a1a2e;color:#e0e0e0;padding:20px;border-radius:8px;max-width:720px;margin:20px auto;font-family:monospace;'>\n";

// Ambil semua role aktif — skip super-admin
$roles = $conn->query("SELECT id, slug, name FROM roles WHERE is_active = 1 AND slug != 'super-admin' ORDER BY id");
if (!$roles || $roles->num_rows === 0) {
    $log("❌ Tidak ada role selain Super Admin.");
    exit;
}

$created = [];
$skipped = [];

while ($role = $roles->fetch_assoc()) {
    $slug = $role['slug'];
    $roleId = (int)$role['id'];
    $roleName = $role['name'];

    // Tentukan username & email dari slug
    $username = $slug;
    $email = $slug . '@nadhiranapoleon.com';
    $fullName = $roleName;

    // Cek apakah user dengan username ini sudah ada
    $existing = $conn->query("SELECT id FROM users WHERE username = '$username' LIMIT 1");
    if ($existing && $existing->num_rows > 0) {
        $skipped[] = ['slug' => $slug, 'name' => $roleName];
        continue;
    }

    // Buat password: 8 karakter acak
    $password = substr(bin2hex(random_bytes(4)), 0, 8);
    $hash = password_hash($password, PASSWORD_BCRYPT);

    $username_e = $conn->real_escape_string($username);
    $email_e = $conn->real_escape_string($email);
    $name_e = $conn->real_escape_string($fullName);

    $conn->query(
        "INSERT INTO users (username, email, full_name, password, phone, role, is_active)
         VALUES ('$username_e', '$email_e', '$name_e', '$hash', '', 'customer', 1)"
    );

    if ($conn->affected_rows > 0) {
        $userId = (int)$conn->insert_id;

        // Assign role
        $conn->query("INSERT INTO user_roles (user_id, role_id) VALUES ($userId, $roleId)");

        $created[] = [
            'slug' => $slug,
            'name' => $roleName,
            'username' => $username,
            'password' => $password,
            'email' => $email,
            'id' => $userId,
        ];
    } else {
        $log("⚠️  Gagal membuat user untuk role: $roleName ($slug)");
    }
}

// ============================================
// OUTPUT
// ============================================
echo "\n";
if (!empty($skipped)) {
    $log("⏭️  Dilewati (sudah ada): " . count($skipped) . " akun");
    foreach ($skipped as $s) {
        $log("   - {$s['name']} (@{$s['slug']})");
    }
}

if (!empty($created)) {
    echo "\n";
    $log("======================================================================");
    $log("  ✅ " . count($created) . " AKUN BERHASIL DIBUAT!");
    $log("  ⚠️  Simpan password di bawah! TIDAK bisa ditampilkan lagi.");
    $log("======================================================================");
    echo "\n";
    $log(str_pad('ROLE', 32) . str_pad('USERNAME', 24) . str_pad('PASSWORD', 14) . 'EMAIL');
    $log(str_repeat('-', 90));
    foreach ($created as $a) {
        $log(str_pad($a['name'], 32) . str_pad($a['username'], 24) . str_pad($a['password'], 14) . $a['email']);
    }
    echo "\n";
    $log("💡 Login di: http://localhost/nad/auth/login.php");
    $log("======================================================================");
} else {
    $log("ℹ️  Semua akun sudah ada sebelumnya. Tidak ada yang dibuat.");
}

echo "\n";
$total = $conn->query("SELECT COUNT(*) AS c FROM users")->fetch_assoc()['c'];
$adminUsers = $conn->query(
    "SELECT COUNT(*) AS c FROM users u
     WHERE (SELECT COUNT(*) FROM user_roles ur WHERE ur.user_id = u.id) > 0 OR u.role = 'admin'"
)->fetch_assoc()['c'];
$log("📊 Ringkasan: $total total user, $adminUsers user dengan role admin.");

if (!$isCLI) echo "</pre>";
echo "\n";