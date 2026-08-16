<?php
// ============================================
// MIDTRANS PAYMENT GATEWAY HELPER
// Website Nadhira Napoleon Pekanbaru
// ============================================
// Integrasi Midtrans Snap (Virtual Account, QRIS, E-Wallet, Kartu Kredit).
// Memakai cURL langsung ke REST API Midtrans (tanpa dependensi composer).
//
// Konfigurasi disimpan di tabel settings:
//   midtrans_server_key       -> Server Key (Sandbox/Production)
//   midtrans_client_key       -> Client Key (Sandbox/Production)
//   midtrans_is_production    -> '1' = Production, '0' = Sandbox
// ============================================

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/rbac.php'; // untuk notifyRole() saat pembayaran lunas

// ============================================
// SKEMA DATABASE - self-healing
// ============================================
// Pastikan kolom & nilai enum Midtrans tersedia di tabel orders,
// plus kunci settings Midtrans. Dipanggil dari halaman yang memakai Midtrans.
function ensureMidtransSchema() {
    static $done = false;
    if ($done) return true;
    $conn = getConnection();
    if (!$conn) return false;

    // 1) Tambahkan 'midtrans' ke enum payment_method
    $r = $conn->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'payment_method'");
    if ($r && $r->num_rows > 0) {
        $type = $r->fetch_assoc()['COLUMN_TYPE'];
        if (stripos($type, 'midtrans') === false) {
            $conn->query("ALTER TABLE orders MODIFY payment_method ENUM('transfer_bank','cod','e_wallet','midtrans') DEFAULT 'midtrans'");
        }
    }

    // 2) Kolom detail pembayaran Midtrans
    $columns = [
        'midtrans_transaction_id' => "ALTER TABLE orders ADD COLUMN midtrans_transaction_id VARCHAR(100) NULL AFTER payment_status",
        'midtrans_payment_type'   => "ALTER TABLE orders ADD COLUMN midtrans_payment_type VARCHAR(50) NULL AFTER midtrans_transaction_id",
        'midtrans_va_number'      => "ALTER TABLE orders ADD COLUMN midtrans_va_number VARCHAR(64) NULL AFTER midtrans_payment_type",
        'midtrans_bank'           => "ALTER TABLE orders ADD COLUMN midtrans_bank VARCHAR(50) NULL AFTER midtrans_va_number",
        'paid_at'                 => "ALTER TABLE orders ADD COLUMN paid_at DATETIME NULL AFTER midtrans_bank",
    ];
    foreach ($columns as $col => $sql) {
        $check = $conn->query("SELECT COUNT(*) c FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = '$col'");
        if ($check && (int)$check->fetch_assoc()['c'] === 0) {
            $conn->query($sql);
        }
    }

    // 3) Kunci settings Midtrans
    $settings = [
        'midtrans_server_key'    => '',
        'midtrans_client_key'    => '',
        'midtrans_is_production' => '0',
    ];
    foreach ($settings as $key => $value) {
        $conn->query("INSERT INTO settings (setting_key, setting_value) VALUES ('$key', '$value')
                      ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key)");
    }

    $done = true;
    return true;
}

// ============================================
// KONFIGURASI
// ============================================
function midtransServerKey()     { return trim(getSetting('midtrans_server_key', '')); }
function midtransClientKey()     { return trim(getSetting('midtrans_client_key', '')); }
function midtransIsProduction()  { return getSetting('midtrans_is_production', '0') === '1'; }

// Base URL halaman Snap (snap.js + pembuatan token), otomatis mengikuti mode
function midtransBaseUrl() {
    return midtransIsProduction() ? 'https://app.midtrans.com' : 'https://app.sandbox.midtrans.com';
}

// Base URL Core API (endpoint /v2/... seperti charge & cek status).
// PENTING: host-nya BEDA dari Snap — pakai api.midtrans.com (bukan app.midtrans.com),
// kalau salah, semua request /v2/ akan dibalas 404 oleh Midtrans.
function midtransApiBaseUrl() {
    return midtransIsProduction() ? 'https://api.midtrans.com' : 'https://api.sandbox.midtrans.com';
}

// ============================================
// LABEL BAHASA INDONESIA untuk payment_type Midtrans
// ============================================
function midtransPaymentLabel($type) {
    $labels = [
        'credit_card'      => 'Kartu Kredit',
        'bank_transfer'    => 'Transfer Bank (Virtual Account)',
        'banktransfer'     => 'Transfer Bank',
        'echannel'         => 'Mandiri Bill Payment',
        'bca_klikpay'      => 'BCA KlikPay',
        'bca_klikbca'      => 'KlikBCA',
        'bri_epay'         => 'BRI E-Pay',
        'cimb_clicks'      => 'CIMB Clicks',
        'danamon_online'   => 'Danamon Online Banking',
        'qris'             => 'QRIS',
        'gopay'            => 'GoPay',
        'shopeepay'        => 'ShopeePay',
        'ovo'              => 'OVO',
        'dana'             => 'DANA',
        'linkaja'          => 'LinkAja',
        'akulaku'          => 'Akulaku',
        'kredivo'          => 'Kredivo',
        'cstore'           => 'Convenience Store',
        'indomaret'        => 'Indomaret',
        'alfamart'         => 'Alfamart',
    ];
    return $labels[$type] ?? ($type !== '' ? ucwords(str_replace('_', ' ', $type)) : 'Midtrans');
}

// ============================================
// BUAT SNAP TOKEN
// ============================================
// Mengirim request ke POST /snap/v1/transactions.
// Mengembalikan ['success' => true, 'token' => ..., 'redirect_url' => ...]
// atau ['success' => false, 'message' => ...]
function midtransCreateSnapToken($order, $orderItems) {
    $serverKey = midtransServerKey();
    if ($serverKey === '') {
        return ['success' => false, 'message' => 'Server Key Midtrans belum diisi di Pengaturan.'];
    }
    if (!function_exists('curl_init')) {
        return ['success' => false, 'message' => 'Ekstensi cURL tidak tersedia di server.'];
    }

    // item_details: produk + ongkir + diskon agar total konsisten
    $itemDetails = [];
    foreach ($orderItems as $item) {
        $price = (int)round((float)$item['price']);
        $qty = max(1, (int)$item['quantity']);
        if ($price <= 0) continue;
        $itemDetails[] = [
            'id'       => 'ITM-' . $item['product_id'],
            'price'    => $price,
            'quantity' => $qty,
            'name'     => mb_substr(trim($item['product_name']), 0, 50),
        ];
    }
    if ((float)$order['shipping_cost'] > 0) {
        $itemDetails[] = [
            'id'       => 'SHIPPING',
            'price'    => (int)round((float)$order['shipping_cost']),
            'quantity' => 1,
            'name'     => 'Ongkos Kirim',
        ];
    }
    if ((float)$order['discount'] > 0) {
        $itemDetails[] = [
            'id'       => 'DISCOUNT',
            'price'    => -1 * (int)round((float)$order['discount']),
            'quantity' => 1,
            'name'     => 'Diskon',
        ];
    }

    $payload = [
        'transaction_details' => [
            'order_id'     => $order['order_number'],
            'gross_amount' => (int)round((float)$order['total']),
        ],
        'item_details' => $itemDetails,
        'customer_details' => [
            'first_name' => mb_substr(trim($order['customer_name']), 0, 60),
            'email'      => $order['customer_email'],
            'phone'      => $order['customer_phone'],
        ],
        'expiry' => ['unit' => 'hours', 'duration' => 24],
    ];

    $url = midtransBaseUrl() . '/snap/v1/transactions';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Basic ' . base64_encode($serverKey . ':'),
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['success' => false, 'message' => 'Gagal terhubung ke Midtrans: ' . $curlError];
    }
    $result = json_decode($response, true);
    if ($httpCode !== 200 && $httpCode !== 201) {
        $msg = $result['error_messages'][0] ?? ('HTTP ' . $httpCode . ' dari Midtrans');
        return ['success' => false, 'message' => 'Midtrans menolak transaksi: ' . $msg];
    }
    if (empty($result['token'])) {
        return ['success' => false, 'message' => 'Tidak ada token Snap yang diterima dari Midtrans.'];
    }
    return [
        'success'      => true,
        'token'        => $result['token'],
        'redirect_url' => $result['redirect_url'] ?? '',
    ];
}

// ============================================
// VERIFIKASI SIGNATURE NOTIFIKASI
// ============================================
// Midtrans menandatangani webhook dengan SHA512(order_id + status_code + gross_amount + ServerKey)
function midtransVerifySignature($orderId, $statusCode, $grossAmount, $receivedSignature) {
    if ($receivedSignature === '' || $receivedSignature === null) return false;
    $expected = hash('sha512', $orderId . $statusCode . $grossAmount . midtransServerKey());
    return hash_equals($expected, (string)$receivedSignature);
}

// ============================================
// CEK STATUS TRANSAKSI (GET /v2/{order_id}/status)
// ============================================
// Dipakai saat webhook belum bisa menjangkau server (mis. masih localhost),
// dengan cara meminta status langsung ke Midtrans.
function midtransGetTransactionStatus($orderNumber) {
    $serverKey = midtransServerKey();
    if ($serverKey === '') return ['success' => false, 'message' => 'Server Key Midtrans belum diisi.'];
    $url = midtransApiBaseUrl() . '/v2/' . rawurlencode($orderNumber) . '/status';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'Authorization: Basic ' . base64_encode($serverKey . ':'),
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) return ['success' => false, 'message' => 'Gagal terhubung ke Midtrans.'];
    $result = json_decode($response, true);
    if (!is_array($result)) return ['success' => false, 'message' => 'Respons dari Midtrans tidak valid.'];
    // Perhatikan: di host api.midtrans.com, transaksi yang tidak ada dikembalikan
    // sebagai HTTP 200 dengan status_code "404" di dalam body (bukan HTTP 404).
    if ($httpCode === 404 || ($result['status_code'] ?? '') === '404') {
        return ['success' => false, 'message' => 'Transaksi belum ditemukan di Midtrans.'];
    }
    if ($httpCode !== 200) return ['success' => false, 'message' => 'Midtrans merespons dengan HTTP ' . $httpCode];
    return ['success' => true, 'data' => $result];
}

// ============================================
// TERAPKAN STATUS TRANSAKSI KE PESANAN
// ============================================
// Dipakai oleh webhook (midtrans-notification.php) & cek status (ajax/midtrans-status.php).
// Memperbarui payment_status + detail pembayaran, lalu menyinkronkan total_sold.
function midtransApplyTransactionStatus($order, $data) {
    $conn = getConnection();
    if (!$conn || !$order || !is_array($data)) return false;

    $orderId = (int)$order['id'];
    $txStatus  = $data['transaction_status'] ?? '';
    $fraud     = $data['fraud_status'] ?? '';
    $paymentType = $data['payment_type'] ?? '';
    $txId      = $data['transaction_id'] ?? '';

    // Ekstrak nomor VA / bank dari berbagai format notifikasi
    $vaNumber = '';
    $bank = '';
    if (!empty($data['va_numbers'][0])) {
        $vaNumber = $data['va_numbers'][0]['va_number'] ?? '';
        $bank     = $data['va_numbers'][0]['bank'] ?? '';
    } elseif (!empty($data['permata_va_number'])) {
        $vaNumber = $data['permata_va_number'];
    } elseif (!empty($data['bill_key'])) {
        $vaNumber = ($data['biller_code'] ?? '') . ' / ' . $data['bill_key'];
        $bank     = 'Mandiri Bill Payment';
    }
    if ($bank === '') $bank = $data['bank'] ?? '';

    $e = function ($v) use ($conn) { return $conn->real_escape_string((string)$v); };

    // LUNAS: settlement selalu lunas; capture (kartu kredit) hanya lunas
    // jika fraud_status = 'accept' (challenge/deny TIDAK dianggap lunas).
    $isPaid = $txStatus === 'settlement' || ($txStatus === 'capture' && $fraud === 'accept');

    if ($isPaid) {
        if ($order['payment_status'] === 'paid') return true; // idempoten
        $ok = $conn->query("UPDATE orders SET
                payment_status = 'paid',
                paid_at = NOW(),
                midtrans_transaction_id = '" . $e($txId) . "',
                midtrans_payment_type = '" . $e($paymentType) . "',
                midtrans_va_number = '" . $e($vaNumber) . "',
                midtrans_bank = '" . $e($bank) . "'
            WHERE id = $orderId");
        if ($ok) {
            countOrderSold($orderId); // sinkron total_sold produk (idempoten)
            activateMembershipForOrder($orderId); // aktifkan langganan membership bila pesanan berisi paket
            // Poin & total belanja HANYA diberikan saat pembayaran LUNAS (idempoten, sekali saja)
            if (!empty($order['user_id'])) {
                $pointsEarned = awardOrderRewards((int)$order['user_id'], $order['subtotal'], $order['order_number'], $orderId);
                if ($pointsEarned > 0 && session_status() === PHP_SESSION_ACTIVE) {
                    $_SESSION['order_points_earned'] = $pointsEarned;
                }
            }
            if (function_exists('logActivity')) {
                logActivity('update', 'orders', "Pembayaran Midtrans LUNAS untuk #{$order['order_number']} (" . midtransPaymentLabel($paymentType) . ")");
            }
            // 🔔 Notifikasi suara/desktop ke role penjualan (default: Admin Penjualan Online)
            // saat pembayaran berhasil LUNAS — dipasang di titik tunggal ini agar tidak ganda
            // (guard idempoten di atas: hanya jalan sekali saat transisi ke paid).
            if (function_exists('notifyPaymentPaid')) {
                notifyPaymentPaid($orderId, $order['order_number'], $order['total'], midtransPaymentLabel($paymentType));
            }
        }
        return $ok;
    }

    if (in_array($txStatus, ['deny', 'cancel', 'expire'], true)) {
        if ($order['payment_status'] === 'failed') {
            return true; // sudah gagal, tidak perlu diubah lagi
        }
        // Jika tadinya sudah lunas lalu dibatalkan, kembalikan total_sold & reward membership
        if ($order['payment_status'] === 'paid') {
            reverseOrderSold($orderId);
            if (!empty($order['user_id'])) {
                reverseOrderRewards((int)$order['user_id'], $order['subtotal'], $order['order_number'], $orderId);
            }
        }
        refundPointsForOrder($orderId); // kembalikan poin yang ditukar jadi diskon
        $ok = $conn->query("UPDATE orders SET
                payment_status = 'failed',
                midtrans_transaction_id = '" . $e($txId) . "',
                midtrans_payment_type = '" . $e($paymentType) . "'
            WHERE id = $orderId");
        if ($ok && function_exists('logActivity')) {
            logActivity('update', 'orders', "Pembayaran Midtrans GAGAL untuk #{$order['order_number']} ({$txStatus})");
        }
        return $ok;
    }

    // Pending: simpan detail saja, tanpa ubah status
    if ($txStatus === 'pending') {
        return $conn->query("UPDATE orders SET
                midtrans_transaction_id = '" . $e($txId) . "',
                midtrans_payment_type = '" . $e($paymentType) . "',
                midtrans_va_number = '" . $e($vaNumber) . "',
                midtrans_bank = '" . $e($bank) . "'
            WHERE id = $orderId");
    }

    // Refund / partial_refund: pesanan lunas yang dikembalikan dana
    // Balik total_sold, reward membership, dan langganan (seperti pembatalan manual),
    // lalu tandai payment_status = refunded.
    if (in_array($txStatus, ['refund', 'partial_refund'], true)) {
        if ($order['payment_status'] === 'paid') {
            reverseOrderSold($orderId);
            if (!empty($order['user_id'])) {
                reverseOrderRewards((int)$order['user_id'], $order['subtotal'], $order['order_number'], $orderId);
            }
            cancelMembershipForOrder($orderId);
        }
        refundPointsForOrder($orderId); // kembalikan poin yang ditukar jadi diskon
        $ok = $conn->query("UPDATE orders SET
                payment_status = 'refunded',
                midtrans_transaction_id = '" . $e($txId) . "',
                midtrans_payment_type = '" . $e($paymentType) . "'
            WHERE id = $orderId");
        if ($ok && function_exists('logActivity')) {
            logActivity('update', 'orders', "Pembayaran Midtrans DI-REFUND untuk #{$order['order_number']} ({$txStatus})");
        }
        return $ok;
    }

    return true;
}
