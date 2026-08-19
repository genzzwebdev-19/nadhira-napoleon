<?php
$currentPage = 'promo';
$pageTitle = 'Promo';
require_once __DIR__ . '/../config/database.php';

$conn = getConnection();
require_once __DIR__ . '/../config/rbac.php';
requirePermission('promo', 'view');

$errors = [];
$success = '';

// Handle actions BEFORE layout.php outputs HTML
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_promo'])) {
    verifyCsrf();
    requirePermission('promo', (int)($_POST['id'] ?? 0) > 0 ? 'edit' : 'create');
    $title = trim($_POST['title'] ?? '');
    $code = strtoupper(trim($_POST['code'] ?? '')); // kode promo yang diketik customer (case-insensitive)
    $description = trim($_POST['description'] ?? '');
    $discount_type = $_POST['discount_type'] ?? 'percentage';
    $discount_value = (float)($_POST['discount_value'] ?? 0);
    $min_purchase = (float)($_POST['min_purchase'] ?? 0);
    // Batas pemakaian: kosong/0 = tanpa batas (NULL), angka = jumlah maksimal pesanan
    $maxUsesRaw = trim($_POST['max_uses'] ?? '');
    $maxUses = ($maxUsesRaw !== '' && is_numeric($maxUsesRaw) && (int)$maxUsesRaw > 0) ? (int)$maxUsesRaw : null;
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';
    $editId = (int)($_POST['id'] ?? 0);

    // Saat edit, kode boleh dikosongkan = pertahankan kode lama
    if ($editId > 0 && $code === '') {
        $oldPromo = $conn->query("SELECT code FROM promotions WHERE id = $editId LIMIT 1");
        $code = $oldPromo && $oldPromo->num_rows > 0 ? strtoupper(trim((string)$oldPromo->fetch_assoc()['code'])) : '';
    }

    if (empty($title)) $errors[] = 'Judul promo wajib diisi';
    if (empty($code)) $errors[] = 'Kode promo wajib diisi (dipakai customer saat checkout)';
    if ($discount_value <= 0) $errors[] = 'Nilai diskon harus lebih dari 0';
    if (empty($start_date)) $errors[] = 'Tanggal mulai wajib diisi';
    if (empty($end_date)) $errors[] = 'Tanggal berakhir wajib diisi';

    if (empty($errors)) {
        $slug = generateSlug($title);
        $title_e = $conn->real_escape_string($title);
        $code_e = $conn->real_escape_string($code);
        $desc_e = $conn->real_escape_string($description);
        $type_e = $conn->real_escape_string($discount_type);

        $slugCheck = $conn->query("SELECT id FROM promotions WHERE slug = '$slug' " . ($editId > 0 ? "AND id != $editId" : "") . " LIMIT 1");
        if ($slugCheck && $slugCheck->num_rows > 0) $slug .= '-' . time();

        // Kode promo harus unik (case-insensitive)
        $codeCheck = $conn->query("SELECT id FROM promotions WHERE UPPER(code) = '$code_e' " . ($editId > 0 ? "AND id != $editId" : "") . " LIMIT 1");
        if ($codeCheck && $codeCheck->num_rows > 0) {
            $errors[] = "Kode promo '$code' sudah dipakai promo lain";
        }
    }

    if (empty($errors)) {
        $maxUsesSql = $maxUses !== null ? (string)$maxUses : 'NULL';
        if ($editId > 0) {
            $sql = "UPDATE promotions SET title='$title_e', code='$code_e', slug='$slug', description='$desc_e', discount_type='$type_e', discount_value=$discount_value, min_purchase=$min_purchase, max_uses=$maxUsesSql, start_date='$start_date', end_date='$end_date' WHERE id=$editId";
        } else {
            $sql = "INSERT INTO promotions (title, code, slug, description, discount_type, discount_value, min_purchase, max_uses, start_date, end_date) VALUES ('$title_e', '$code_e', '$slug', '$desc_e', '$type_e', $discount_value, $min_purchase, $maxUsesSql, '$start_date', '$end_date')";
        }

        if ($conn->query($sql)) {
            $success = 'Promo berhasil ' . ($editId > 0 ? 'diperbarui' : 'ditambahkan') . '!';
            logActivity($editId > 0 ? 'update' : 'create', 'promo', ($editId > 0 ? 'Mengubah' : 'Menambahkan') . " promo: $title");
        } else {
            $errors[] = 'Gagal: ' . $conn->error;
        }
    }
}

if (isset($_GET['delete'])) {
    requirePermission('promo', 'delete');
    $delId = (int)$_GET['delete'];
    $conn->query("DELETE FROM promotions WHERE id = $delId");
    logActivity('delete', 'promo', "Menghapus promo #$delId");
    header('Location: promo.php');
    exit;
}

if (isset($_GET['toggle'])) {
    requirePermission('promo', 'edit');
    $togId = (int)$_GET['toggle'];
    $conn->query("UPDATE promotions SET is_active = NOT is_active WHERE id = $togId");
    logActivity('edit', 'promo', "Toggle status promo #$togId");
    header('Location: promo.php');
    exit;
}

$promos = $conn->query("SELECT * FROM promotions ORDER BY start_date DESC");

require_once __DIR__ . '/layout.php';
?>

            <?php if ($success): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $success ?></div>
            <?php endif; ?>
            <?php foreach ($errors as $err): ?>
                <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($err) ?></div>
            <?php endforeach; ?>

            <div class="admin-card">
                <h3 class="admin-card-title">Tambah Promo Baru</h3>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="save_promo" value="1">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Judul Promo <span style="color: #EF4444;">*</span></label>
                            <input type="text" name="title" class="form-input" placeholder="Contoh: Diskon 20%" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Kode Promo <span style="color: #EF4444;">*</span></label>
                            <input type="text" name="code" class="form-input" placeholder="Contoh: NAPOLEON20" required
                                   style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase()">
                            <small style="color: var(--text-muted);">Kode yang diketik customer saat checkout (huruf besar otomatis)</small>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Tipe Diskon <span style="color: #EF4444;">*</span></label>
                            <select name="discount_type" class="form-select">
                                <option value="percentage">Persentase (%)</option>
                                <option value="nominal">Nominal (Rp)</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Nilai Diskon <span style="color: #EF4444;">*</span></label>
                            <input type="number" name="discount_value" class="form-input" min="0" placeholder="Contoh: 20" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Min. Pembelian (Rp)</label>
                            <input type="number" name="min_purchase" class="form-input" min="0" value="0" placeholder="0 = tanpa minimal">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Batas Pemakaian</label>
                            <input type="number" name="max_uses" class="form-input" min="0" placeholder="Kosongkan = tanpa batas">
                            <small style="color: var(--text-muted);">Maksimal berapa kali kode ini bisa dipakai (jumlah pesanan)</small>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Tanggal Mulai <span style="color: #EF4444;">*</span></label>
                            <input type="datetime-local" name="start_date" class="form-input" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Tanggal Berakhir <span style="color: #EF4444;">*</span></label>
                            <input type="datetime-local" name="end_date" class="form-input" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Deskripsi</label>
                        <textarea name="description" class="form-textarea" placeholder="Detail promo..."></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Tambah Promo
                    </button>
                </form>
            </div>

            <div class="admin-card">
                <div style="overflow-x: auto;">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Judul</th>
                                <th>Kode</th>
                                <th>Tipe</th>
                                <th>Nilai</th>
                                <th>Min. Belanja</th>
                                <th>Pemakaian</th>
                                <th>Periode</th>
                                <th>Status</th>
                                <th style="width: 130px;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($promos && $promos->num_rows > 0):
                                while ($p = $promos->fetch_assoc()):
                                $isActive = $p['is_active'];
                                $isExpired = strtotime($p['end_date']) < time();
                            ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($p['title']) ?></strong></td>
                                <td>
                                    <?php if (!empty($p['code'])): ?>
                                    <span style="font-family: monospace; font-weight: 700; background: var(--soft-gold-gradient); padding: 3px 10px; border-radius: 6px; font-size: 12px;"><?= htmlspecialchars($p['code']) ?></span>
                                    <?php else: ?>
                                    <span style="color: var(--text-muted);">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= $p['discount_type'] === 'percentage' ? 'Persentase' : 'Nominal' ?></td>
                                <td>
                                    <?= $p['discount_type'] === 'percentage' ? $p['discount_value'] . '%' : 'Rp ' . number_format($p['discount_value'], 0, ',', '.') ?>
                                </td>
                                <td><?= $p['min_purchase'] > 0 ? 'Rp ' . number_format($p['min_purchase'], 0, ',', '.') : '-' ?></td>
                                <td>
                                    <?php $pMax = ($p['max_uses'] !== null && (int)$p['max_uses'] > 0) ? (int)$p['max_uses'] : null; ?>
                                    <?php $pUsed = (int)($p['used_count'] ?? 0); ?>
                                    <?php if ($pMax !== null && $pUsed >= $pMax): ?>
                                    <span class="status-badge inactive"><?= $pUsed ?>/<?= $pMax ?> · Habis</span>
                                    <?php else: ?>
                                    <span style="font-size: 12px;"><?= $pUsed ?> / <?= $pMax !== null ? $pMax : '∞' ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size: 12px;">
                                    <?= date('d/m/Y', strtotime($p['start_date'])) ?> -<br>
                                    <?= date('d/m/Y', strtotime($p['end_date'])) ?>
                                </td>
                                <td>
                                    <span class="status-badge <?= $isExpired ? 'inactive' : ($isActive ? 'active' : 'inactive') ?>">
                                        <?= $isExpired ? 'Kadaluarsa' : ($isActive ? 'Aktif' : 'Nonaktif') ?>
                                    </span>
                                </td>
                                <td>
                                    <div style="display: flex; gap: 4px;">
                                        <button class="btn btn-outline btn-sm" title="Edit"
                                                onclick="editPromo(<?= $p['id'] ?>, '<?= htmlspecialchars($p['title'], ENT_QUOTES) ?>', '<?= htmlspecialchars($p['code'] ?? '', ENT_QUOTES) ?>', '<?= htmlspecialchars($p['description'] ?? '', ENT_QUOTES) ?>', '<?= $p['discount_type'] ?>', <?= $p['discount_value'] ?>, <?= $p['min_purchase'] ?>, <?= $pMax !== null ? $pMax : 0 ?>, '<?= date('Y-m-d\TH:i', strtotime($p['start_date'])) ?>', '<?= date('Y-m-d\TH:i', strtotime($p['end_date'])) ?>')">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <a href="promo.php?toggle=<?= $p['id'] ?>" class="btn btn-outline btn-sm" title="<?= $isActive ? 'Nonaktifkan' : 'Aktifkan' ?>">
                                            <i class="fas <?= $isActive ? 'fa-pause' : 'fa-play' ?>"></i>
                                        </a>
                                        <a href="promo.php?delete=<?= $p['id'] ?>" class="btn btn-outline btn-sm" title="Hapus"
                                           onclick="return confirm('Hapus promo <?= htmlspecialchars($p['title']) ?>?')"
                                           style="color: #EF4444; border-color: #EF4444;">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; else: ?>
                            <tr>                                <td colspan="9" style="text-align: center; padding: 40px; color: var(--text-muted);">Belum ada promo</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Edit Modal -->
            <div class="modal-overlay" id="editModal">
                <div class="modal">
                    <div class="modal-header">
                        <h3 class="modal-title">Edit Promo</h3>
                        <button class="modal-close" onclick="document.getElementById('editModal').classList.remove('active')">&times;</button>
                    </div>
                    <div class="modal-body">
                        <form method="POST">
                            <?= csrfField() ?>
                            <input type="hidden" name="save_promo" value="1">
                            <input type="hidden" name="id" id="edit-id">
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Judul Promo</label>
                                    <input type="text" name="title" id="edit-title" class="form-input" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Kode Promo</label>
                                    <input type="text" name="code" id="edit-code" class="form-input"
                                           style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase()"
                                           placeholder="Kosongkan untuk mempertahankan kode lama">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Tipe Diskon</label>
                                    <select name="discount_type" id="edit-type" class="form-select">
                                        <option value="percentage">Persentase (%)</option>
                                        <option value="nominal">Nominal (Rp)</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Nilai Diskon</label>
                                    <input type="number" name="discount_value" id="edit-value" class="form-input" min="0" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Min. Pembelian (Rp)</label>
                                    <input type="number" name="min_purchase" id="edit-min" class="form-input" min="0" value="0">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Batas Pemakaian</label>
                                    <input type="number" name="max_uses" id="edit-max" class="form-input" min="0" placeholder="Kosongkan = tanpa batas">
                                    <small style="color: var(--text-muted);">Kosongkan/0 = tanpa batas</small>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Tanggal Mulai</label>
                                    <input type="datetime-local" name="start_date" id="edit-start" class="form-input" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Tanggal Berakhir</label>
                                    <input type="datetime-local" name="end_date" id="edit-end" class="form-input" required>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Deskripsi</label>
                                <textarea name="description" id="edit-desc" class="form-textarea"></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
                        </form>
                    </div>
                </div>
            </div>

            <script>
            function editPromo(id, title, code, desc, type, value, min, maxUses, start, end) {
                document.getElementById('edit-id').value = id;
                document.getElementById('edit-title').value = title;
                document.getElementById('edit-code').value = code || '';
                document.getElementById('edit-desc').value = desc;
                document.getElementById('edit-type').value = type;
                document.getElementById('edit-value').value = value;
                document.getElementById('edit-min').value = min;
                document.getElementById('edit-max').value = maxUses || '';
                document.getElementById('edit-start').value = start;
                document.getElementById('edit-end').value = end;
                document.getElementById('editModal').classList.add('active');
            }
            </script>
        </main>
    </div>
</body>
</html>
