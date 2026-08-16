<?php
$currentPage = 'hero_slides';
$pageTitle = 'Hero Slider';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/../config/cloudinary.php'; // penyimpanan foto Cloudinary

$conn = getConnection();

// ============================================
// HANDLE POST - Add/Edit slide (upload image)
// ============================================
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_slide'])) {
    requirePermission('hero_slides', (int)($_POST['edit_id'] ?? 0) > 0 ? 'edit' : 'create');
    $label = trim($_POST['label'] ?? '');
    $sort_order = (int)($_POST['sort_order'] ?? 0);
    $editId = (int)($_POST['edit_id'] ?? 0);

    // Determine which fields keep existing values when editing
    $imageUrl = trim($_POST['image_url'] ?? '');
    $mobileUrl = trim($_POST['mobile_url'] ?? '');

    // Simpan foto slide lama (untuk dibersihkan saat diganti dengan yang baru)
    $oldSlideImage = '';
    $oldSlideMobile = '';
    if ($editId > 0) {
        $oldS = $conn->query("SELECT image, image_mobile FROM hero_slides WHERE id = $editId LIMIT 1");
        if ($oldS && $oldS->num_rows > 0) {
            $oldRow = $oldS->fetch_assoc();
            $oldSlideImage = $oldRow['image'] ?? '';
            $oldSlideMobile = $oldRow['image_mobile'] ?? '';
        }
    }

    // Handle image upload (auto-generate desktop + mobile versions)
    if (isset($_FILES['slide_image']) && $_FILES['slide_image']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/../uploads/hero/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        $tmpPath = $_FILES['slide_image']['tmp_name'];
        $ext = strtolower(pathinfo($_FILES['slide_image']['name'], PATHINFO_EXTENSION));
        $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

        if ($_FILES['slide_image']['size'] > 20 * 1024 * 1024) {
            $errors[] = 'Ukuran foto terlalu besar (maks 20MB).';
        } elseif (!in_array($ext, $allowedExts) || !@getimagesize($tmpPath)) {
            $errors[] = 'Format foto tidak didukung (JPG, PNG, WebP, GIF).';
        } else {
            $saved = false;
            $savedMobile = false;
            $destPath = $uploadDir . 'hero_' . time() . '_' . uniqid() . '.jpg';
            $destMobile = $uploadDir . 'hero_' . time() . '_' . uniqid() . '_mobile.jpg';

            // Jika Cloudinary aktif: upload sekali, lalu pakai transformasi Cloudinary
            // untuk versi desktop (16:9) & mobile (9:16) — tanpa proses GD lokal.
            if (cloudinaryEnabled()) {
                $up = cloudinaryUploadFromUploaded($tmpPath, $_FILES['slide_image']['name'], 'nadhira/hero', 'hero');
                if ($up['success']) {
                    $imageUrl = cloudinaryImageUrl($up['public_id'], ['w' => 1920, 'h' => 1080, 'c' => 'fill', 'f' => 'auto', 'q' => 'auto']);
                    $mobileUrl = cloudinaryImageUrl($up['public_id'], ['w' => 1080, 'h' => 1920, 'c' => 'fill', 'f' => 'auto', 'q' => 'auto']);
                    $saved = true;
                    $savedMobile = true;
                } else {
                    $errors[] = 'Gagal upload foto slide ke Cloudinary: ' . $up['message'];
                }
            }

            if (!$saved && function_exists('imagecreatetruecolor')) {
                $imgInfo = @getimagesize($tmpPath);
                if ($imgInfo) {
                    list($w, $h) = $imgInfo;
                    $src = null;
                    switch ($imgInfo['mime']) {
                        case 'image/jpeg': $src = @imagecreatefromjpeg($tmpPath); break;
                        case 'image/png':  $src = @imagecreatefrompng($tmpPath); break;
                        case 'image/webp': $src = @imagecreatefromwebp($tmpPath); break;
                        case 'image/gif':  $src = @imagecreatefromgif($tmpPath); break;
                    }
                    if ($src) {
                        // Desktop: center-crop 16:9 -> 1920x1080
                        $targetRatio = 16 / 9;
                        $srcRatio = $w / $h;
                        if ($srcRatio > $targetRatio) {
                            $cw = (int)round($h * $targetRatio); $ch = $h; $cx = (int)round(($w - $cw) / 2); $cy = 0;
                        } else {
                            $cw = $w; $ch = (int)round($w / $targetRatio); $cx = 0; $cy = (int)round(($h - $ch) / 2);
                        }
                        $TW = 1920; $TH = 1080;
                        $dst = imagecreatetruecolor($TW, $TH);
                        if ($dst) {
                            imagecopyresampled($dst, $src, 0, 0, $cx, $cy, $TW, $TH, $cw, $ch);
                            if (imagejpeg($dst, $destPath, 80)) $saved = true;
                            imagedestroy($dst);
                        }

                        // Mobile: center-crop 9:16 -> 1080x1920
                        $mobileRatio = 9 / 16;
                        $srcRatioM = $w / $h;
                        if ($srcRatioM > $mobileRatio) {
                            $cwM = (int)round($h * $mobileRatio); $chM = $h; $cxM = (int)round(($w - $cwM) / 2); $cyM = 0;
                        } else {
                            $cwM = $w; $chM = (int)round($w / $mobileRatio); $cxM = 0; $cyM = (int)round(($h - $chM) / 2);
                        }
                        $MW = 1080; $MH = 1920;
                        $dstM = imagecreatetruecolor($MW, $MH);
                        if ($dstM) {
                            imagecopyresampled($dstM, $src, 0, 0, $cxM, $cyM, $MW, $MH, $cwM, $chM);
                            if (imagejpeg($dstM, $destMobile, 80)) $savedMobile = true;
                            imagedestroy($dstM);
                        }
                        imagedestroy($src);
                    }
                }
            }

            // Fallback: move original as-is
            if (!$saved) {
                $destPath = $uploadDir . 'hero_' . time() . '_' . uniqid() . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
                $saved = move_uploaded_file($tmpPath, $destPath);
            }

            if ($saved) {
                // Mode lokal: simpan URL uploads/hero/ (mode Cloudinary sudah diisi di atas)
                if (!cloudinaryEnabled()) {
                    $imageUrl = SITE_URL . '/uploads/hero/' . basename($destPath);
                    if ($savedMobile) {
                        $mobileUrl = SITE_URL . '/uploads/hero/' . basename($destMobile);
                    }
                }
                // Bersihkan foto slide lama (Cloudinary / lokal)
                if ($oldSlideImage !== '' && $oldSlideImage !== $imageUrl) {
                    if (isCloudinaryUrl($oldSlideImage)) {
                        cloudinaryDeleteByUrl($oldSlideImage);
                    } elseif (strpos($oldSlideImage, '/uploads/hero/') !== false) {
                        $oldFile = __DIR__ . '/../uploads/hero/' . basename(parse_url($oldSlideImage, PHP_URL_PATH));
                        if ($oldFile !== $destPath && file_exists($oldFile)) @unlink($oldFile);
                    }
                }
                if ($savedMobile && $oldSlideMobile !== '' && $oldSlideMobile !== $mobileUrl) {
                    if (isCloudinaryUrl($oldSlideMobile)) {
                        cloudinaryDeleteByUrl($oldSlideMobile);
                    } elseif (strpos($oldSlideMobile, '/uploads/hero/') !== false) {
                        $oldMFile = __DIR__ . '/../uploads/hero/' . basename(parse_url($oldSlideMobile, PHP_URL_PATH));
                        if ($oldMFile !== $destMobile && file_exists($oldMFile)) @unlink($oldMFile);
                    }
                }
            } else {
                $errors[] = 'Gagal mengunggah foto slide.';
            }
        }
    }

    if (empty($imageUrl)) {
        $errors[] = 'Foto slide wajib diisi (upload file atau isi URL).';
    }

    if (empty($errors)) {
        $label_e = $conn->real_escape_string($label);
        $img_e = $conn->real_escape_string($imageUrl);
        $mob_e = $conn->real_escape_string($mobileUrl);

        if ($editId > 0) {
            $conn->query("UPDATE hero_slides SET
                image = '$img_e', image_mobile = '$mob_e', label = '$label_e', sort_order = $sort_order
                WHERE id = $editId");
            $success = 'Slide berhasil diperbarui!';
            logActivity('update', 'hero_slides', "Mengubah slide hero #$editId");
        } else {
            $conn->query("INSERT INTO hero_slides (image, image_mobile, label, sort_order, is_active)
                         VALUES ('$img_e', '$mob_e', '$label_e', $sort_order, 1)");
            $success = 'Slide berhasil ditambahkan!';
            logActivity('create', 'hero_slides', 'Menambahkan slide hero baru');
        }
    }
}

// ============================================
// HANDLE GET - Toggle active
// ============================================
if (isset($_GET['toggle'])) {
    requirePermission('hero_slides', 'edit');
    $id = (int)$_GET['toggle'];
    $conn->query("UPDATE hero_slides SET is_active = NOT is_active WHERE id = $id");
    logActivity('edit', 'hero_slides', "Toggle status slide #$id");
    echo '<script>window.location.href="hero-slides.php";</script>';
    exit;
}

// ============================================
// HANDLE GET - Delete
// ============================================
if (isset($_GET['delete'])) {
    requirePermission('hero_slides', 'delete');
    $id = (int)$_GET['delete'];
    // Bersihkan foto slide (Cloudinary / lokal) sebelum baris dihapus
    $oldS = $conn->query("SELECT image, image_mobile FROM hero_slides WHERE id = $id LIMIT 1");
    if ($oldS && $oldS->num_rows > 0) {
        $oldRow = $oldS->fetch_assoc();
        foreach ([$oldRow['image'] ?? '', $oldRow['image_mobile'] ?? ''] as $oldImg) {
            if ($oldImg === '') continue;
            if (isCloudinaryUrl($oldImg)) {
                cloudinaryDeleteByUrl($oldImg);
            } elseif (strpos($oldImg, '/uploads/hero/') !== false) {
                $oldFile = __DIR__ . '/../uploads/hero/' . basename(parse_url($oldImg, PHP_URL_PATH));
                if (file_exists($oldFile)) @unlink($oldFile);
            }
        }
    }
    $conn->query("DELETE FROM hero_slides WHERE id = $id");
    logActivity('delete', 'hero_slides', "Menghapus slide hero #$id");
    echo '<script>window.location.href="hero-slides.php";</script>';
    exit;
}

// ============================================
// HANDLE GET - Move up/down (sort order)
// ============================================
if (isset($_GET['move'])) {
    $id = (int)$_GET['move'];
    $dir = $_GET['dir'] === 'up' ? 'up' : 'down';

    // Get current slide + its siblings ordered
    $slides = [];
    $r = $conn->query("SELECT id, sort_order FROM hero_slides ORDER BY sort_order ASC, id ASC");
    if ($r) {
        while ($row = $r->fetch_assoc()) { $slides[] = $row; }
    }
    $idx = -1;
    foreach ($slides as $i => $s) {
        if ((int)$s['id'] === $id) { $idx = $i; break; }
    }
    if ($idx > 0 && $dir === 'up') {
        $a = $slides[$idx]; $b = $slides[$idx - 1];
        $conn->query("UPDATE hero_slides SET sort_order = " . (int)$b['sort_order'] . " WHERE id = " . (int)$a['id']);
        $conn->query("UPDATE hero_slides SET sort_order = " . (int)$a['sort_order'] . " WHERE id = " . (int)$b['id']);
    } elseif ($idx >= 0 && $idx < count($slides) - 1 && $dir === 'down') {
        $a = $slides[$idx]; $b = $slides[$idx + 1];
        $conn->query("UPDATE hero_slides SET sort_order = " . (int)$b['sort_order'] . " WHERE id = " . (int)$a['id']);
        $conn->query("UPDATE hero_slides SET sort_order = " . (int)$a['sort_order'] . " WHERE id = " . (int)$b['id']);
    }
    echo '<script>window.location.href="hero-slides.php";</script>';
    exit;
}

// ============================================
// LOAD DATA
// ============================================
$slides = $conn->query("SELECT * FROM hero_slides ORDER BY sort_order ASC, id ASC");

$editSlide = null;
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $r = $conn->query("SELECT * FROM hero_slides WHERE id = $editId LIMIT 1");
    if ($r && $r->num_rows > 0) {
        $editSlide = $r->fetch_assoc();
    }
}
?>

<?php if (!empty($success)): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $success ?></div>
<?php endif; ?>
<?php if (!empty($errors)): foreach ($errors as $err): ?>
    <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($err) ?></div>
<?php endforeach; endif; ?>

<!-- Add/Edit Form -->
<div class="admin-card">
    <h3 class="admin-card-title">
        <i class="fas fa-<?= $editSlide ? 'edit' : 'plus-circle' ?>"></i>
        <?= $editSlide ? 'Edit Slide' : 'Tambah Slide Baru' ?>
    </h3>
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="save_slide" value="1">
        <?php if ($editSlide): ?>
            <input type="hidden" name="edit_id" value="<?= (int)$editSlide['id'] ?>">
        <?php endif; ?>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Foto Slide (desktop 16:9 & mobile otomatis) <span style="color: #EF4444;">*</span></label>
                <input type="file" name="slide_image" id="slideImage" class="form-input" accept="image/jpeg,image/png,image/webp,image/gif">
                <small style="color: var(--text-muted); margin-top: 4px; display: block;">
                    <i class="fas fa-info-circle"></i> Maks 20MB. Otomatis dibuat 2 versi: desktop (1920×1080) & mobile (1080×1920).
                    <?= $editSlide ? 'Kosongkan jika tidak ingin mengubah foto.' : '' ?>
                </small>
                <?php if ($editSlide): ?>
                    <img src="<?= htmlspecialchars($editSlide['image']) ?>" alt="Preview"
                         style="margin-top: 10px; max-width: 100%; max-height: 200px; object-fit: contain; border-radius: 10px; border: 2px solid var(--border-color);">
                <?php endif; ?>
            </div>
            <div class="form-group">
                <label class="form-label">Label / Keterangan</label>
                <input type="text" name="label" class="form-input"
                       value="<?= htmlspecialchars($editSlide['label'] ?? '') ?>"
                       placeholder="Contoh: Ketan Talam">
                <small style="color: var(--text-muted); margin-top: 4px; display: block;">Hanya untuk identifikasi di admin (tidak tampil di website).</small>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">URL Foto (opsional, alternatif upload)</label>
                <input type="text" name="image_url" class="form-input"
                       value="<?= htmlspecialchars($editSlide['image'] ?? '') ?>"
                       placeholder="URL gambar — mis. https://domain-anda.com/uploads/hero/....jpg">
            </div>
            <div class="form-group">
                <label class="form-label">URL Foto Mobile (opsional)</label>
                <input type="text" name="mobile_url" class="form-input"
                       value="<?= htmlspecialchars($editSlide['image_mobile'] ?? '') ?>"
                       placeholder="Kosongkan untuk memakai foto desktop">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Urutan</label>
                <input type="number" name="sort_order" class="form-input" min="0"
                       value="<?= (int)($editSlide['sort_order'] ?? 0) ?>">
                <small style="color: var(--text-muted); margin-top: 4px; display: block;">Semakin kecil angka, semakin awal tampil. Bisa juga pakai tombol panah di daftar.</small>
            </div>
            <div class="form-group" style="display: flex; align-items: flex-end;">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> <?= $editSlide ? 'Simpan Perubahan' : 'Tambah Slide' ?>
                </button>
                <?php if ($editSlide): ?>
                    <a href="hero-slides.php" class="btn btn-outline" style="margin-left: 8px;">
                        <i class="fas fa-times"></i> Batal
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </form>
</div>

<!-- Slides List -->
<div class="admin-card">
    <h3 class="admin-card-title"><i class="fas fa-list"></i> Daftar Slide</h3>
    <div style="overflow-x: auto;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th style="width: 100px;">Foto</th>
                    <th>Label</th>
                    <th>Urutan</th>
                    <th>Status</th>
                    <th style="width: 230px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($slides && $slides->num_rows > 0): ?>
                    <?php
                    $slideRows = [];
                    while ($s = $slides->fetch_assoc()) { $slideRows[] = $s; }
                    $totalRows = count($slideRows);
                    foreach ($slideRows as $i => $s):
                    ?>
                    <tr>
                        <td>
                            <img src="<?= htmlspecialchars($s['image']) ?>" alt="Slide"
                                 style="width: 120px; height: 68px; object-fit: cover; border-radius: 6px; display: block;">
                        </td>
                        <td><strong><?= htmlspecialchars($s['label'] ?: 'Slide #' . $s['id']) ?></strong></td>
                        <td>
                            <div style="display: flex; align-items: center; gap: 4px;">
                                <span style="font-size: 12px; color: var(--text-muted); min-width: 20px;"><?= (int)$s['sort_order'] ?></span>
                                <a href="hero-slides.php?move=<?= $s['id'] ?>&dir=up" class="btn btn-outline btn-sm sort-arrow <?= $i === 0 ? 'sort-disabled' : '' ?>" title="Naik">
                                    <i class="fas fa-arrow-up"></i>
                                </a>
                                <a href="hero-slides.php?move=<?= $s['id'] ?>&dir=down" class="btn btn-outline btn-sm sort-arrow <?= $i === $totalRows - 1 ? 'sort-disabled' : '' ?>" title="Turun">
                                    <i class="fas fa-arrow-down"></i>
                                </a>
                            </div>
                        </td>
                        <td>
                            <span class="status-badge <?= $s['is_active'] ? 'active' : 'inactive' ?>">
                                <?= $s['is_active'] ? 'Aktif' : 'Nonaktif' ?>
                            </span>
                        </td>
                        <td>
                            <div style="display: flex; gap: 4px; flex-wrap: wrap;">
                                <a href="hero-slides.php?edit=<?= $s['id'] ?>" class="btn btn-outline btn-sm" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a href="hero-slides.php?toggle=<?= $s['id'] ?>" class="btn btn-outline btn-sm"
                                   title="<?= $s['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?>"
                                   style="<?= $s['is_active'] ? '' : 'color: #10B981; border-color: #10B981;' ?>">
                                    <i class="fas <?= $s['is_active'] ? 'fa-eye-slash' : 'fa-eye' ?>"></i>
                                </a>
                                <a href="hero-slides.php?delete=<?= $s['id'] ?>" class="btn btn-outline btn-sm" title="Hapus"
                                   onclick="return confirm('Hapus slide <?= htmlspecialchars($s['label'] ?: 'ini') ?>?')"
                                   style="color: #EF4444; border-color: #EF4444;">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="5" style="text-align: center; padding: 40px; color: var(--text-muted);">
                        <i class="fas fa-images" style="font-size: 2rem; display: block; margin-bottom: 8px;"></i>
                        Belum ada slide. Tambahkan foto pertama di atas.
                    </td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</main></div></body></html>
