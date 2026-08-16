<?php
// ============================================
// DOWNLOAD INVOICE PDF - NADHIRA NAPOLEON
// Generate invoice as proper PDF file using Dompdf
// ============================================
require_once '../config/database.php';
require_once '../config/midtrans.php';
require_once '../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

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

// Access control
$accessDenied = true;
if ($isAdmin && isLoggedIn()) {
    $user = getCurrentUser();
    if ($user && $user['role'] === 'admin') {
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
    'footer_tagline' => getSetting('footer_tagline', 'Membawa Cita Rasa Khas Riau Dalam Setiap Gigitan'),
    'site_name' => getSetting('site_name', 'Nadhira Napoleon'),
];

// Status labels
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

$totalWeight = 0;
foreach ($orderItems as $item) {
    $totalWeight += ($item['weight'] ?? 0) * $item['quantity'];
}

// Build HTML for PDF
$html = '
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <style>
        @page {
            margin: 0;
            size: A4;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: "DejaVu Sans", sans-serif;
            color: #2C1810;
            font-size: 10pt;
            line-height: 1.5;
        }
        
        /* Watermark */
        .watermark {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-30deg);
            font-size: 72pt;
            font-weight: 700;
            color: ' . ($order['payment_status'] === 'paid' ? 'rgba(5, 150, 105, 0.08)' : 'rgba(245, 158, 11, 0.08)') . ';
            text-transform: uppercase;
            letter-spacing: 20px;
            z-index: 0;
            pointer-events: none;
            white-space: nowrap;
        }
        
        .invoice-wrap {
            position: relative;
            z-index: 1;
            padding: 0;
        }
        
        /* Header */
        .header {
            background: linear-gradient(135deg, #2C1810 0%, #5C3A21 100%);
            padding: 30px 40px;
            color: #FFFFFF;
        }
        
        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }
        
        .brand {
            font-size: 24pt;
            font-weight: 700;
            font-family: "DejaVu Serif", serif;
        }
        .brand span { color: #D4A853; }
        
        .brand-tagline {
            font-size: 8pt;
            color: rgba(255,255,255,0.6);
            margin-top: 2px;
        }
        
        .invoice-title-box {
            text-align: right;
        }
        
        .invoice-badge {
            display: inline-block;
            padding: 4px 16px;
            background: rgba(212,168,83,0.2);
            border: 1px solid rgba(212,168,83,0.3);
            border-radius: 50px;
            font-size: 8pt;
            font-weight: 600;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: #D4A853;
        }
        
        .invoice-number {
            font-size: 10pt;
            color: rgba(255,255,255,0.6);
            margin-top: 4px;
        }
        
        /* Body */
        .body {
            padding: 30px 40px;
        }
        
        /* Address Section */
        .address-section {
            display: flex;
            justify-content: space-between;
            margin-bottom: 24px;
            padding-bottom: 24px;
            border-bottom: 2px solid #F0EDE8;
        }
        
        .address-box {
            width: 48%;
        }
        
        .address-title {
            font-size: 8pt;
            font-weight: 600;
            color: #8B6F47;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 6px;
        }
        
        .address-name {
            font-weight: 700;
            font-size: 11pt;
            margin-bottom: 2px;
        }
        
        .address-detail {
            font-size: 9pt;
            color: #5C3A21;
            line-height: 1.6;
        }
        
        /* Meta Info */
        .meta-section {
            display: flex;
            justify-content: space-between;
            margin-bottom: 24px;
            padding-bottom: 24px;
            border-bottom: 2px solid #F0EDE8;
        }
        
        .meta-item {
            width: 30%;
        }
        
        .meta-label {
            font-size: 7pt;
            color: #A0886A;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 2px;
        }
        
        .meta-value {
            font-weight: 600;
            font-size: 10pt;
        }
        
        .status-pill {
            display: inline-block;
            padding: 3px 12px;
            border-radius: 50px;
            font-size: 8pt;
            font-weight: 600;
            background: ' . $statusColors[$order['payment_status']] . ';
            color: #FFFFFF;
        }
        
        /* Table */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        
        .items-table th {
            text-align: left;
            padding: 10px 12px;
            border-bottom: 2px solid #2C1810;
            font-size: 8pt;
            font-weight: 600;
            color: #2C1810;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .items-table th:last-child,
        .items-table td:last-child {
            text-align: right;
        }
        
        .items-table th:nth-child(2),
        .items-table th:nth-child(3),
        .items-table td:nth-child(2),
        .items-table td:nth-child(3) {
            text-align: center;
        }
        
        .items-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #F0EDE8;
            font-size: 9pt;
        }
        
        .items-table .product-name {
            font-weight: 600;
        }
        
        .items-table tbody tr:last-child td {
            border-bottom: 1px solid #F0EDE8;
        }
        
        /* Totals */
        .totals-section {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 24px;
        }
        
        .totals-table {
            width: 280px;
            border-collapse: collapse;
        }
        
        .totals-table td {
            padding: 5px 0;
            font-size: 9pt;
        }
        
        .totals-table td:first-child {
            text-align: left;
            color: #5C3A21;
        }
        
        .totals-table td:last-child {
            text-align: right;
            font-weight: 600;
        }
        
        .totals-table .grand-total td {
            padding-top: 10px;
            border-top: 2px solid #2C1810;
            font-size: 13pt;
            font-weight: 700;
            color: #E8853B;
        }
        
        .totals-table .discount td:last-child {
            color: #059669;
        }
        
        /* Payment Info */
        .payment-section {
            padding: 16px 20px;
            background: #FFF5E6;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .payment-title {
            font-size: 9pt;
            font-weight: 600;
            color: #2C1810;
            margin-bottom: 8px;
        }
        
        .payment-detail {
            font-size: 8pt;
            color: #5C3A21;
            line-height: 1.8;
        }
        
        /* Footer */
        .footer {
            padding: 20px 40px;
            border-top: 1px solid #F0EDE8;
            text-align: center;
        }
        
        .footer-brand {
            font-size: 12pt;
            font-weight: 600;
            color: #2C1810;
            margin-bottom: 4px;
        }
        
        .footer-text {
            font-size: 8pt;
            color: #A0886A;
            line-height: 1.6;
        }
        
        .footer-legal {
            font-size: 7pt;
            color: #A0886A;
            margin-top: 4px;
        }
        
        /* Notes */
        .notes-box {
            padding: 12px 16px;
            background: #FFFBEB;
            border-radius: 6px;
            margin-top: 12px;
        }
        .notes-box p {
            font-size: 8pt;
            color: #92400E;
        }
        .notes-box strong {
            font-weight: 600;
        }
        
        /* QR Section */
        .qr-section {
            display: flex;
            justify-content: flex-end;
            margin-top: 10px;
        }
        
        .qr-box {
            text-align: center;
        }
        
        .qr-label {
            font-size: 6pt;
            color: #A0886A;
            margin-top: 2px;
        }
    </style>
</head>
<body>
    <div class="watermark">' . strtoupper($statusLabels[$order['payment_status']]) . '</div>
    
    <div class="invoice-wrap">
        <!-- Header -->
        <div class="header">
            <div class="header-top">
                <div>
                    <div class="brand">Nadhira <span>Napoleon</span></div>
                    <div class="brand-tagline">Premium Oleh-Oleh Khas Riau</div>
                </div>
                <div class="invoice-title-box">
                    <div class="invoice-badge">INVOICE</div>
                    <div class="invoice-number">#' . htmlspecialchars($order['order_number']) . '</div>
                </div>
            </div>
        </div>
        
        <!-- Body -->
        <div class="body">
            <!-- Addresses -->
            <div class="address-section">
                <div class="address-box">
                    <div class="address-title">Dari</div>
                    <div class="address-name">Nadhira Napoleon Pekanbaru</div>
                    <div class="address-detail">
                        ' . htmlspecialchars($settings['contact_address']) . '<br>
                        Telp: ' . htmlspecialchars($settings['contact_phone']) . '<br>
                        Email: ' . htmlspecialchars($settings['contact_email']) . '
                    </div>
                </div>
                <div class="address-box">
                    <div class="address-title">Kepada</div>
                    <div class="address-name">' . htmlspecialchars($order['customer_name']) . '</div>
                    <div class="address-detail">
                        ' . nl2br(htmlspecialchars($order['shipping_address'])) . '<br>
                        ' . htmlspecialchars($order['shipping_city']) . ', ' . htmlspecialchars($order['shipping_province']) . ' ' . htmlspecialchars($order['shipping_postal_code']) . '<br>
                        Telp: ' . htmlspecialchars($order['customer_phone']) . '<br>
                        Email: ' . htmlspecialchars($order['customer_email']) . '
                    </div>
                </div>
            </div>
            
            <!-- Meta -->
            <div class="meta-section">
                <div class="meta-item">
                    <div class="meta-label">Tanggal Invoice</div>
                    <div class="meta-value">' . date('d/m/Y', strtotime($order['created_at'])) . '</div>
                </div>
                <div class="meta-item">
                    <div class="meta-label">Metode Pembayaran</div>
                    <div class="meta-value" style="text-transform: capitalize;">' . ($order['payment_method'] === 'midtrans' ? 'Midtrans' : str_replace('_', ' ', $order['payment_method'])) . '</div>
                </div>
                <div class="meta-item">
                    <div class="meta-label">Status Pembayaran</div>
                    <div><span class="status-pill">' . $statusLabels[$order['payment_status']] . '</span></div>
                </div>
            </div>
            
            <!-- Items Table -->
            <table class="items-table">
                <thead>
                    <tr>
                        <th style="width: 45%;">Produk</th>
                        <th style="width: 18%;">Harga</th>
                        <th style="width: 12%;">Qty</th>
                        <th style="width: 25%;">Subtotal</th>
                    </tr>
                </thead>
                <tbody>';

foreach ($orderItems as $item) {
    $html .= '
                    <tr>
                        <td class="product-name">' . htmlspecialchars($item['product_name']) . '</td>
                        <td>Rp ' . number_format($item['price'], 0, ',', '.') . '</td>
                        <td>' . $item['quantity'] . '</td>
                        <td>Rp ' . number_format($item['subtotal'], 0, ',', '.') . '</td>
                    </tr>';
}

$html .= '
                </tbody>
            </table>
            
            <!-- Totals -->
            <div class="totals-section">
                <table class="totals-table">
                    <tr>
                        <td>Subtotal</td>
                        <td>Rp ' . number_format($order['subtotal'], 0, ',', '.') . '</td>
                    </tr>
                    <tr>
                        <td>Ongkos Kirim</td>
                        <td>' . ($order['shipping_cost'] > 0 ? 'Rp ' . number_format($order['shipping_cost'], 0, ',', '.') : '<strong style="color:#059669;">GRATIS</strong>') . '</td>
                    </tr>';

if ($order['discount'] > 0) {
    $html .= '
                    <tr class="discount">
                        <td>Diskon' . (!empty($order['promo_code']) ? ' (kode promo)' : '') . '</td>
                        <td>-Rp ' . number_format($order['discount'], 0, ',', '.') . '</td>
                    </tr>';
}

$html .= '
                    <tr class="grand-total">
                        <td>Total Pembayaran</td>
                        <td>Rp ' . number_format($order['total'], 0, ',', '.') . '</td>
                    </tr>
                </table>';

if (!empty($order['promo_code'])) {
    $html .= '
                    <div style="margin-top:8px; font-size:10px; color:#888;">
                        <i class="fas fa-ticket-alt" style="color:#D4A853;"></i> Kode promo: <strong>' . htmlspecialchars($order['promo_code']) . '</strong>
                    </div>';
}

$html .= '            </div>
            ';

// Detail pembayaran PDF: tampilkan info Midtrans bila pesanan memakai Midtrans
$payDetail = '<strong>Metode:</strong> Midtrans';
if ($order['payment_method'] === 'midtrans') {
    if (!empty($order['midtrans_payment_type'])) {
        $payDetail .= ' &nbsp;|&nbsp; <strong>Kanal:</strong> ' . htmlspecialchars(midtransPaymentLabel($order['midtrans_payment_type']));
    }
    if (!empty($order['midtrans_bank'])) {
        $payDetail .= ' &nbsp;|&nbsp; <strong>Bank:</strong> ' . htmlspecialchars(strtoupper($order['midtrans_bank']));
    }
    if (!empty($order['midtrans_va_number'])) {
        $payDetail .= '<br><strong>No. Virtual Account:</strong> ' . htmlspecialchars($order['midtrans_va_number']);
    }
    if (!empty($order['midtrans_transaction_id'])) {
        $payDetail .= ' &nbsp;|&nbsp; <strong>Ref:</strong> ' . htmlspecialchars($order['midtrans_transaction_id']);
    }
} else {
    $payDetail = '<strong>Bank:</strong> ' . htmlspecialchars($settings['bank_name']) . ' &nbsp;|&nbsp; <strong>No. Rekening:</strong> ' . htmlspecialchars($settings['bank_account']) . ' &nbsp;|&nbsp; <strong>A/N:</strong> ' . htmlspecialchars($settings['bank_holder']) . '<br><strong>Metode:</strong> ' . ucfirst(str_replace('_', ' ', $order['payment_method']));
}

$html .= '
            <!-- Payment Info -->
            <div class="payment-section">
                <div class="payment-title">Informasi Pembayaran</div>
                <div class="payment-detail">
                    ' . $payDetail . '
                </div>
            </div>';

if ($order['notes']) {
    $html .= '
            <div class="notes-box">
                <p><strong>Catatan:</strong> ' . nl2br(htmlspecialchars($order['notes'])) . '</p>
            </div>';
}

$html .= '
        </div>
        
        <!-- Footer -->
        <div class="footer">
            <div class="footer-brand">' . htmlspecialchars($settings['site_name']) . '</div>
            <div class="footer-text">
                "' . htmlspecialchars($settings['footer_tagline']) . '"<br>
                Terima kasih telah berbelanja di ' . htmlspecialchars($settings['site_name']) . ' Pekanbaru.
            </div>
            <div class="footer-legal">
                Invoice ini sah dan diproses secara elektronik. 
                Untuk verifikasi, kunjungi ' . SITE_URL . '/pages/tracking.php?order_number=' . urlencode($order['order_number']) . '<br>
                Tanggal Cetak: ' . date('d/m/Y H:i') . ' WIB
            </div>
        </div>
    </div>
</body>
</html>';

// Configure Dompdf
$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true);
$options->set('defaultPaperSize', 'A4');
$options->set('defaultPaperOrientation', 'portrait');
$options->set('dpi', 96);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Generate filename
$filename = 'INV-' . $order['order_number'] . '-' . date('Ymd') . '.pdf';

// Output PDF for download
$dompdf->stream($filename, [
    'Attachment' => true
]);
exit;
