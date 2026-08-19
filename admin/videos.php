<?php
$currentPage = 'videos';
$pageTitle = 'Video Gallery';
require_once __DIR__ . '/layout.php';

$conn = getConnection();

// Handle POST - Add/Edit video
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    requirePermission('videos', (int)($_POST['edit_id'] ?? 0) > 0 ? 'edit' : 'create');
    $title = trim($_POST['title'] ?? '');
    $video_url = trim($_POST['video_url'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $sort_order = (int)($_POST['sort_order'] ?? 0);
    $editId = (int)($_POST['edit_id'] ?? 0);
    $errors = [];
    $success = '';

    if (empty($title)) $errors[] = 'Judul video wajib diisi';
    if (empty($video_url)) $errors[] = 'URL video wajib diisi';

    if (empty($errors)) {
        $title_e = $conn->real_escape_string($title);
        $url_e = $conn->real_escape_string($video_url);
        $desc_e = $conn->real_escape_string($description);

        // Auto-generate thumbnail URL
        $thumbnail = '';
        if (preg_match('/(?:youtube\\.com\\/(?:watch\\?v=|embed\\/|shorts\\/)|youtu\\.be\\/)([a-zA-Z0-9_-]+)/', $video_url, $matches)) {
            $videoId = $matches[1];
            $thumbnail = 'https://img.youtube.com/vi/' . $videoId . '/hqdefault.jpg';
        } elseif (preg_match('/instagram\\.com\\/(?:p|reel)\\/([a-zA-Z0-9_-]+)/', $video_url, $matches)) {
            // Try to fetch Instagram thumbnail via public oEmbed API (no token needed)
            if (function_exists('curl_init')) {
                $oembedUrl = 'https://api.instagram.com/oembed?url=' . urlencode($video_url);
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $oembedUrl,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 5,
                    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
                ]);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($httpCode === 200 && $response) {
                    $data = json_decode($response, true);
                    if (!empty($data['thumbnail_url'])) {
                        $thumbnail = $data['thumbnail_url'];
                    }
                }
            }
        }
        $thumb_e = $conn->real_escape_string($thumbnail);

        if ($editId > 0) {
            $conn->query("UPDATE video_gallery SET 
                title = '$title_e', video_url = '$url_e', thumbnail = '$thumb_e',
                description = '$desc_e', sort_order = $sort_order
                WHERE id = $editId");
            $success = 'Video berhasil diperbarui!';
            logActivity('update', 'videos', "Mengubah video: $title");
        } else {
            $conn->query("INSERT INTO video_gallery (title, video_url, thumbnail, description, sort_order, is_active) 
                         VALUES ('$title_e', '$url_e', '$thumb_e', '$desc_e', $sort_order, 1)");
            $success = 'Video berhasil ditambahkan!';
            logActivity('create', 'videos', "Menambahkan video: $title");
        }
    }
}

// Handle GET - Toggle active
if (isset($_GET['toggle']) && !isset($_GET['delete'])) {
    requirePermission('videos', 'edit');
    $id = (int)$_GET['toggle'];
    $conn->query("UPDATE video_gallery SET is_active = NOT is_active WHERE id = $id");
    logActivity('edit', 'videos', "Toggle status video #$id");
    echo '<script>window.location.href="videos.php";</script>';
    exit;
}

// Handle GET - Delete
if (isset($_GET['delete'])) {
    requirePermission('videos', 'delete');
    $id = (int)$_GET['delete'];
    $conn->query("DELETE FROM video_gallery WHERE id = $id");
    logActivity('delete', 'videos', "Menghapus video #$id");
    echo '<script>window.location.href="videos.php";</script>';
    exit;
}

// Handle GET - Set as main video (sort_order = 0, shift others up)
if (isset($_GET['setmain'])) {
    requirePermission('videos', 'edit');
    $id = (int)$_GET['setmain'];
    // Get video title for success message
    $titleResult = $conn->query("SELECT title FROM video_gallery WHERE id = $id LIMIT 1");
    $mainTitle = ($titleResult && $titleResult->num_rows > 0) ? $titleResult->fetch_assoc()['title'] : 'Video';
    // Shift all other videos up by 1
    $conn->query("UPDATE video_gallery SET sort_order = sort_order + 1 WHERE id != $id");
    // Set this video as main (sort_order = 0)
    $conn->query("UPDATE video_gallery SET sort_order = 0 WHERE id = $id");
    echo '<script>window.location.href="videos.php?main_ok=' . urlencode($mainTitle) . '";</script>';
    exit;
}

// Get all videos
$videos = $conn->query("SELECT * FROM video_gallery ORDER BY sort_order ASC, created_at DESC");

// Get video to edit
$editVideo = null;
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $r = $conn->query("SELECT * FROM video_gallery WHERE id = $editId LIMIT 1");
    if ($r && $r->num_rows > 0) {
        $editVideo = $r->fetch_assoc();
    }
}
?>

<!-- Success/Error Messages -->
<?php if (!empty($success)): ?>
    <div class="alert alert-success" id="mainSuccessAlert"><i class="fas fa-check-circle"></i> <?= $success ?></div>
<?php endif; ?>
<?php 
// Handle GET success message for setmain
if (isset($_GET['main_ok'])) {
    $mainTitle = htmlspecialchars(urldecode($_GET['main_ok']));
    $success = "Video &quot;{$mainTitle}&quot; berhasil dijadikan video utama! 🎉";
    echo '<div class="alert alert-success" id="toastSuccess"><i class="fas fa-crown"></i> ' . $success . '</div>';
}
?>
<?php if (!empty($errors)): foreach ($errors as $err): ?>
    <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($err) ?></div>
<?php endforeach; endif; ?>

<!-- Add/Edit Form -->
<div class="admin-card">
    <h3 class="admin-card-title">
        <i class="fas fa-<?= $editVideo ? 'edit' : 'plus-circle' ?>"></i> 
        <?= $editVideo ? 'Edit Video' : 'Tambah Video Baru' ?>
    </h3>
    <form method="POST">
        <?= csrfField() ?>
        <?php if ($editVideo): ?>
            <input type="hidden" name="edit_id" value="<?= $editVideo['id'] ?>">
        <?php endif; ?>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Judul Video <span style="color: #EF4444;">*</span></label>
                <input type="text" name="title" class="form-input" required
                       value="<?= htmlspecialchars($editVideo['title'] ?? '') ?>"
                       placeholder="Contoh: Proses Pembuatan Napoleon">
            </div>
            <div class="form-group">
                <label class="form-label">URL Video <span style="color: #EF4444;">*</span></label>
                <input type="url" name="video_url" class="form-input" required
                       value="<?= htmlspecialchars($editVideo['video_url'] ?? '') ?>"
                       placeholder="https://www.youtube.com/watch?v=...">
                <small style="color: var(--text-muted); margin-top: 4px; display: block;">
                    <i class="fas fa-info-circle"></i> Support YouTube, YouTube Shorts, Instagram Reels
                </small>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Deskripsi</label>
                <textarea name="description" class="form-textarea" placeholder="Deskripsi singkat video..."><?= htmlspecialchars($editVideo['description'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label class="form-label">Urutan</label>
                <input type="number" name="sort_order" class="form-input" min="0" 
                       value="<?= (int)($editVideo['sort_order'] ?? 0) ?>">
                <small style="color: var(--text-muted); margin-top: 4px; display: block;">
                    Semakin kecil angka, semakin atas posisinya
                </small>
            </div>
        </div>
        <div style="display: flex; gap: var(--space-md);">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> <?= $editVideo ? 'Simpan Perubahan' : 'Tambah Video' ?>
            </button>
            <?php if ($editVideo): ?>
                <a href="videos.php" class="btn btn-outline">
                    <i class="fas fa-times"></i> Batal
                </a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Videos List -->
<div class="admin-card">
    <h3 class="admin-card-title"><i class="fas fa-list"></i> Daftar Video</h3>
    <div style="overflow-x: auto;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th style="width: 60px;">Utama</th>
                    <th style="width: 100px;">Thumbnail</th>
                    <th>Judul</th>
                    <th>URL</th>
                    <th>Status</th>
                    <th style="width: 200px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($videos && $videos->num_rows > 0): ?>
                    <?php while ($v = $videos->fetch_assoc()): ?>
                    <tr>
                        <td style="text-align: center;">
                            <?php if ((int)$v['sort_order'] === 0): ?>
                                <span style="display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; background: linear-gradient(135deg, rgba(212,168,83,0.15), rgba(184,134,11,0.1)); border-radius: 20px; font-size: 11px; font-weight: 700; color: #B8860B;">
                                    <i class="fas fa-crown"></i> Utama
                                </span>
                            <?php else: ?>
                                <span style="color: var(--text-muted); font-size: 12px;"><?= (int)$v['sort_order'] ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($v['thumbnail']): ?>
                                <img src="<?= htmlspecialchars($v['thumbnail']) ?>" alt="Thumbnail" 
                                     style="width: 80px; height: 45px; object-fit: cover; border-radius: 6px; display: block;">
                            <?php else: ?>
                                <div style="width: 80px; height: 45px; background: var(--soft-grey); border-radius: 6px; display: flex; align-items: center; justify-content: center; color: var(--text-muted); font-size: 10px;">
                                    <i class="fas fa-video"></i>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td><strong><?= htmlspecialchars($v['title']) ?></strong></td>
                        <td style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                            <a href="<?= htmlspecialchars($v['video_url']) ?>" target="_blank" rel="noopener" style="color: var(--text-muted);">
                                <?= htmlspecialchars(mb_substr($v['video_url'], 0, 50)) ?>...
                            </a>
                        </td>
                        <td>
                            <span class="status-badge <?= $v['is_active'] ? 'active' : 'inactive' ?>">
                                <?= $v['is_active'] ? 'Aktif' : 'Nonaktif' ?>
                            </span>
                        </td>
                        <td>
                            <div style="display: flex; gap: 4px; flex-wrap: wrap;">
                                <?php if ((int)$v['sort_order'] !== 0): ?>
                                <a href="videos.php?setmain=<?= $v['id'] ?>" class="btn btn-outline btn-sm" 
                                   title="Jadikan video utama"
                                   style="color: #B8860B; border-color: #D4A853;"
                                   onclick="return confirm('Jadikan "<?= htmlspecialchars($v['title']) ?>" sebagai video utama?')">
                                    <i class="fas fa-crown"></i>
                                </a>
                                <?php endif; ?>
                                <a href="videos.php?edit=<?= $v['id'] ?>" class="btn btn-outline btn-sm" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a href="videos.php?toggle=<?= $v['id'] ?>" class="btn btn-outline btn-sm" 
                                   title="<?= $v['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?>"
                                   style="<?= $v['is_active'] ? '' : 'color: #10B981; border-color: #10B981;' ?>">
                                    <i class="fas <?= $v['is_active'] ? 'fa-eye-slash' : 'fa-eye' ?>"></i>
                                </a>
                                <a href="videos.php?delete=<?= $v['id'] ?>" class="btn btn-outline btn-sm" title="Hapus"
                                   onclick="return confirm('Hapus video <?= htmlspecialchars($v['title']) ?>?')"
                                   style="color: #EF4444; border-color: #EF4444;">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="6" style="text-align: center; padding: 40px; color: var(--text-muted);">
                        <i class="fas fa-video" style="font-size: 2rem; display: block; margin-bottom: 8px;"></i>
                        Belum ada video. Tambah video baru di atas.
                    </td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<!-- Toast notification styles -->
<style>
.admin-toast-container {
    position: fixed;
    top: 24px;
    right: 24px;
    z-index: 9999;
    display: flex;
    flex-direction: column;
    gap: 8px;
    pointer-events: none;
}
.admin-toast {
    pointer-events: all;
    padding: 16px 24px;
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.15);
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 320px;
    max-width: 420px;
    font-size: 14px;
    font-weight: 500;
    color: #333;
    border-left: 4px solid #B8860B;
    animation: adminToastIn 0.4s cubic-bezier(0.68, -0.55, 0.265, 1.55);
    transform-origin: top right;
}
.admin-toast i {
    font-size: 20px;
    color: #B8860B;
}
@keyframes adminToastIn {
    from { opacity: 0; transform: translateX(100%) scale(0.8); }
    to { opacity: 1; transform: translateX(0) scale(1); }
}
@keyframes adminToastOut {
    from { opacity: 1; transform: translateX(0) scale(1); }
    to { opacity: 0; transform: translateX(100%) scale(0.8); }
}
</style>

<div class="admin-toast-container" id="adminToastContainer"></div>

<script>
function showAdminToast(message) {
    const container = document.getElementById('adminToastContainer');
    if (!container) return;
    const toast = document.createElement('div');
    toast.className = 'admin-toast';
    toast.innerHTML = '<i class="fas fa-crown"></i> ' + message;
    container.appendChild(toast);
    setTimeout(() => {
        toast.style.animation = 'adminToastOut 0.3s ease forwards';
        setTimeout(() => toast.remove(), 300);
    }, 4000);
}
<?php if (isset($_GET['main_ok'])): ?>
document.addEventListener('DOMContentLoaded', function() {
    const el = document.getElementById('toastSuccess');
    if (el) {
        const msg = el.textContent.trim();
        el.remove();
        setTimeout(() => showAdminToast(msg), 100);
    }
});
<?php endif; ?>
</script>

</main></div></body></html>
