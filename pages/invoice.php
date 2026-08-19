<?php
// ============================================
// INVOICE VIEW - NADHIRA NAPOLEON
// Tampilan invoice premium siap cetak
// ============================================
require_once '../config/database.php';
require_once '../config/midtrans.php';

$orderNumber = trim($_GET['order'] ?? '');
$isAdmin = isset($_GET['admin']) && $_GET['admin'] === '1';
$email = trim($_GET['email'] ?? '');

if (empty($orderNumber)) {
    header('Location: ' . SITE_URL);
    exit;
}

$conn = getConnection();
if (!$conn) {
    die('Koneksi database gagal');
}
ensureMidtransSchema();

$orderNumber = $conn->real_escape_string($orderNumber);
$order = $conn->query("SELECT * FROM orders WHERE order_number = '$orderNumber' LIMIT 1");

if (!$order || $order->num_rows === 0) {
    header('Location: ' . SITE_URL . '/404.php');
    exit;
}
$order = $order->fetch_assoc();

// Penanda hasil pembayaran dari halaman Snap (?pay=success|pending|error)
$payFlag = trim($_GET['pay'] ?? '');

// Access control
$accessDenied = true;
if ($isAdmin && isLoggedIn()) {
    // Admin RBAC (super admin & semua role admin) — bukan cek kolom role lama
    if (isAdminUser()) {
        $accessDenied = false;
    }
}
if (!empty($email) && strtolower($email) === strtolower($order['customer_email'])) {
    $accessDenied = false;
}
if (isLoggedIn() && (int)$_SESSION['user_id'] === (int)$order['user_id']) {
    $accessDenied = false;
}

if ($accessDenied) {
    header('Location: ' . SITE_URL . '/pages/tracking.php');
    exit;
}

// Get order items
$items = $conn->query("SELECT * FROM order_items WHERE order_id = {$order['id']}");
$orderItems = [];
if ($items) {
    while ($item = $items->fetch_assoc()) {
        $orderItems[] = $item;
    }
}

// Get settings
$settings = [
    'contact_address' => getSetting('contact_address', 'Jl. Sudirman No. 123, Pekanbaru'),
    'contact_phone' => getSetting('contact_phone', '0821-1234-5678'),
    'contact_email' => getSetting('contact_email', 'info@nadhiranapoleon.com'),
    'bank_name' => getSetting('bank_name', 'Bank Mandiri'),
    'bank_account' => getSetting('bank_account', '123-00-4567890-1'),
    'bank_holder' => getSetting('bank_holder', 'Nadhira Napoleon'),
];

$statusLabels = [
    'pending' => 'Menunggu Pembayaran',
    'paid' => 'LUNAS',
    'failed' => 'Gagal',
    'refunded' => 'Dikembalikan'
];
$statusColors = [
    'pending' => '#F59E0B',
    'paid' => '#059669',
    'failed' => '#DC2626',
    'refunded' => '#6366F1'
];

// Generate QR verification URL
$qrVerificationUrl = SITE_URL . '/pages/tracking.php?order_number=' . urlencode($order['order_number']);

// Poin membership yang baru didapat dari pesanan ini (ditampilkan sekali)
$pointsEarned = $_SESSION['order_points_earned'] ?? null;
unset($_SESSION['order_points_earned']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice #<?= htmlspecialchars($order['order_number']) ?> - <?= SITE_NAME ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Inter:wght@300;400;500;600;700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        /* ============================================
           RESET & BASE
           ============================================ */
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', -apple-system, sans-serif;
            background: #F0EDE8;
            color: #2C1810;
            -webkit-font-smoothing: antialiased;
            padding: 30px 20px;
            min-height: 100vh;
        }

        /* ============================================
           ACTION BAR
           ============================================ */
        .action-bar {
            max-width: 210mm;
            margin: 0 auto 20px;
            display: flex;
            justify-content: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .action-bar .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            font-family: 'Inter', sans-serif;
            font-size: 14px;
            font-weight: 500;
            border: none;
            border-radius: 50px;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .btn-pdf {
            background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
            color: #FFF;
            box-shadow: 0 4px 15px rgba(220,38,38,0.3);
        }
        .btn-pdf:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(220,38,38,0.4); }

        .btn-print {
            background: linear-gradient(135deg, #D4A853 0%, #E8853B 100%);
            color: #FFF;
            box-shadow: 0 4px 15px rgba(212,168,83,0.3);
        }
        .btn-print:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(212,168,83,0.4); }

        .btn-secondary {
            background: #FFF;
            color: #5C3A21;
            border: 2px solid #D4A853;
        }
        .btn-secondary:hover { background: #D4A853; color: #FFF; }

        .btn-whatsapp {
            background: #25D366;
            color: #FFF;
            box-shadow: 0 4px 15px rgba(37,211,102,0.3);
        }
        .btn-whatsapp:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(37,211,102,0.4); }

        /* ============================================
           INVOICE DOCUMENT
           ============================================ */
        .invoice-doc {
            max-width: 210mm;
            margin: 0 auto;
            background: #FFFFFF;
            border-radius: 16px;
            box-shadow: 0 8px 40px rgba(44,24,16,0.12);
            overflow: hidden;
            position: relative;
        }

        /* Watermark */
        .invoice-watermark {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-30deg);
            font-size: 96px;
            font-weight: 900;
            color: <?= $order['payment_status'] === 'paid' ? 'rgba(5, 150, 105, 0.06)' : 'rgba(245, 158, 11, 0.06)' ?>;
            text-transform: uppercase;
            letter-spacing: 30px;
            z-index: 1;
            pointer-events: none;
            white-space: nowrap;
            font-family: 'Playfair Display', serif;
        }

        .invoice-content {
            position: relative;
            z-index: 2;
        }

        /* ============================================
           HEADER
           ============================================ */
        .inv-header {
            background: linear-gradient(135deg, #2C1810 0%, #5C3A21 100%);
            padding: 40px;
            color: #FFF;
            position: relative;
            overflow: hidden;
        }

        .inv-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(212,168,83,0.08) 0%, transparent 70%);
            border-radius: 50%;
        }

        .inv-header-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            position: relative;
            z-index: 1;
        }

        .inv-brand {
            font-family: 'Playfair Display', serif;
            font-size: 28px;
            font-weight: 700;
        }
        .inv-brand span { color: #D4A853; }

        .inv-brand-tagline {
            font-size: 12px;
            color: rgba(255,255,255,0.6);
            margin-top: 4px;
        }

        .inv-title-box { text-align: right; }

        .inv-badge {
            display: inline-block;
            padding: 6px 20px;
            background: rgba(212,168,83,0.2);
            border: 1px solid rgba(212,168,83,0.3);
            border-radius: 50px;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: #D4A853;
        }

        .inv-number {
            font-size: 16px;
            font-weight: 600;
            color: rgba(255,255,255,0.7);
            margin-top: 6px;
            font-family: 'DM Sans', monospace;
        }

        .inv-number span {
            color: #D4A853;
            font-weight: 700;
        }

        /* ============================================
           BODY
           ============================================ */
        .inv-body {
            padding: 40px;
        }

        /* Address Section */
        .inv-addresses {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 28px;
            padding-bottom: 28px;
            border-bottom: 2px solid #F0EDE8;
        }

        .inv-address-title {
            font-size: 10px;
            font-weight: 600;
            color: #8B6F47;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            margin-bottom: 8px;
        }

        .inv-address-name {
            font-weight: 700;
            font-size: 16px;
            margin-bottom: 4px;
            font-family: 'Playfair Display', serif;
        }

        .inv-address-detail {
            font-size: 13px;
            color: #5C3A21;
            line-height: 1.8;
        }

        /* Meta Section */
        .inv-meta {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 28px;
            padding-bottom: 28px;
            border-bottom: 2px solid #F0EDE8;
        }

        .inv-meta-label {
            font-size: 10px;
            color: #A0886A;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }

        .inv-meta-value {
            font-weight: 600;
            font-size: 14px;
        }

        .inv-status {
            display: inline-block;
            padding: 4px 14px;
            border-radius: 50px;
            font-size: 11px;
            font-weight: 600;
            background: <?= $statusColors[$order['payment_status']] ?>;
            color: #FFF;
        }

        /* Table */
        .inv-table-wrap {
            overflow-x: auto;
            margin-bottom: 20px;
        }

        .inv-table {
            width: 100%;
            border-collapse: collapse;
        }

        .inv-table th {
            text-align: left;
            padding: 12px 14px;
            border-bottom: 2px solid #2C1810;
            font-size: 11px;
            font-weight: 600;
            color: #2C1810;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        .inv-table th:last-child,
        .inv-table td:last-child {
            text-align: right;
        }

        .inv-table th:nth-child(2),
        .inv-table th:nth-child(3),
        .inv-table td:nth-child(2),
        .inv-table td:nth-child(3) {
            text-align: center;
        }

        .inv-table td {
            padding: 14px;
            border-bottom: 1px solid #F0EDE8;
            font-size: 13px;
        }

        .inv-table .product-name {
            font-weight: 600;
        }

        .inv-table tbody tr:last-child td {
            border-bottom: none;
        }

        .inv-table tbody tr:hover {
            background: #FFFAF5;
        }

        /* Totals */
        .inv-totals {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 24px;
        }

        .inv-totals table {
            width: 300px;
            border-collapse: collapse;
        }

        .inv-totals td {
            padding: 6px 0;
            font-size: 14px;
        }

        .inv-totals td:first-child {
            text-align: left;
            color: #5C3A21;
        }

        .inv-totals td:last-child {
            text-align: right;
            font-weight: 600;
        }

        .inv-totals .grand-total td {
            padding-top: 12px;
            border-top: 2px solid #2C1810;
            font-family: 'Playfair Display', serif;
            font-size: 20px;
            font-weight: 700;
            color: #E8853B;
        }

        .inv-totals .discount td:last-child {
            color: #059669;
        }

        /* Payment Info Box */
        .inv-payment {
            padding: 20px 24px;
            background: linear-gradient(135deg, #FFF5E6 0%, #FFFAF5 100%);
            border: 1px solid rgba(212,168,83,0.2);
            border-radius: 12px;
            margin-bottom: 20px;
        }

        .inv-payment-title {
            font-weight: 600;
            font-size: 13px;
            margin-bottom: 10px;
            color: #2C1810;
        }

        .inv-payment-detail {
            font-size: 13px;
            color: #5C3A21;
            line-height: 2;
        }

        .inv-payment-detail strong {
            color: #2C1810;
        }

        .inv-payment-detail .divider {
            display: inline-block;
            width: 1px;
            height: 14px;
            background: #D4A853;
            margin: 0 12px;
            vertical-align: middle;
            opacity: 0.3;
        }

        /* Notes */
        .inv-notes {
            padding: 16px 20px;
            background: #FFFBEB;
            border: 1px solid #FDE68A;
            border-radius: 8px;
            margin-top: 16px;
        }

        .inv-notes p {
            font-size: 13px;
            color: #92400E;
        }

        .inv-notes strong {
            font-weight: 600;
        }

        /* QR & Verification */
        .inv-verification {
            display: flex;
            justify-content: flex-end;
            align-items: flex-start;
            margin-top: 20px;
            gap: 16px;
        }

        .inv-qr {
            text-align: center;
        }

        .inv-qr img {
            width: 80px;
            height: 80px;
            border-radius: 6px;
        }

        .inv-qr-label {
            font-size: 9px;
            color: #A0886A;
            margin-top: 4px;
        }

        /* ============================================
           FOOTER
           ============================================ */
        .inv-footer {
            padding: 28px 40px;
            border-top: 1px solid #F0EDE8;
            text-align: center;
        }

        .inv-footer-brand {
            font-family: 'Playfair Display', serif;
            font-size: 16px;
            font-weight: 600;
            color: #2C1810;
            margin-bottom: 4px;
        }

        .inv-footer-text {
            font-size: 13px;
            color: #A0886A;
            line-height: 1.8;
        }

        .inv-footer-legal {
            font-size: 11px;
            color: #A0886A;
            margin-top: 8px;
            font-style: italic;
        }

        /* Confirmation button */
        .inv-confirm-btn {
            text-align: center;
            margin-top: 24px;
        }

        .inv-confirm-btn .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 14px 32px;
            background: linear-gradient(135deg, #D4A853 0%, #E8853B 100%);
            color: #FFF;
            border: none;
            border-radius: 50px;
            font-size: 15px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(212,168,83,0.3);
        }
        .inv-confirm-btn .btn:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(212,168,83,0.4); }

        .inv-confirm-btn .hint {
            font-size: 12px;
            color: #A0886A;
            margin-top: 8px;
        }

        /* Area CTA pembayaran di bawah invoice */
        .inv-payment-cta {
            padding: 0 40px 30px;
        }

        /* ============================================
           PRINT STYLES
           ============================================ */
        @media print {
            body {
                background: #FFF;
                padding: 0;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .action-bar { display: none; }

            .invoice-doc {
                max-width: none;
                box-shadow: none;
                border-radius: 0;
            }

            .inv-header {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .inv-payment {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .inv-status {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .inv-watermark {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .inv-qr img {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            @page {
                margin: 0;
                size: A4;
            }
        }

        /* ============================================
           RESPONSIVE
           ============================================ */
        @media (max-width: 768px) {
            body { padding: 12px; }
            .invoice-doc { border-radius: 12px; }
            .inv-header { padding: 24px; }
            .inv-body { padding: 20px; }
            .inv-addresses { grid-template-columns: 1fr; gap: 20px; }
            .inv-meta { grid-template-columns: 1fr 1fr; gap: 16px; }
            .inv-footer { padding: 20px; }
            .inv-table td, .inv-table th { padding: 10px; }
            .inv-table { min-width: 520px; }
            .inv-totals table { width: 100%; }
            .inv-verification { justify-content: center; }
            .inv-brand { font-size: 22px; }
            .inv-header-top { flex-wrap: wrap; gap: 12px; }
            /* Watermark tidak meluber di layar kecil */
            .invoice-watermark { font-size: 60px; letter-spacing: 16px; }
        }

        @media (max-width: 480px) {
            .inv-meta { grid-template-columns: 1fr; }
            .inv-table { font-size: 12px; }

            /* Tombol aksi: grid 2 kolom penuh agar nyaman ditekan satu tangan */
            .action-bar {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 8px;
            }
            .action-bar .btn {
                justify-content: center;
                padding: 12px 10px;
                font-size: 13px;
                text-align: center;
            }

            /* Padding invoice lebih ringkas */
            .inv-header { padding: 20px 16px; }
            .inv-body { padding: 16px 14px; }
            .inv-footer { padding: 18px 16px; }
            .inv-brand { font-size: 19px; }
            .inv-number { font-size: 13px; word-break: break-all; }
            .inv-badge { padding: 5px 14px; font-size: 11px; }
            .inv-meta-value { font-size: 13px; }
            .inv-payment { padding: 14px; }
            .inv-payment-detail { font-size: 12.5px; }
            .inv-totals .grand-total td { font-size: 17px; }
            .inv-footer-text { font-size: 12px; }
            .inv-footer-legal { font-size: 10px; }

            /* Watermark lebih ringkas di layar sangat sempit */
            .invoice-watermark { font-size: 46px; letter-spacing: 8px; }

            /* Verifikasi: QR + teks menumpuk ke bawah */
            .inv-verification {
                flex-direction: column;
                align-items: center;
                text-align: center;
                gap: 14px;
            }
            .inv-verification > div:first-child { width: 100%; }

            /* Tombol konfirmasi/bayar full-width */
            .inv-confirm-btn .btn { width: 100%; justify-content: center; }
            .inv-confirm-btn .hint { font-size: 11px; }
            .inv-payment-cta { padding: 0 16px 24px; }
        }

        /* Toast notification */
        .toast-copied {
            position: fixed;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%) translateY(100px);
            background: #2C1810;
            color: #FFF;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 13px;
            opacity: 0;
            transition: all 0.3s ease;
            z-index: 9999;
        }
        .toast-copied.show {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }

        /* Points earned banner */
        .points-banner {
            max-width: 210mm;
            margin: 0 auto 20px;
            padding: 16px 24px;
            background: linear-gradient(135deg, #D1FAE5 0%, #ECFDF5 100%);
            border: 1px solid #A7F3D0;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: #065F46;
            font-size: 14px;
            font-weight: 500;
            box-shadow: 0 4px 15px rgba(5, 150, 105, 0.1);
        }
        .points-banner i {
            font-size: 20px;
            color: #059669;
        }
        .points-banner strong {
            color: #047857;
        }
        @media print {
            .points-banner { display: none; }
        }
    </style>
</head>
<body>

    <!-- Toast -->
    <div class="toast-copied" id="toastCopied">✓ Link verifikasi disalin ke clipboard!</div>

    <?php if ($pointsEarned > 0): ?>
    <!-- Points Banner -->
    <div class="points-banner">
        <i class="fas fa-coins"></i>
        <span>
            Selamat! Anda mendapat <strong><?= number_format($pointsEarned) ?> poin membership</strong> dari pesanan ini.
            <a href="<?= SITE_URL ?>/pages/membership.php" style="color: #047857; font-weight: 600; text-decoration: underline; margin-left: 6px;">Lihat kartu member</a>
        </span>
    </div>
    <?php endif; ?>

    <?php if (in_array($payFlag, ['success', 'pending', 'error'], true)): ?>
    <!-- Pay Status Banner -->
    <div style="max-width: 210mm; margin: 0 auto 20px; border-radius: 14px; padding: 18px 24px; display: flex; align-items: center; gap: 14px; box-shadow: 0 4px 14px rgba(0,0,0,0.06);
         <?php if ($payFlag === 'success'): ?>background: #D1FAE5; border: 1px solid #A7F3D0; color: #065F46;
         <?php elseif ($payFlag === 'pending'): ?>background: #FEF3C7; border: 1px solid #FDE68A; color: #92400E;
         <?php else: ?>background: #FEE2E2; border: 1px solid #FECACA; color: #991B1B;<?php endif; ?>">
        <div style="width: 44px; height: 44px; min-width: 44px; border-radius: 50%; background: #FFF; display: flex; align-items: center; justify-content: center;">
            <i class="fas <?= $payFlag === 'success' ? 'fa-check-circle' : ($payFlag === 'pending' ? 'fa-clock' : 'fa-times-circle') ?>" style="font-size: 22px; <?= $payFlag === 'success' ? 'color: #059669' : ($payFlag === 'pending' ? 'color: #D97706' : 'color: #DC2626') ?>"></i>
        </div>
        <div>
            <strong style="font-size: 15px;">
                <?php if ($payFlag === 'success'): ?>Pembayaran Berhasil!<?php elseif ($payFlag === 'pending'): ?>Menunggu Konfirmasi Pembayaran<?php else: ?>Pembayaran Gagal / Dibatalkan<?php endif; ?>
            </strong>
            <div style="font-size: 13px; opacity: 0.9; margin-top: 2px;">
                <?php if ($payFlag === 'success'): ?>
                    Terima kasih! Status pesanan akan diperbarui otomatis setelah pembayaran terverifikasi.
                <?php elseif ($payFlag === 'pending'): ?>
                    Pembayaran Anda sedang diproses. Anda bisa mencoba lagi atau menghubungi kami jika ada kendala.
                <?php else: ?>
                    Pembayaran tidak dapat diselesaikan. Silakan coba bayar kembali.
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Action Bar -->
    <div class="action-bar">
        <a href="<?= SITE_URL ?>/pages/download-invoice-pdf.php?order=<?= urlencode($order['order_number']) ?>&email=<?= urlencode($email) ?><?= $isAdmin ? '&admin=1' : '' ?>" class="btn btn-pdf">
            <i class="fas fa-file-pdf"></i>
            Download PDF
        </a>
        <button class="btn btn-print" onclick="window.print()">
            <i class="fas fa-print"></i>
            Cetak Invoice
        </button>
        <a href="https://wa.me/6282112345678?text=Halo%20Nadhira%20Napoleon,%20saya%20ingin%20menanyakan%20pesanan%20saya%20dengan%20nomor%20<?= urlencode($order['order_number']) ?>" class="btn btn-whatsapp" target="_blank">
            <i class="fab fa-whatsapp"></i>
            Bantuan
        </a>
        <button class="btn btn-secondary" onclick="window.close()">
            <i class="fas fa-times"></i>
            Tutup
        </button>
    </div>

    <!-- Invoice Document -->
    <div class="invoice-doc">
        <!-- Watermark -->
        <div class="invoice-watermark"><?= strtoupper($statusLabels[$order['payment_status']]) ?></div>

        <div class="invoice-content">
            <!-- Header -->
            <div class="inv-header">
                <div class="inv-header-top">
                    <div>
                        <div class="inv-brand">Nadhira <span>Napoleon</span></div>
                        <div class="inv-brand-tagline">Premium Oleh-Oleh Khas Riau</div>
                    </div>
                    <div class="inv-title-box">
                        <div class="inv-badge">
                            <i class="fas fa-file-invoice" style="margin-right: 6px;"></i>
                            INVOICE
                        </div>
                        <div class="inv-number">
                            #<span><?= htmlspecialchars($order['order_number']) ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Body -->
            <div class="inv-body">
                <!-- Addresses -->
                <div class="inv-addresses">
                    <div>
                        <div class="inv-address-title">
                            <i class="fas fa-building" style="margin-right: 6px;"></i>
                            Dari
                        </div>
                        <div class="inv-address-name">Nadhira Napoleon Pekanbaru</div>
                        <div class="inv-address-detail">
                            <?= htmlspecialchars($settings['contact_address']) ?><br>
                            <i class="fas fa-phone" style="width: 14px; color: #D4A853;"></i> <?= htmlspecialchars($settings['contact_phone']) ?><br>
                            <i class="fas fa-envelope" style="width: 14px; color: #D4A853;"></i> <?= htmlspecialchars($settings['contact_email']) ?>
                        </div>
                    </div>
                    <div>
                        <div class="inv-address-title">
                            <i class="fas fa-user" style="margin-right: 6px;"></i>
                            Kepada
                        </div>
                        <div class="inv-address-name"><?= htmlspecialchars($order['customer_name']) ?></div>
                        <div class="inv-address-detail">
                            <i class="fas fa-map-marker-alt" style="width: 14px; color: #D4A853;"></i> <?= nl2br(htmlspecialchars($order['shipping_address'])) ?><br>
                            <?php if ($order['shipping_city']): ?>
                                &nbsp;&nbsp;&nbsp; <?= htmlspecialchars($order['shipping_city']) ?>, <?= htmlspecialchars($order['shipping_province']) ?> <?= htmlspecialchars($order['shipping_postal_code']) ?><br>
                            <?php endif; ?>
                            <i class="fas fa-phone" style="width: 14px; color: #D4A853;"></i> <?= htmlspecialchars($order['customer_phone']) ?><br>
                            <i class="fas fa-envelope" style="width: 14px; color: #D4A853;"></i> <?= htmlspecialchars($order['customer_email']) ?>
                        </div>
                    </div>
                </div>

                <!-- Meta -->
                <div class="inv-meta">
                    <div>
                        <div class="inv-meta-label"><i class="fas fa-calendar"></i> Tanggal Invoice</div>
                        <div class="inv-meta-value"><?= formatDate($order['created_at'], 'd F Y') ?></div>
                    </div>
                    <div>
                        <div class="inv-meta-label"><i class="fas fa-credit-card"></i> Metode Pembayaran</div>
                        <div class="inv-meta-value" style="text-transform: capitalize;"><?= str_replace('_', ' ', $order['payment_method']) ?></div>
                    </div>
                    <div>
                        <div class="inv-meta-label"><i class="fas fa-check-circle"></i> Status Pembayaran</div>
                        <div><span class="inv-status"><?= $statusLabels[$order['payment_status']] ?></span></div>
                    </div>
                </div>

                <!-- Items Table -->
                <div class="inv-table-wrap">
                    <table class="inv-table">
                        <thead>
                            <tr>
                                <th style="width: 45%;">Produk</th>
                                <th style="width: 18%;">Harga</th>
                                <th style="width: 12%;">Qty</th>
                                <th style="width: 25%;">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orderItems as $item): ?>
                            <tr>
                                <td class="product-name">
                                    <span style="display: flex; align-items: center; gap: 10px;">
                                        <?php if ($item['product_image']): ?>
                                            <img src="<?= htmlspecialchars($item['product_image']) ?>" alt="" style="width: 36px; height: 36px; border-radius: 6px; object-fit: cover; display: none;" loading="lazy">
                                        <?php endif; ?>
                                        <?= htmlspecialchars($item['product_name']) ?>
                                    </span>
                                </td>
                                <td>Rp <?= number_format($item['price'], 0, ',', '.') ?></td>
                                <td><?= $item['quantity'] ?></td>
                                <td><strong>Rp <?= number_format($item['subtotal'], 0, ',', '.') ?></strong></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Totals -->
                <div class="inv-totals">
                    <table>
                        <tr>
                            <td>Subtotal</td>
                            <td>Rp <?= number_format($order['subtotal'], 0, ',', '.') ?></td>
                        </tr>
                        <tr>
                            <td>Ongkos Kirim</td>
                            <td><?= $order['shipping_cost'] > 0 ? 'Rp ' . number_format($order['shipping_cost'], 0, ',', '.') : '<strong style="color: #059669;">GRATIS</strong>' ?></td>
                        </tr>
                        <?php if ($order['discount'] > 0): ?>
                        <tr class="discount">
                            <td>Diskon<?= !empty($order['promo_code']) ? ' (kode promo)' : '' ?></td>
                            <td>-Rp <?= number_format($order['discount'], 0, ',', '.') ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr class="grand-total">
                            <td>Total Pembayaran</td>
                            <td>Rp <?= number_format($order['total'], 0, ',', '.') ?></td>
                        </tr>
                    </table>
                    <?php if (!empty($order['promo_code'])): ?>
                    <div style="margin-top: var(--space-sm); font-size: var(--text-xs); color: var(--text-muted);">
                        <i class="fas fa-ticket-alt" style="color: var(--soft-gold); margin-right: 6px;"></i>
                        Kode promo: <strong style="color: var(--text-secondary);"><?= htmlspecialchars($order['promo_code']) ?></strong>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Payment Info -->
                <div class="inv-payment">
                    <div class="inv-payment-title">
                        <i class="fas fa-university" style="color: #D4A853; margin-right: 8px;"></i>
                        Informasi Pembayaran
                    </div>
                    <div class="inv-payment-detail">
                        <?php if ($order['payment_method'] === 'midtrans'): ?>
                            <strong>Metode:</strong> Midtrans
                            <?php if (!empty($order['midtrans_payment_type'])): ?>
                                <span class="divider"></span>
                                <strong>Kanal:</strong> <?= htmlspecialchars(midtransPaymentLabel($order['midtrans_payment_type'])) ?>
                            <?php endif; ?>
                            <?php if (!empty($order['midtrans_bank'])): ?>
                                <span class="divider"></span>
                                <strong>Bank:</strong> <?= htmlspecialchars(strtoupper($order['midtrans_bank'])) ?>
                            <?php endif; ?>
                            <?php if (!empty($order['midtrans_va_number'])): ?>
                                <br>
                                <strong>No. Virtual Account:</strong>
                                <span style="font-family: monospace; font-weight: 700; letter-spacing: 1px;"><?= htmlspecialchars($order['midtrans_va_number']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($order['midtrans_transaction_id'])): ?>
                                <span class="divider"></span>
                                <strong>Ref:</strong> <?= htmlspecialchars($order['midtrans_transaction_id']) ?>
                            <?php endif; ?>
                        <?php else: ?>
                            <strong>Bank:</strong> <?= htmlspecialchars($settings['bank_name']) ?>
                            <span class="divider"></span>
                            <strong>No. Rekening:</strong> <?= htmlspecialchars($settings['bank_account']) ?>
                            <span class="divider"></span>
                            <strong>A/N:</strong> <?= htmlspecialchars($settings['bank_holder']) ?>
                            <br>
                            <strong>Metode:</strong> <span style="text-transform: capitalize;"><?= str_replace('_', ' ', $order['payment_method']) ?></span>
                        <?php endif; ?>
                        <span class="divider"></span>
                        <strong>Total:</strong> <span style="color: #E8853B; font-weight: 700;">Rp <?= number_format($order['total'], 0, ',', '.') ?></span>
                    </div>
                </div>

                <?php if ($order['notes']): ?>
                <div class="inv-notes">
                    <p>
                        <i class="fas fa-sticky-note" style="color: #D97706; margin-right: 6px;"></i>
                        <strong>Catatan:</strong> <?= nl2br(htmlspecialchars($order['notes'])) ?>
                    </p>
                </div>
                <?php endif; ?>

                <!-- QR Code -->
                <div class="inv-verification">
                    <div style="flex: 1;">
                        <p style="font-size: 11px; color: #A0886A; line-height: 1.6;">
                            <i class="fas fa-shield-alt" style="color: #10B981;"></i>
                            <strong style="color: #2C1810;">Verifikasi Invoice</strong><br>
                            Scan QR code atau kunjungi:<br>
                            <span style="font-family: monospace; font-size: 10px; color: #D4A853; word-break: break-all;">
                                <?= htmlspecialchars($qrVerificationUrl) ?>
                            </span>
                        </p>
                    </div>
                    <div class="inv-qr">
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=<?= urlencode($qrVerificationUrl) ?>" 
                             alt="QR Code" 
                             loading="lazy"
                             onerror="this.style.display='none'">
                        <div class="inv-qr-label">Scan untuk verifikasi</div>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <div class="inv-footer">
                <div class="inv-footer-brand">★ Nadhira Napoleon ★</div>
                <div class="inv-footer-text">
                    <em>"<?= htmlspecialchars(getSetting('footer_tagline', 'Membawa Cita Rasa Khas Riau Dalam Setiap Gigitan')) ?>"</em><br>
                    Terima kasih telah berbelanja di <?= htmlspecialchars(getSetting('site_name', 'Nadhira Napoleon')) ?> Pekanbaru.
                </div>
                <div class="inv-footer-legal">
                    <i class="fas fa-check-circle" style="color: #10B981;"></i>
                    Invoice ini sah dan diproses secara elektronik.
                    Dicetak pada <?= date('d/m/Y H:i') ?> WIB
                </div>
            </div>

            <!-- Payment CTA -->
            <?php if ($order['payment_status'] === 'pending' && $order['order_status'] !== 'cancelled'): ?>
            <div class="inv-payment-cta">
                <div class="inv-confirm-btn">
                    <?php if ($order['payment_method'] === 'midtrans'): ?>
                    <a href="<?= SITE_URL ?>/pages/payment.php?order=<?= urlencode($order['order_number']) ?>&email=<?= urlencode($order['customer_email']) ?>" class="btn">
                        <i class="fas fa-credit-card"></i>
                        Bayar Sekarang
                    </a>
                    <div class="hint">
                        <i class="fas fa-info-circle"></i>
                        Selesaikan pembayaran online melalui Midtrans (Virtual Account, QRIS, E-Wallet, Kartu Kredit)
                    </div>
                    <?php else: ?>
                    <a href="<?= SITE_URL ?>/pages/payment-confirm.php?order=<?= urlencode($order['order_number']) ?>" class="btn">
                        <i class="fas fa-credit-card"></i>
                        Konfirmasi Pembayaran
                    </a>
                    <div class="hint">
                        <i class="fas fa-info-circle"></i>
                        Sudah transfer? Klik tombol di atas untuk upload bukti pembayaran
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Auto-print if ?print=1 is set
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('print') === '1') {
            window.onload = function() {
                setTimeout(function() { window.print(); }, 600);
            };
        }

        // Copy verification link to clipboard
        function copyVerificationLink() {
            const link = '<?= htmlspecialchars($qrVerificationUrl) ?>';
            navigator.clipboard.writeText(link).then(() => {
                const toast = document.getElementById('toastCopied');
                toast.classList.add('show');
                setTimeout(() => toast.classList.remove('show'), 2500);
            });
        }

        <?php if ($order['payment_method'] === 'midtrans' && $order['payment_status'] === 'pending' && $payFlag !== ''): ?>
        // Sinkronisasi status pembayaran Midtrans: hanya saat kembali dari halaman
        // pembayaran (?pay=...), agar tidak memanggil API Midtrans di setiap buka halaman.
        (function () {
            var orderNo = <?= json_encode($order['order_number']) ?>;
            var email = <?= json_encode($order['customer_email']) ?>;
            fetch('<?= SITE_URL ?>/ajax/midtrans-status.php?order=' + encodeURIComponent(orderNo) + '&email=' + encodeURIComponent(email))
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data && data.success && data.payment_status === 'paid') {
                        window.location.reload();
                    }
                })
                .catch(function () { /* abaikan bila server tidak merespons */ });
        })();
        <?php endif; ?>
    </script>

</body>
</html>
