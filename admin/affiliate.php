<?php
$currentPage = 'affiliate';
$pageTitle = 'Affiliate & Reseller';
require_once __DIR__ . '/../config/rbac.php';

requirePermission('affiliate', 'view');
$conn = getConnection();

$errors = [];
$success = '';
$info = '';

// ============================================
// ACTION: Simpan afiliasi (tambah/edit)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_affiliate'])) {
    verifyCsrf();
    $editId = (int)($_POST['id'] ?? 0);
    requirePermission('affiliate', $editId > 0 ? 'edit' : 'create');

    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $type = in_array($_POST['type'] ?? '', ['affiliate', 'reseller'], true) ? $_POST['type'] : 'affiliate';
    $referralCode = strtoupper(trim($_POST['referral_code'] ?? ''));
    $commissionRate = (float)($_POST['commission_rate'] ?? 10);

    if ($name === '') {
        $errors[] = 'Nama wajib diisi';
    }
    if ($referralCode === '') {
        $referralCode = 'NN' . strtoupper(substr(md5($name . time()), 0, 6));
    }
    if (!preg_match('/^[A-Z0-9_-]{2,50}$/', $referralCode)) {
        $errors[] = 'Kode referral hanya huruf besar, angka, _ atau - (2-50 karakter)';
    }

    if (empty($errors)) {
        $name_e = $conn->real_escape_string($name);
        $email_e = $conn->real_escape_string($email);
        $phone_e = $conn->real_escape_string($phone);

        $dup = $conn->query("SELECT id FROM affiliates WHERE referral_code = '$referralCode'" . ($editId > 0 ? " AND id != $editId" : "") . " LIMIT 1");
        if ($dup && $dup->num_rows > 0) {
            $errors[] = 'Kode referral sudah dipakai';
        } else {
            if ($editId > 0) {
                $conn->query(
                    "UPDATE affiliates SET name = '$name_e', email = '$email_e', phone = '$phone_e', type = '$type',
                     referral_code = '$referralCode', commission_rate = $commissionRate WHERE id = $editId"
                );
                $success = 'Data afiliasi berhasil diperbarui!';
                logActivity('update', 'affiliate', "Mengubah afiliasi: $name");
            } else {
                $conn->query(
                    "INSERT INTO affiliates (name, email, phone, type, referral_code, commission_rate)
                     VALUES ('$name_e', '$email_e', '$phone_e', '$type', '$referralCode', $commissionRate)"
                );
                $success = 'Afiliasi berhasil ditambahkan!';
                logActivity('create', 'affiliate', "Menambahkan afiliasi: $name");
            }
            header('Location: affiliate.php');
            exit;
        }
    }
}

// ============================================
// ACTION: Sesuaikan saldo komisi
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['adjust_balance'])) {
    verifyCsrf();
    requirePermission('affiliate', 'edit');
    $aid = (int)($_POST['id'] ?? 0);
    $amount = (float)($_POST['amount'] ?? 0);
    $note = trim($_POST['note'] ?? '');
    $conn->query("UPDATE affiliates SET balance = balance + $amount WHERE id = $aid");
    $success = 'Saldo komisi disesuaikan (' . ($amount >= 0 ? '+' : '') . number_format($amount, 0, ',', '.') . ').';
    logActivity('edit', 'affiliate', "Adjust saldo afiliasi #$aid ($amount): $note");
    header('Location: affiliate.php');
    exit;
}

// ============================================
// ACTION: Toggle status
// ============================================
if (isset($_GET['toggle'])) {
    verifyCsrf();
    requirePermission('affiliate', 'edit');
    $aid = (int)$_GET['toggle'];
    $conn->query("UPDATE affiliates SET status = IF(status = 'active', 'inactive', 'active') WHERE id = $aid");
    logActivity('edit', 'affiliate', "Toggle status afiliasi #$aid");
    header('Location: affiliate.php');
    exit;
}

// ============================================
// ACTION: Hapus
// ============================================
if (isset($_GET['delete'])) {
    verifyCsrf();
    requirePermission('affiliate', 'delete');
    $aid = (int)$_GET['delete'];
    $conn->query("DELETE FROM affiliates WHERE id = $aid");
    $success = 'Afiliasi dihapus.';
    logActivity('delete', 'affiliate', "Menghapus afiliasi #$aid");
    header('Location: affiliate.php');
    exit;
}

// ============================================
// DATA
// ============================================
$statusFilter = $_GET['status'] ?? '';
$typeFilter = $_GET['type'] ?? '';
$where = "WHERE 1=1";
if ($statusFilter) {
    $s = $conn->real_escape_string($statusFilter);
    $where .= " AND status = '$s'";
}
if ($typeFilter) {
    $s = $conn->real_escape_string($typeFilter);
    $where .= " AND type = '$s'";
}
$affiliates = $conn->query("SELECT * FROM affiliates $where ORDER BY created_at DESC");

$activeCount = (int)$conn->query("SELECT COUNT(*) c FROM affiliates WHERE status = 'active'")->fetch_assoc()['c'];
$resellerCount = (int)$conn->query("SELECT COUNT(*) c FROM affiliates WHERE type = 'reseller' AND status = 'active'")->fetch_assoc()['c'];
$totalBalance = (float)$conn->query("SELECT COALESCE(SUM(balance),0) c FROM affiliates WHERE status = 'active'")->fetch_assoc()['c'];
$avgRate = (float)$conn->query("SELECT COALESCE(AVG(commission_rate),0) c FROM affiliates WHERE status = 'active'")->fetch_assoc()['c'];

$editAffiliate = null;
if (isset($_GET['edit'])) {
    $aid = (int)$_GET['edit'];
    $r = $conn->query("SELECT * FROM affiliates WHERE id = $aid LIMIT 1");
    if ($r && $r->num_rows > 0) $editAffiliate = $r->fetch_assoc();
}

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
    <div class="stat-card"><div class="stat-card-header"><div class="stat-card-icon success"><i class="fas fa-handshake"></i></div><div><div class="stat-card-value"><?= $activeCount ?></div></div></div><div class="stat-card-label">Afiliasi Aktif</div></div>
    <div class="stat-card"><div class="stat-card-header"><div class="stat-card-icon info"><i class="fas fa-store"></i></div><div><div class="stat-card-value"><?= $resellerCount ?></div></div></div><div class="stat-card-label">Reseller Aktif</div></div>
    <div class="stat-card"><div class="stat-card-header"><div class="stat-card-icon warning"><i class="fas fa-wallet"></i></div><div><div class="stat-card-value" style="font-size: 18px;">Rp <?= number_format($totalBalance, 0, ',', '.') ?></div></div></div><div class="stat-card-label">Total Komisi Belum Dicairkan</div></div>
    <div class="stat-card"><div class="stat-card-header"><div class="stat-card-icon"><i class="fas fa-percent"></i></div><div><div class="stat-card-value"><?= number_format($avgRate, 1, ',', '.') ?>%</div></div></div><div class="stat-card-label">Rata-rata Komisi</div></div>
</div>

<!-- ============ FORM AFILIASI ============ -->
<?php if (hasPermission('affiliate', 'create') || hasPermission('affiliate', 'edit')): ?>
<div class="admin-card">
    <h3 class="admin-card-title"><i class="fas fa-user-plus" style="color: var(--soft-gold);"></i> <?= $editAffiliate ? 'Edit Afiliasi' : 'Tambah Afiliasi / Reseller' ?></h3>
    <form method="POST">
        <input type="hidden" name="save_affiliate" value="1">
        <input type="hidden" name="id" value="<?= $editAffiliate['id'] ?? 0 ?>">
        <?= csrfField() ?>
        <div class="form-row">
            <div class="form-group" style="flex: 2;">
                <label class="form-label">Nama <span style="color: #EF4444;">*</span></label>
                <input type="text" name="name" class="form-input" required value="<?= htmlspecialchars($editAffiliate['name'] ?? '') ?>" placeholder="Nama partner / toko">
            </div>
            <div class="form-group">
                <label class="form-label">Tipe</label>
                <select name="type" class="form-select">
                    <option value="affiliate" <?= ($editAffiliate['type'] ?? '') === 'affiliate' ? 'selected' : '' ?>>Affiliate</option>
                    <option value="reseller" <?= ($editAffiliate['type'] ?? '') === 'reseller' ? 'selected' : '' ?>>Reseller</option>
                </select>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-input" value="<?= htmlspecialchars($editAffiliate['email'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Telepon / WA</label>
                <input type="text" name="phone" class="form-input" value="<?= htmlspecialchars($editAffiliate['phone'] ?? '') ?>">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Kode Referral</label>
                <input type="text" name="referral_code" class="form-input" value="<?= htmlspecialchars($editAffiliate['referral_code'] ?? '') ?>" placeholder="Kosongkan untuk otomatis" style="text-transform: uppercase;">
            </div>
            <div class="form-group">
                <label class="form-label">Komisi (%)</label>
                <input type="number" name="commission_rate" class="form-input" min="0" max="100" step="0.01" value="<?= $editAffiliate['commission_rate'] ?? 10 ?>">
            </div>
        </div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> <?= $editAffiliate ? 'Simpan Perubahan' : 'Tambah Afiliasi' ?></button>
        <?php if ($editAffiliate): ?><a href="affiliate.php" class="btn btn-outline">Batal</a><?php endif; ?>
    </form>
</div>
<?php endif; ?>

<!-- ============ DAFTAR AFILIASI ============ -->
<div class="admin-card">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 16px;">
        <h3 class="admin-card-title" style="margin: 0;">Daftar Afiliasi (<?= $affiliates ? $affiliates->num_rows : 0 ?>)</h3>
        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
            <a href="affiliate.php" class="btn btn-outline btn-sm <?= !$statusFilter && !$typeFilter ? 'btn-secondary' : '' ?>">Semua</a>
            <a href="affiliate.php?status=active" class="btn btn-outline btn-sm <?= $statusFilter === 'active' ? 'btn-secondary' : '' ?>">Aktif</a>
            <a href="affiliate.php?type=reseller" class="btn btn-outline btn-sm <?= $typeFilter === 'reseller' ? 'btn-secondary' : '' ?>">Reseller</a>
        </div>
    </div>
    <div style="overflow-x: auto;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Partner</th>
                    <th>Kode Referral</th>
                    <th>Komisi</th>
                    <th>Saldo</th>
                    <th>Status</th>
                    <th>Bergabung</th>
                    <th style="width: 180px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($affiliates && $affiliates->num_rows > 0): while ($a = $affiliates->fetch_assoc()): ?>
                <tr>
                    <td>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <div class="admin-avatar" style="width: 32px; height: 32px; font-size: 13px; background: rgba(212,168,83,0.15); color: var(--soft-gold);"><?= strtoupper(substr($a['name'], 0, 1)) ?></div>
                            <div>
                                <strong><?= htmlspecialchars($a['name']) ?></strong><br>
                                <small style="color: var(--text-light);"><?= htmlspecialchars($a['email'] ?: $a['phone'] ?: '-') ?></small>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span style="font-family: monospace; background: rgba(212,168,83,0.12); color: var(--soft-gold); padding: 3px 8px; border-radius: 6px; font-size: 12px;"><?= htmlspecialchars($a['referral_code']) ?></span>
                        <br><small style="font-size: 10px; color: var(--text-light);"><?= $a['type'] === 'reseller' ? 'Reseller' : 'Affiliate' ?></small>
                    </td>
                    <td><strong><?= number_format($a['commission_rate'], 1, ',', '.') ?>%</strong></td>
                    <td><strong style="color: var(--soft-gold);">Rp <?= number_format($a['balance'], 0, ',', '.') ?></strong></td>
                    <td><span class="status-badge <?= $a['status'] ?>"><?= $a['status'] === 'active' ? 'Aktif' : 'Nonaktif' ?></span></td>
                    <td style="font-size: 12px;"><?= date('d/m/Y', strtotime($a['created_at'])) ?></td>
                    <td>
                        <div style="display: flex; gap: 4px; flex-wrap: wrap;">
                            <a href="affiliate.php?edit=<?= $a['id'] ?>" class="btn btn-outline btn-sm" title="Edit"><i class="fas fa-edit"></i></a>
                            <a href="affiliate.php?toggle=<?= $a['id'] ?>&csrf_token=<?= csrfToken() ?>" class="btn btn-outline btn-sm" title="<?= $a['status'] === 'active' ? 'Nonaktifkan' : 'Aktifkan' ?>"><i class="fas <?= $a['status'] === 'active' ? 'fa-eye-slash' : 'fa-eye' ?>"></i></a>
                            <?php if (hasPermission('affiliate', 'edit')): ?>
                            <button class="btn btn-outline btn-sm" title="Sesuaikan Saldo" onclick="document.getElementById('balance-<?= $a['id'] ?>').showModal ? document.getElementById('balance-<?= $a['id'] ?>').showModal() : alert('Modal tidak didukung');"><i class="fas fa-coins"></i></button>
                            <?php endif; ?>
                            <a href="affiliate.php?delete=<?= $a['id'] ?>&csrf_token=<?= csrfToken() ?>" class="btn btn-outline btn-sm" title="Hapus" style="color: #EF4444; border-color: #EF4444;" onclick="return confirm('Hapus afiliasi ini?')"><i class="fas fa-trash"></i></a>
                        </div>

                        <?php if (hasPermission('affiliate', 'edit')): ?>
                        <dialog id="balance-<?= $a['id'] ?>" style="border: none; border-radius: 12px; padding: 24px; box-shadow: 0 20px 60px rgba(0,0,0,0.2);">
                            <form method="POST" style="min-width: 300px;">
                                <h4 style="margin: 0 0 12px; color: var(--text-dark);">Sesuaikan Saldo — <?= htmlspecialchars($a['name']) ?></h4>
                                <input type="hidden" name="adjust_balance" value="1">
                                <input type="hidden" name="id" value="<?= $a['id'] ?>">
                                <?= csrfField() ?>
                                <div class="form-group">
                                    <label class="form-label">Jumlah (+/-)</label>
                                    <input type="number" name="amount" class="form-input" step="0.01" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Catatan</label>
                                    <input type="text" name="note" class="form-input" placeholder="Contoh: Pencairan komisi">
                                </div>
                                <div style="display: flex; gap: 8px; margin-top: 16px;">
                                    <button type="submit" class="btn btn-primary btn-sm">Terapkan</button>
                                    <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('balance-<?= $a['id'] ?>').close()">Tutup</button>
                                </div>
                            </form>
                        </dialog>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endwhile; else: ?>
                <tr><td colspan="7" style="text-align: center; padding: 40px; color: var(--text-muted);">Belum ada afiliasi</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
        </main>
    </div>
</body>
</html>
