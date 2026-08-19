<?php
$currentPage = 'articles';
$pageTitle = 'Artikel';
require_once __DIR__ . '/../config/database.php';

$conn = getConnection();
require_once __DIR__ . '/../config/rbac.php';
requirePermission('articles', 'view');

$errors = [];
$success = '';

// Handle actions BEFORE layout.php outputs HTML
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_article'])) {
    verifyCsrf();
    requirePermission('articles', (int)($_POST['id'] ?? 0) > 0 ? 'edit' : 'create');
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $excerpt = trim($_POST['excerpt'] ?? '');
    $author = trim($_POST['author'] ?? 'Nadhira Napoleon');
    $is_published = isset($_POST['is_published']) ? 1 : 0;
    $editId = (int)($_POST['id'] ?? 0);

    if (empty($title)) $errors[] = 'Judul artikel wajib diisi';
    if (empty($content)) $errors[] = 'Konten artikel wajib diisi';

    if (empty($errors)) {
        $slug = generateSlug($title);
        $title_e = $conn->real_escape_string($title);
        $content_e = $conn->real_escape_string($content);
        $excerpt_e = $conn->real_escape_string($excerpt ?: substr(strip_tags($content), 0, 150));
        $author_e = $conn->real_escape_string($author);

        $slugCheck = $conn->query("SELECT id FROM articles WHERE slug = '$slug' " . ($editId > 0 ? "AND id != $editId" : "") . " LIMIT 1");
        if ($slugCheck && $slugCheck->num_rows > 0) $slug .= '-' . time();

        if ($editId > 0) {
            $pubSql = $is_published ? ", published_at = NOW()" : "";
            $sql = "UPDATE articles SET title='$title_e', slug='$slug', content='$content_e', excerpt='$excerpt_e', author='$author_e', is_published=$is_published $pubSql WHERE id=$editId";
        } else {
            $pubVal = $is_published ? "NOW()" : "NULL";
            $sql = "INSERT INTO articles (title, slug, content, excerpt, author, is_published, published_at) VALUES ('$title_e', '$slug', '$content_e', '$excerpt_e', '$author_e', $is_published, $pubVal)";
        }

        if ($conn->query($sql)) {
            $success = 'Artikel berhasil ' . ($editId > 0 ? 'diperbarui' : 'ditambahkan') . '!';
            logActivity($editId > 0 ? 'update' : 'create', 'articles', ($editId > 0 ? 'Mengubah' : 'Menambahkan') . " artikel: $title");
        } else {
            $errors[] = 'Gagal: ' . $conn->error;
        }
    }
}

if (isset($_GET['delete'])) {
    requirePermission('articles', 'delete');
    $delId = (int)$_GET['delete'];
    $conn->query("DELETE FROM articles WHERE id = $delId");
    logActivity('delete', 'articles', "Menghapus artikel #$delId");
    header('Location: articles.php');
    exit;
}

if (isset($_GET['publish'])) {
    requirePermission('articles', 'publish');
    $pubId = (int)$_GET['publish'];
    $r = $conn->query("SELECT is_published FROM articles WHERE id = $pubId LIMIT 1");
    if ($r && $r->num_rows > 0) {
        $cur = (int)$r->fetch_assoc()['is_published'];
        $newStatus = $cur ? 0 : 1;
        $pubDate = $newStatus ? ", published_at = NOW()" : "";
        $conn->query("UPDATE articles SET is_published = $newStatus $pubDate WHERE id = $pubId");
        logActivity('publish', 'articles', "Artikel #$pubId " . ($newStatus ? 'diterbitkan' : 'diarsipkan'));
    }
    header('Location: articles.php');
    exit;
}

$articles = $conn->query("SELECT * FROM articles ORDER BY is_published DESC, created_at DESC");

require_once __DIR__ . '/layout.php';
?>

            <?php if ($success): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $success ?></div>
            <?php endif; ?>
            <?php foreach ($errors as $err): ?>
                <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($err) ?></div>
            <?php endforeach; ?>

            <div class="admin-card">
                <h3 class="admin-card-title">Tambah Artikel Baru</h3>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="save_article" value="1">
                    <div class="form-row">
                        <div class="form-group" style="flex: 2;">
                            <label class="form-label">Judul Artikel <span style="color: #EF4444;">*</span></label>
                            <input type="text" name="title" class="form-input" placeholder="Masukkan judul artikel" required>
                        </div>
                        <div class="form-group" style="flex: 1;">
                            <label class="form-label">Penulis</label>
                            <input type="text" name="author" class="form-input" value="Nadhira Napoleon">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Konten <span style="color: #EF4444;">*</span></label>
                        <textarea name="content" class="form-textarea" style="min-height: 250px;" placeholder="Tulis konten artikel di sini... (HTML didukung)" required></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Excerpt (Ringkasan)</label>
                        <textarea name="excerpt" class="form-textarea" placeholder="Ringkasan singkat artikel (jika kosong, akan diambil dari konten)"></textarea>
                    </div>
                    <div style="display: flex; gap: var(--space-xl); align-items: center; flex-wrap: wrap;">
                        <label style="display: flex; align-items: center; gap: var(--space-sm); cursor: pointer;">
                            <input type="checkbox" name="is_published" value="1" style="width: 18px; height: 18px; accent-color: var(--soft-gold);" checked>
                            <span>Publikasikan sekarang</span>
                        </label>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Simpan Artikel
                        </button>
                    </div>
                </form>
            </div>

            <div class="admin-card">
                <div style="overflow-x: auto;">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Judul</th>
                                <th>Penulis</th>
                                <th>Status</th>
                                <th>Dipublikasi</th>
                                <th style="width: 130px;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($articles && $articles->num_rows > 0):
                                while ($a = $articles->fetch_assoc()):
                            ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($a['title']) ?></strong>
                                    <br><small style="color: var(--text-light); font-size: 11px;"><?= htmlspecialchars($a['slug']) ?></small>
                                </td>
                                <td><?= htmlspecialchars($a['author'] ?? 'Nadhira Napoleon') ?></td>
                                <td>
                                    <span class="status-badge <?= $a['is_published'] ? 'active' : 'inactive' ?>">
                                        <?= $a['is_published'] ? 'Terverbit' : 'Draft' ?>
                                    </span>
                                </td>
                                <td style="font-size: 12px;">
                                    <?= $a['published_at'] ? formatDate($a['published_at'], 'd M Y') : '-' ?>
                                </td>
                                <td>
                                    <div style="display: flex; gap: 4px;">
                                        <button class="btn btn-outline btn-sm" title="Edit"
                                                onclick="editArticle(<?= $a['id'] ?>, '<?= htmlspecialchars($a['title'], ENT_QUOTES) ?>', '<?= htmlspecialchars($a['content'], ENT_QUOTES) ?>', '<?= htmlspecialchars($a['excerpt'] ?? '', ENT_QUOTES) ?>', '<?= htmlspecialchars($a['author'] ?? 'Nadhira Napoleon', ENT_QUOTES) ?>', <?= $a['is_published'] ? 1 : 0 ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <?php if ($a['is_published']): ?>
                                        <a href="<?= SITE_URL ?>/pages/artikel.php?slug=<?= urlencode($a['slug']) ?>" class="btn btn-outline btn-sm" title="Lihat" target="_blank">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <?php endif; ?>
                                        <a href="articles.php?publish=<?= $a['id'] ?>" class="btn btn-outline btn-sm" title="<?= $a['is_published'] ? 'Arsipkan' : 'Terbitkan' ?>">
                                            <i class="fas <?= $a['is_published'] ? 'fa-archive' : 'fa-check-circle' ?>"></i>
                                        </a>
                                        <a href="articles.php?delete=<?= $a['id'] ?>" class="btn btn-outline btn-sm" title="Hapus"
                                           onclick="return confirm('Hapus artikel <?= htmlspecialchars($a['title']) ?>?')"
                                           style="color: #EF4444; border-color: #EF4444;">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; else: ?>
                            <tr><td colspan="5" style="text-align: center; padding: 40px; color: var(--text-muted);">Belum ada artikel</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Edit Modal -->
            <div class="modal-overlay" id="editModal">
                <div class="modal">
                    <div class="modal-header">
                        <h3 class="modal-title">Edit Artikel</h3>
                        <button class="modal-close" onclick="document.getElementById('editModal').classList.remove('active')">&times;</button>
                    </div>
                    <div class="modal-body">
                        <form method="POST">
                            <?= csrfField() ?>
                            <input type="hidden" name="save_article" value="1">
                            <input type="hidden" name="id" id="edit-id">
                            <div class="form-row">
                                <div class="form-group" style="flex: 2;">
                                    <label class="form-label">Judul Artikel</label>
                                    <input type="text" name="title" id="edit-title" class="form-input" required>
                                </div>
                                <div class="form-group" style="flex: 1;">
                                    <label class="form-label">Penulis</label>
                                    <input type="text" name="author" id="edit-author" class="form-input">
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Konten</label>
                                <textarea name="content" id="edit-content" class="form-textarea" style="min-height: 250px;" required></textarea>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Excerpt</label>
                                <textarea name="excerpt" id="edit-excerpt" class="form-textarea"></textarea>
                            </div>
                            <div style="display: flex; gap: var(--space-xl); align-items: center; flex-wrap: wrap;">
                                <label style="display: flex; align-items: center; gap: var(--space-sm); cursor: pointer;">
                                    <input type="checkbox" name="is_published" value="1" id="edit-published" style="width: 18px; height: 18px; accent-color: var(--soft-gold);">
                                    <span>Publikasikan</span>
                                </label>
                                <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <script>
            function editArticle(id, title, content, excerpt, author, published) {
                document.getElementById('edit-id').value = id;
                document.getElementById('edit-title').value = title;
                document.getElementById('edit-content').value = content;
                document.getElementById('edit-excerpt').value = excerpt;
                document.getElementById('edit-author').value = author;
                document.getElementById('edit-published').checked = published === 1;
                document.getElementById('editModal').classList.add('active');
            }
            </script>
        </main>
    </div>
</body>
</html>
