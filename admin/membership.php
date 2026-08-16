<?php
$currentPage = 'membership';
$pageTitle = 'Membership';
require_once __DIR__ . '/../config/rbac.php';

requirePermission('membership', 'view');
$conn = getConnection();

$errors = [];
$success = '';
$info = '';
$levelFilter = $_GET['level'] ?? '';

// ============================================
// ACTION: Simpan benefit membership
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_benefit'])) {
    verifyCsrf();
    $editId = (int)($_POST['id'] ?? 0);
    requirePermission('membership', $editId > 0 ? 'edit' : 'create');

    $level = in_array($_POST['level'] ?? '', ['silver', 'gold', 'platinum', 'diamond'], true) ? $_POST['level'] : 'silver';
    $benefitName = trim($_POST['benefit_name'] ?? '');
    $benefitDesc = trim($_POST['benefit_description'] ?? '');
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if (empty($benefitName)) {
        $errors[] = 'Nama benefit wajib diisi';
    } else {
        $bn_e = $conn->real_escape_string($benefitName);
        $bd_e = $conn->real_escape_string($benefitDesc);
        if ($editId > 0) {
            $conn->query("UPDATE membership_benefits SET membership_level = '$level', benefit_name = '$bn_e', benefit_description = '$bd_e', is_active = $isActive WHERE id = $editId");
            $success = 'Benefit berhasil diperbarui!';
            logActivity('update', 'membership', "Mengubah benefit: $benefitName");
        } else {
            $conn->query("INSERT INTO membership_benefits (membership_level, benefit_name, benefit_description, is_active) VALUES ('$level', '$bn_e', '$bd_e', $isActive)");
            $success = 'Benefit berhasil ditambahkan!';
            logActivity('create', 'membership', "Menambahkan benefit: $benefitName");
        }
        header('Location: membership.php');
        exit;
    }
}

// ============================================
// ACTION: Toggle benefit
// ============================================
if (isset($_GET['toggle'])) {
    verifyCsrf();
    requirePermission('membership', 'edit');
    $bid = (int)$_GET['toggle'];
    $conn->query("UPDATE membership_benefits SET is_active = NOT is_active WHERE id = $bid");
    logActivity('edit', 'membership', "Toggle benefit #$bid");
    header('Location: membership.php');
    exit;
}

// ============================================
// ACTION: Hapus benefit
// ============================================
if (isset($_GET['delete'])) {
    verifyCsrf();
    requirePermission('membership', 'delete');
    $bid = (int)$_GET['delete'];
    $conn->query("DELETE FROM membership_benefits WHERE id = $bid");
    $success = 'Benefit berhasil dihapus.';
    logActivity('delete', 'membership', "Menghapus benefit #$bid");
    header('Location: membership.php');
    exit;
}

// ============================================
// ACTION: Ganti level member
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_level'])) {
    verifyCsrf();
    requirePermission('membership', 'edit');
    $userId = (int)($_POST['user_id'] ?? 0);
    $level = in_array($_POST['level'] ?? '', ['silver', 'gold', 'platinum', 'diamond'], true) ? $_POST['level'] : 'silver';
    $conn->query("UPDATE users SET membership = '$level' WHERE id = $userId");
    $success = 'Level member #' . $userId . ' diubah menjadi ' . ucfirst($level) . '.';
    logActivity('edit', 'membership', "Ubah level user #$userId menjadi $level");
    header('Location: membership.php' . ($levelFilter ? "?level=" . urlencode($levelFilter) : ''));
    exit;
}

// ============================================
// ACTION: Simpan paket membership (berbayar)
// ============================================
$planLevels = ['gold' => 'Gold', 'platinum' => 'Platinum', 'diamond' => 'Diamond'];
$planPeriods = ['monthly' => 'Bulanan', 'yearly' => 'Tahunan'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_plan'])) {
    verifyCsrf();
    $editPlanId = (int)($_POST['id'] ?? 0);
    requirePermission('membership', $editPlanId > 0 ? 'edit' : 'create');

    $level = in_array($_POST['level'] ?? '', array_keys($planLevels), true) ? $_POST['level'] : 'gold';
    $period = in_array($_POST['period'] ?? '', array_keys($planPeriods), true) ? $_POST['period'] : 'monthly';
    $price = max(0, (float)($_POST['price'] ?? 0));
    $duration = max(1, (int)($_POST['duration_days'] ?? 30));
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if ($price <= 0) {
        $errors[] = 'Harga paket wajib diisi lebih dari 0';
    } elseif ($editPlanId > 0) {
        $dup = $conn->query("SELECT id FROM membership_plans WHERE level = '$level' AND period = '$period' AND id != $editPlanId LIMIT 1");
        if ($dup && $dup->num_rows > 0) {
            $errors[] = 'Paket untuk level & periode tersebut sudah ada';
        } else {
            $conn->query("UPDATE membership_plans SET level = '$level', period = '$period', price = $price, duration_days = $duration, is_active = $isActive WHERE id = $editPlanId");
            $success = 'Paket membership berhasil diperbarui!';
            logActivity('update', 'membership', "Ubah paket membership #$editPlanId ($level $period)");
            ensurePlanProducts();
            header('Location: membership.php#plans');
            exit;
        }
    } else {
        $chk = $conn->query("SELECT id FROM membership_plans WHERE level = '$level' AND period = '$period' LIMIT 1");
        if ($chk && $chk->num_rows > 0) {
            $errors[] = 'Paket untuk level & periode tersebut sudah ada';
        } else {
            $conn->query("INSERT INTO membership_plans (level, period, price, duration_days, is_active) VALUES ('$level', '$period', $price, $duration, $isActive)");
            $success = 'Paket membership berhasil ditambahkan!';
            logActivity('create', 'membership', "Tambah paket $level $period");
            ensurePlanProducts();
            header('Location: membership.php#plans');
            exit;
        }
    }
}

// ============================================
// ACTION: Toggle paket membership
// ============================================
if (isset($_GET['toggle_plan'])) {
    verifyCsrf();
    requirePermission('membership', 'edit');
    $pid = (int)$_GET['toggle_plan'];
    $conn->query("UPDATE membership_plans SET is_active = NOT is_active WHERE id = $pid");
    ensurePlanProducts();
    logActivity('edit', 'membership', "Toggle paket membership #$pid");
    header('Location: membership.php#plans');
    exit;
}

// ============================================
// ACTION: Atur poin member
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['adjust_points'])) {
    verifyCsrf();
    requirePermission('membership', 'edit');
    $userId = (int)($_POST['user_id'] ?? 0);
    $points = (int)($_POST['points'] ?? 0);
    $conn->query("UPDATE users SET points = points + $points WHERE id = $userId");
    logPointHistory($userId, $points, 'adjusted', 'Penyesuaian poin oleh admin', 0);
    $success = "Poin member #$userId disesuaikan (+$points).";
    logActivity('edit', 'membership', "Adjust poin user #$userId ($points)");
    header('Location: membership.php');
    exit;
}

// ============================================
// DATA
// ============================================
// Member = pelanggan + akun admin (super admin/owner juga member, mis. level diamond)
$where = "WHERE role IN ('customer', 'admin')";
if ($levelFilter) {
    $s = $conn->real_escape_string($levelFilter);
    $where .= " AND membership = '$s'";
}
$members = $conn->query("SELECT * FROM users $where ORDER BY total_spent DESC LIMIT 200");

$benefits = $conn->query("SELECT * FROM membership_benefits ORDER BY FIELD(membership_level,'silver','gold','platinum','diamond'), id");
$levels = ['silver' => 0, 'gold' => 0, 'platinum' => 0, 'diamond' => 0];
foreach ($levels as $lv => &$v) {
    $v = (int)$conn->query("SELECT COUNT(*) c FROM users WHERE role IN ('customer','admin') AND membership = '$lv'")->fetch_assoc()['c'];
}
$totalMembers = (int)$conn->query("SELECT COUNT(*) c FROM users WHERE role IN ('customer','admin')")->fetch_assoc()['c'];
$totalPoints = (int)$conn->query("SELECT COALESCE(SUM(points),0) c FROM users WHERE role IN ('customer','admin')")->fetch_assoc()['c'];

$editBenefit = null;
if (isset($_GET['edit'])) {
    $bid = (int)$_GET['edit'];
    $r = $conn->query("SELECT * FROM membership_benefits WHERE id = $bid LIMIT 1");
    if ($r && $r->num_rows > 0) $editBenefit = $r->fetch_assoc();
}

// Paket membership berbayar
ensurePlanProducts();
$plans = $conn->query("SELECT * FROM membership_plans ORDER BY FIELD(level,'gold','platinum','diamond'), FIELD(period,'monthly','yearly'), id");
$purchases = $conn->query("SELECT mp.*, u.full_name, u.email FROM membership_purchases mp LEFT JOIN users u ON u.id = mp.user_id ORDER BY mp.created_at DESC LIMIT 30");
$activeSubs = (int)$conn->query("SELECT COUNT(*) c FROM membership_purchases WHERE status = 'active' AND expires_at > NOW()")->fetch_assoc()['c'];

// Riwayat poin (bisa difilter per member via ?ph_user=ID)
$phUser = (int)($_GET['ph_user'] ?? 0);
$phWhere = $phUser > 0 ? "WHERE ph.user_id = $phUser" : '';
$pointHistory = $conn->query("SELECT ph.*, u.full_name, u.email FROM point_history ph LEFT JOIN users u ON u.id = ph.user_id $phWhere ORDER BY ph.id DESC LIMIT 30");

$editPlan = null;
if (isset($_GET['edit_plan'])) {
    $pid = (int)$_GET['edit_plan'];
    $r = $conn->query("SELECT * FROM membership_plans WHERE id = $pid LIMIT 1");
    if ($r && $r->num_rows > 0) $editPlan = $r->fetch_assoc();
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
    <div class="stat-card"><div class="stat-card-header"><div class="stat-card-icon"><i class="fas fa-users"></i></div><div><div class="stat-card-value"><?= number_format($totalMembers) ?></div></div></div><div class="stat-card-label">Total Member</div></div>
    <div class="stat-card"><div class="stat-card-header"><div class="stat-card-icon info"><i class="fas fa-star"></i></div><div><div class="stat-card-value"><?= number_format($totalPoints) ?></div></div></div><div class="stat-card-label">Total Poin Beredar</div></div>
    <?php foreach ($levels as $lv => $count): ?>
    <div class="stat-card"><div class="stat-card-header"><div class="stat-card-icon"><i class="fas fa-crown"></i></div><div><div class="stat-card-value" style="font-size: 22px;"><?= $count ?></div></div></div><div class="stat-card-label"><span class="status-badge <?= $lv ?>"><?= ucfirst($lv) ?></span></div></div>
    <?php endforeach; ?>
</div>

<!-- ============ FORM BENEFIT ============ -->
<?php if (hasPermission('membership', 'create') || hasPermission('membership', 'edit')): ?>
<div class="admin-card">
    <h3 class="admin-card-title"><?= $editBenefit ? 'Edit Benefit' : 'Tambah Benefit Membership' ?></h3>
    <form method="POST" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end;">
        <input type="hidden" name="save_benefit" value="1">
        <input type="hidden" name="id" value="<?= $editBenefit['id'] ?? 0 ?>">
        <?= csrfField() ?>
        <div class="form-group" style="margin-bottom: 0; min-width: 130px;">
            <label class="form-label">Level</label>
            <select name="level" class="form-select">
                <?php foreach (array_keys($levels) as $lv): ?>
                <option value="<?= $lv ?>" <?= ($editBenefit['membership_level'] ?? '') === $lv ? 'selected' : '' ?>><?= ucfirst($lv) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="flex: 1; min-width: 180px; margin-bottom: 0;">
            <label class="form-label">Nama Benefit <span style="color: #EF4444;">*</span></label>
            <input type="text" name="benefit_name" class="form-input" required value="<?= htmlspecialchars($editBenefit['benefit_name'] ?? '') ?>" placeholder="Contoh: Voucher 10%">
        </div>
        <div class="form-group" style="flex: 2; min-width: 200px; margin-bottom: 0;">
            <label class="form-label">Deskripsi</label>
            <input type="text" name="benefit_description" class="form-input" value="<?= htmlspecialchars($editBenefit['benefit_description'] ?? '') ?>">
        </div>
        <div class="form-group" style="margin-bottom: 0;">
            <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                <input type="checkbox" name="is_active" value="1" style="accent-color: var(--soft-gold);" <?= (!isset($editBenefit) || $editBenefit['is_active']) ? 'checked' : '' ?>>
                Aktif
            </label>
        </div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> <?= $editBenefit ? 'Simpan' : 'Tambah' ?></button>
        <?php if ($editBenefit): ?><a href="membership.php" class="btn btn-outline">Batal</a><?php endif; ?>
    </form>
</div>
<?php endif; ?>

<!-- ============ DAFTAR BENEFIT ============ -->
<div class="admin-card">
    <h3 class="admin-card-title">Benefit per Level (<?= $benefits ? $benefits->num_rows : 0 ?>)</h3>
    <div style="overflow-x: auto;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Level</th>
                    <th>Benefit</th>
                    <th>Deskripsi</th>
                    <th>Status</th>
                    <th style="width: 130px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($benefits && $benefits->num_rows > 0): while ($b = $benefits->fetch_assoc()): ?>
                <tr>
                    <td><span class="status-badge <?= $b['membership_level'] ?>"><?= ucfirst($b['membership_level']) ?></span></td>
                    <td><strong><?= htmlspecialchars($b['benefit_name']) ?></strong></td>
                    <td style="font-size: 12px; color: var(--text-muted);"><?= htmlspecialchars($b['benefit_description'] ?: '-') ?></td>
                    <td><span class="status-badge <?= $b['is_active'] ? 'active' : 'inactive' ?>"><?= $b['is_active'] ? 'Aktif' : 'Nonaktif' ?></span></td>
                    <td>
                        <div style="display: flex; gap: 4px;">
                            <a href="membership.php?edit=<?= $b['id'] ?>" class="btn btn-outline btn-sm"><i class="fas fa-edit"></i></a>
                            <a href="membership.php?toggle=<?= $b['id'] ?>&csrf_token=<?= csrfToken() ?>" class="btn btn-outline btn-sm"><i class="fas <?= $b['is_active'] ? 'fa-eye-slash' : 'fa-eye' ?>"></i></a>
                            <a href="membership.php?delete=<?= $b['id'] ?>&csrf_token=<?= csrfToken() ?>" class="btn btn-outline btn-sm" style="color: #EF4444; border-color: #EF4444;" onclick="return confirm('Hapus benefit ini?')"><i class="fas fa-trash"></i></a>
                        </div>
                    </td>
                </tr>
                <?php endwhile; else: ?>
                <tr><td colspan="5" style="text-align: center; padding: 30px; color: var(--text-muted);">Belum ada benefit</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ============ PAKET MEMBERSHIP (BERBAYAR) ============ -->
<div class="admin-card" id="plans">
    <h3 class="admin-card-title"><i class="fas fa-crown" style="color: var(--soft-gold);"></i> Paket Membership Dijual</h3>
    <p style="font-size: 12px; color: var(--text-muted); margin-bottom: 16px;">
        Paket dibeli lewat sistem order & konfirmasi bayar. Langganan aktif otomatis saat pembayaran diverifikasi. Produk terkait dibuat otomatis & disembunyikan dari katalog.
    </p>

    <?php if (hasPermission('membership', 'create') || hasPermission('membership', 'edit')): ?>
    <form method="POST" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; margin-bottom: 16px; padding: 16px; background: var(--bg-cream); border-radius: 12px;">
        <input type="hidden" name="save_plan" value="1">
        <input type="hidden" name="id" value="<?= $editPlan['id'] ?? 0 ?>">
        <?= csrfField() ?>
        <div class="form-group" style="margin-bottom: 0; min-width: 120px;">
            <label class="form-label">Level</label>
            <select name="level" class="form-select">
                <?php foreach ($planLevels as $lv => $label): ?>
                <option value="<?= $lv ?>" <?= ($editPlan['level'] ?? '') === $lv ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin-bottom: 0; min-width: 120px;">
            <label class="form-label">Periode</label>
            <select name="period" class="form-select">
                <?php foreach ($planPeriods as $pv => $pl): ?>
                <option value="<?= $pv ?>" <?= ($editPlan['period'] ?? '') === $pv ? 'selected' : '' ?>><?= $pl ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin-bottom: 0; min-width: 140px;">
            <label class="form-label">Harga (Rp) <span style="color: #EF4444;">*</span></label>
            <input type="number" name="price" class="form-input" min="0" step="1000" required value="<?= $editPlan['price'] ?? '' ?>" placeholder="Contoh: 99000">
        </div>
        <div class="form-group" style="margin-bottom: 0; min-width: 120px;">
            <label class="form-label">Durasi (hari)</label>
            <input type="number" name="duration_days" class="form-input" min="1" value="<?= $editPlan['duration_days'] ?? 30 ?>">
        </div>
        <div class="form-group" style="margin-bottom: 0;">
            <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                <input type="checkbox" name="is_active" value="1" style="accent-color: var(--soft-gold);" <?= (!isset($editPlan) || $editPlan['is_active']) ? 'checked' : '' ?>>
                Aktif
            </label>
        </div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> <?= $editPlan ? 'Simpan' : 'Tambah Paket' ?></button>
        <?php if ($editPlan): ?><a href="membership.php#plans" class="btn btn-outline">Batal</a><?php endif; ?>
    </form>
    <?php endif; ?>

    <div style="overflow-x: auto;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Level</th>
                    <th>Periode</th>
                    <th>Harga</th>
                    <th>Durasi</th>
                    <th>Status</th>
                    <th style="width: 130px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($plans && $plans->num_rows > 0): while ($p = $plans->fetch_assoc()): ?>
                <tr>
                    <td><span class="status-badge <?= $p['level'] ?>"><?= ucfirst($p['level']) ?></span></td>
                    <td><?= $planPeriods[$p['period']] ?? ucfirst($p['period']) ?></td>
                    <td><strong>Rp <?= number_format($p['price'], 0, ',', '.') ?></strong></td>
                    <td><?= (int)$p['duration_days'] ?> hari</td>
                    <td><span class="status-badge <?= $p['is_active'] ? 'active' : 'inactive' ?>"><?= $p['is_active'] ? 'Aktif' : 'Nonaktif' ?></span></td>
                    <td>
                        <div style="display: flex; gap: 4px;">
                            <a href="membership.php?edit_plan=<?= $p['id'] ?>#plans" class="btn btn-outline btn-sm"><i class="fas fa-edit"></i></a>
                            <a href="membership.php?toggle_plan=<?= $p['id'] ?>&csrf_token=<?= csrfToken() ?>#plans" class="btn btn-outline btn-sm"><i class="fas <?= $p['is_active'] ? 'fa-eye-slash' : 'fa-eye' ?>"></i></a>
                        </div>
                    </td>
                </tr>
                <?php endwhile; else: ?>
                <tr><td colspan="6" style="text-align: center; padding: 30px; color: var(--text-muted);">Belum ada paket. Tambahkan paket membership di atas.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ============ RIWAYAT LANGGANAN ============ -->
<div class="admin-card">
    <h3 class="admin-card-title"><i class="fas fa-file-invoice" style="color: var(--soft-gold);"></i> Riwayat Langganan (30 terakhir)</h3>
    <p style="font-size: 12px; color: var(--text-muted); margin-bottom: 16px;">Langganan aktif saat ini: <strong style="color: #059669;"><?= $activeSubs ?></strong></p>
    <div style="overflow-x: auto;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Member</th>
                    <th>Paket</th>
                    <th>Harga</th>
                    <th>Mulai</th>
                    <th>Berakhir</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($purchases && $purchases->num_rows > 0): while ($pu = $purchases->fetch_assoc()): ?>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars($pu['full_name'] ?? ('#' . $pu['user_id'])) ?></strong>
                        <?php if ($pu['email']): ?><br><small style="color: var(--text-light);"><?= htmlspecialchars($pu['email']) ?></small><?php endif; ?>
                    </td>
                    <td><span class="status-badge <?= $pu['level'] ?>"><?= ucfirst($pu['level']) ?></span> <?= $pu['period'] === 'yearly' ? 'Tahunan' : 'Bulanan' ?></td>
                    <td>Rp <?= number_format($pu['price'], 0, ',', '.') ?></td>
                    <td style="font-size: 12px;"><?= $pu['starts_at'] ? date('d M Y', strtotime($pu['starts_at'])) : '-' ?></td>
                    <td style="font-size: 12px;"><?= $pu['expires_at'] ? date('d M Y', strtotime($pu['expires_at'])) : '-' ?></td>
                    <td><span class="status-badge <?= $pu['status'] ?>"><?= ucfirst($pu['status']) ?></span></td>
                </tr>
                <?php endwhile; else: ?>
                <tr><td colspan="6" style="text-align: center; padding: 30px; color: var(--text-muted);">Belum ada langganan</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ============ RIWAYAT POIN ============ -->
<div class="admin-card" id="point-history">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 16px;">
        <h3 class="admin-card-title" style="margin: 0;"><i class="fas fa-coins" style="color: var(--soft-gold);"></i> Riwayat Poin Member (30 terakhir)</h3>
        <?php if ($phUser > 0): ?>
        <a href="membership.php" class="btn btn-outline btn-sm"><i class="fas fa-times"></i> Tampilkan semua member</a>
        <?php endif; ?>
    </div>
    <div style="overflow-x: auto;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Member</th>
                    <th>Deskripsi</th>
                    <th>Poin</th>
                    <th>Saldo</th>
                    <th>Tipe</th>
                    <th>Waktu</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($pointHistory && $pointHistory->num_rows > 0): while ($ph = $pointHistory->fetch_assoc()):
                    $phPlus = (int)$ph['points'] > 0;
                    $phType = [
                        'earned'   => ['Poin Belanja', 'active'],
                        'spent'    => ['Tukar Diskon', 'inactive'],
                        'refunded' => ['Refund', 'delivered'],
                        'reversed' => ['Ditarik', 'cancelled'],
                        'adjusted' => ['Penyesuaian', 'pending'],
                    ][$ph['type']] ?? ['Transaksi', 'pending'];
                ?>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars($ph['full_name'] ?? ('#' . $ph['user_id'])) ?></strong>
                        <?php if ($ph['email']): ?><br><small style="color: var(--text-light);"><?= htmlspecialchars($ph['email']) ?></small><?php endif; ?>
                    </td>
                    <td style="font-size: 12px;"><?= htmlspecialchars($ph['description'] ?: '-') ?></td>
                    <td><strong style="color: <?= $phPlus ? '#059669' : '#DC2626' ?>;"><?= $phPlus ? '+' : '' ?><?= number_format((int)$ph['points']) ?></strong></td>
                    <td style="font-size: 12px;"><?= number_format((int)$ph['balance_after']) ?></td>
                    <td><span class="status-badge <?= $phType[1] ?>"><?= $phType[0] ?></span></td>
                    <td style="font-size: 12px; white-space: nowrap;"><?= date('d M Y H:i', strtotime($ph['created_at'])) ?></td>
                </tr>
                <?php endwhile; else: ?>
                <tr><td colspan="6" style="text-align: center; padding: 30px; color: var(--text-muted);">Belum ada riwayat poin</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ============ DAFTAR MEMBER ============ -->
<div class="admin-card">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 16px;">
        <h3 class="admin-card-title" style="margin: 0;">Daftar Member</h3>
        <div style="display: flex; gap: 6px; flex-wrap: wrap;">
            <a href="membership.php" class="btn btn-outline btn-sm <?= !$levelFilter ? 'btn-secondary' : '' ?>">Semua</a>
            <?php foreach (array_keys($levels) as $lv): ?>
            <a href="membership.php?level=<?= $lv ?>" class="btn btn-outline btn-sm <?= $levelFilter === $lv ? 'btn-secondary' : '' ?>"><?= ucfirst($lv) ?></a>
            <?php endforeach; ?>
        </div>
    </div>
    <div style="overflow-x: auto;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Member</th>
                    <th>Level</th>
                    <th>Poin</th>
                    <th>Total Belanja</th>
                    <th>Bergabung</th>
                    <?php if (hasPermission('membership', 'edit')): ?>
                    <th style="width: 150px;">Ganti Level</th>
                    <?php endif; ?>
                    <th style="width: 220px;">Atur Poin</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($members && $members->num_rows > 0): while ($m = $members->fetch_assoc()): ?>
                <tr>
                    <td>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <div class="admin-avatar" style="width: 32px; height: 32px; font-size: 13px;"><?= strtoupper(substr($m['full_name'], 0, 1)) ?></div>
                            <div>
                                <strong><?= htmlspecialchars($m['full_name']) ?></strong><br>
                                <small style="color: var(--text-light);"><?= htmlspecialchars($m['email']) ?></small>
                            </div>
                        </div>
                    </td>
                    <td><span class="status-badge <?= $m['membership'] ?>"><?= ucfirst($m['membership']) ?></span></td>
                    <td>
                        <strong><?= number_format((int)$m['points']) ?></strong>
                        <a href="membership.php?ph_user=<?= $m['id'] ?>#point-history" title="Lihat riwayat poin" style="margin-left: 6px; color: var(--soft-gold);"><i class="fas fa-history"></i></a>
                    </td>
                    <td>Rp <?= number_format($m['total_spent'], 0, ',', '.') ?></td>
                    <td style="font-size: 12px;"><?= date('d M Y', strtotime($m['created_at'])) ?></td>
                    <?php if (hasPermission('membership', 'edit')): ?>
                    <td>
                        <form method="POST" style="display: flex; gap: 4px;" onsubmit="return confirm('Ubah level <?= htmlspecialchars($m['full_name'], ENT_QUOTES) ?>?')">
                            <input type="hidden" name="change_level" value="1">
                            <input type="hidden" name="user_id" value="<?= $m['id'] ?>">
                            <?= csrfField() ?>
                            <select name="level" class="form-select" style="width: 105px; padding: 6px 8px; font-size: 12px;">
                                <?php foreach (array_keys($levels) as $lv): ?>
                                <option value="<?= $lv ?>" <?= $m['membership'] === $lv ? 'selected' : '' ?>><?= ucfirst($lv) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-primary btn-sm" title="Simpan level"><i class="fas fa-check"></i></button>
                        </form>
                    </td>
                    <?php endif; ?>
                    <td>
                        <?php if (hasPermission('membership', 'edit')): ?>
                        <form method="POST" style="display: flex; gap: 6px;">
                            <input type="hidden" name="adjust_points" value="1">
                            <input type="hidden" name="user_id" value="<?= $m['id'] ?>">
                            <?= csrfField() ?>
                            <input type="number" name="points" class="form-input" placeholder="+/- poin" style="width: 100px; padding: 6px 8px; font-size: 12px;">
                            <button type="submit" class="btn btn-primary btn-sm" title="Terapkan"><i class="fas fa-check"></i></button>
                        </form>
                        <?php else: ?>
                        <span style="color: var(--text-light); font-size: 12px;">-</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endwhile; else: ?>
                <tr><td colspan="<?= hasPermission('membership', 'edit') ? 7 : 6 ?>" style="text-align: center; padding: 40px; color: var(--text-muted);">Belum ada member</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
        </main>
    </div>
</body>
</html>
