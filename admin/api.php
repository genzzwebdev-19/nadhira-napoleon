<?php
$currentPage = 'api';
$pageTitle = 'API Integrasi';
require_once __DIR__ . '/../config/rbac.php';

requirePermission('api', 'view');
$conn = getConnection();

// ============================================
// JSON ENDPOINTS
// ============================================
if (isset($_GET['json'])) {
    $endpoint = $_GET['json'] ?? '';
    $data = ['status' => 'error', 'message' => 'Endpoint tidak dikenal'];

    switch ($endpoint) {
        case 'roles':
            requirePermission('api', 'settings');
            $r = $conn->query("SELECT id, name, slug, description, is_system, is_active FROM roles ORDER BY id");
            $rows = [];
            if ($r) while ($row = $r->fetch_assoc()) $rows[] = $row;
            $data = ['status' => 'ok', 'total' => count($rows), 'data' => $rows];
            break;

        case 'permissions':
            requirePermission('api', 'settings');
            $module = isset($_GET['module']) ? $conn->real_escape_string($_GET['module']) : '';
            $where = $module !== '' ? "WHERE module = '$module'" : '';
            $r = $conn->query("SELECT id, module, action, name FROM permissions $where ORDER BY module, action");
            $rows = [];
            if ($r) while ($row = $r->fetch_assoc()) $rows[] = $row;
            $data = ['status' => 'ok', 'total' => count($rows), 'data' => $rows];
            break;

        case 'menus':
            requirePermission('api', 'settings');
            $r = $conn->query("SELECT id, name, slug, icon, url, module, section, sort_order, is_active FROM menus ORDER BY section, sort_order");
            $rows = [];
            if ($r) while ($row = $r->fetch_assoc()) $rows[] = $row;
            $data = ['status' => 'ok', 'total' => count($rows), 'data' => $rows];
            break;

        case 'role_permissions':
            requirePermission('api', 'settings');
            $roleId = (int)($_GET['role_id'] ?? 0);
            $r = $conn->query(
                "SELECT p.module, p.action, p.name FROM permissions p
                 JOIN role_permissions rp ON rp.permission_id = p.id
                 WHERE rp.role_id = $roleId ORDER BY p.module, p.action"
            );
            $rows = [];
            if ($r) while ($row = $r->fetch_assoc()) $rows[] = $row;
            $data = ['status' => 'ok', 'role_id' => $roleId, 'total' => count($rows), 'data' => $rows];
            break;

        case 'my_permissions':
            $permSet = getUserPermissionSet();
            $data = ['status' => 'ok', 'user_id' => getCurrentUserId(), 'permissions' => $permSet];
            break;

        case 'branches':
            requirePermission('api', 'settings');
            $r = $conn->query("SELECT id, name, address, phone, whatsapp FROM branches WHERE is_active = 1 ORDER BY sort_order");
            $rows = [];
            if ($r) while ($row = $r->fetch_assoc()) $rows[] = $row;
            $data = ['status' => 'ok', 'total' => count($rows), 'data' => $rows];
            break;

        case 'users':
            requirePermission('api', 'settings');
            $r = $conn->query(
                "SELECT u.id, u.full_name, u.email, u.username, u.phone, u.is_active, u.last_login,
                        (SELECT GROUP_CONCAT(r.slug SEPARATOR ',') FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id) AS roles
                 FROM users u
                 WHERE (SELECT COUNT(*) FROM user_roles ur WHERE ur.user_id = u.id) > 0
                 ORDER BY u.id"
            );
            $rows = [];
            if ($r) while ($row = $r->fetch_assoc()) $rows[] = $row;
            $data = ['status' => 'ok', 'total' => count($rows), 'data' => $rows];
            break;
    }

    jsonResponse($data);
    exit;
}
?>
<div class="admin-card">
    <h3 class="admin-card-title"><i class="fas fa-code" style="color: var(--soft-gold);"></i> API Endpoint Role &amp; Permission</h3>
    <p style="color: var(--text-muted); font-size: 13px; line-height: 1.7;">
        API JSON untuk integrasi sistem lain. Setiap endpoint membutuhkan login admin dan permission
        <strong>api:settings</strong> (kecuali <code>my_permissions</code>). Tambahkan parameter <code>?json=</code> berikut:
    </p>

    <table class="admin-table">
        <thead>
            <tr><th>Endpoint</th><th>Keterangan</th><th>Permission</th></tr>
        </thead>
        <tbody>
            <tr>
                <td><code>api.php?json=roles</code></td>
                <td>Daftar semua role</td>
                <td><span class="status-badge gold">api:settings</span></td>
            </tr>
            <tr>
                <td><code>api.php?json=permissions</code></td>
                <td>Daftar permission (filter <code>&amp;module=produk</code>)</td>
                <td><span class="status-badge gold">api:settings</span></td>
            </tr>
            <tr>
                <td><code>api.php?json=menus</code></td>
                <td>Daftar menu sidebar dinamis</td>
                <td><span class="status-badge gold">api:settings</span></td>
            </tr>
            <tr>
                <td><code>api.php?json=role_permissions&amp;role_id=1</code></td>
                <td>Permission milik sebuah role</td>
                <td><span class="status-badge gold">api:settings</span></td>
            </tr>
            <tr>
                <td><code>api.php?json=branches</code></td>
                <td>Daftar cabang aktif</td>
                <td><span class="status-badge gold">api:settings</span></td>
            </tr>
            <tr>
                <td><code>api.php?json=users</code></td>
                <td>Daftar user admin + role-nya</td>
                <td><span class="status-badge gold">api:settings</span></td>
            </tr>
            <tr>
                <td><code>api.php?json=my_permissions</code></td>
                <td>Permission milik user yang login</td>
                <td><span class="status-badge active">login saja</span></td>
            </tr>
        </tbody>
    </table>

    <h4 style="margin: 24px 0 12px; font-size: 14px; color: var(--text-dark);">Contoh Respons</h4>
    <pre style="background: #1a1a2e; color: #e0e0e0; padding: 16px; border-radius: 10px; font-size: 12px; overflow-x: auto;">{
  "status": "ok",
  "total": 2,
  "data": [
    { "id": 1, "name": "Super Admin", "slug": "super-admin" },
    { "id": 2, "name": "Owner", "slug": "owner" }
  ]
}</pre>

    <div style="margin-top: 20px;">
        <a href="?json=roles" target="_blank" class="btn btn-secondary btn-sm"><i class="fas fa-eye"></i> Coba roles</a>
        <a href="?json=my_permissions" target="_blank" class="btn btn-secondary btn-sm"><i class="fas fa-eye"></i> Coba my_permissions</a>
    </div>
</div>
        </main>
    </div>
</body>
</html>
