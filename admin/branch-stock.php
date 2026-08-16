<?php
$currentPage = 'branch-stock';
$pageTitle = 'Stok per Cabang';
$requiredModule = 'stock';
require_once __DIR__ . '/layout.php';

$conn = getConnection();
ensureBranchProductsStock();

$errors = [];
$success = '';
$info = '';

// Flash message dari simpan (PRG: redirect setelah POST)
if (!empty($_SESSION['flash_success'])) { $success = $_SESSION['flash_success']; unset($_SESSION['flash_success']); }
if (!empty($_SESSION['flash_info']))   { $info    = $_SESSION['flash_info'];   unset($_SESSION['flash_info']); }
if (!empty($_SESSION['flash_error']))  { $errors[] = $_SESSION['flash_error']; unset($_SESSION['flash_error']); }

// ============================================
// ACTION: Simpan stok per cabang (matriks / per cabang)
// Format POST: stock[product_id][branch_id] = qty
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_branch_stocks'])) {
    verifyCsrf();
    requirePermission('stock', 'edit');
    $stockData = $_POST['stock'] ?? [];
    $updated = 0;
    $conn->begin_transaction();
    try {
        foreach ($stockData as $pid => $branches) {
            $pid = (int)$pid;
            if ($pid <= 0) continue;
            foreach ((array)$branches as $bid => $qty) {
                $bid = (int)$bid;
                if ($bid <= 0) continue;
                $qty = max(0, (int)$qty);
                if (!$conn->query(
                    "INSERT INTO branch_products (branch_id, product_id, is_available, stock)
                     VALUES ($bid, $pid, 1, $qty)
                     ON DUPLICATE KEY UPDATE stock = $qty, is_available = 1"
                )) {
                    throw new Exception('Gagal menyimpan data: ' . $conn->error);
                }
                $updated++;
            }
        }
        $conn->commit();
        if ($updated > 0) {
            $_SESSION['flash_success'] = "Stok per cabang berhasil diperbarui ($updated sel).";
            logActivity('edit', 'stock', "Perbarui stok per cabang ($updated sel)");
        } else {
            $_SESSION['flash_info'] = 'Tidak ada perubahan stok.';
        }
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['flash_error'] = 'Gagal menyimpan: ' . $e->getMessage();
    }
    header('Location: branch-stock.php?mode=' . urlencode($_GET['mode'] ?? 'matrix') . ($_GET['branch_id'] ? '&branch_id=' . (int)$_GET['branch_id'] : '') . ($_GET['search'] ? '&search=' . urlencode($_GET['search']) : ''));
    exit;
}

// ============================================
// DATA
// ============================================
$mode = ($_GET['mode'] ?? 'matrix') === 'branch' ? 'branch' : 'matrix';
$selectedBranchId = (int)($_GET['branch_id'] ?? 0);
$search = trim($_GET['search'] ?? '');

$branches = [];
$r = $conn->query("SELECT * FROM branches WHERE is_active = 1 ORDER BY sort_order, id");
if ($r) while ($row = $r->fetch_assoc()) $branches[] = $row;
if ($selectedBranchId <= 0 && !empty($branches)) $selectedBranchId = (int)$branches[0]['id'];
$selectedBranchId = max(0, $selectedBranchId);

// Filter produk: aktif (bukan paket membership/spesial) + pencarian
$where = "p.is_active = TRUE AND p.id NOT IN (SELECT product_id FROM membership_plans WHERE product_id IS NOT NULL)
          AND p.id NOT IN (SELECT product_id FROM packages WHERE product_id IS NOT NULL)";
if ($search) {
    $s = $conn->real_escape_string($search);
    $where .= " AND (p.name LIKE '%$s%' OR p.slug LIKE '%$s%')";
}

$products = $conn->query("
    SELECT p.id, p.name, p.stock, c.name AS category_name,
        (SELECT image FROM product_images WHERE product_id = p.id AND is_primary = 1 LIMIT 1) AS product_image
    FROM products p
    LEFT JOIN product_categories c ON c.id = p.category_id
    WHERE $where
    ORDER BY p.name ASC
");

// Stok per cabang: map[product_id][branch_id] = stok
$stockMap = [];
$r = $conn->query("SELECT product_id, branch_id, stock, is_available FROM branch_products");
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $stockMap[(int)$row['product_id']][(int)$row['branch_id']] = [
            'stock' => (int)$row['stock'],
            'available' => (int)$row['is_available'] === 1,
        ];
    }
}

// Ringkasan
$totalBranches = count($branches);
$totalProducts = $conn->query("SELECT COUNT(*) c FROM products WHERE is_active = TRUE")->fetch_assoc()['c'];
$totalUnits = 0;
$lowCells = 0;
$outCells = 0;
foreach ($stockMap as $pid => $byBranch) {
    foreach ($byBranch as $bid => $s) {
        $totalUnits += $s['stock'];
        if ($s['stock'] <= 0) $outCells++;
        elseif ($s['stock'] <= 5) $lowCells++;
    }
}
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
    <div class="stat-card"><div class="stat-card-header"><div class="stat-card-icon"><i class="fas fa-store"></i></div><div><div class="stat-card-value"><?= $totalBranches ?></div></div></div><div class="stat-card-label">Cabang Aktif</div></div>
    <div class="stat-card"><div class="stat-card-header"><div class="stat-card-icon"><i class="fas fa-box"></i></div><div><div class="stat-card-value"><?= number_format($totalProducts) ?></div></div></div><div class="stat-card-label">Produk Aktif</div></div>
    <div class="stat-card"><div class="stat-card-header"><div class="stat-card-icon info"><i class="fas fa-cubes"></i></div><div><div class="stat-card-value"><?= number_format($totalUnits) ?></div></div></div><div class="stat-card-label">Total Unit Semua Cabang</div></div>
    <div class="stat-card"><div class="stat-card-header"><div class="stat-card-icon warning"><i class="fas fa-exclamation-triangle"></i></div><div><div class="stat-card-value"><?= $lowCells ?></div></div></div><div class="stat-card-label">Sel Stok Menipis (≤5)</div></div>
    <div class="stat-card"><div class="stat-card-header"><div class="stat-card-icon" style="background: rgba(239,68,68,0.12); color: #EF4444;"><i class="fas fa-ban"></i></div><div><div class="stat-card-value"><?= $outCells ?></div></div></div><div class="stat-card-label">Sel Stok Habis</div></div>
</div>

<!-- ============ MODE SWITCH ============ -->
<div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 16px;">
    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
        <a href="branch-stock.php?mode=matrix<?= $search ? '&search=' . urlencode($search) : '' ?>" class="btn <?= $mode === 'matrix' ? 'btn-primary' : 'btn-outline' ?> btn-sm">
            <i class="fas fa-table-cells-large"></i> Matriks Semua Cabang
        </a>
        <a href="branch-stock.php?mode=branch<?= $selectedBranchId ? '&branch_id=' . $selectedBranchId : '' ?><?= $search ? '&search=' . urlencode($search) : '' ?>" class="btn <?= $mode === 'branch' ? 'btn-primary' : 'btn-outline' ?> btn-sm">
            <i class="fas fa-store"></i> Detail per Cabang
        </a>
        <?php if (hasPermission('stock', 'create')): ?>
        <a href="stock.php" class="btn btn-outline btn-sm"><i class="fas fa-arrows-up-down"></i> Pergerakan Stock</a>
        <?php endif; ?>
    </div>
    <form method="GET" style="display: flex; gap: 8px; align-items: center;">
        <input type="hidden" name="mode" value="<?= $mode ?>">
        <?php if ($mode === 'branch'): ?>
        <select name="branch_id" class="form-select" style="width: auto;" onchange="this.form.submit()">
            <?php foreach ($branches as $b): ?>
            <option value="<?= $b['id'] ?>" <?= $selectedBranchId === (int)$b['id'] ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <input type="text" name="search" class="form-input" placeholder="Cari produk..." value="<?= htmlspecialchars($search) ?>" style="width: 200px;">
        <button type="submit" class="btn btn-outline btn-sm"><i class="fas fa-search"></i></button>
        <a href="branch-stock.php?mode=<?= $mode ?>" class="btn btn-outline btn-sm"><i class="fas fa-times"></i></a>
    </form>
</div>

<?php if (empty($branches)): ?>
<div class="admin-card">
    <p style="color: var(--text-muted); text-align: center; padding: 30px;">
        <i class="fas fa-store" style="font-size: 2rem; display: block; margin-bottom: 10px; opacity: .4;"></i>
        Belum ada cabang aktif. Tambahkan cabang dulu di menu <strong>Cabang</strong>.
    </p>
</div>
<?php elseif ($mode === 'matrix'): ?>

<!-- ============ MODE MATRIKS: produk × cabang ============ -->
<?php if (hasPermission('stock', 'edit')): ?>
<form method="POST" id="matrix-form">
    <?= csrfField() ?>
    <input type="hidden" name="save_branch_stocks" value="1">
<?php endif; ?>
<div class="admin-card">
    <h3 class="admin-card-title"><i class="fas fa-table-cells-large" style="color: var(--soft-gold);"></i> Matriks Stok — Produk × Cabang</h3>
    <p style="font-size: 12px; color: var(--text-muted); margin-bottom: 14px;">
        Isi stok tiap produk di tiap cabang, lalu klik <strong>Simpan Semua Stok</strong>. Baris berwarna abu-abu = produk tidak dijual di cabang itu.
    </p>
    <div style="overflow-x: auto;">
        <table class="admin-table branch-stock-table">
            <thead>
                <tr>
                    <th style="min-width: 220px;">Produk</th>
                    <?php foreach ($branches as $b): ?>
                    <th style="text-align: center; min-width: 110px;"><?= htmlspecialchars($b['name']) ?></th>
                    <?php endforeach; ?>
                    <th style="text-align: center; min-width: 80px;">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($products && $products->num_rows > 0):
                    $products->data_seek(0);
                    while ($p = $products->fetch_assoc()):
                        $pid = (int)$p['id'];
                        $rowTotal = 0;
                ?>
                <tr>
                    <td>
                        <strong style="font-size: 13px;"><?= htmlspecialchars($p['name']) ?></strong>
                        <div style="font-size: 11px; color: var(--text-muted);"><?= htmlspecialchars($p['category_name'] ?: '-') ?></div>
                    </td>
                    <?php foreach ($branches as $b):
                        $bid = (int)$b['id'];
                        $cell = $stockMap[$pid][$bid] ?? ['stock' => 0, 'available' => true];
                        $rowTotal += $cell['stock'];
                        $cellCls = $cell['stock'] <= 0 ? 'is-out' : ($cell['stock'] <= 5 ? 'is-low' : '');
                    ?>
                    <td style="text-align: center;" class="<?= !$cell['available'] ? 'is-unavailable' : $cellCls ?>">
                        <?php if (hasPermission('stock', 'edit')): ?>
                        <input type="number" name="stock[<?= $pid ?>][<?= $bid ?>]" class="form-input branch-stock-input"
                               value="<?= $cell['stock'] ?>" min="0" title="<?= htmlspecialchars($b['name']) ?>">
                        <?php else: ?>
                        <strong><?= $cell['stock'] ?></strong>
                        <?php endif; ?>
                    </td>
                    <?php endforeach; ?>
                    <td style="text-align: center;"><strong style="font-size: 15px; color: var(--warm-orange);"><?= $rowTotal ?></strong></td>
                </tr>
                <?php endwhile; else: ?>
                <tr><td colspan="<?= count($branches) + 2 ?>" style="text-align: center; padding: 40px; color: var(--text-muted);">Belum ada produk</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if (hasPermission('stock', 'edit')): ?>
    <div style="margin-top: 16px; display: flex; justify-content: flex-end;">
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Simpan Semua Stok</button>
    </div>
    <?php endif; ?>
</div>
<?php if (hasPermission('stock', 'edit')): ?>
</form>
<?php endif; ?>

<?php else: ?>

<!-- ============ MODE DETAIL PER CABANG ============ -->
<?php $branchName = '';
foreach ($branches as $b) { if ((int)$b['id'] === $selectedBranchId) { $branchName = $b['name']; break; } } ?>
<?php if (hasPermission('stock', 'edit')): ?>
<form method="POST">
    <?= csrfField() ?>
    <input type="hidden" name="save_branch_stocks" value="1">
<?php endif; ?>
<div class="admin-card">
    <h3 class="admin-card-title"><i class="fas fa-store" style="color: var(--soft-gold);"></i> Stok — <?= htmlspecialchars($branchName) ?></h3>
    <div style="overflow-x: auto;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th style="min-width: 220px;">Produk</th>
                    <th>Kategori</th>
                    <th style="text-align: center; min-width: 110px;">Stok</th>
                    <th style="min-width: 120px;">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($products && $products->num_rows > 0):
                    $products->data_seek(0);
                    while ($p = $products->fetch_assoc()):
                        $pid = (int)$p['id'];
                        $cell = $stockMap[$pid][$selectedBranchId] ?? ['stock' => 0, 'available' => true];
                        $st = $cell['stock'];
                ?>
                <tr class="<?= !$cell['available'] ? 'is-unavailable-row' : '' ?>">
                    <td><strong style="font-size: 13px;"><?= htmlspecialchars($p['name']) ?></strong></td>
                    <td style="font-size: 12px;"><?= htmlspecialchars($p['category_name'] ?: '-') ?></td>
                    <td style="text-align: center;">
                        <?php if (hasPermission('stock', 'edit')): ?>
                        <input type="number" name="stock[<?= $pid ?>][<?= $selectedBranchId ?>]" class="form-input" style="width: 90px; text-align: center;" value="<?= $st ?>" min="0">
                        <?php else: ?>
                        <strong><?= $st ?></strong>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!$cell['available']): ?>
                            <span class="status-badge inactive">Tidak Dijual</span>
                        <?php elseif ($st <= 0): ?>
                            <span class="status-badge cancelled">Habis</span>
                        <?php elseif ($st <= 5): ?>
                            <span class="status-badge pending">Menipis</span>
                        <?php else: ?>
                            <span class="status-badge active">Tersedia</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endwhile; else: ?>
                <tr><td colspan="4" style="text-align: center; padding: 40px; color: var(--text-muted);">Belum ada produk</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if (hasPermission('stock', 'edit')): ?>
    <div style="margin-top: 16px; display: flex; justify-content: flex-end;">
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Simpan Stok <?= htmlspecialchars($branchName) ?></button>
    </div>
    <?php endif; ?>
</div>
<?php if (hasPermission('stock', 'edit')): ?>
</form>
<?php endif; ?>

<?php endif; ?>

<style>
.branch-stock-table th { white-space: nowrap; }
.branch-stock-input { width: 74px; text-align: center; padding: 6px 4px; font-size: 13px; }
.branch-stock-table td.is-out { background: rgba(239,68,68,0.06); }
.branch-stock-table td.is-low { background: rgba(245,158,11,0.06); }
.branch-stock-table td.is-unavailable { background: #F3F1EC; opacity: .65; }
.branch-stock-table tr.is-unavailable-row { background: #F9F8F4; opacity: .7; }
</style>
