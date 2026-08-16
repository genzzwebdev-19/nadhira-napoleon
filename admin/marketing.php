<?php
$currentPage = 'marketing';
$pageTitle = 'Marketing';
require_once __DIR__ . '/../config/rbac.php';
require_once __DIR__ . '/../config/mail.php';

requirePermission('marketing', 'view');
$conn = getConnection();

$errors = [];
$success = '';
$info = '';

// ============================================
// ACTION: Simpan kampanye (tambah/edit)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_campaign'])) {
    verifyCsrf();
    $editId = (int)($_POST['id'] ?? 0);
    requirePermission('marketing', $editId > 0 ? 'edit' : 'create');

    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $channel = trim($_POST['channel'] ?? 'sosial_media');
    $budget = (float)($_POST['budget'] ?? 0);
    $startDate = trim($_POST['start_date'] ?? '');
    $endDate = trim($_POST['end_date'] ?? '');
    $status = in_array($_POST['status'] ?? '', ['draft', 'active', 'ended', 'cancelled'], true) ? $_POST['status'] : 'draft';

    if ($title === '') {
        $errors[] = 'Judul kampanye wajib diisi';
    } else {
        $title_e = $conn->real_escape_string($title);
        $desc_e = $conn->real_escape_string($description);
        $channel_e = $conn->real_escape_string($channel);
        $startSql = $startDate !== '' ? "'" . $conn->real_escape_string($startDate) . "'" : 'NULL';
        $endSql = $endDate !== '' ? "'" . $conn->real_escape_string($endDate) . "'" : 'NULL';
        $isActive = $status === 'active' ? 1 : 0;

        if ($editId > 0) {
            $conn->query(
                "UPDATE marketing_campaigns SET title = '$title_e', description = '$desc_e', channel = '$channel_e',
                 budget = $budget, start_date = $startSql, end_date = $endSql, status = '$status', is_active = $isActive
                 WHERE id = $editId"
            );
            $success = 'Kampanye berhasil diperbarui!';
            logActivity('update', 'marketing', "Mengubah kampanye: $title");
        } else {
            $conn->query(
                "INSERT INTO marketing_campaigns (title, description, channel, budget, start_date, end_date, status, is_active)
                 VALUES ('$title_e', '$desc_e', '$channel_e', $budget, $startSql, $endSql, '$status', $isActive)"
            );
            $success = 'Kampanye berhasil ditambahkan!';
            logActivity('create', 'marketing', "Menambahkan kampanye: $title");
        }
        header('Location: marketing.php');
        exit;
    }
}

// ============================================
// ACTION: Toggle status aktif kampanye
// ============================================
if (isset($_GET['toggle'])) {
    verifyCsrf();
    requirePermission('marketing', 'edit');
    $cid = (int)$_GET['toggle'];
    $conn->query("UPDATE marketing_campaigns SET is_active = NOT is_active WHERE id = $cid");
    logActivity('edit', 'marketing', "Toggle kampanye #$cid");
    header('Location: marketing.php');
    exit;
}

// ============================================
// ACTION: Hapus kampanye
// ============================================
if (isset($_GET['delete'])) {
    verifyCsrf();
    requirePermission('marketing', 'delete');
    $cid = (int)$_GET['delete'];
    $conn->query("DELETE FROM marketing_campaigns WHERE id = $cid");
    $success = 'Kampanye berhasil dihapus.';
    logActivity('delete', 'marketing', "Menghapus kampanye #$cid");
    header('Location: marketing.php');
    exit;
}

// ============================================
// ACTION: Broadcast newsletter ke semua subscriber aktif
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_newsletter'])) {
    verifyCsrf();
    requirePermission('marketing', 'create');

    $subject = trim($_POST['newsletter_subject'] ?? '');
    $message = trim($_POST['newsletter_message'] ?? '');

    if ($subject === '') {
        $errors[] = 'Subjek newsletter wajib diisi';
    } elseif ($message === '') {
        $errors[] = 'Isi pesan newsletter wajib diisi';
    } else {
        $body = mailTemplate($subject,
            '<h2 style="color:#B8860B;font-family:Georgia,serif;margin:0 0 16px;">' . htmlspecialchars($subject) . '</h2>'
            . nl2br(htmlspecialchars($message)));
        $res = sendNewsletterBroadcastEmail($subject, $body);

        if ($res['total'] === 0) {
            $info = 'Belum ada subscriber newsletter aktif — tidak ada email yang dikirim.';
        } elseif ($res['failed'] === 0) {
            $success = "✅ Newsletter terkirim ke {$res['sent']} dari {$res['total']} subscriber!";
        } else {
            $info = "⚠️ Newsletter terkirim ke {$res['sent']} dari {$res['total']} subscriber ({$res['failed']} gagal).";
        }
        logActivity('create', 'marketing', "Broadcast newsletter: $subject");
    }
}

// ============================================
// ACTION: Hapus subscriber newsletter
// ============================================
if (isset($_GET['delete_subscriber'])) {
    verifyCsrf();
    requirePermission('marketing', 'delete');
    $sid = (int)$_GET['delete_subscriber'];
    $conn->query("DELETE FROM newsletter_subscribers WHERE id = $sid");
    $success = 'Subscriber dihapus.';
    logActivity('delete', 'marketing', "Menghapus subscriber #$sid");
    header('Location: marketing.php');
    exit;
}

// ============================================
// DATA
// ============================================
$statusFilter = $_GET['status'] ?? '';
$where = "WHERE 1=1";
if ($statusFilter) {
    $s = $conn->real_escape_string($statusFilter);
    $where .= " AND status = '$s'";
}
$campaigns = $conn->query("SELECT * FROM marketing_campaigns $where ORDER BY created_at DESC");
$subscribers = $conn->query("SELECT * FROM newsletter_subscribers ORDER BY subscribed_at DESC LIMIT 200");

$activeCount = (int)$conn->query("SELECT COUNT(*) c FROM marketing_campaigns WHERE status = 'active'")->fetch_assoc()['c'];
$draftCount = (int)$conn->query("SELECT COUNT(*) c FROM marketing_campaigns WHERE status = 'draft'")->fetch_assoc()['c'];
$subscriberCount = (int)$conn->query("SELECT COUNT(*) c FROM newsletter_subscribers")->fetch_assoc()['c'];
$totalBudget = (float)$conn->query("SELECT COALESCE(SUM(budget),0) c FROM marketing_campaigns WHERE status IN ('draft','active')")->fetch_assoc()['c'];

$editCampaign = null;
if (isset($_GET['edit'])) {
    $cid = (int)$_GET['edit'];
    $r = $conn->query("SELECT * FROM marketing_campaigns WHERE id = $cid LIMIT 1");
    if ($r && $r->num_rows > 0) $editCampaign = $r->fetch_assoc();
}

$statusLabels = ['draft' => 'Draft', 'active' => 'Aktif', 'ended' => 'Selesai', 'cancelled' => 'Dibatalkan'];
$statusClasses = ['draft' => 'pending', 'active' => 'active', 'ended' => 'inactive', 'cancelled' => 'cancelled'];

require_once __DIR__ . '/layout.php';
?>

<?php if ($success): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $success ?></div>
<?php endif; ?>
<?php if ($info): ?>
    <div class="alert alert-info"><i class="fas fa-info-circle"></i> <?= $info ?></div>
<?php endif; ?>
<?php foreach ($errors as $err): ?>
    <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($err) ?></div>
<?php endforeach; ?>

<!-- ============ STATS ============ -->
<div class="stats-grid">
    <div class="stat-card"><div class="stat-card-header"><div class="stat-card-icon success"><i class="fas fa-bullhorn"></i></div><div><div class="stat-card-value"><?= $activeCount ?></div></div></div><div class="stat-card-label">Kampanye Aktif</div></div>
    <div class="stat-card"><div class="stat-card-header"><div class="stat-card-icon warning"><i class="fas fa-pen-ruler"></i></div><div><div class="stat-card-value"><?= $draftCount ?></div></div></div><div class="stat-card-label">Kampanye Draft</div></div>
    <div class="stat-card"><div class="stat-card-header"><div class="stat-card-icon info"><i class="fas fa-envelope-open-text"></i></div><div><div class="stat-card-value"><?= number_format($subscriberCount) ?></div></div></div><div class="stat-card-label">Subscriber Newsletter</div></div>
    <div class="stat-card"><div class="stat-card-header"><div class="stat-card-icon"><i class="fas fa-coins"></i></div><div><div class="stat-card-value" style="font-size: 18px;">Rp <?= number_format($totalBudget, 0, ',', '.') ?></div></div></div><div class="stat-card-label">Total Anggaran Aktif</div></div>
</div>

<!-- ============ FORM KAMPANYE ============ -->
<?php if (hasPermission('marketing', 'create') || hasPermission('marketing', 'edit')): ?>
<div class="admin-card">
    <h3 class="admin-card-title"><i class="fas fa-bullhorn" style="color: var(--soft-gold);"></i> <?= $editCampaign ? 'Edit Kampanye' : 'Tambah Kampanye Baru' ?></h3>
    <form method="POST">
        <input type="hidden" name="save_campaign" value="1">
        <input type="hidden" name="id" value="<?= $editCampaign['id'] ?? 0 ?>">
        <?= csrfField() ?>
        <div class="form-row">
            <div class="form-group" style="flex: 2;">
                <label class="form-label">Judul Kampanye <span style="color: #EF4444;">*</span></label>
                <input type="text" name="title" class="form-input" required value="<?= htmlspecialchars($editCampaign['title'] ?? '') ?>" placeholder="Contoh: Flash Sale HUT ke-5">
            </div>
            <div class="form-group">
                <label class="form-label">Channel</label>
                <select name="channel" class="form-select">
                    <?php $channels = ['sosial_media' => 'Sosial Media', 'instagram' => 'Instagram', 'tiktok' => 'TikTok', 'facebook' => 'Facebook', 'whatsapp' => 'WhatsApp', 'email' => 'Email', 'seo' => 'SEO', 'offline' => 'Offline / Toko']; ?>
                    <?php foreach ($channels as $val => $label): ?>
                    <option value="<?= $val ?>" <?= ($editCampaign['channel'] ?? 'sosial_media') === $val ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <?php foreach ($statusLabels as $val => $label): ?>
                    <option value="<?= $val ?>" <?= ($editCampaign['status'] ?? 'draft') === $val ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Anggaran (Rp)</label>
                <input type="number" name="budget" class="form-input" min="0" step="0.01" value="<?= $editCampaign['budget'] ?? 0 ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Tanggal Mulai</label>
                <input type="datetime-local" name="start_date" class="form-input" value="<?= $editCampaign && $editCampaign['start_date'] ? date('Y-m-d\TH:i', strtotime($editCampaign['start_date'])) : '' ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Tanggal Berakhir</label>
                <input type="datetime-local" name="end_date" class="form-input" value="<?= $editCampaign && $editCampaign['end_date'] ? date('Y-m-d\TH:i', strtotime($editCampaign['end_date'])) : '' ?>">
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">Deskripsi</label>
            <textarea name="description" class="form-textarea" placeholder="Detail kampanye, target audiens, materi..."><?= htmlspecialchars($editCampaign['description'] ?? '') ?></textarea>
        </div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> <?= $editCampaign ? 'Simpan Perubahan' : 'Tambah Kampanye' ?></button>
        <?php if ($editCampaign): ?><a href="marketing.php" class="btn btn-outline">Batal</a><?php endif; ?>
    </form>
</div>
<?php endif; ?>

<!-- ============ DAFTAR KAMPANYE ============ -->
<div class="admin-card">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 16px;">
        <h3 class="admin-card-title" style="margin: 0;">Daftar Kampanye (<?= $campaigns ? $campaigns->num_rows : 0 ?>)</h3>
        <select class="form-select" style="width: auto;" onchange="location.href='marketing.php?status='+this.value;">
            <option value="">Semua Status</option>
            <?php foreach ($statusLabels as $val => $label): ?>
            <option value="<?= $val ?>" <?= $statusFilter === $val ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div style="overflow-x: auto;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Kampanye</th>
                    <th>Channel</th>
                    <th>Anggaran</th>
                    <th>Periode</th>
                    <th>Status</th>
                    <th style="width: 130px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($campaigns && $campaigns->num_rows > 0): while ($c = $campaigns->fetch_assoc()): ?>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars($c['title']) ?></strong>
                        <?php if ($c['description']): ?><br><small style="font-size: 11px; color: var(--text-light);"><?= htmlspecialchars(mb_substr($c['description'], 0, 60)) ?></small><?php endif; ?>
                    </td>
                    <td style="font-size: 12px;"><i class="fas fa-share-alt" style="color: var(--soft-gold);"></i> <?= htmlspecialchars(ucwords(str_replace('_', ' ', $c['channel']))) ?></td>
                    <td>Rp <?= number_format($c['budget'], 0, ',', '.') ?></td>
                    <td style="font-size: 12px; white-space: nowrap;">
                        <?= $c['start_date'] ? date('d/m/Y', strtotime($c['start_date'])) : '-' ?>
                        s/d<br>
                        <?= $c['end_date'] ? date('d/m/Y', strtotime($c['end_date'])) : '-' ?>
                    </td>
                    <td><span class="status-badge <?= $statusClasses[$c['status']] ?? 'pending' ?>"><?= $statusLabels[$c['status']] ?? $c['status'] ?></span></td>
                    <td>
                        <div style="display: flex; gap: 4px;">
                            <a href="marketing.php?edit=<?= $c['id'] ?>" class="btn btn-outline btn-sm" title="Edit"><i class="fas fa-edit"></i></a>
                            <a href="marketing.php?toggle=<?= $c['id'] ?>&csrf_token=<?= csrfToken() ?>" class="btn btn-outline btn-sm" title="<?= $c['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?>"><i class="fas <?= $c['is_active'] ? 'fa-pause' : 'fa-play' ?>"></i></a>
                            <a href="marketing.php?delete=<?= $c['id'] ?>&csrf_token=<?= csrfToken() ?>" class="btn btn-outline btn-sm" title="Hapus" style="color: #EF4444; border-color: #EF4444;" onclick="return confirm('Hapus kampanye ini?')"><i class="fas fa-trash"></i></a>
                        </div>
                    </td>
                </tr>
                <?php endwhile; else: ?>
                <tr><td colspan="6" style="text-align: center; padding: 40px; color: var(--text-muted);">Belum ada kampanye</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ============ BROADCAST NEWSLETTER ============ -->
<div class="admin-card">
    <h3 class="admin-card-title"><i class="fas fa-paper-plane" style="color: var(--soft-gold);"></i> Kirim Newsletter ke Subscriber</h3>
    <form method="POST">
        <input type="hidden" name="send_newsletter" value="1">
        <?= csrfField() ?>
        <div class="form-group">
            <label class="form-label">Subjek Email</label>
            <input type="text" name="newsletter_subject" class="form-input" required maxlength="150"
                   placeholder="Contoh: Promo Spesial Akhir Pekan 🎉">
        </div>
        <div class="form-group">
            <label class="form-label">Isi Pesan</label>
            <textarea name="newsletter_message" class="form-textarea" rows="6" required
                      placeholder="Tulis promosi / info terbaru... (teks biasa; baris kosong = paragraf baru)"></textarea>
            <small style="color: var(--text-muted); font-size: 11px;">Hanya subscriber <strong>aktif</strong> yang menerima. Gmail membatasi ±500 email/hari — untuk daftar sangat besar sebaiknya pakai layanan khusus newsletter.</small>
        </div>
        <button type="submit" class="btn btn-primary"
                onclick="return confirm('Kirim newsletter ini ke semua subscriber aktif?')">
            <i class="fas fa-paper-plane"></i> Kirim ke <?= number_format($subscriberCount) ?> Subscriber
        </button>
    </form>
</div>

<!-- ============ NEWSLETTER SUBSCRIBERS ============ -->
<div class="admin-card">
    <h3 class="admin-card-title"><i class="fas fa-envelope" style="color: var(--soft-gold);"></i> Subscriber Newsletter (<?= $subscribers ? $subscribers->num_rows : 0 ?>)</h3>
    <div style="overflow-x: auto;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Email</th>
                    <th>Status</th>
                    <th>Bergabung</th>
                    <?php if (hasPermission('marketing', 'delete')): ?><th style="width: 80px;">Aksi</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if ($subscribers && $subscribers->num_rows > 0): while ($s = $subscribers->fetch_assoc()): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($s['email']) ?></strong></td>
                    <td><span class="status-badge <?= $s['is_active'] ? 'active' : 'inactive' ?>"><?= $s['is_active'] ? 'Aktif' : 'Berhenti' ?></span></td>
                    <td style="font-size: 12px;"><?= date('d/m/Y', strtotime($s['subscribed_at'])) ?></td>
                    <?php if (hasPermission('marketing', 'delete')): ?>
                    <td>
                        <a href="marketing.php?delete_subscriber=<?= $s['id'] ?>&csrf_token=<?= csrfToken() ?>" class="btn btn-outline btn-sm" title="Hapus" style="color: #EF4444; border-color: #EF4444;" onclick="return confirm('Hapus subscriber ini?')"><i class="fas fa-trash"></i></a>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endwhile; else: ?>
                <tr><td colspan="4" style="text-align: center; padding: 40px; color: var(--text-muted);">Belum ada subscriber</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
        </main>
    </div>
</body>
</html>
