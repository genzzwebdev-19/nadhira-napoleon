<?php
$currentPage = 'branches';
$pageTitle = 'Cabang';
require_once __DIR__ . '/../config/database.php';

$conn = getConnection();
require_once __DIR__ . '/../config/rbac.php';
require_once __DIR__ . '/../config/cloudinary.php'; // penyimpanan foto Cloudinary
requirePermission('branches', 'view');
ensureBranchesOpenHours(); // pastikan kolom open_time & close_time tersedia

$errors = [];
$success = '';

// Handle actions BEFORE layout.php outputs HTML
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_branch'])) {
    verifyCsrf();
    requirePermission('branches', (int)($_POST['id'] ?? 0) > 0 ? 'edit' : 'create');
    $name = trim($_POST['name'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $whatsapp = trim($_POST['whatsapp'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $open_hours = trim($_POST['open_hours'] ?? '');
    $open_time = trim($_POST['open_time'] ?? '');
    $close_time = trim($_POST['close_time'] ?? '');
    $open_24h = isset($_POST['open_24h']) ? 1 : 0;
    $latitude = (float)($_POST['latitude'] ?? 0);
    $longitude = (float)($_POST['longitude'] ?? 0);
    $mapsUrl = trim($_POST['maps_url'] ?? '');
    $imageUrl = trim($_POST['image_url'] ?? '');
    $sort_order = (int)($_POST['sort_order'] ?? 0);
    $editId = (int)($_POST['id'] ?? 0);
    ensureBranchesMapsUrl(); // pastikan kolom maps_url tersedia sebelum disimpan

    if (empty($name)) $errors[] = 'Nama cabang wajib diisi';
    if (empty($address)) $errors[] = 'Alamat cabang wajib diisi';
    if ($mapsUrl !== '' && !preg_match('#^https?://#i', $mapsUrl)) {
        $errors[] = 'Link Google Maps harus diawali http:// atau https://';
    }
    if ($imageUrl !== '' && !preg_match('#^https?://#i', $imageUrl)) {
        $errors[] = 'URL foto harus diawali http:// atau https://';
    }
    // Validasi format jam buka/tutup (HH:MM, mis. 08:00 / 21:00)
    if ($open_time !== '' && !preg_match('/^([0-9]|0[0-9]|1[0-9]|2[0-3]):[0-5][0-9]$/', $open_time)) {
        $errors[] = 'Format Jam Buka tidak valid (contoh: 08:00).';
    }
    if ($close_time !== '' && !preg_match('/^([0-9]|0[0-9]|1[0-9]|2[0-3]):[0-5][0-9]$/', $close_time)) {
        $errors[] = 'Format Jam Tutup tidak valid (contoh: 21:00).';
    }
    // Normalisasi ke HH:MM agar konsisten saat disimpan
    $open_time = $open_time !== '' ? date('H:i', strtotime($open_time)) : '';
    $close_time = $close_time !== '' ? date('H:i', strtotime($close_time)) : '';

    // Simpan foto lama (untuk dibersihkan saat diganti dengan yang baru)
    $oldImage = '';
    if ($editId > 0) {
        $oldQ = $conn->query("SELECT image FROM branches WHERE id = $editId LIMIT 1");
        if ($oldQ && $oldQ->num_rows > 0) $oldImage = $oldQ->fetch_assoc()['image'] ?? '';
    }

    // Upload foto toko (jika ada file)
    if (isset($_FILES['branch_image']) && $_FILES['branch_image']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/../uploads/branches/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
        $tmpPath = $_FILES['branch_image']['tmp_name'];
        $ext = strtolower(pathinfo($_FILES['branch_image']['name'], PATHINFO_EXTENSION));
        $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

        if ($_FILES['branch_image']['size'] > 20 * 1024 * 1024) {
            $errors[] = 'Ukuran foto terlalu besar (maks 20MB).';
        } elseif (!in_array($ext, $allowedExts) || !@getimagesize($tmpPath)) {
            $errors[] = 'Format foto tidak didukung (JPG, PNG, WebP, GIF).';
        } else {
            $destPath = $uploadDir . 'cabang_' . time() . '_' . uniqid() . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
            $uploaded = false;
            // Jika Cloudinary aktif, upload langsung ke Cloudinary (tanpa simpan lokal)
            if (cloudinaryEnabled()) {
                $up = cloudinaryUploadFromUploaded($tmpPath, $_FILES['branch_image']['name'], 'nadhira/branches', 'cabang');
                if ($up['success']) {
                    $imageUrl = $up['url'];
                    $uploaded = true;
                } else {
                    $errors[] = 'Gagal upload foto ke Cloudinary: ' . $up['message'];
                }
            } elseif (move_uploaded_file($tmpPath, $destPath)) {
                $imageUrl = SITE_URL . '/uploads/branches/' . basename($destPath);
                $uploaded = true;
            } else {
                $errors[] = 'Gagal mengunggah foto cabang.';
            }
            // Foto lama diganti → hapus dari Cloudinary / lokal
            if ($uploaded && $oldImage !== '' && $oldImage !== $imageUrl) {
                if (isCloudinaryUrl($oldImage)) {
                    cloudinaryDeleteByUrl($oldImage);
                } elseif (strpos($oldImage, '/uploads/branches/') !== false) {
                    $oldPath = __DIR__ . '/../uploads/branches/' . basename(parse_url($oldImage, PHP_URL_PATH));
                    if (file_exists($oldPath)) @unlink($oldPath);
                }
            }
        }
    }

    if (empty($errors)) {
        $name_e = $conn->real_escape_string($name);
        $addr_e = $conn->real_escape_string($address);
        $phone_e = $conn->real_escape_string($phone);
        $wa_e = $conn->real_escape_string($whatsapp);
        $email_e = $conn->real_escape_string($email);
        $hours_e = $conn->real_escape_string($open_hours);
        $maps_e = $conn->real_escape_string($mapsUrl);
        $img_e = $conn->real_escape_string($imageUrl);
        // Jam buka/tutup (sudah tervalidasi HH:MM) — NULL bila kosong
        $open_t = $open_time !== '' ? "'$open_time'" : 'NULL';
        $close_t = $close_time !== '' ? "'$close_time'" : 'NULL';

        if ($editId > 0) {
            $sql = "UPDATE branches SET name='$name_e', address='$addr_e', phone='$phone_e', whatsapp='$wa_e', email='$email_e', open_hours='$hours_e', open_time=$open_t, close_time=$close_t, open_24h=$open_24h, latitude=$latitude, longitude=$longitude, maps_url='$maps_e', image='$img_e', sort_order=$sort_order WHERE id=$editId";
        } else {
            $sql = "INSERT INTO branches (name, address, phone, whatsapp, email, open_hours, open_time, close_time, open_24h, latitude, longitude, maps_url, image, sort_order) VALUES ('$name_e', '$addr_e', '$phone_e', '$wa_e', '$email_e', '$hours_e', $open_t, $close_t, $open_24h, $latitude, $longitude, '$maps_e', '$img_e', $sort_order)";
        }
        if ($conn->query($sql)) {
            $success = 'Cabang berhasil ' . ($editId > 0 ? 'diperbarui' : 'ditambahkan') . '!';
            logActivity($editId > 0 ? 'update' : 'create', 'branches', ($editId > 0 ? 'Mengubah' : 'Menambahkan') . " cabang: $name");
        } else {
            $errors[] = 'Gagal: ' . $conn->error;
        }
    }
}

if (isset($_GET['delete'])) {
    requirePermission('branches', 'delete');
    $delId = (int)$_GET['delete'];
    // Bersihkan foto cabang (Cloudinary / lokal) sebelum baris dihapus
    $oldQ = $conn->query("SELECT image FROM branches WHERE id = $delId LIMIT 1");
    if ($oldQ && $oldQ->num_rows > 0) {
        $oldImage = $oldQ->fetch_assoc()['image'] ?? '';
        if ($oldImage !== '') {
            if (isCloudinaryUrl($oldImage)) {
                cloudinaryDeleteByUrl($oldImage);
            } elseif (strpos($oldImage, '/uploads/branches/') !== false) {
                $oldPath = __DIR__ . '/../uploads/branches/' . basename(parse_url($oldImage, PHP_URL_PATH));
                if (file_exists($oldPath)) @unlink($oldPath);
            }
        }
    }
    $conn->query("DELETE FROM branches WHERE id = $delId");
    logActivity('delete', 'branches', "Menghapus cabang #$delId");
    header('Location: branches.php');
    exit;
}

if (isset($_GET['toggle'])) {
    requirePermission('branches', 'edit');
    $togId = (int)$_GET['toggle'];
    $conn->query("UPDATE branches SET is_active = NOT is_active WHERE id = $togId");
    logActivity('edit', 'branches', "Toggle status cabang #$togId");
    header('Location: branches.php');
    exit;
}

// Multi-branch: user dengan akses cabang tertentu hanya melihat cabang miliknya
$branchScope = '';
if (!isSuperAdmin()) {
    $allowedBranches = getAccessibleBranchIds();
    if (!empty($allowedBranches)) {
        $branchScope = " WHERE id IN (" . implode(',', array_map('intval', $allowedBranches)) . ")";
    }
}
$branches = $conn->query("SELECT * FROM branches $branchScope ORDER BY sort_order ASC, created_at ASC");

require_once __DIR__ . '/layout.php';
?>

            <?php if ($success): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $success ?></div>
            <?php endif; ?>
            <?php foreach ($errors as $err): ?>
                <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($err) ?></div>
            <?php endforeach; ?>

            <div class="admin-card">
                <h3 class="admin-card-title">Tambah Cabang Baru</h3>
                <form method="POST" enctype="multipart/form-data">
                    <?= csrfField() ?>
                    <input type="hidden" name="save_branch" value="1">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Nama Cabang <span style="color: #EF4444;">*</span></label>
                            <input type="text" name="name" class="form-input" placeholder="Contoh: Nadhira Napoleon - Sudirman" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Urutan</label>
                            <input type="number" name="sort_order" class="form-input" value="0" min="0">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Alamat <span style="color: #EF4444;">*</span></label>
                        <textarea name="address" class="form-textarea" placeholder="Alamat lengkap cabang" required></textarea>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">No. Telepon</label>
                            <input type="text" name="phone" class="form-input" placeholder="0821-1234-5678">
                        </div>
                        <div class="form-group">
                            <label class="form-label">WhatsApp</label>
                            <input type="text" name="whatsapp" class="form-input" placeholder="6282112345678">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-input" placeholder="cabang@nadhiranapoleon.com">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Catatan Jam (opsional)</label>
                            <input type="text" name="open_hours" class="form-input" placeholder="Contoh: Tutup setiap hari Senin / Buka 24 jam">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Jam Buka</label>
                            <input type="time" name="open_time" id="add-open-time" class="form-input">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Jam Tutup</label>
                            <input type="time" name="close_time" id="add-close-time" class="form-input">
                        </div>
                        <div style="flex: 2; align-self: flex-end; padding-bottom: 6px;">
                            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 500; margin-bottom: 4px;">
                                <input type="checkbox" name="open_24h" value="1" style="accent-color: var(--soft-gold); width: 18px; height: 18px;"
                                       onchange="toggleOpen24h(this, ['add-open-time','add-close-time'])">
                                Buka 24 Jam
                            </label>
                            <small style="color: var(--text-muted);">
                                <i class="fas fa-info-circle"></i> Tampil sebagai "08.00 - 21.00 WIB" atau "Buka 24 Jam". Kosongkan untuk memakai Catatan Jam / pengaturan global.
                            </small>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Latitude</label>
                            <input type="number" name="latitude" class="form-input" step="any" placeholder="Contoh: 0.5070677">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Longitude</label>
                            <input type="number" name="longitude" class="form-input" step="any" placeholder="Contoh: 101.4477793">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Link Google Maps (opsional)</label>
                        <input type="url" name="maps_url" class="form-input" placeholder="https://maps.app.goo.gl/xxxxxxxx atau https://www.google.com/maps?...">
                        <small style="color: var(--text-muted); margin-top: 4px; display: block;">
                            <i class="fas fa-info-circle"></i> Tombol "Maps" di website akan membuka link ini. Kosongkan untuk memakai link otomatis dari latitude/longitude/alamat.
                        </small>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Foto Toko</label>
                        <input type="file" name="branch_image" class="form-input" accept="image/jpeg,image/png,image/webp,image/gif">
                        <small style="color: var(--text-muted); margin-top: 4px; display: block;">
                            <i class="fas fa-info-circle"></i> Maks 20MB (JPG, PNG, WebP, GIF). Foto tampil di kartu cabang homepage.
                        </small>
                        <input type="url" name="image_url" class="form-input" style="margin-top: 8px;" placeholder="Atau isi URL foto toko (opsional)">
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Tambah Cabang
                    </button>
                </form>
            </div>

            <div class="admin-card">
                <div style="overflow-x: auto;">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th style="width: 60px;">Urutan</th>
                                <th style="width: 90px;">Foto</th>
                                <th>Nama</th>
                                <th>Alamat</th>
                                <th>Kontak</th>
                                <th>Jam Buka</th>
                                <th>Status</th>
                                <th style="width: 120px;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($branches && $branches->num_rows > 0):
                                while ($b = $branches->fetch_assoc()):
                            ?>
                            <tr>
                                <td style="text-align: center;"><?= $b['sort_order'] ?></td>
                                <td>
                                    <?php if (!empty($b['image'])): ?>
                                        <img src="<?= htmlspecialchars($b['image']) ?>" alt="Foto <?= htmlspecialchars($b['name']) ?>"
                                             style="width: 70px; height: 45px; object-fit: cover; border-radius: 6px; display: block;">
                                    <?php else: ?>
                                        <span style="color: var(--text-light); font-size: 12px;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><strong><?= htmlspecialchars($b['name']) ?></strong></td>
                                <td style="max-width: 250px;">
                                    <span style="font-size: 13px; color: var(--text-muted); display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">
                                        <?= htmlspecialchars($b['address']) ?>
                                    </span>
                                </td>
                                <td>
                                    <div style="font-size: 12px;">
                                        <?php if ($b['phone']): ?><div><i class="fas fa-phone" style="width: 14px;"></i> <?= htmlspecialchars($b['phone']) ?></div><?php endif; ?>
                                        <?php if ($b['whatsapp']): ?><div><i class="fab fa-whatsapp" style="width: 14px; color: #25D366;"></i> <?= htmlspecialchars($b['whatsapp']) ?></div><?php endif; ?>
                                    </div>
                                </td>
                                <td style="font-size: 12px;"><?= htmlspecialchars(formatBranchHours($b)) ?></td>
                                <td>
                                    <span class="status-badge <?= $b['is_active'] ? 'active' : 'inactive' ?>">
                                        <?= $b['is_active'] ? 'Aktif' : 'Nonaktif' ?>
                                    </span>
                                </td>
                                <td>
                                    <div style="display: flex; gap: 4px;">
                                        <button class="btn btn-outline btn-sm" title="Edit"
                                                onclick="editBranch(<?= $b['id'] ?>, '<?= htmlspecialchars($b['name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($b['address'], ENT_QUOTES) ?>', '<?= htmlspecialchars($b['phone'] ?? '', ENT_QUOTES) ?>', '<?= htmlspecialchars($b['whatsapp'] ?? '', ENT_QUOTES) ?>', '<?= htmlspecialchars($b['email'] ?? '', ENT_QUOTES) ?>', '<?= htmlspecialchars($b['open_hours'] ?? '', ENT_QUOTES) ?>', '<?= htmlspecialchars($b['open_time'] ?? '', ENT_QUOTES) ?>', '<?= htmlspecialchars($b['close_time'] ?? '', ENT_QUOTES) ?>', <?= (int)($b['open_24h'] ?? 0) ?>, <?= $b['latitude'] ?? 0 ?>, <?= $b['longitude'] ?? 0 ?>, '<?= htmlspecialchars($b['maps_url'] ?? '', ENT_QUOTES) ?>', '<?= htmlspecialchars($b['image'] ?? '', ENT_QUOTES) ?>', <?= $b['sort_order'] ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <a href="branches.php?toggle=<?= $b['id'] ?>" class="btn btn-outline btn-sm" title="<?= $b['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?>">
                                            <i class="fas <?= $b['is_active'] ? 'fa-eye-slash' : 'fa-eye' ?>"></i>
                                        </a>
                                        <a href="branches.php?delete=<?= $b['id'] ?>" class="btn btn-outline btn-sm" title="Hapus"
                                           onclick="return confirm('Hapus cabang <?= htmlspecialchars($b['name']) ?>?')"
                                           style="color: #EF4444; border-color: #EF4444;">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; else: ?>
                            <tr><td colspan="7" style="text-align: center; padding: 40px; color: var(--text-muted);">Belum ada cabang</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Edit Modal -->
            <div class="modal-overlay" id="editModal">
                <div class="modal">
                    <div class="modal-header">
                        <h3 class="modal-title">Edit Cabang</h3>
                        <button class="modal-close" onclick="document.getElementById('editModal').classList.remove('active')">&times;</button>
                    </div>
                    <div class="modal-body">
                        <form method="POST" enctype="multipart/form-data">
                            <?= csrfField() ?>
                            <input type="hidden" name="save_branch" value="1">
                            <input type="hidden" name="id" id="edit-id">
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Nama Cabang</label>
                                    <input type="text" name="name" id="edit-name" class="form-input" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Urutan</label>
                                    <input type="number" name="sort_order" id="edit-sort" class="form-input" min="0">
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Alamat</label>
                                <textarea name="address" id="edit-address" class="form-textarea" required></textarea>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Telepon</label>
                                    <input type="text" name="phone" id="edit-phone" class="form-input">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">WhatsApp</label>
                                    <input type="text" name="whatsapp" id="edit-whatsapp" class="form-input">
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Email</label>
                                    <input type="email" name="email" id="edit-email" class="form-input">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Catatan Jam (opsional)</label>
                                    <input type="text" name="open_hours" id="edit-hours" class="form-input" placeholder="Contoh: Tutup setiap hari Senin / Buka 24 jam">
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Jam Buka</label>
                                    <input type="time" name="open_time" id="edit-open-time" class="form-input">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Jam Tutup</label>
                                    <input type="time" name="close_time" id="edit-close-time" class="form-input">
                                </div>
                                <div style="flex: 2; align-self: flex-end; padding-bottom: 6px;">
                                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 500;">
                                        <input type="checkbox" name="open_24h" value="1" id="edit-open-24h" style="accent-color: var(--soft-gold); width: 18px; height: 18px;"
                                               onchange="toggleOpen24h(this, ['edit-open-time','edit-close-time'])">
                                        Buka 24 Jam
                                    </label>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Latitude</label>
                                    <input type="number" name="latitude" id="edit-lat" class="form-input" step="any">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Longitude</label>
                                    <input type="number" name="longitude" id="edit-lng" class="form-input" step="any">
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Link Google Maps (opsional)</label>
                                <input type="url" name="maps_url" id="edit-maps" class="form-input" placeholder="https://maps.app.goo.gl/xxxxxxxx">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Foto Toko</label>
                                <input type="file" name="branch_image" id="edit-branch-image" class="form-input" accept="image/jpeg,image/png,image/webp,image/gif">
                                <small style="color: var(--text-muted); margin-top: 4px; display: block;">Kosongkan jika tidak ingin mengubah foto.</small>
                                <input type="url" name="image_url" id="edit-image-url" class="form-input" style="margin-top: 8px;" placeholder="Atau isi URL foto toko">
                                <img id="edit-image-preview" src="" alt="Preview Foto Toko"
                                     style="margin-top: 10px; max-width: 100%; max-height: 150px; object-fit: cover; border-radius: 8px; border: 2px solid var(--border-color); display: none;">
                            </div>
                            <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
                        </form>
                    </div>
                </div>
            </div>

            <script>
            function toggleOpen24h(cb, ids) {
                var disabled = cb.checked;
                for (var i = 0; i < ids.length; i++) {
                    var el = document.getElementById(ids[i]);
                    if (el) el.disabled = disabled;
                }
            }
            function editBranch(id, name, address, phone, wa, email, hours, openTime, closeTime, open24h, lat, lng, mapsUrl, image, sort) {
                document.getElementById('edit-id').value = id;
                document.getElementById('edit-name').value = name;
                document.getElementById('edit-address').value = address;
                document.getElementById('edit-phone').value = phone;
                document.getElementById('edit-whatsapp').value = wa;
                document.getElementById('edit-email').value = email;
                document.getElementById('edit-hours').value = hours;
                document.getElementById('edit-open-time').value = openTime || '';
                document.getElementById('edit-close-time').value = closeTime || '';
                document.getElementById('edit-open-24h').checked = open24h == 1;
                toggleOpen24h(document.getElementById('edit-open-24h'), ['edit-open-time', 'edit-close-time']);
                document.getElementById('edit-lat').value = lat;
                document.getElementById('edit-lng').value = lng;
                document.getElementById('edit-maps').value = mapsUrl || '';
                document.getElementById('edit-image-url').value = image || '';
                var preview = document.getElementById('edit-image-preview');
                if (image) {
                    preview.src = image;
                    preview.style.display = 'block';
                } else {
                    preview.style.display = 'none';
                }
                document.getElementById('edit-sort').value = sort;
                document.getElementById('editModal').classList.add('active');
            }
            </script>
        </main>
    </div>
</body>
</html>
