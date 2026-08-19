<?php
$currentPage = 'faq';
$pageTitle = 'FAQ';
require_once __DIR__ . '/../config/database.php';

$conn = getConnection();
require_once __DIR__ . '/../config/rbac.php';
requirePermission('faq', 'view');

$errors = [];
$success = '';

// Handle actions BEFORE layout.php outputs HTML
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_faq'])) {
    verifyCsrf();
    requirePermission('faq', (int)($_POST['id'] ?? 0) > 0 ? 'edit' : 'create');
    $question = trim($_POST['question'] ?? '');
    $answer = trim($_POST['answer'] ?? '');
    $category = trim($_POST['category'] ?? 'general');
    $sort_order = (int)($_POST['sort_order'] ?? 0);
    $editId = (int)($_POST['id'] ?? 0);

    if (empty($question)) $errors[] = 'Pertanyaan wajib diisi';
    if (empty($answer)) $errors[] = 'Jawaban wajib diisi';

    if (empty($errors)) {
        $q_e = $conn->real_escape_string($question);
        $a_e = $conn->real_escape_string($answer);
        $c_e = $conn->real_escape_string($category);

        if ($editId > 0) {
            $sql = "UPDATE faq SET question='$q_e', answer='$a_e', category='$c_e', sort_order=$sort_order WHERE id=$editId";
        } else {
            $sql = "INSERT INTO faq (question, answer, category, sort_order) VALUES ('$q_e', '$a_e', '$c_e', $sort_order)";
        }
        if ($conn->query($sql)) {
            $success = 'FAQ berhasil ' . ($editId > 0 ? 'diperbarui' : 'ditambahkan') . '!';
            logActivity($editId > 0 ? 'update' : 'create', 'faq', ($editId > 0 ? 'Mengubah' : 'Menambahkan') . ' FAQ');
        } else {
            $errors[] = 'Gagal: ' . $conn->error;
        }
    }
}

if (isset($_GET['delete'])) {
    requirePermission('faq', 'delete');
    $delId = (int)$_GET['delete'];
    $conn->query("DELETE FROM faq WHERE id = $delId");
    logActivity('delete', 'faq', "Menghapus FAQ #$delId");
    header('Location: faq.php');
    exit;
}

if (isset($_GET['toggle'])) {
    requirePermission('faq', 'edit');
    $togId = (int)$_GET['toggle'];
    $conn->query("UPDATE faq SET is_active = NOT is_active WHERE id = $togId");
    logActivity('edit', 'faq', "Toggle status FAQ #$togId");
    header('Location: faq.php');
    exit;
}

// Get unique categories
$catResult = $conn->query("SELECT DISTINCT category FROM faq ORDER BY category ASC");
$categories = [];
if ($catResult) {
    while ($c = $catResult->fetch_assoc()) {
        $categories[] = $c['category'];
    }
}

$faqs = $conn->query("SELECT * FROM faq ORDER BY category, sort_order ASC, created_at DESC");
$categoryCounts = [];
if ($faqs) {
    while ($f = $faqs->fetch_assoc()) {
        $cat = $f['category'] ?: 'general';
        if (!isset($categoryCounts[$cat])) $categoryCounts[$cat] = 0;
        $categoryCounts[$cat]++;
    }
    $faqs->data_seek(0);
}

require_once __DIR__ . '/layout.php';
?>

            <?php if ($success): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $success ?></div>
            <?php endif; ?>
            <?php foreach ($errors as $err): ?>
                <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($err) ?></div>
            <?php endforeach; ?>

            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-card-icon info"><i class="fas fa-question-circle"></i></div>
                        <div><div class="stat-card-value"><?= $faqs ? $faqs->num_rows : 0 ?></div></div>
                    </div>
                    <div class="stat-card-label">Total FAQ</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-card-icon info"><i class="fas fa-tags"></i></div>
                        <div><div class="stat-card-value"><?= count($categories) ?></div></div>
                    </div>
                    <div class="stat-card-label">Kategori</div>
                </div>
            </div>

            <div class="admin-card">
                <h3 class="admin-card-title">Tambah FAQ Baru</h3>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="save_faq" value="1">
                    <div class="form-row">
                        <div class="form-group" style="flex: 3;">
                            <label class="form-label">Pertanyaan <span style="color: #EF4444;">*</span></label>
                            <input type="text" name="question" class="form-input" placeholder="Masukkan pertanyaan" required>
                        </div>
                        <div class="form-group" style="flex: 1;">
                            <label class="form-label">Kategori</label>
                            <select name="category" class="form-select">
                                <option value="general">General</option>
                                <option value="produk">Produk</option>
                                <option value="pengiriman">Pengiriman</option>
                                <option value="pembayaran">Pembayaran</option>
                                <option value="layanan">Layanan</option>
                                <option value="cabang">Cabang</option>
                            </select>
                        </div>
                        <div class="form-group" style="flex: 0.5;">
                            <label class="form-label">Urutan</label>
                            <input type="number" name="sort_order" class="form-input" value="0" min="0">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Jawaban <span style="color: #EF4444;">*</span></label>
                        <textarea name="answer" class="form-textarea" placeholder="Tulis jawaban..." required></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Tambah FAQ
                    </button>
                </form>
            </div>

            <div class="admin-card">
                <div style="overflow-x: auto;">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Pertanyaan</th>
                                <th>Kategori</th>
                                <th>Urutan</th>
                                <th>Status</th>
                                <th style="width: 120px;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($faqs && $faqs->num_rows > 0):
                                while ($f = $faqs->fetch_assoc()):
                            ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($f['question']) ?></strong>
                                    <br><small style="color: var(--text-muted); font-size: 12px; display: -webkit-box; -webkit-line-clamp: 1; -webkit-box-orient: vertical; overflow: hidden;">
                                        <?= htmlspecialchars(strip_tags($f['answer'])) ?>
                                    </small>
                                </td>
                                <td>
                                    <span class="status-badge" style="background: var(--soft-gold-gradient); color: var(--soft-gold); text-transform: capitalize;">
                                        <?= htmlspecialchars($f['category'] ?: 'general') ?>
                                    </span>
                                </td>
                                <td style="text-align: center;"><?= $f['sort_order'] ?></td>
                                <td>
                                    <span class="status-badge <?= $f['is_active'] ? 'active' : 'inactive' ?>">
                                        <?= $f['is_active'] ? 'Aktif' : 'Nonaktif' ?>
                                    </span>
                                </td>
                                <td>
                                    <div style="display: flex; gap: 4px;">
                                        <button class="btn btn-outline btn-sm" title="Edit"
                                                onclick="editFaq(<?= $f['id'] ?>, '<?= htmlspecialchars($f['question'], ENT_QUOTES) ?>', '<?= htmlspecialchars($f['answer'], ENT_QUOTES) ?>', '<?= htmlspecialchars($f['category'] ?: 'general', ENT_QUOTES) ?>', <?= $f['sort_order'] ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <a href="faq.php?toggle=<?= $f['id'] ?>" class="btn btn-outline btn-sm" title="<?= $f['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?>">
                                            <i class="fas <?= $f['is_active'] ? 'fa-eye-slash' : 'fa-eye' ?>"></i>
                                        </a>
                                        <a href="faq.php?delete=<?= $f['id'] ?>" class="btn btn-outline btn-sm" title="Hapus"
                                           onclick="return confirm('Hapus FAQ ini?')" style="color: #EF4444; border-color: #EF4444;">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; else: ?>
                            <tr><td colspan="5" style="text-align: center; padding: 40px; color: var(--text-muted);">Belum ada FAQ</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Edit Modal -->
            <div class="modal-overlay" id="editModal">
                <div class="modal">
                    <div class="modal-header">
                        <h3 class="modal-title">Edit FAQ</h3>
                        <button class="modal-close" onclick="document.getElementById('editModal').classList.remove('active')">&times;</button>
                    </div>
                    <div class="modal-body">
                        <form method="POST">
                            <?= csrfField() ?>
                            <input type="hidden" name="save_faq" value="1">
                            <input type="hidden" name="id" id="edit-id">
                            <div class="form-row">
                                <div class="form-group" style="flex: 3;">
                                    <label class="form-label">Pertanyaan</label>
                                    <input type="text" name="question" id="edit-question" class="form-input" required>
                                </div>
                                <div class="form-group" style="flex: 1;">
                                    <label class="form-label">Kategori</label>
                                    <select name="category" id="edit-category" class="form-select">
                                        <option value="general">General</option>
                                        <option value="produk">Produk</option>
                                        <option value="pengiriman">Pengiriman</option>
                                        <option value="pembayaran">Pembayaran</option>
                                        <option value="layanan">Layanan</option>
                                        <option value="cabang">Cabang</option>
                                    </select>
                                </div>
                                <div class="form-group" style="flex: 0.5;">
                                    <label class="form-label">Urutan</label>
                                    <input type="number" name="sort_order" id="edit-sort" class="form-input" min="0">
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Jawaban</label>
                                <textarea name="answer" id="edit-answer" class="form-textarea" required></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
                        </form>
                    </div>
                </div>
            </div>

            <script>
            function editFaq(id, question, answer, category, sort) {
                document.getElementById('edit-id').value = id;
                document.getElementById('edit-question').value = question;
                document.getElementById('edit-answer').value = answer;
                document.getElementById('edit-category').value = category;
                document.getElementById('edit-sort').value = sort;
                document.getElementById('editModal').classList.add('active');
            }
            </script>
        </main>
    </div>
</body>
</html>
