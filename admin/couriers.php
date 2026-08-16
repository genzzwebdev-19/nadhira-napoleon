<?php
$currentPage = 'couriers';
$pageTitle = 'Kurir & Tracking';
require_once __DIR__ . '/../config/database.php';

$conn = getConnection();
require_once __DIR__ . '/../config/rbac.php';
requirePermission('couriers', 'view');

$errors = [];
$success = '';

// ============ ACTION: Simpan kurir ============
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_courier'])) {
    requirePermission('couriers', (int)($_POST['id'] ?? 0) > 0 ? 'edit' : 'create');
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $userId = (int)($_POST['user_id'] ?? 0);
    $branchId = (int)($_POST['branch_id'] ?? 0);
    $editId = (int)($_POST['id'] ?? 0);

    if (empty($name)) $errors[] = 'Nama kurir wajib diisi';
    if ($editId === 0 && $userId <= 0) $errors[] = 'Pilih akun user untuk kurir baru';

    if (empty($errors)) {
        $name_e = $conn->real_escape_string($name);
        $phone_e = $conn->real_escape_string($phone);
        $userSql = $userId > 0 ? $userId : 'NULL';
        $branchSql = $branchId > 0 ? $branchId : 'NULL';

        if ($editId > 0) {
            $sql = "UPDATE couriers SET name='$name_e', phone='$phone_e', branch_id=$branchSql WHERE id=$editId";
        } else {
            $sql = "INSERT INTO couriers (user_id, name, phone, branch_id, is_active) VALUES ($userSql, '$name_e', '$phone_e', $branchSql, 1)";
        }
        if ($conn->query($sql)) {
            $success = 'Kurir berhasil ' . ($editId > 0 ? 'diperbarui' : 'ditambahkan') . '!';
            logActivity($editId > 0 ? 'update' : 'create', 'couriers', ($editId > 0 ? 'Mengubah' : 'Menambahkan') . " kurir: $name");
        } else {
            $errors[] = 'Gagal: ' . $conn->error;
        }
    }
}

// ============ ACTION: Toggle aktif ============
if (isset($_GET['toggle'])) {
    requirePermission('couriers', 'edit');
    $togId = (int)$_GET['toggle'];
    $conn->query("UPDATE couriers SET is_active = NOT is_active WHERE id = $togId");
    logActivity('edit', 'couriers', "Toggle status kurir #$togId");
    header('Location: couriers.php');
    exit;
}

// ============ ACTION: Hapus ============
if (isset($_GET['delete'])) {
    requirePermission('couriers', 'delete');
    $delId = (int)$_GET['delete'];
    $conn->query("DELETE FROM couriers WHERE id = $delId");
    logActivity('delete', 'couriers', "Menghapus kurir #$delId");
    header('Location: couriers.php');
    exit;
}

// ============ DATA ============
// Kurir + posisi terakhir + cabang
$couriers = [];
$r = $conn->query("SELECT c.*, b.name as branch_name, b.latitude as branch_lat, b.longitude as branch_lng, u.full_name as user_name
    FROM couriers c
    LEFT JOIN branches b ON b.id = c.branch_id
    LEFT JOIN users u ON u.id = c.user_id
    ORDER BY c.is_active DESC, c.name ASC");
if ($r) {
    while ($c = $r->fetch_assoc()) {
        $c['location'] = getLatestCourierLocation((int)$c['id']);
        $couriers[] = $c;
    }
}

// Daftar user yang belum jadi kurir (untuk dropdown)
$candidateUsers = $conn->query("SELECT u.id, u.full_name, u.phone, u.email FROM users u
    LEFT JOIN couriers c ON c.user_id = u.id
    WHERE c.id IS NULL ORDER BY u.full_name ASC");
$branches = $conn->query("SELECT * FROM branches WHERE is_active = 1 ORDER BY name ASC");

require_once __DIR__ . '/layout.php';
?>

            <?php if ($success): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $success ?></div>
            <?php endif; ?>
            <?php foreach ($errors as $err): ?>
                <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($err) ?></div>
            <?php endforeach; ?>

            <!-- Peta Live Kurir -->
            <div class="admin-card">
                <h3 class="admin-card-title">
                    <i class="fas fa-map-marked-alt" style="color: var(--soft-gold);"></i>
                    Posisi Kurir (Real-Time)
                    <span style="font-size: 11px; color: var(--text-muted); font-weight: 400; margin-left: 8px;">diperbarui otomatis setiap 15 detik</span>
                </h3>
                <div id="courier-map" style="height: 420px; border-radius: 12px; border: 1px solid #e5e0db;"></div>
            </div>

            <!-- Form Tambah Kurir -->
            <div class="admin-card">
                <h3 class="admin-card-title">Tambah Kurir Baru</h3>
                <form method="POST">
                    <input type="hidden" name="save_courier" value="1">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Nama Kurir <span style="color: #EF4444;">*</span></label>
                            <input type="text" name="name" class="form-input" placeholder="Contoh: Budi - Kurir Sudirman" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">No. Telepon</label>
                            <input type="text" name="phone" class="form-input" placeholder="0821-1234-5678">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Akun User <span style="color: #EF4444;">*</span></label>
                            <select name="user_id" class="form-select" required>
                                <option value="">— Pilih akun (untuk login panel kurir) —</option>
                                <?php if ($candidateUsers): while ($u = $candidateUsers->fetch_assoc()): ?>
                                <option value="<?= (int)$u['id'] ?>">
                                    <?= htmlspecialchars($u['full_name']) ?> (<?= htmlspecialchars($u['email']) ?>)
                                </option>
                                <?php endwhile; endif; ?>
                            </select>
                            <small style="color: var(--text-muted);">User ini akan bisa login di <b><?= SITE_URL ?>/courier/index.php</b> untuk mengirim posisi GPS.</small>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Cabang</label>
                            <select name="branch_id" class="form-select">
                                <option value="0">— Tanpa cabang —</option>
                                <?php if ($branches): while ($b = $branches->fetch_assoc()): ?>
                                <option value="<?= (int)$b['id'] ?>"><?= htmlspecialchars($b['name']) ?></option>
                                <?php endwhile; endif; ?>
                            </select>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Tambah Kurir</button>
                </form>
            </div>

            <!-- Tabel Kurir -->
            <div class="admin-card">
                <div style="overflow-x: auto;">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Nama</th>
                                <th>Akun</th>
                                <th>Cabang</th>
                                <th>Posisi Terakhir</th>
                                <th>Status</th>
                                <th style="width: 120px;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($couriers)): foreach ($couriers as $c): 
                                $loc = $c['location'];
                            ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($c['name']) ?></strong>
                                    <?php if ($c['phone']): ?><br><small style="color: var(--text-muted);"><?= htmlspecialchars($c['phone']) ?></small><?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($c['user_name'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($c['branch_name'] ?? '-') ?></td>
                                <td>
                                    <?php if ($loc): ?>
                                        <span style="font-size: 12px;">
                                            <?= number_format((float)$loc['latitude'], 5, ',', '.') ?>, <?= number_format((float)$loc['longitude'], 5, ',', '.') ?>
                                            <br><small style="color: var(--text-muted);"><?= formatDate($loc['recorded_at'], 'd M Y H:i:s') ?></small>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: var(--text-light); font-size: 12px;">Belum ada sinyal</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="status-badge <?= $c['is_active'] ? 'active' : 'inactive' ?>">
                                        <?= $c['is_active'] ? 'Aktif' : 'Nonaktif' ?>
                                    </span>
                                </td>
                                <td>
                                    <div style="display: flex; gap: 4px;">
                                        <?php if ($loc): ?>
                                        <a href="https://www.google.com/maps?q=<?= (float)$loc['latitude'] ?>,<?= (float)$loc['longitude'] ?>" target="_blank" class="btn btn-outline btn-sm" title="Buka di Google Maps">
                                            <i class="fas fa-map-marker-alt"></i>
                                        </a>
                                        <?php endif; ?>
                                        <a href="couriers.php?toggle=<?= $c['id'] ?>" class="btn btn-outline btn-sm" title="<?= $c['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?>">
                                            <i class="fas <?= $c['is_active'] ? 'fa-eye-slash' : 'fa-eye' ?>"></i>
                                        </a>
                                        <a href="couriers.php?delete=<?= $c['id'] ?>" class="btn btn-outline btn-sm" title="Hapus"
                                           onclick="return confirm('Hapus kurir <?= htmlspecialchars($c['name']) ?>?')"
                                           style="color: #EF4444; border-color: #EF4444;">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; else: ?>
                            <tr><td colspan="6" style="text-align: center; padding: 40px; color: var(--text-muted);">Belum ada kurir — tambahkan kurir pertama di atas.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
            <style>#courier-map .leaflet-top, #courier-map .leaflet-bottom { z-index: 1; }</style>
            <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
            <script>
            (function () {
                function escHtml(s) {
                    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
                    });
                }
                var COURIERS = <?= json_encode(array_map(function ($c) {
                    return [
                        'id'    => (int)$c['id'],
                        'name'  => $c['name'],
                        'active'=> (int)$c['is_active'],
                        'loc'   => $c['location'] ? [
                            'lat' => (float)$c['location']['latitude'],
                            'lng' => (float)$c['location']['longitude'],
                            'at'  => $c['location']['recorded_at'],
                        ] : null,
                    ];
                }, $couriers), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

                if (typeof L === 'undefined') return;
                var map = L.map('courier-map').setView([0.5071, 101.4478], 13);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19,
                    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
                }).addTo(map);

                var markers = {};
                var courierIcon = L.divIcon({ className: '', html: '<div style="background:#2563EB;color:#fff;width:30px;height:30px;border-radius:50%;border:2px solid #fff;box-shadow:0 3px 10px rgba(0,0,0,.4);display:flex;align-items:center;justify-content:center;"><i class="fas fa-motorcycle" style="font-size:14px;"></i></div>', iconSize: [30, 30], iconAnchor: [15, 15] });

                function drawCourier(c) {
                    if (!c.loc) return;
                    var pop = '<b>📦 ' + escHtml(c.name) + '</b><br>Diperbarui: ' + c.loc.at + '<br><a href="https://www.google.com/maps?q=' + c.loc.lat + ',' + c.loc.lng + '" target="_blank">Buka di Google Maps</a>';
                    if (markers[c.id]) {
                        markers[c.id].setLatLng([c.loc.lat, c.loc.lng]);
                        markers[c.id].setPopupContent(pop);
                    } else {
                        markers[c.id] = L.marker([c.loc.lat, c.loc.lng], { icon: courierIcon }).addTo(map).bindPopup(pop);
                    }
                }
                COURIERS.forEach(drawCourier);

                // Auto refresh posisi setiap 15 detik
                setInterval(function () {
                    fetch('<?= SITE_URL ?>/ajax/courier-location-list.php')
                        .then(function (r) { return r.json(); })
                        .then(function (d) {
                            if (d.ok) {
                                COURIERS = d.couriers;
                                COURIERS.forEach(drawCourier);
                            }
                        })
                        .catch(function () {});
                }, 15000);
            })();
            </script>
        </main>
    </div>
</body>
</html>
