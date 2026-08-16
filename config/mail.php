<?php
// ============================================
// NOTIFIKASI EMAIL — PHPMailer + SMTP
// Website Nadhira Napoleon Pekanbaru
// ============================================
// Dipakai untuk: konfirmasi pesanan, notifikasi nomor resi,
// reset password, dan broadcast newsletter.
// Konfigurasi SMTP di Admin > Pengaturan > "Email Notifikasi (SMTP)".
//
// Catatan hosting: InfinityFree memblokir fungsi mail() bawaan PHP,
// sehingga pengiriman WAJIB lewat SMTP eksternal (mis. Gmail dengan
// App Password, bukan password biasa).
//
// Semua fungsi di file ini TIDAK melempar exception ke pemanggil:
// bila SMTP belum dikonfigurasi / gagal, dicatat di error_log dan
// dikembalikan sebagai array hasil, agar alur utama (order, admin)
// tidak pernah gagal hanya karena email.
// ============================================

require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailerException;

// Apakah SMTP sudah dikonfigurasi & diaktifkan di Admin > Pengaturan?
function mailIsConfigured() {
    if (getSetting('mail_enabled', '1') !== '1') return false;
    foreach (['mail_host', 'mail_user', 'mail_pass', 'mail_from_email'] as $k) {
        if (trim(getSetting($k, '')) === '') return false;
    }
    return true;
}

// Nama pengirim (default: nama toko)
function mailFromName() {
    $n = trim(getSetting('mail_from_name', ''));
    return $n !== '' ? $n : (defined('SITE_NAME') ? SITE_NAME : 'Website');
}

// Instance PHPMailer siap pakai; null jika SMTP belum dikonfigurasi.
function getMailer() {
    if (!mailIsConfigured()) return null;
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = getSetting('mail_host', 'smtp.gmail.com');
    $mail->SMTPAuth   = true;
    $mail->Username   = getSetting('mail_user', '');
    $mail->Password   = getSetting('mail_pass', '');
    $mail->Port       = (int)getSetting('mail_port', '587');
    $enc = strtolower(getSetting('mail_encryption', 'tls'));
    $mail->SMTPSecure = in_array($enc, ['ssl', 'tls', ''], true) ? $enc : 'tls';
    $mail->CharSet    = 'UTF-8';
    $mail->isHTML(true);
    $mail->setFrom(getSetting('mail_from_email', ''), mailFromName());
    return $mail;
}

// Kirim email. Selalu mengembalikan ['ok' => bool, 'error' => string].
function sendMail($to, $subject, $htmlBody, $altBody = '') {
    try {
        $mail = getMailer();
        if (!$mail) {
            error_log('[MAIL] SMTP belum dikonfigurasi — email ke ' . $to . ' dilewati: ' . $subject);
            return ['ok' => false, 'error' => 'SMTP belum dikonfigurasi di Admin > Pengaturan.'];
        }
        $mail->clearAddresses();
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = $altBody !== '' ? $altBody : htmlToText($htmlBody);
        $mail->send();
        return ['ok' => true, 'error' => ''];
    } catch (MailerException $e) {
        error_log('[MAIL] Gagal kirim ke ' . $to . ': ' . $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage()];
    } catch (Exception $e) {
        error_log('[MAIL] Gagal kirim ke ' . $to . ': ' . $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

// Ubah HTML sederhana menjadi teks polos (untuk AltBody klien email lama).
function htmlToText($html) {
    $text = preg_replace('#<br\s*/?>#i', "\n", $html);
    $text = preg_replace('#</(p|div|tr|h1|h2|h3|li)>#i', "\n", $text);
    $text = preg_replace('#<td[^>]*>#i', "\t", $text);
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    return trim(preg_replace('/\n{3,}/', "\n\n", $text));
}

// Tombol CTA di dalam email
function mailButton($label, $url) {
    return '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" style="display:inline-block;background:linear-gradient(135deg,#D4A853,#B8860B);color:#FFFFFF;text-decoration:none;padding:12px 28px;border-radius:8px;font-weight:bold;font-size:14px;">' . htmlspecialchars($label) . '</a>';
}

// Kerangka email HTML premium bertema emas (inline style, aman di semua klien email).
function mailTemplate($title, $contentHtml) {
    $siteName = defined('SITE_NAME') ? SITE_NAME : 'Website';
    $tagline  = defined('SITE_TAGLINE') ? SITE_TAGLINE : '';

    $footerLines = [];
    if (getSetting('contact_address', '') !== '') $footerLines[] = htmlspecialchars(getSetting('contact_address', ''));
    if (getSetting('contact_phone', '') !== '')   $footerLines[] = '📞 ' . htmlspecialchars(getSetting('contact_phone', ''));
    if (getSetting('contact_email', '') !== '')   $footerLines[] = '✉️ ' . htmlspecialchars(getSetting('contact_email', ''));
    $footer = implode('<br>', $footerLines);
    $footer .= ($footer !== '' ? '<br>' : '') . '© ' . date('Y') . ' ' . htmlspecialchars($siteName);

    return '<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>' . htmlspecialchars($title) . '</title>
</head>
<body style="margin:0;padding:0;background:#F7F3EC;-webkit-text-size-adjust:100%;font-family:Arial,Helvetica,sans-serif;">
<div style="max-width:620px;margin:0 auto;background:#FFFFFF;">
  <div style="background:linear-gradient(135deg,#D4A853,#B8860B);padding:26px 32px;text-align:center;">
    <div style="font-size:22px;font-weight:bold;color:#FFFFFF;letter-spacing:1.5px;">' . htmlspecialchars($siteName) . '</div>
    ' . ($tagline !== '' ? '<div style="font-size:12px;color:#FBF3E3;margin-top:4px;letter-spacing:0.5px;">' . htmlspecialchars($tagline) . '</div>' : '') . '
  </div>
  <div style="padding:32px;color:#3B2F23;font-size:14px;line-height:1.7;">
    ' . $contentHtml . '
  </div>
  <div style="background:#F7F3EC;padding:20px 32px;font-size:12px;color:#8A7A5C;text-align:center;line-height:1.9;">
    ' . $footer . '
  </div>
</div>
</body>
</html>';
}

// ============================================
// RESET PASSWORD (lupa password)
// ============================================

// Pastikan tabel password_resets tersedia (self-healing untuk DB lama).
function ensurePasswordResetSchema() {
    static $done = false;
    if ($done) return true;
    $conn = getConnection();
    if (!$conn) return false;
    $ok = $conn->query("CREATE TABLE IF NOT EXISTS password_resets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(255) NOT NULL,
        token_hash VARCHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        used TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_email (email),
        INDEX idx_token (token_hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $done = true;
    return (bool)$ok;
}

// Buat token reset & kirim email berisi link. Hanya email TERDAFTAR yang
// dibuatkan token; email tak dikenal dikembalikan 'not_found' = true namun
// tetap tampil sukses di frontend (anti enumerasi akun).
function sendPasswordResetEmail($email) {
    $email = trim($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Format email tidak valid'];
    }
    $conn = getConnection();
    if (!$conn) return ['ok' => false, 'error' => 'Koneksi database gagal'];
    if (!ensurePasswordResetSchema()) return ['ok' => false, 'error' => 'Gagal menyiapkan tabel reset password'];

    $email_e = $conn->real_escape_string($email);
    $u = $conn->query("SELECT id FROM users WHERE email = '$email_e' AND is_active = 1 LIMIT 1");
    if (!$u || $u->num_rows === 0) {
        return ['ok' => true, 'error' => '', 'not_found' => true];
    }

    // Bersihkan token lama (kedaluwarsa atau sudah dipakai) untuk email ini
    $conn->query("DELETE FROM password_resets WHERE expires_at < NOW()");
    $conn->query("DELETE FROM password_resets WHERE email = '$email_e' AND used = 1");

    $token     = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expires   = date('Y-m-d H:i:s', time() + 30 * 60); // 30 menit
    $conn->query("INSERT INTO password_resets (email, token_hash, expires_at, used) VALUES ('$email_e', '$tokenHash', '$expires', 0)");

    $link = SITE_URL . '/auth/reset-password.php?token=' . urlencode($token) . '&email=' . urlencode($email);
    $content = '<h2 style="color:#B8860B;font-family:Georgia,serif;margin:0 0 16px;">Reset Password</h2>'
        . '<p>Halo,</p>'
        . '<p>Kami menerima permintaan untuk mereset password akun Anda di <strong>' . htmlspecialchars(SITE_NAME) . '</strong>.</p>'
        . '<p>Klik tombol di bawah untuk membuat password baru. Link berlaku selama <strong>30 menit</strong> dan hanya bisa dipakai sekali.</p>'
        . '<p style="text-align:center;margin:28px 0;">' . mailButton('Reset Password Sekarang', $link) . '</p>'
        . '<p style="font-size:12px;color:#8A7A5C;">Jika Anda tidak meminta reset password, abaikan email ini — password Anda tetap aman.</p>';

    $res = sendMail($email, 'Reset Password — ' . SITE_NAME, mailTemplate('Reset Password', $content), 'Reset password akun Anda di ' . SITE_NAME . ': ' . $link);
    if (!$res['ok']) return $res;
    return ['ok' => true, 'error' => ''];
}

// Validasi token reset: cocok, belum dipakai, belum kedaluwarsa.
function isValidPasswordResetToken($email, $token) {
    $conn = getConnection();
    if (!$conn || !ensurePasswordResetSchema()) return false;
    $email = trim($email);
    $token = trim($token);
    if ($email === '' || $token === '') return false;
    $email_e   = $conn->real_escape_string($email);
    $tokenHash = hash('sha256', $token);
    $r = $conn->query("SELECT id FROM password_resets WHERE email = '$email_e' AND token_hash = '$tokenHash' AND used = 0 AND expires_at > NOW() LIMIT 1");
    return $r && $r->num_rows > 0;
}

// Tandai token sudah dipakai (dipanggil SETELAH password berhasil diubah).
function consumePasswordResetToken($email, $token) {
    $conn = getConnection();
    if (!$conn) return;
    $email_e   = $conn->real_escape_string(trim($email));
    $tokenHash = hash('sha256', trim($token));
    $conn->query("UPDATE password_resets SET used = 1 WHERE email = '$email_e' AND token_hash = '$tokenHash'");
}

// ============================================
// KONFIRMASI PESANAN
// ============================================

// Email konfirmasi pesanan — dikirim setelah pesanan berhasil dibuat
// (berisi ringkasan item + link pembayaran Midtrans).
function sendOrderConfirmationEmail($orderId) {
    $conn = getConnection();
    if (!$conn) return ['ok' => false, 'error' => 'Koneksi database gagal'];
    $orderId = (int)$orderId;
    $r = $conn->query("SELECT * FROM orders WHERE id = $orderId LIMIT 1");
    if (!$r || $r->num_rows === 0) return ['ok' => false, 'error' => 'Pesanan tidak ditemukan'];
    $o = $r->fetch_assoc();
    $to = trim($o['customer_email'] ?? '');
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'Email customer tidak valid'];

    $items = $conn->query("SELECT * FROM order_items WHERE order_id = $orderId");
    $rows = '';
    if ($items) {
        while ($i = $items->fetch_assoc()) {
            $rows .= '<tr>'
                . '<td style="padding:10px 12px;border-bottom:1px solid #F0E9DC;">' . htmlspecialchars($i['product_name']) . '</td>'
                . '<td style="padding:10px 12px;border-bottom:1px solid #F0E9DC;text-align:center;">x' . (int)$i['quantity'] . '</td>'
                . '<td style="padding:10px 12px;border-bottom:1px solid #F0E9DC;text-align:right;">Rp ' . number_format((float)$i['subtotal'], 0, ',', '.') . '</td>'
                . '</tr>';
        }
    }

    $payLink = SITE_URL . '/pages/payment.php?order=' . urlencode($o['order_number']) . '&email=' . urlencode($to);
    $content = '<h2 style="color:#B8860B;font-family:Georgia,serif;margin:0 0 16px;">Terima kasih, ' . htmlspecialchars($o['customer_name']) . '! 🎉</h2>'
        . '<p>Pesanan Anda berhasil dibuat di <strong>' . htmlspecialchars(SITE_NAME) . '</strong>.</p>'
        . '<p style="background:#FFFBEB;border:1px solid #FDE68A;border-radius:8px;padding:12px 16px;">'
        . 'Nomor Pesanan: <strong style="color:#B8860B;">' . htmlspecialchars($o['order_number']) . '</strong><br>'
        . 'Status: <strong>Menunggu Pembayaran</strong></p>'
        . '<table style="width:100%;border-collapse:collapse;margin:20px 0;">'
        . '<thead><tr style="background:#F7F3EC;">'
        . '<th style="padding:10px 12px;text-align:left;">Produk</th><th style="padding:10px 12px;">Jumlah</th><th style="padding:10px 12px;text-align:right;">Subtotal</th>'
        . '</tr></thead><tbody>' . $rows . '</tbody>'
        . '<tfoot>'
        . '<tr><td colspan="2" style="padding:8px 12px;text-align:right;">Subtotal</td><td style="padding:8px 12px;text-align:right;">Rp ' . number_format((float)$o['subtotal'], 0, ',', '.') . '</td></tr>'
        . '<tr><td colspan="2" style="padding:8px 12px;text-align:right;">Ongkos Kirim</td><td style="padding:8px 12px;text-align:right;">' . ((float)$o['shipping_cost'] > 0 ? 'Rp ' . number_format((float)$o['shipping_cost'], 0, ',', '.') : '<strong>GRATIS</strong>') . '</td></tr>';
    if ((float)$o['discount'] > 0) {
        $content .= '<tr><td colspan="2" style="padding:8px 12px;text-align:right;color:#059669;">Diskon' . (!empty($o['promo_code']) ? ' (kode ' . htmlspecialchars($o['promo_code']) . ')' : '') . '</td><td style="padding:8px 12px;text-align:right;color:#059669;">-Rp ' . number_format((float)$o['discount'], 0, ',', '.') . '</td></tr>';
    }
    $content .= '<tr><td colspan="2" style="padding:10px 12px;text-align:right;font-weight:bold;">Total Pembayaran</td><td style="padding:10px 12px;text-align:right;font-weight:bold;color:#B8860B;">Rp ' . number_format((float)$o['total'], 0, ',', '.') . '</td></tr>'
        . '</tfoot></table>'
        . '<p>Selesaikan pembayaran Anda melalui tombol di bawah:</p>'
        . '<p style="text-align:center;margin:24px 0;">' . mailButton('Bayar Sekarang', $payLink) . '</p>'
        . '<p style="font-size:12px;color:#8A7A5C;">Poin membership dari pesanan ini akan diberikan setelah pembayaran lunas. Pantau status pesanan Anda di halaman <a href="' . SITE_URL . '/pages/tracking.php" style="color:#B8860B;">Tracking Pesanan</a>.</p>';

    return sendMail($to, 'Konfirmasi Pesanan #' . $o['order_number'] . ' — ' . SITE_NAME, mailTemplate('Konfirmasi Pesanan', $content));
}

// ============================================
// NOTIFIKASI NOMOR RESI (pengiriman)
// ============================================

// Email pemberitahuan nomor resi — dikirim saat admin mengisi/merubah no. resi.
function sendTrackingNumberEmail($orderId, $trackingNumber) {
    $conn = getConnection();
    if (!$conn) return ['ok' => false, 'error' => 'Koneksi database gagal'];
    $orderId = (int)$orderId;
    $trackingNumber = trim((string)$trackingNumber);
    if ($trackingNumber === '') return ['ok' => false, 'error' => 'Nomor resi kosong'];
    $r = $conn->query("SELECT * FROM orders WHERE id = $orderId LIMIT 1");
    if (!$r || $r->num_rows === 0) return ['ok' => false, 'error' => 'Pesanan tidak ditemukan'];
    $o = $r->fetch_assoc();
    $to = trim($o['customer_email'] ?? '');
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'Email customer tidak valid'];

    $trackLink = SITE_URL . '/pages/tracking.php';
    $content = '<h2 style="color:#B8860B;font-family:Georgia,serif;margin:0 0 16px;">Pesanan Anda Dikirim 🚚</h2>'
        . '<p>Halo ' . htmlspecialchars($o['customer_name']) . ',</p>'
        . '<p>Pesanan <strong>' . htmlspecialchars($o['order_number']) . '</strong> sudah dalam perjalanan! Berikut nomor resi pengiriman Anda:</p>'
        . '<p style="background:#FFFBEB;border:1px solid #FDE68A;border-radius:8px;padding:14px 18px;text-align:center;font-size:16px;">'
        . 'No. Resi: <strong style="color:#B8860B;letter-spacing:1px;">' . htmlspecialchars($trackingNumber) . '</strong></p>'
        . '<p style="text-align:center;margin:24px 0;">' . mailButton('Lacak Pesanan Saya', $trackLink) . '</p>'
        . '<p style="font-size:12px;color:#8A7A5C;">Simpan nomor resi ini untuk melacak pengiriman Anda. Terima kasih telah berbelanja di ' . htmlspecialchars(SITE_NAME) . '! 💛</p>';

    return sendMail($to, 'Pesanan Dikirim #' . $o['order_number'] . ' — No. Resi: ' . $trackingNumber, mailTemplate('No. Resi Pengiriman', $content));
}

// ============================================
// BROADCAST NEWSLETTER
// ============================================

// Kirim newsletter ke semua subscriber aktif.
// $htmlBody diharapkan sudah berupa template lengkap (mailTemplate).
function sendNewsletterBroadcastEmail($subject, $htmlBody) {
    $conn = getConnection();
    if (!$conn) return ['sent' => 0, 'failed' => 0, 'total' => 0, 'skipped' => 'koneksi database gagal'];
    $subject = trim($subject);
    if ($subject === '') return ['sent' => 0, 'failed' => 0, 'total' => 0, 'skipped' => 'subjek kosong'];

    $r = $conn->query("SELECT email FROM newsletter_subscribers WHERE is_active = 1");
    $emails = [];
    if ($r) while ($row = $r->fetch_assoc()) $emails[] = $row['email'];

    $total  = count($emails);
    $sent   = 0;
    $failed = 0;
    foreach ($emails as $email) {
        $res = sendMail($email, $subject, $htmlBody);
        if ($res['ok']) $sent++; else $failed++;
    }
    return ['sent' => $sent, 'failed' => $failed, 'total' => $total, 'skipped' => ''];
}
