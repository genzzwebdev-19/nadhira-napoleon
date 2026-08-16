<?php
$currentPage = 'support';
$pageTitle = 'Ticket Support';
require_once __DIR__ . '/../config/rbac.php';

requirePermission('support', 'view');
$conn = getConnection();

$errors = [];
$success = '';
$info = '';

// ============================================
// ACTION: Tambah ticket manual
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_ticket'])) {
    verifyCsrf();
    requirePermission('support', 'create');
    $name = trim($_POST['customer_name'] ?? '');
    $email = trim($_POST['customer_email'] ?? '');
    $phone = trim($_POST['customer_phone'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $priority = in_array($_POST['priority'] ?? '', ['low', 'medium', 'high'], true) ? $_POST['priority'] : 'medium';

    if ($name === '' || $subject === '') {
        $errors[] = 'Nama dan subjek wajib diisi';
    } else {
        $name_e = $conn->real_escape_string($name);
        $email_e = $conn->real_escape_string($email);
        $phone_e = $conn->real_escape_string($phone);
        $subject_e = $conn->real_escape_string($subject);
        $message_e = $conn->real_escape_string($message);
        $conn->query(
            "INSERT INTO support_tickets (customer_name, customer_email, customer_phone, subject, message, priority, status)
             VALUES ('$name_e', '$email_e', '$phone_e', '$subject_e', '$message_e', '$priority', 'open')"
        );
        $success = 'Ticket berhasil dibuat!';
        logActivity('create', 'support', "Membuat ticket: $subject");
        header('Location: support.php');
        exit;
    }
}

// ============================================
// ACTION: Update status ticket
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_ticket'])) {
    verifyCsrf();
    requirePermission('support', 'edit');
    $tid = (int)($_POST['ticket_id'] ?? 0);
    $status = in_array($_POST['status'] ?? '', ['open', 'in_progress', 'resolved', 'closed'], true) ? $_POST['status'] : 'open';
    $priority = in_array($_POST['priority'] ?? '', ['low', 'medium', 'high'], true) ? $_POST['priority'] : 'medium';
    $assignedTo = (int)($_POST['assigned_to'] ?? 0);
    $reply = trim($_POST['reply'] ?? '');

    $assignedSql = $assignedTo > 0 ? $assignedTo : 'NULL';
    $conn->query("UPDATE support_tickets SET status = '$status', priority = '$priority', assigned_to = $assignedSql WHERE id = $tid");

    if ($reply !== '') {
        // Simpan balasan singkat di message (append)
        $reply_e = $conn->real_escape_string("\n\n[Balasan admin] " . date('d/m/Y H:i') . ": " . $reply);
        $conn->query("UPDATE support_tickets SET message = CONCAT(message, '$reply_e') WHERE id = $tid");
    }

    $success = 'Ticket #' . $tid . ' diperbarui.';
    logActivity('edit', 'support', "Update ticket #$tid -> $status");
    header('Location: support.php' . ($tid ? '?view=' . $tid : ''));
    exit;
}

// ============================================
// ACTION: Hapus ticket
// ============================================
if (isset($_GET['delete'])) {
    verifyCsrf();
    requirePermission('support', 'delete');
    $tid = (int)$_GET['delete'];
    $conn->query("DELETE FROM support_tickets WHERE id = $tid");
    $success = 'Ticket dihapus.';
    logActivity('delete', 'support', "Menghapus ticket #$tid");
    header('Location: support.php');
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
$tickets = $conn->query(
    "SELECT t.*, u.full_name AS assignee_name
     FROM support_tickets t
     LEFT JOIN users u ON u.id = t.assigned_to
     $where ORDER BY FIELD(priority,'high','medium','low'), t.created_at DESC"
);

$openCount = (int)$conn->query("SELECT COUNT(*) c FROM support_tickets WHERE status = 'open'")->fetch_assoc()['c'];
$progressCount = (int)$conn->query("SELECT COUNT(*) c FROM support_tickets WHERE status = 'in_progress'")->fetch_assoc()['c'];
$resolvedCount = (int)$conn->query("SELECT COUNT(*) c FROM support_tickets WHERE status = 'resolved'")->fetch_assoc()['c'];
$highCount = (int)$conn->query("SELECT COUNT(*) c FROM support_tickets WHERE priority = 'high' AND status IN ('open','in_progress')")->fetch_assoc()['c'];

// Staff untuk dropdown assign
$staff = $conn->query("SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name");

// Ticket yang sedang dilihat
$viewTicket = null;
if (isset($_GET['view'])) {
    $tid = (int)$_GET['view'];
    $r = $conn->query(
        "SELECT t.*, u.full_name AS assignee_name FROM support_tickets t
         LEFT JOIN users u ON u.id = t.assigned_to WHERE t.id = $tid LIMIT 1"
    );
    if ($r && $r->num_rows > 0) $viewTicket = $r->fetch_assoc();
}

$statusLabels = ['open' => 'Baru', 'in_progress' => 'Diproses', 'resolved' => 'Selesai', 'closed' => 'Ditutup'];
$statusClasses = ['open' => 'pending', 'in_progress' => 'processing', 'resolved' => 'active', 'closed' => 'inactive'];
$priorityLabels = ['low' => 'Rendah', 'medium' => 'Sedang', 'high' => 'Tinggi'];

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
    <div class="stat-card"><div class="stat-card-header"><div class="stat-card-icon warning"><i class="fas fa-inbox"></i></div><div><div class="stat-card-value"><?= $openCount ?></div></div></div><div class="stat-card-label">Ticket Baru</div></div>
    <div class="stat-card"><div class="stat-card-header"><div class="stat-card-icon info"><i class="fas fa-gears"></i></div><div><div class="stat-card-value"><?= $progressCount ?></div></div></div><div class="stat-card-label">Sedang Diproses</div></div>
    <div class="stat-card"><div class="stat-card-header"><div class="stat-card-icon success"><i class="fas fa-check-circle"></i></div><div><div class="stat-card-value"><?= $resolvedCount ?></div></div></div><div class="stat-card-label">Selesai</div></div>
    <div class="stat-card"><div class="stat-card-header"><div class="stat-card-icon" style="background: rgba(239,68,68,0.12); color: #EF4444;"><i class="fas fa-fire"></i></div><div><div class="stat-card-value"><?= $highCount ?></div></div></div><div class="stat-card-label">Prioritas Tinggi</div></div>
</div>

<!-- ============ FORM TICKET BARU ============ -->
<?php if (hasPermission('support', 'create')): ?>
<div class="admin-card">
    <h3 class="admin-card-title"><i class="fas fa-plus-circle" style="color: var(--soft-gold);"></i> Buat Ticket Baru</h3>
    <form method="POST">
        <input type="hidden" name="save_ticket" value="1">
        <?= csrfField() ?>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Nama Pelanggan <span style="color: #EF4444;">*</span></label>
                <input type="text" name="customer_name" class="form-input" required>
            </div>
            <div class="form-group">
                <label class="form-label">Email</label>
                <input type="email" name="customer_email" class="form-input">
            </div>
            <div class="form-group">
                <label class="form-label">Telepon</label>
                <input type="text" name="customer_phone" class="form-input">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group" style="flex: 2;">
                <label class="form-label">Subjek <span style="color: #EF4444;">*</span></label>
                <input type="text" name="subject" class="form-input" required placeholder="Ringkasan masalah">
            </div>
            <div class="form-group">
                <label class="form-label">Prioritas</label>
                <select name="priority" class="form-select">
                    <option value="low">Rendah</option>
                    <option value="medium" selected>Sedang</option>
                    <option value="high">Tinggi</option>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">Pesan / Keluhan</label>
            <textarea name="message" class="form-textarea" placeholder="Detail masalah..."></textarea>
        </div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Buat Ticket</button>
    </form>
</div>
<?php endif; ?>

<!-- ============ DAFTAR TICKET ============ -->
<div class="admin-card">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 16px;">
        <h3 class="admin-card-title" style="margin: 0;">Daftar Ticket (<?= $tickets ? $tickets->num_rows : 0 ?>)</h3>
        <select class="form-select" style="width: auto;" onchange="location.href='support.php?status='+this.value;">
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
                    <th>ID</th>
                    <th>Subjek</th>
                    <th>Pelanggan</th>
                    <th>Prioritas</th>
                    <th>Status</th>
                    <th>Ditugaskan</th>
                    <th>Tanggal</th>
                    <th style="width: 100px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($tickets && $tickets->num_rows > 0): while ($t = $tickets->fetch_assoc()): ?>
                <tr>
                    <td>#<?= $t['id'] ?></td>
                    <td>
                        <a href="support.php?view=<?= $t['id'] ?>" style="color: var(--text-dark); text-decoration: none; font-weight: 600;"><?= htmlspecialchars($t['subject']) ?></a>
                        <br><small style="font-size: 11px; color: var(--text-light);"><?= htmlspecialchars(mb_substr($t['message'], 0, 50)) ?>...</small>
                    </td>
                    <td style="font-size: 12px;">
                        <strong><?= htmlspecialchars($t['customer_name']) ?></strong>
                        <br><small style="color: var(--text-light);"><?= htmlspecialchars($t['customer_phone'] ?: $t['customer_email'] ?: '-') ?></small>
                    </td>
                    <td><span class="status-badge <?= $t['priority'] === 'high' ? 'cancelled' : ($t['priority'] === 'medium' ? 'pending' : 'inactive') ?>"><?= $priorityLabels[$t['priority']] ?></span></td>
                    <td><span class="status-badge <?= $statusClasses[$t['status']] ?? 'pending' ?>"><?= $statusLabels[$t['status']] ?? $t['status'] ?></span></td>
                    <td style="font-size: 12px;"><?= htmlspecialchars($t['assignee_name'] ?: '-') ?></td>
                    <td style="font-size: 12px; white-space: nowrap;"><?= date('d/m/Y H:i', strtotime($t['created_at'])) ?></td>
                    <td>
                        <div style="display: flex; gap: 4px;">
                            <a href="support.php?view=<?= $t['id'] ?>" class="btn btn-outline btn-sm" title="Buka"><i class="fas fa-eye"></i></a>
                            <a href="support.php?delete=<?= $t['id'] ?>&csrf_token=<?= csrfToken() ?>" class="btn btn-outline btn-sm" title="Hapus" style="color: #EF4444; border-color: #EF4444;" onclick="return confirm('Hapus ticket #<?= $t['id'] ?>?')"><i class="fas fa-trash"></i></a>
                        </div>
                    </td>
                </tr>
                <?php endwhile; else: ?>
                <tr><td colspan="8" style="text-align: center; padding: 40px; color: var(--text-muted);">Belum ada ticket</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ============ DETAIL TICKET ============ -->
<?php if ($viewTicket): ?>
<div class="admin-card" id="detail">
    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 10px; margin-bottom: 16px;">
        <div>
            <h3 class="admin-card-title" style="margin: 0;">Ticket #<?= $viewTicket['id'] ?> — <?= htmlspecialchars($viewTicket['subject']) ?></h3>
            <small style="color: var(--text-light);">
                Dari <strong><?= htmlspecialchars($viewTicket['customer_name']) ?></strong>
                <?= $viewTicket['customer_email'] ? '· ' . htmlspecialchars($viewTicket['customer_email']) : '' ?>
                <?= $viewTicket['customer_phone'] ? '· ' . htmlspecialchars($viewTicket['customer_phone']) : '' ?>
                · <?= date('d/m/Y H:i', strtotime($viewTicket['created_at'])) ?>
            </small>
        </div>
        <span class="status-badge <?= $statusClasses[$viewTicket['status']] ?? 'pending' ?>"><?= $statusLabels[$viewTicket['status']] ?? $viewTicket['status'] ?></span>
    </div>
    <div style="background: #FAF7F2; border: 1px solid #EDE6DA; border-radius: 10px; padding: 16px; margin-bottom: 16px; white-space: pre-wrap; line-height: 1.7; font-size: 14px;">
        <?= htmlspecialchars($viewTicket['message']) ?>
    </div>

    <?php if (hasPermission('support', 'edit')): ?>
    <form method="POST" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end;">
        <input type="hidden" name="update_ticket" value="1">
        <input type="hidden" name="ticket_id" value="<?= $viewTicket['id'] ?>">
        <?= csrfField() ?>
        <div class="form-group" style="margin-bottom: 0;">
            <label class="form-label">Status</label>
            <select name="status" class="form-select">
                <?php foreach ($statusLabels as $val => $label): ?>
                <option value="<?= $val ?>" <?= $viewTicket['status'] === $val ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin-bottom: 0;">
            <label class="form-label">Prioritas</label>
            <select name="priority" class="form-select">
                <?php foreach ($priorityLabels as $val => $label): ?>
                <option value="<?= $val ?>" <?= $viewTicket['priority'] === $val ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin-bottom: 0; min-width: 160px;">
            <label class="form-label">Ditugaskan ke</label>
            <select name="assigned_to" class="form-select">
                <option value="0">— Tidak ada —</option>
                <?php if ($staff): while ($st = $staff->fetch_assoc()): ?>
                <option value="<?= $st['id'] ?>" <?= (int)$viewTicket['assigned_to'] === (int)$st['id'] ? 'selected' : '' ?>><?= htmlspecialchars($st['full_name']) ?></option>
                <?php endwhile; endif; ?>
            </select>
        </div>
        <div class="form-group" style="flex: 1; min-width: 220px; margin-bottom: 0;">
            <label class="form-label">Balasan admin (dilampirkan ke pesan)</label>
            <input type="text" name="reply" class="form-input" placeholder="Tulis balasan...">
        </div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Simpan</button>
        <a href="support.php" class="btn btn-outline">Tutup Detail</a>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>
        </main>
    </div>
</body>
</html>
