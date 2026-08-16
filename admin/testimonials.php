<?php
$currentPage = 'testimonials';
$pageTitle = 'Testimoni';
require_once __DIR__ . '/../config/database.php';

$conn = getConnection();
require_once __DIR__ . '/../config/rbac.php';
requirePermission('testimonials', 'view');

$errors = [];
$success = '';

// Handle actions BEFORE layout.php outputs HTML
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_testimonial'])) {
    requirePermission('testimonials', (int)($_POST['id'] ?? 0) > 0 ? 'edit' : 'create');
    $name = trim($_POST['customer_name'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $rating = (int)($_POST['rating'] ?? 5);
    $is_featured = isset($_POST['is_featured']) ? 1 : 0;
    $editId = (int)($_POST['id'] ?? 0);

    if (empty($name)) $errors[] = 'Nama pelanggan wajib diisi';
    if (empty($content)) $errors[] = 'Konten testimoni wajib diisi';
    if ($rating < 1 || $rating > 5) $errors[] = 'Rating harus 1-5';

    if (empty($errors)) {
        $name_e = $conn->real_escape_string($name);
        $content_e = $conn->real_escape_string($content);

        if ($editId > 0) {
            $sortOrder = (int)$_POST['sort_order'];
            $sql = "UPDATE testimonials SET customer_name='$name_e', content='$content_e', rating=$rating, is_featured=$is_featured, sort_order=$sortOrder WHERE id=$editId";
        } else {
            // Auto set sort_order to next available number
            $maxSort = $conn->query("SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_sort FROM testimonials")->fetch_assoc();
            $nextSort = $maxSort['next_sort'];
            $sql = "INSERT INTO testimonials (customer_name, content, rating, is_featured, sort_order) VALUES ('$name_e', '$content_e', $rating, $is_featured, $nextSort)";
        }
        if ($conn->query($sql)) {
            $success = 'Testimoni berhasil ' . ($editId > 0 ? 'diperbarui' : 'ditambahkan') . '!';
            logActivity($editId > 0 ? 'update' : 'create', 'testimonials', ($editId > 0 ? 'Mengubah' : 'Menambahkan') . " testimoni: $name");
        } else {
            $errors[] = 'Gagal: ' . $conn->error;
        }
    }
}

if (isset($_GET['delete'])) {
    requirePermission('testimonials', 'delete');
    $delId = (int)$_GET['delete'];
    $conn->query("DELETE FROM testimonials WHERE id = $delId");
    logActivity('delete', 'testimonials', "Menghapus testimoni #$delId");
    header('Location: testimonials.php');
    exit;
}

if (isset($_GET['toggle'])) {
    requirePermission('testimonials', 'edit');
    $togId = (int)$_GET['toggle'];
    $conn->query("UPDATE testimonials SET is_active = NOT is_active WHERE id = $togId");
    logActivity('edit', 'testimonials', "Toggle status testimoni #$togId");
    header('Location: testimonials.php');
    exit;
}

if (isset($_GET['feature'])) {
    requirePermission('testimonials', 'publish');
    $featId = (int)$_GET['feature'];
    $conn->query("UPDATE testimonials SET is_featured = NOT is_featured WHERE id = $featId");
    logActivity('publish', 'testimonials', "Toggle unggulan testimoni #$featId");
    header('Location: testimonials.php');
    exit;
}

// Handle move up / move down for sort order
if (isset($_GET['moveup'])) {
    $curId = (int)$_GET['moveup'];
    $cur = $conn->query("SELECT id, sort_order FROM testimonials WHERE id = $curId")->fetch_assoc();
    $prev = $conn->query("SELECT id, sort_order FROM testimonials WHERE sort_order < {$cur['sort_order']} ORDER BY sort_order DESC LIMIT 1")->fetch_assoc();
    if ($cur && $prev) {
        $conn->query("UPDATE testimonials SET sort_order = {$prev['sort_order']} WHERE id = {$cur['id']}");
        $conn->query("UPDATE testimonials SET sort_order = {$cur['sort_order']} WHERE id = {$prev['id']}");
    }
    header('Location: testimonials.php');
    exit;
}

if (isset($_GET['movedown'])) {
    $curId = (int)$_GET['movedown'];
    $cur = $conn->query("SELECT id, sort_order FROM testimonials WHERE id = $curId")->fetch_assoc();
    $next = $conn->query("SELECT id, sort_order FROM testimonials WHERE sort_order > {$cur['sort_order']} ORDER BY sort_order ASC LIMIT 1")->fetch_assoc();
    if ($cur && $next) {
        $conn->query("UPDATE testimonials SET sort_order = {$next['sort_order']} WHERE id = {$cur['id']}");
        $conn->query("UPDATE testimonials SET sort_order = {$cur['sort_order']} WHERE id = {$next['id']}");
    }
    header('Location: testimonials.php');
    exit;
}

// Rating filter
$ratingFilter = (int)($_GET['rating'] ?? 0);
$ratingWhere = '';
if ($ratingFilter >= 1 && $ratingFilter <= 5) {
    $ratingWhere = "WHERE rating = $ratingFilter";
}

$testimonials = $conn->query("SELECT * FROM testimonials $ratingWhere ORDER BY sort_order ASC, is_featured DESC, created_at DESC");

require_once __DIR__ . '/layout.php';
?>

            <?php if ($success): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $success ?></div>
            <?php endif; ?>
            <?php foreach ($errors as $err): ?>
                <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($err) ?></div>
            <?php endforeach; ?>

            <div class="admin-card">
                <h3 class="admin-card-title">Tambah Testimoni Baru</h3>
                <form method="POST">
                    <input type="hidden" name="save_testimonial" value="1">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Nama Pelanggan <span style="color: #EF4444;">*</span></label>
                            <input type="text" name="customer_name" class="form-input" placeholder="Contoh: Siti Rahmawati" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Rating <span style="color: #EF4444;">*</span></label>
                            <select name="rating" class="form-select">
                                <option value="5">★★★★★ (5)</option>
                                <option value="4">★★★★☆ (4)</option>
                                <option value="3">★★★☆☆ (3)</option>
                                <option value="2">★★☆☆☆ (2)</option>
                                <option value="1">★☆☆☆☆ (1)</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Isi Testimoni <span style="color: #EF4444;">*</span></label>
                        <textarea name="content" class="form-textarea" placeholder="Tulis testimoni pelanggan..." required></textarea>
                    </div>
                    <div class="form-group">
                        <label style="display: flex; align-items: center; gap: var(--space-sm); cursor: pointer;">
                            <input type="checkbox" name="is_featured" value="1" style="width: 18px; height: 18px; accent-color: var(--soft-gold);">
                            <span>Tampilkan sebagai unggulan (featured)</span>
                        </label>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Tambah Testimoni
                    </button>
                </form>
            </div>

            <div class="admin-card">
                <!-- Rating Filter -->
                <div style="margin-bottom: var(--space-lg); display: flex; align-items: center; gap: var(--space-sm); flex-wrap: wrap;">
                    <span style="font-size: 13px; font-weight: 600; color: var(--text-muted); margin-right: 4px;">Filter Rating:</span>
                    <a href="testimonials.php" class="btn btn-outline btn-sm <?= !$ratingFilter ? 'btn-primary' : 'btn-outline' ?>" style="<?= !$ratingFilter ? 'background: var(--luxury-gradient); color: #fff; border: none;' : '' ?>">
                        Semua
                    </a>
                    <?php for ($r = 5; $r >= 1; $r--): ?>
                    <a href="testimonials.php?rating=<?= $r ?>" 
                       class="btn <?= $ratingFilter === $r ? 'btn-primary' : 'btn-outline' ?> btn-sm"
                       style="font-size: 13px; <?= $ratingFilter === $r ? 'background: var(--luxury-gradient); color: #fff; border: none;' : '' ?>">
                        <?= str_repeat('★', $r) ?>
                    </a>
                    <?php endfor; ?>
                    <?php if ($ratingFilter): ?>
                    <span style="font-size: 12px; color: var(--text-muted); margin-left: 4px;">
                        <i class="fas fa-filter"></i> Difilter: <?= $ratingFilter ?> ★
                    </span>
                    <?php endif; ?>
                </div>
                <div style="overflow-x: auto;">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th style="width: 40px;">#</th>
                                <th>Pelanggan</th>
                                <th>Testimoni</th>
                                <th>Rating</th>
                                <th>Unggulan</th>
                                <th>Status</th>
                                <th>Tanggal</th>
                                <th style="width: 200px;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($testimonials && $testimonials->num_rows > 0):
                                $testData = [];
                                while ($tr = $testimonials->fetch_assoc()) { $testData[] = $tr; }
                                $totalTest = count($testData);
                                foreach ($testData as $idx => $t):
                                $stars = str_repeat('★', (int)$t['rating']) . str_repeat('☆', 5 - (int)$t['rating']);
                            ?>
                            <tr>
                                <td style="text-align: center; font-size: 13px; color: var(--text-muted);">
                                    <?= $t['sort_order'] ?: $idx + 1 ?>
                                </td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: var(--space-sm);">
                                        <div style="width: 32px; height: 32px; border-radius: 50%; background: var(--soft-gold-gradient); display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 600; color: var(--soft-gold);">
                                            <?= strtoupper(substr($t['customer_name'], 0, 1)) ?>
                                        </div>
                                        <strong><?= htmlspecialchars($t['customer_name']) ?></strong>
                                    </div>
                                </td>
                                <td style="max-width: 250px;">
                                    <span style="color: var(--text-muted); font-size: 13px; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">
                                        "<?= htmlspecialchars($t['content']) ?>"
                                    </span>
                                </td>
                                <td style="color: var(--soft-gold); font-size: 14px;"><?= $stars ?></td>
                                <td>
                                    <span class="status-badge <?= $t['is_featured'] ? 'active' : 'inactive' ?>">
                                        <?= $t['is_featured'] ? 'Unggulan' : 'Biasa' ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="status-badge <?= $t['is_active'] ? 'active' : 'inactive' ?>">
                                        <?= $t['is_active'] ? 'Aktif' : 'Nonaktif' ?>
                                    </span>
                                </td>
                                <td style="font-size: 12px;"><?= formatDate($t['created_at'], 'd M Y') ?></td>
                                <td>
                                    <div style="display: flex; gap: 2px; align-items: center;">
                                        <!-- Sort order arrows -->
                                        <div style="display: flex; flex-direction: column; gap: 1px; margin-right: 4px;">
                                            <a href="testimonials.php?moveup=<?= $t['id'] ?>"
                                               class="btn btn-outline btn-sm sort-arrow <?= $idx === 0 ? 'sort-disabled' : '' ?>"
                                               title="Naikkan peringkat"
                                               <?= $idx === 0 ? 'onclick="return false;"' : '' ?>>
                                                <i class="fas fa-chevron-up"></i>
                                            </a>
                                            <a href="testimonials.php?movedown=<?= $t['id'] ?>"
                                               class="btn btn-outline btn-sm sort-arrow <?= $idx === $totalTest - 1 ? 'sort-disabled' : '' ?>"
                                               title="Turunkan peringkat"
                                               <?= $idx === $totalTest - 1 ? 'onclick="return false;"' : '' ?>>
                                                <i class="fas fa-chevron-down"></i>
                                            </a>
                                        </div>
                                        <button class="btn btn-outline btn-sm" title="Edit"
                                                onclick="editTestimonial(<?= $t['id'] ?>, '<?= htmlspecialchars($t['customer_name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($t['content'], ENT_QUOTES) ?>', <?= $t['rating'] ?>, <?= $t['is_featured'] ? 1 : 0 ?>, <?= $t['sort_order'] ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <a href="testimonials.php?feature=<?= $t['id'] ?>" class="btn btn-outline btn-sm" title="<?= $t['is_featured'] ? 'Hapus Unggulan' : 'Jadikan Unggulan' ?>">
                                            <i class="fas <?= $t['is_featured'] ? 'fa-star' : 'fa-star-o' ?>"></i>
                                        </a>
                                        <a href="testimonials.php?toggle=<?= $t['id'] ?>" class="btn btn-outline btn-sm" title="<?= $t['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?>">
                                            <i class="fas <?= $t['is_active'] ? 'fa-eye-slash' : 'fa-eye' ?>"></i>
                                        </a>
                                        <a href="testimonials.php?delete=<?= $t['id'] ?>" class="btn btn-outline btn-sm" title="Hapus"
                                           onclick="return confirm('Hapus testimoni ini?')" style="color: #EF4444; border-color: #EF4444;">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; else: ?>
                            <tr><td colspan="8" style="text-align: center; padding: 40px; color: var(--text-muted);">Belum ada testimoni</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Edit Modal -->
            <div class="modal-overlay" id="editModal">
                <div class="modal">
                    <div class="modal-header">
                        <h3 class="modal-title">Edit Testimoni</h3>
                        <button class="modal-close" onclick="document.getElementById('editModal').classList.remove('active')">&times;</button>
                    </div>                            <div class="modal-body">
                        <form method="POST">
                            <input type="hidden" name="save_testimonial" value="1">
                            <input type="hidden" name="id" id="edit-id">
                            <input type="hidden" name="sort_order" id="edit-sort">
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Nama Pelanggan</label>
                                    <input type="text" name="customer_name" id="edit-name" class="form-input" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Rating</label>
                                    <select name="rating" id="edit-rating" class="form-select">
                                        <option value="5">★★★★★ (5)</option>
                                        <option value="4">★★★★☆ (4)</option>
                                        <option value="3">★★★☆☆ (3)</option>
                                        <option value="2">★★☆☆☆ (2)</option>
                                        <option value="1">★☆☆☆☆ (1)</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Isi Testimoni</label>
                                <textarea name="content" id="edit-content" class="form-textarea" required></textarea>
                            </div>
                            <div class="form-group">
                                <label style="display: flex; align-items: center; gap: var(--space-sm); cursor: pointer;">
                                    <input type="checkbox" name="is_featured" value="1" id="edit-featured" style="width: 18px; height: 18px; accent-color: var(--soft-gold);">
                                    <span>Tampilkan sebagai unggulan</span>
                                </label>
                            </div>
                            <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
                        </form>
                    </div>
                </div>
            </div>

            <script>
            function editTestimonial(id, name, content, rating, featured, sortOrder) {
                document.getElementById('edit-id').value = id;
                document.getElementById('edit-name').value = name;
                document.getElementById('edit-content').value = content;
                document.getElementById('edit-rating').value = rating;
                document.getElementById('edit-featured').checked = featured === 1;
                document.getElementById('edit-sort').value = sortOrder || 0;
                document.getElementById('editModal').classList.add('active');
            }
            </script>
        </main>
    </div>
</body>
</html>
