<?php
// ============================================
// KONFIRMASI PEMBAYARAN
// Customer upload bukti transfer setelah order
// Website Nadhira Napoleon Pekanbaru
// ============================================
require_once '../config/database.php';
require_once __DIR__ . '/../config/rbac.php';      // isLoggedIn/getCurrentUser/verifyCsrf — keamanan konfirmasi
require_once __DIR__ . '/../config/cloudinary.php'; // penyimpanan bukti transfer ke Cloudinary

$page_title = 'Konfirmasi Pembayaran';
$meta_description = 'Konfirmasi pembayaran pesanan Anda di Nadhira Napoleon Pekanbaru.';

$conn = getConnection();
$errors = [];
$success = '';

// ============================================
// HANDLE FORM SUBMISSION
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_confirmation'])) {
    // Keamanan: wajib login (semua pesanan baru memang dari akun), token CSRF,
    // dan batas kirim per IP agar form tidak disalahgunakan (spam/konfirmasi palsu).
    if (!isLoggedIn()) {
        $errors[] = 'Silakan login terlebih dahulu untuk konfirmasi pembayaran.';
    }
    if (empty($errors) && !verifyCsrf()) {
        $errors[] = 'Sesi berakhir. Muat ulang halaman dan coba lagi.';
    }
    if (empty($errors) && function_exists('rateLimitIp') && !rateLimitIp('pay-confirm', 3, 3600)) {
        $errors[] = 'Terlalu banyak konfirmasi dikirim. Silakan coba lagi nanti.';
    }

    $orderNumber = trim($_POST['order_number'] ?? '');
    $bankName = trim($_POST['bank_name'] ?? '');
    $accountNumber = trim($_POST['account_number'] ?? '');
    $accountName = trim($_POST['account_name'] ?? '');
    $amount = (float)($_POST['amount'] ?? 0);
    $transferDate = trim($_POST['transfer_date'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    // Validation
    if (empty($orderNumber)) $errors[] = 'Nomor pesanan wajib diisi';
    if (empty($bankName)) $errors[] = 'Nama bank wajib diisi';
    if (empty($accountNumber)) $errors[] = 'No. rekening pengirim wajib diisi';
    if (empty($accountName)) $errors[] = 'Nama pemilik rekening wajib diisi';
    if ($amount <= 0) $errors[] = 'Jumlah transfer harus lebih dari 0';
    if (empty($transferDate)) $errors[] = 'Tanggal transfer wajib diisi';
    if (!isset($_FILES['proof_image']) || $_FILES['proof_image']['error'] === UPLOAD_ERR_NO_FILE) {
        $errors[] = 'Bukti transfer (foto/scan) wajib diupload';
    }

    // Verify order exists
    if (empty($errors) && $conn) {
        $orderNumberEsc = $conn->real_escape_string($orderNumber);
        $orderResult = $conn->query("SELECT * FROM orders WHERE order_number = '$orderNumberEsc' LIMIT 1");
        
        if (!$orderResult || $orderResult->num_rows === 0) {
            $errors[] = 'Nomor pesanan tidak ditemukan. Periksa kembali nomor pesanan Anda.';
        } else {
            $order = $orderResult->fetch_assoc();

            // Kepemilikan: pesanan harus milik akun yang login (ID atau email cocok)
            $me = getCurrentUser();
            if ($me
                && (int)$me['id'] !== (int)$order['user_id']
                && strtolower((string)$me['email']) !== strtolower((string)$order['customer_email'])) {
                $errors[] = 'Pesanan ini bukan milik akun Anda. Periksa kembali nomor pesanan.';
            }

            // Check if already paid
            if ($order['payment_status'] === 'paid') {
                $errors[] = 'Pesanan ini sudah dibayar. Jika ada kendala, silakan hubungi kami.';
            }
            
            // Validate amount (must be >= order total)
            if ($amount < $order['total']) {
                $errors[] = 'Jumlah transfer minimal Rp ' . number_format($order['total'], 0, ',', '.') . ' (sesuai total pesanan)';
            }
            
            // Check if already has pending confirmation
            $pendingCheck = $conn->query("SELECT id FROM payment_confirmations WHERE order_id = {$order['id']} AND status = 'pending' LIMIT 1");
            if ($pendingCheck && $pendingCheck->num_rows > 0) {
                $errors[] = 'Konfirmasi pembayaran untuk pesanan ini sudah dikirim dan sedang menunggu verifikasi. Silakan tunggu atau hubungi kami.';
            }
        }
    }

    // Handle file upload
    $proofImagePath = '';
    if (empty($errors) && isset($_FILES['proof_image']) && $_FILES['proof_image']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['proof_image'];
        $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $maxSize = 5 * 1024 * 1024; // 5MB
        $extension = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        $imgInfo = @getimagesize($file['tmp_name']); // validasi isi file (bukan sekadar MIME dari client)

        if (!in_array($extension, $allowedExts, true) || !$imgInfo) {
            $errors[] = 'Format file harus JPG, PNG, GIF, atau WebP yang valid';
        } elseif ($file['size'] > $maxSize) {
            $errors[] = 'Ukuran file maksimal 5MB';
        } else {
            // Nama file aman: hanya karakter alfanumerik & strip (nomor pesanan dibersihkan)
            $safeOrder = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$orderNumber);
            $fileName = 'payment_' . ($safeOrder !== '' ? $safeOrder : 'order') . '_' . time() . '.' . $extension;
            $uploadPath = __DIR__ . '/../uploads/payments/' . $fileName;
            
            $uploaded = false;
            // Jika Cloudinary aktif, upload bukti transfer langsung ke Cloudinary
            if (cloudinaryEnabled()) {
                $up = cloudinaryUploadFile($file['tmp_name'], 'nadhira/payments', 'payment_' . preg_replace('/[^a-zA-Z0-9_-]/', '-', $orderNumber) . '_' . time());
                if ($up['success']) {
                    $proofImagePath = $up['url'];
                    $uploaded = true;
                } else {
                    $errors[] = 'Gagal upload bukti transfer ke Cloudinary: ' . $up['message'];
                }
            } elseif (move_uploaded_file($file['tmp_name'], $uploadPath)) {
                $proofImagePath = 'uploads/payments/' . $fileName;
                $uploaded = true;
            } else {
                $errors[] = 'Gagal mengupload file. Silakan coba lagi.';
            }
        }
    }

    // Save to database
    if (empty($errors) && $conn) {
        $bankName_e = $conn->real_escape_string($bankName);
        $accNum_e = $conn->real_escape_string($accountNumber);
        $accName_e = $conn->real_escape_string($accountName);
        $notes_e = $conn->real_escape_string($notes);
        $proof_e = $conn->real_escape_string($proofImagePath);

        $sql = "INSERT INTO payment_confirmations (order_id, customer_name, bank_name, account_number, account_name, amount, transfer_date, proof_image, notes, status) 
                VALUES ({$order['id']}, '{$order['customer_name']}', '$bankName_e', '$accNum_e', '$accName_e', $amount, '$transferDate', '$proof_e', '$notes_e', 'pending')";

        if ($conn->query($sql)) {
            $success = 'Konfirmasi pembayaran berhasil dikirim! Kami akan memverifikasi pembayaran Anda dan memperbarui status pesanan.';
        } else {
            $errors[] = 'Gagal menyimpan konfirmasi: ' . $conn->error;
        }
    }
}

// ============================================
// PRE-FILL FROM ORDER NUMBER (GET)
// ============================================
$presetOrder = null;
if (isset($_GET['order'])) {
    $orderNum = trim($_GET['order']);
    if ($conn) {
        $orderNum_e = $conn->real_escape_string($orderNum);
        $r = $conn->query("SELECT * FROM orders WHERE order_number = '$orderNum_e' LIMIT 1");
        if ($r && $r->num_rows > 0) {
            $presetOrder = $r->fetch_assoc();
        }
    }
}

include '../includes/header.php';
?>

<section style="padding-top: calc(var(--navbar-total-height, 120px) + 8px); min-height: 100vh;">
    <div class="container container-narrow">
        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="<?= SITE_URL ?>">Home</a>
            <span class="separator">/</span>
            <span class="current">Konfirmasi Pembayaran</span>
        </div>

        <!-- Header -->
        <div style="text-align: center; margin-bottom: var(--space-2xl);">
            <h1 style="font-family: var(--font-display); font-size: var(--text-4xl); font-weight: 700; margin-bottom: var(--space-sm);">
                Konfirmasi <span class="gold-text">Pembayaran</span>
            </h1>
            <p style="color: var(--text-muted); font-size: var(--text-lg);">
                Upload bukti transfer untuk mempercepat proses verifikasi pesanan Anda
            </p>
        </div>

        <!-- Alert Messages -->
        <?php if ($success): ?>
            <div style="padding: var(--space-lg); background: #D1FAE5; border: 1px solid #A7F3D0; border-radius: var(--radius-lg); margin-bottom: var(--space-xl); display: flex; align-items: center; gap: var(--space-md);" data-aos="fade-up">
                <div style="width: 48px; height: 48px; min-width: 48px; border-radius: 50%; background: #059669; display: flex; align-items: center; justify-content: center; color: #FFF; font-size: 24px;">
                    <i class="fas fa-check"></i>
                </div>
                <div>
                    <h4 style="color: #065F46; font-family: var(--font-primary); font-size: var(--text-base);">Berhasil!</h4>
                    <p style="color: #047857; font-size: var(--text-sm); margin: 0;"><?= htmlspecialchars($success) ?></p>
                    <div style="margin-top: var(--space-md); display: flex; gap: var(--space-md); flex-wrap: wrap;">
                        <a href="<?= SITE_URL ?>/pages/tracking.php?order_number=<?= urlencode($_POST['order_number'] ?? ($presetOrder['order_number'] ?? '')) ?>" class="btn btn-primary btn-sm">
                            <i class="fas fa-truck"></i> Lacak Pesanan
                        </a>
                        <a href="<?= SITE_URL ?>" class="btn btn-outline btn-sm">
                            <i class="fas fa-home"></i> Kembali ke Home
                        </a>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php foreach ($errors as $err): ?>
            <div style="padding: var(--space-md) var(--space-lg); background: #FEF2F2; border: 1px solid #FECACA; border-radius: var(--radius-md); margin-bottom: var(--space-md); color: #DC2626; font-size: var(--text-sm); display: flex; align-items: center; gap: var(--space-sm);">
                <i class="fas fa-exclamation-circle"></i>
                <?= htmlspecialchars($err) ?>
            </div>
        <?php endforeach; ?>

        <?php if (!$success): ?>
        <!-- Form -->
        <div class="panel-card" data-aos="fade-up">
            <!-- Info Bank -->
            <div style="background: linear-gradient(135deg, #2C1810 0%, #5C3A21 100%); border-radius: var(--radius-lg); padding: var(--space-xl); margin-bottom: var(--space-2xl); color: #FFF;">
                <h4 style="color: var(--soft-gold); font-family: var(--font-primary); font-size: var(--text-sm); text-transform: uppercase; letter-spacing: 1px; margin-bottom: var(--space-md);">
                    <i class="fas fa-university"></i> Transfer ke Rekening Kami
                </h4>
                <div class="pay-bank-grid">
                    <div>
                        <p style="font-size: var(--text-xs); opacity: 0.6; margin-bottom: 2px;">Bank</p>
                        <p style="font-weight: 600;"><?= htmlspecialchars(getSetting('bank_name', 'Bank Mandiri')) ?></p>
                    </div>
                    <div>
                        <p style="font-size: var(--text-xs); opacity: 0.6; margin-bottom: 2px;">No. Rekening</p>
                        <p style="font-weight: 600; font-size: var(--text-lg); letter-spacing: 1px;"><?= htmlspecialchars(getSetting('bank_account', '123-00-4567890-1')) ?></p>
                    </div>
                    <div>
                        <p style="font-size: var(--text-xs); opacity: 0.6; margin-bottom: 2px;">A/N</p>
                        <p style="font-weight: 600;"><?= htmlspecialchars(getSetting('bank_holder', 'Nadhira Napoleon')) ?></p>
                    </div>
                </div>
            </div>

            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="submit_confirmation" value="1">
                <?= csrfField() ?>

                <!-- Order Number -->
                <div class="form-group">
                    <label class="form-label">Nomor Pesanan <span style="color: #EF4444;">*</span></label>
                    <input type="text" name="order_number" class="form-input" 
                           placeholder="Contoh: INV-2024-00123" 
                           value="<?= htmlspecialchars($_POST['order_number'] ?? ($presetOrder['order_number'] ?? '')) ?>" 
                           required
                           style="font-weight: 600; font-size: var(--text-lg); letter-spacing: 1px;">
                </div>

                <!-- Bank Info -->
                <div style="border-top: 1px solid var(--soft-grey); padding-top: var(--space-xl); margin-top: var(--space-xl);">
                    <h4 style="font-family: var(--font-primary); font-size: var(--text-base); font-weight: 600; margin-bottom: var(--space-lg); color: var(--text-primary);">
                        <i class="fas fa-credit-card" style="color: var(--soft-gold);"></i>
                        Data Transfer
                    </h4>

                    <div class="grid grid-2" style="gap: var(--space-lg);">
                        <div class="form-group">
                            <label class="form-label">Bank Pengirim <span style="color: #EF4444;">*</span></label>
                            <select name="bank_name" class="form-select" required>
                                <option value="">-- Pilih Bank --</option>
                                <?php
                                $banks = ['Bank Mandiri', 'Bank BCA', 'Bank BNI', 'Bank BRI', 'Bank Syariah Indonesia', 'Bank Danamon', 'Bank CIMB Niaga', 'Bank Permata', 'Bank Maybank', 'Bank OCBC NISP', 'Bank Lainnya'];
                                $selectedBank = $_POST['bank_name'] ?? '';
                                foreach ($banks as $b):
                                ?>
                                <option value="<?= htmlspecialchars($b) ?>" <?= $selectedBank === $b ? 'selected' : '' ?>><?= htmlspecialchars($b) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Jumlah Transfer <span style="color: #EF4444;">*</span></label>
                            <input type="number" name="amount" class="form-input" min="0" 
                                   placeholder="Rp" 
                                   value="<?= htmlspecialchars($_POST['amount'] ?? ($presetOrder['total'] ?? '')) ?>" 
                                   required>
                        </div>
                    </div>

                    <div class="grid grid-2" style="gap: var(--space-lg);">
                        <div class="form-group">
                            <label class="form-label">No. Rekening Pengirim <span style="color: #EF4444;">*</span></label>
                            <input type="text" name="account_number" class="form-input" 
                                   placeholder="Masukkan no. rekening Anda" 
                                   value="<?= htmlspecialchars($_POST['account_number'] ?? '') ?>" 
                                   required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Nama Pemilik Rekening <span style="color: #EF4444;">*</span></label>
                            <input type="text" name="account_name" class="form-input" 
                                   placeholder="Sesuai dengan nama di rekening" 
                                   value="<?= htmlspecialchars($_POST['account_name'] ?? '') ?>" 
                                   required>
                        </div>
                    </div>

                    <div class="grid grid-2" style="gap: var(--space-lg);">
                        <div class="form-group">
                            <label class="form-label">Tanggal Transfer <span style="color: #EF4444;">*</span></label>
                            <input type="date" name="transfer_date" class="form-input" 
                                   value="<?= htmlspecialchars($_POST['transfer_date'] ?? date('Y-m-d')) ?>" 
                                   required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Upload Bukti Transfer <span style="color: #EF4444;">*</span></label>
                            <div style="position: relative;">
                                <input type="file" name="proof_image" id="proof_image" class="form-input" 
                                       accept="image/jpeg,image/png,image/gif,image/webp" 
                                       style="padding: 8px; opacity: 0.7; cursor: pointer;" required
                                       onchange="updateFileName(this)">
                                <div id="file-name" style="font-size: var(--text-sm); color: var(--text-muted); margin-top: 4px;">Format: JPG, PNG, GIF, WebP (max 5MB)</div>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Catatan (opsional)</label>
                        <textarea name="notes" class="form-textarea" placeholder="Tambahkan catatan jika perlu..."><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-lg w-full" style="margin-top: var(--space-xl);">
                    <i class="fas fa-paper-plane"></i>
                    Kirim Konfirmasi Pembayaran
                </button>
            </form>
        </div>

        <!-- Help -->
        <div style="text-align: center; margin-top: var(--space-xl);">
            <p style="color: var(--text-muted); font-size: var(--text-sm);">
                <i class="fas fa-question-circle"></i>
                Butuh bantuan? 
                <a href="https://wa.me/6282112345678?text=Halo%20Nadhira%20Napoleon%2C%20saya%20butuh%20bantuan%20konfirmasi%20pembayaran" 
                   target="_blank" style="color: var(--soft-gold); font-weight: 500;">
                    Hubungi via WhatsApp
                </a>
            </p>
        </div>
        <?php endif; ?>
    </div>
</section>

<script>
function updateFileName(input) {
    const fileName = document.getElementById('file-name');
    if (input.files && input.files.length > 0) {
        const file = input.files[0];
        const sizeMB = (file.size / (1024 * 1024)).toFixed(1);
        fileName.textContent = '📎 ' + file.name + ' (' + sizeMB + ' MB)';
        fileName.style.color = '#059669';
    } else {
        fileName.textContent = 'Format: JPG, PNG, GIF, WebP (max 5MB)';
        fileName.style.color = 'var(--text-muted)';
    }
}
</script>

<?php include '../includes/footer.php'; ?>
