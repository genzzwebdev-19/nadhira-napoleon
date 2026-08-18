<?php
$currentPage = 'settings';
$pageTitle = 'Pengaturan';
require_once __DIR__ . '/../config/rbac.php';
require_once __DIR__ . '/../config/midtrans.php';
require_once __DIR__ . '/../config/otp.php'; // verifikasi OTP WhatsApp (Fonnte)
require_once __DIR__ . '/../config/cloudinary.php'; // penyimpanan foto Cloudinary

$conn = getConnection();
ensureMidtransSchema();
ensureOtpSchema();
ensureCloudinarySchema();

requirePermission('settings', 'view');

$errors = [];
$success = '';

// Handle form submission BEFORE layout.php outputs HTML
// (saat tombol "Kirim Email Uji" ditekan, handler simpan dilewati — ditangani khusus di bawah)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings']) && !isset($_POST['test_mail'])) {
    requirePermission('settings', 'settings');
    // Handle story image upload FIRST
    if (isset($_FILES['story_image']) && $_FILES['story_image']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/../uploads/story/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        $ext = strtolower(pathinfo($_FILES['story_image']['name'], PATHINFO_EXTENSION));
        $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        if (in_array($ext, $allowedExts)) {
            // Foto story lama (untuk dibersihkan saat diganti)
            $oldStory = '';
            $oldSQ = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'story_image' LIMIT 1");
            if ($oldSQ && $oldSQ->num_rows > 0) $oldStory = $oldSQ->fetch_assoc()['setting_value'];

            $savedStory = false;
            $storyUrl = '';
            // Jika Cloudinary aktif, upload langsung ke Cloudinary
            if (cloudinaryEnabled()) {
                $up = cloudinaryUploadFromUploaded($_FILES['story_image']['tmp_name'], $_FILES['story_image']['name'], 'nadhira/story', 'story');
                if ($up['success']) {
                    $storyUrl = $up['url'];
                    $savedStory = true;
                } else {
                    $errors[] = 'Gagal upload foto story ke Cloudinary: ' . $up['message'];
                }
            } else {
                $filename = 'story_' . time() . '_' . uniqid() . '.' . $ext;
                $destPath = $uploadDir . $filename;
                if (move_uploaded_file($_FILES['story_image']['tmp_name'], $destPath)) {
                    $storyUrl = SITE_URL . '/uploads/story/' . $filename;
                    $savedStory = true;
                }
            }
            if ($savedStory && $storyUrl !== '') {
                $_POST['story_image'] = $storyUrl;
                // Hapus foto story lama (Cloudinary / lokal)
                if ($oldStory !== '' && $oldStory !== $storyUrl) {
                    if (isCloudinaryUrl($oldStory)) {
                        cloudinaryDeleteByUrl($oldStory);
                    } elseif (strpos($oldStory, '/uploads/story/') !== false) {
                        $oldFile = __DIR__ . '/../uploads/story/' . basename(parse_url($oldStory, PHP_URL_PATH));
                        if (file_exists($oldFile)) @unlink($oldFile);
                    }
                }
            }
        }
    }

    // Handle hero background image upload (auto-optimized to 1920x1080)
    if (isset($_FILES['hero_bg_image']) && $_FILES['hero_bg_image']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/../uploads/hero/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        $tmpPath = $_FILES['hero_bg_image']['tmp_name'];
        $ext = strtolower(pathinfo($_FILES['hero_bg_image']['name'], PATHINFO_EXTENSION));
        $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

        // Capture the previous hero files so we can clean them up after a successful upload
        $oldHero = '';
        $oldQ = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'hero_background_image' LIMIT 1");
        if ($oldQ && $oldQ->num_rows > 0) {
            $oldHero = $oldQ->fetch_assoc()['setting_value'];
        }
        $oldMobile = '';
        $oldQM = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'hero_background_image_mobile' LIMIT 1");
        if ($oldQM && $oldQM->num_rows > 0) {
            $oldMobile = $oldQM->fetch_assoc()['setting_value'];
        }

        if ($_FILES['hero_bg_image']['size'] > 20 * 1024 * 1024) {
            $errors[] = 'Ukuran foto background hero terlalu besar (maks 20MB).';
        } elseif (!in_array($ext, $allowedExts) || !@getimagesize($tmpPath)) {
            $errors[] = 'Format foto background hero tidak didukung (JPG, PNG, WebP, GIF).';
        } else {
            $saved = false;
            $savedMobile = false;
            $destPath = $uploadDir . 'hero_' . time() . '_' . uniqid() . '.jpg';
            $destMobile = $uploadDir . 'hero_' . time() . '_' . uniqid() . '_mobile.jpg';

            // Jika Cloudinary aktif: upload sekali, lalu pakai transformasi Cloudinary
            // untuk versi desktop (16:9) & mobile (9:16) — tanpa proses GD lokal.
            if (cloudinaryEnabled()) {
                $up = cloudinaryUploadFromUploaded($tmpPath, $_FILES['hero_bg_image']['name'], 'nadhira/hero', 'hero');
                if ($up['success']) {
                    $_POST['hero_background_image'] = cloudinaryImageUrl($up['public_id'], ['w' => 1920, 'h' => 1080, 'c' => 'fill', 'f' => 'auto', 'q' => 'auto']);
                    $_POST['hero_background_image_mobile'] = cloudinaryImageUrl($up['public_id'], ['w' => 1080, 'h' => 1920, 'c' => 'fill', 'f' => 'auto', 'q' => 'auto']);
                    $saved = true;
                    $savedMobile = true;
                } else {
                    $errors[] = 'Gagal upload foto hero ke Cloudinary: ' . $up['message'];
                }
            }

            // Optimize (mode lokal): generate desktop (16:9, 1920x1080) AND mobile (9:16, 1080x1920) versions
            if (!$saved && function_exists('imagecreatetruecolor')) {
                $imgInfo = @getimagesize($tmpPath);
                if ($imgInfo) {
                    list($w, $h) = $imgInfo;
                    $src = null;
                    switch ($imgInfo['mime']) {
                        case 'image/jpeg': $src = @imagecreatefromjpeg($tmpPath); break;
                        case 'image/png':  $src = @imagecreatefrompng($tmpPath); break;
                        case 'image/webp': $src = @imagecreatefromwebp($tmpPath); break;
                        case 'image/gif':  $src = @imagecreatefromgif($tmpPath); break;
                    }
                    if ($src) {
                        // Desktop: center-crop to 16:9, resize to 1920x1080
                        $targetRatio = 16 / 9;
                        $srcRatio = $w / $h;
                        if ($srcRatio > $targetRatio) {
                            $cw = (int)round($h * $targetRatio); $ch = $h; $cx = (int)round(($w - $cw) / 2); $cy = 0;
                        } else {
                            $cw = $w; $ch = (int)round($w / $targetRatio); $cx = 0; $cy = (int)round(($h - $ch) / 2);
                        }
                        $TW = 1920; $TH = 1080;
                        $dst = imagecreatetruecolor($TW, $TH);
                        if ($dst) {
                            imagecopyresampled($dst, $src, 0, 0, $cx, $cy, $TW, $TH, $cw, $ch);
                            if (imagejpeg($dst, $destPath, 80)) {
                                $saved = true;
                            }
                            imagedestroy($dst);
                        }

                        // Mobile: center-crop to 9:16, resize to 1080x1920
                        $mobileRatio = 9 / 16;
                        $srcRatioM = $w / $h;
                        if ($srcRatioM > $mobileRatio) {
                            $cwM = (int)round($h * $mobileRatio); $chM = $h; $cxM = (int)round(($w - $cwM) / 2); $cyM = 0;
                        } else {
                            $cwM = $w; $chM = (int)round($w / $mobileRatio); $cxM = 0; $cyM = (int)round(($h - $chM) / 2);
                        }
                        $MW = 1080; $MH = 1920;
                        $dstM = imagecreatetruecolor($MW, $MH);
                        if ($dstM) {
                            imagecopyresampled($dstM, $src, 0, 0, $cxM, $cyM, $MW, $MH, $cwM, $chM);
                            if (imagejpeg($dstM, $destMobile, 80)) {
                                $savedMobile = true;
                            }
                            imagedestroy($dstM);
                        }

                        imagedestroy($src);
                    }
                }
            }

            // Fallback: move original file as-is if optimization fails
            if (!$saved) {
                $destPath = $uploadDir . 'hero_' . time() . '_' . uniqid() . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
                $saved = move_uploaded_file($tmpPath, $destPath);
            }

            if ($saved) {
                // Mode lokal: simpan URL uploads/hero/ (mode Cloudinary sudah diisi di atas)
                if (!cloudinaryEnabled()) {
                    $_POST['hero_background_image'] = SITE_URL . '/uploads/hero/' . basename($destPath);
                    if ($savedMobile) {
                        $_POST['hero_background_image_mobile'] = SITE_URL . '/uploads/hero/' . basename($destMobile);
                    }
                }
                // Bersihkan foto hero lama (Cloudinary / lokal)
                if ($oldHero) {
                    if (isCloudinaryUrl($oldHero)) {
                        cloudinaryDeleteByUrl($oldHero);
                    } elseif (strpos($oldHero, '/uploads/hero/') !== false) {
                        $oldFile = __DIR__ . '/../uploads/hero/' . basename(parse_url($oldHero, PHP_URL_PATH));
                        if ($oldFile !== $destPath && file_exists($oldFile)) {
                            @unlink($oldFile);
                        }
                    }
                }
                // Hanya bersihkan versi mobile lama bila versi mobile baru benar-benar dibuat,
                // kalau tidak DB akan menunjuk file yang sudah tidak ada (hero mobile rusak).
                if ($savedMobile && $oldMobile) {
                    if (isCloudinaryUrl($oldMobile)) {
                        cloudinaryDeleteByUrl($oldMobile);
                    } elseif (strpos($oldMobile, '/uploads/hero/') !== false) {
                        $oldMFile = __DIR__ . '/../uploads/hero/' . basename(parse_url($oldMobile, PHP_URL_PATH));
                        if ($oldMFile !== $destMobile && file_exists($oldMFile)) {
                            @unlink($oldMFile);
                        }
                    }
                }
            } else {
                $errors[] = 'Gagal mengunggah foto background hero.';
            }
        }
    }

    // Define editable settings with their defaults
    $editableSettings = [
        // Informasi Toko
        'site_name' => '',
        'site_tagline' => '',
        'site_description' => '',
        'about_us' => '',
        
        // Logo Navbar (tinggi dalam piksel)
        'navbar_logo_height' => '90',
        
        // Announcement Bar (strip di atas navbar)
        // Catatan: default aktif = '' agar checkbox yang tidak dicentang tersimpan sebagai nonaktif
        // (checkbox tidak ikut terkirim saat POST; pola sama seperti membership_promo_active).
        'announcement_active' => '',
        'announcement_start' => '',
        'announcement_end' => '',
        'announcement_size' => 'medium',
        'announcement_color' => 'gold',
        'announcement_speed' => 'medium',
        'announcement_marquee' => '',
        'announcement_text' => 'Belanja Online Aja. Krisna Oleh Oleh Bali. Kini Bisa Pesan Via Online',
        'announcement_text_mobile' => 'Kini Bisa Pesan Via Online',
        'announcement_label' => 'SHOP NOW',
        'announcement_link' => SITE_URL . '/pages/products.php',
        
        // Our Story
        'story_image' => '',
        'story_title' => '',
        'story_title_suffix' => '',
        'story_subtitle' => '',
        'story_content' => '',
        'story_signature' => '',
        
        // Hero Section (foto background halaman awal)
        'hero_background_image' => '',
        'hero_background_image_mobile' => '',
        
        // Why Us (4 fitur di homepage)
        'whyus_1_icon' => 'fa-award',
        'whyus_1_title' => 'Bahan Premium',
        'whyus_1_text' => 'Hanya menggunakan bahan-bahan berkualitas terbaik untuk memastikan cita rasa yang sempurna.',
        'whyus_2_icon' => 'fa-leaf',
        'whyus_2_title' => 'Fresh Product',
        'whyus_2_text' => 'Produk fresh dibuat setiap hari untuk menjaga kualitas dan kesegaran terbaik.',
        'whyus_3_icon' => 'fa-gem',
        'whyus_3_title' => 'Kemasan Premium',
        'whyus_3_text' => 'Kemasan eksklusif yang elegan, cocok untuk oleh-oleh dan hadiah istimewa.',
        'whyus_4_icon' => 'fa-truck',
        'whyus_4_title' => 'Pengiriman Nasional',
        'whyus_4_text' => 'Melayani pengiriman ke seluruh Indonesia dengan kemasan khusus yang terjaga.',
        
        // Kontak
        'contact_phone' => '',
        'contact_whatsapp' => '',
        'contact_email' => '',
        'contact_address' => '',
        'operational_hours' => '',
        
        // Tombol WhatsApp Mengambang (di kanan-bawah layar)
        // Default aktif = '' agar checkbox yang tidak dicentang tersimpan sebagai nonaktif.
        'wa_floating_enabled' => '',
        'wa_floating_label' => 'Chat Kami',
        'wa_floating_message' => 'Halo Nadhira Napoleon, saya ingin bertanya tentang produk',
        'wa_floating_link' => '',
        
        // Sosial Media
        'social_instagram' => '',
        'social_facebook' => '',
        'social_tiktok' => '',
        
        // Pembayaran (100% via Midtrans — otomatis terverifikasi, tanpa verifikasi manual)
        // Midtrans Payment Gateway
        'midtrans_server_key' => '',
        'midtrans_client_key' => '',
        'midtrans_is_production' => '0',
        
        // Auto-expire pesanan pending yang tidak dibayar (jam; default 24 = sama dengan masa berlaku token Midtrans)
        'order_expiry_hours' => '24',
        
        // WhatsApp OTP (verifikasi pendaftaran via kode OTP ke WA)
        'wa_otp_enabled' => '1',
        'wa_otp_token' => '',
        'wa_otp_test_mode' => '1',
        'wa_otp_expiry_minutes' => '5',
        
        // Cloudinary (penyimpanan foto via CDN)
        'cloudinary_cloud_name' => '',
        'cloudinary_api_key'    => '',
        'cloudinary_api_secret' => '',
        'cloudinary_enabled'    => '0',

        // Pengiriman (ongkir; 0 = GRATIS ONGKIR)
        'shipping_cost' => '0',
        
        // Promo Membership (diskon paket tahunan di homepage)
        'membership_promo_active' => '',
        'membership_promo_title' => 'Promo Paket Tahunan',
        'membership_promo_desc' => '',
        'membership_promo_discount' => '20',
        'membership_promo_end' => '',
        
        // Email Notifikasi (SMTP — konfirmasi pesanan, resi, reset password, newsletter)
        'mail_enabled' => '1',
        'mail_host' => 'smtp.gmail.com',
        'mail_port' => '587',
        'mail_encryption' => 'tls',
        'mail_user' => '',
        'mail_pass' => '',
        'mail_from_email' => '',
        'mail_from_name' => '',

        // Harga Khusus Member (diskon % per level, otomatis di keranjang & checkout)
        'member_discount_silver' => '0',
        'member_discount_gold' => '5',
        'member_discount_platinum' => '10',
        'member_discount_diamond' => '15',
        
        // Lainnya
        'footer_tagline' => '',
        'hero_open_days' => '7',
        'sound_notify_role' => 'admin-penjualan-online',
    ];

    // Validasi ikon Why Us: hanya terima nama ikon FontAwesome yang aman (fa-xxx)
    for ($w = 1; $w <= 4; $w++) {
        $iconKey = 'whyus_' . $w . '_icon';
        if (isset($_POST[$iconKey]) && !preg_match('/^fa-[a-z0-9-]+$/i', trim($_POST[$iconKey]))) {
            $errors[] = "Ikon Fitur $w tidak valid (contoh yang benar: fa-award, fa-leaf, fa-heart).";
        }
    }

    // Validasi link tombol SHOP NOW: harus URL/path yang aman
    if (isset($_POST['announcement_link']) && trim($_POST['announcement_link']) !== '') {
        $annLinkInput = trim($_POST['announcement_link']);
        if (!preg_match('~^(https?://|//|/|#|mailto:|tel:)~i', $annLinkInput)) {
            $errors[] = 'Link tombol SHOP NOW harus diawali http://, https://, /, #, mailto: atau tel:.';
        }
    }

    // Validasi ukuran Announcement Bar: hanya terima small / medium / large
    if (isset($_POST['announcement_size']) && !in_array($_POST['announcement_size'], ['small', 'medium', 'large'], true)) {
        $errors[] = 'Ukuran Announcement Bar tidak valid (pilih Kecil, Sedang, atau Besar).';
    }

    // Validasi warna Announcement Bar: hanya terima gold / dark / green / white
    if (isset($_POST['announcement_color']) && !in_array($_POST['announcement_color'], ['gold', 'dark', 'green', 'white'], true)) {
        $errors[] = 'Warna Announcement Bar tidak valid (pilih Emas, Dark, Hijau, atau Putih).';
    }

    // Validasi kecepatan marquee Announcement Bar: hanya terima slow / medium / fast
    if (isset($_POST['announcement_speed']) && !in_array($_POST['announcement_speed'], ['slow', 'medium', 'fast'], true)) {
        $errors[] = 'Kecepatan marquee Announcement Bar tidak valid (pilih Lambat, Sedang, atau Cepat).';
    }

    // Validasi jadwal Announcement Bar: tanggal "Sampai" harus setelah "Mulai"
    $annStartIn = isset($_POST['announcement_start']) ? trim($_POST['announcement_start']) : '';
    $annEndIn   = isset($_POST['announcement_end']) ? trim($_POST['announcement_end']) : '';
    if ($annStartIn !== '' && $annEndIn !== ''
        && strtotime($annStartIn) !== false && strtotime($annEndIn) !== false
        && strtotime($annEndIn) < strtotime($annStartIn)) {
        $errors[] = 'Jadwal Announcement Bar salah: tanggal "Sampai" harus lebih besar dari tanggal "Mulai".';
    }

    // Validasi link kustom tombol WhatsApp: harus URL yang aman
    if (isset($_POST['wa_floating_link']) && trim($_POST['wa_floating_link']) !== '') {
        $waLinkInput = trim($_POST['wa_floating_link']);
        if (!preg_match('#^(https?://|//)#i', $waLinkInput)) {
            $errors[] = 'Link kustom WhatsApp harus diawali http:// atau https://.';
        }
    }

    // Validasi diskon member: angka antara 0 - 100
    foreach (['silver', 'gold', 'platinum', 'diamond'] as $mLevel) {
        $mk = 'member_discount_' . $mLevel;
        if (isset($_POST[$mk]) && trim($_POST[$mk]) !== '') {
            $mv = (float)trim($_POST[$mk]);
            if ($mv < 0 || $mv > 100) {
                $errors[] = 'Diskon member ' . ucfirst($mLevel) . ' harus antara 0 - 100%.';
            }
        }
    }

    // Validasi ukuran Logo Navbar: angka bulat antara 40 - 200 piksel
    // (dikosongkan = kembali ke ukuran default 90)
    if (isset($_POST['navbar_logo_height'])) {
        $lhIn = trim($_POST['navbar_logo_height']);
        $lh = ($lhIn === '') ? 90 : (int)$lhIn;
        if ($lh < 40 || $lh > 200) {
            $errors[] = 'Ukuran Logo Navbar harus antara 40 - 200 piksel.';
        } else {
            $_POST['navbar_logo_height'] = (string)$lh;
        }
    }

    // Simpan hanya bila tidak ada error validasi
    if (empty($errors)) {
        $updated = 0;
        foreach ($editableSettings as $key => $default) {
            $value = trim($_POST[$key] ?? $default);
            $key_e = $conn->real_escape_string($key);
            $value_e = $conn->real_escape_string($value);

            // Check if setting exists
            $check = $conn->query("SELECT id FROM settings WHERE setting_key = '$key_e' LIMIT 1");
            if ($check && $check->num_rows > 0) {
                $conn->query("UPDATE settings SET setting_value = '$value_e' WHERE setting_key = '$key_e'");
            } else {
                $conn->query("INSERT INTO settings (setting_key, setting_value) VALUES ('$key_e', '$value_e')");
            }
            $updated++;
        }
        $success = "✅ $updated pengaturan berhasil disimpan!";
        logActivity('settings', 'settings', 'Memperbarui pengaturan website');
    } else {
        $errors[] = 'Pengaturan TIDAK disimpan karena ada error di atas. Perbaiki lalu simpan lagi.';
    }
}

// ============================================
// ACTION: Kirim Email Uji (tombol di kartu Email Notifikasi)
// Konfigurasi SMTP yang baru diketik ikut tersimpan dulu,
// lalu langsung dicoba kirim — tanpa harus klik "Simpan" dulu.
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_mail'])) {
    requirePermission('settings', 'settings');
    require_once __DIR__ . '/../config/mail.php';

    $testEmail = trim($_POST['test_email'] ?? '');
    if (!filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Alamat email uji tidak valid.';
    } else {
        // Simpan nilai SMTP terbaru dari form (agar uji memakai konfigurasi yang baru diketik)
        $mailKeys = ['mail_enabled', 'mail_host', 'mail_port', 'mail_encryption', 'mail_user', 'mail_pass', 'mail_from_email', 'mail_from_name'];
        foreach ($mailKeys as $mk) {
            $mk_e = $conn->real_escape_string($mk);
            $mv_e = $conn->real_escape_string(trim($_POST[$mk] ?? ''));
            $chk = $conn->query("SELECT id FROM settings WHERE setting_key = '$mk_e' LIMIT 1");
            if ($chk && $chk->num_rows > 0) {
                $conn->query("UPDATE settings SET setting_value = '$mv_e' WHERE setting_key = '$mk_e'");
            } else {
                $conn->query("INSERT INTO settings (setting_key, setting_value) VALUES ('$mk_e', '$mv_e')");
            }
        }

        $content = mailTemplate('Email Uji Berhasil 🎉',
            '<h2 style="color:#B8860B;font-family:Georgia,serif;margin:0 0 16px;">Email Uji Terkirim!</h2>'
            . '<p>Halo,</p>'
            . '<p>Email ini dikirim otomatis dari <strong>' . htmlspecialchars(SITE_NAME) . '</strong> untuk memastikan konfigurasi SMTP berfungsi dengan benar.</p>'
            . '<p style="background:#ECFDF5;border:1px solid #A7F3D0;border-radius:8px;padding:12px 16px;color:#065F46;">✅ Konfigurasi email Anda berhasil — notifikasi pesanan, resi, reset password, dan newsletter akan terkirim.</p>');
        $res = sendMail($testEmail, 'Email Uji — ' . SITE_NAME, $content);

        if ($res['ok']) {
            $success = '✅ Email uji berhasil dikirim ke ' . htmlspecialchars($testEmail) . ' — periksa kotak masuk Anda.';
            logActivity('settings', 'settings', 'Mengirim email uji ke ' . $testEmail);
        } else {
            $errors[] = 'Email uji GAGAL dikirim: ' . $res['error']
                . ' — untuk Gmail pastikan memakai <strong>App Password</strong> (bukan password biasa) dan 2 Langkah Verifikasi aktif.';
        }
    }
}

// Load all settings from database
$allSettings = [];
$result = $conn->query("SELECT setting_key, setting_value FROM settings");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $allSettings[$row['setting_key']] = $row['setting_value'];
    }
}

// Helper to get setting value with default
function settingVal($key, $default = '') {
    global $allSettings;
    return $allSettings[$key] ?? $default;
}

require_once __DIR__ . '/layout.php';
?>

            <?php if ($success): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $success ?></div>
            <?php endif; ?>
            <?php foreach ($errors as $err): ?>
                <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($err) ?></div>
            <?php endforeach; ?>

            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="save_settings" value="1">

                <!-- Informasi Toko -->
                <div class="admin-card">
                    <h3 class="admin-card-title">
                        <i class="fas fa-store" style="color: #D4A853; margin-right: 8px;"></i>
                        Informasi Toko
                    </h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Nama Toko</label>
                            <input type="text" name="site_name" class="form-input" 
                                   value="<?= htmlspecialchars(settingVal('site_name', 'Nadhira Napoleon')) ?>"
                                   placeholder="Nadhira Napoleon">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Tagline</label>
                            <input type="text" name="site_tagline" class="form-input" 
                                   value="<?= htmlspecialchars(settingVal('site_tagline', 'Premium Oleh-Oleh Khas Riau')) ?>"
                                   placeholder="Premium Oleh-Oleh Khas Riau">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Deskripsi</label>
                        <textarea name="site_description" class="form-textarea" rows="3"
                                  placeholder="Deskripsi singkat tentang toko"><?= htmlspecialchars(settingVal('site_description', '')) ?></textarea>
                    </div>
                    <div class="form-group" style="max-width: 320px;">
                        <label class="form-label">Ukuran Logo Navbar (px)</label>
                        <input type="number" name="navbar_logo_height" class="form-input" min="40" max="200" step="1"
                               value="<?= (int)settingVal('navbar_logo_height', '90') ?>">
                        <small style="color: var(--text-muted); font-size: 11px;">Tinggi logo di navbar (40–200 px). Lebar menyesuaikan otomatis agar tetap proporsional.</small>
                    </div>
                </div>

                <!-- Announcement Bar -->
                <?php
                // Default link tombol: halaman produk. Dipakai bila belum pernah disimpan / masih kosong.
                $annLinkVal = settingVal('announcement_link', '');
                if ($annLinkVal === '') { $annLinkVal = SITE_URL . '/pages/products.php'; }
                ?>
                <div class="admin-card">
                    <h3 class="admin-card-title">
                        <i class="fas fa-bullhorn" style="color: #D4A853; margin-right: 8px;"></i>
                        Announcement Bar <span style="font-size: 12px; color: var(--text-muted); font-family: 'Inter', sans-serif; font-weight: 400; margin-left: 4px;">(strip emas di atas navbar)</span>
                    </h3>
                    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px; flex-wrap: wrap;">
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 500;">
                            <input type="checkbox" name="announcement_active" value="1" style="accent-color: var(--soft-gold); width: 18px; height: 18px;"
                                   <?= settingVal('announcement_active', '1') === '1' ? 'checked' : '' ?>>
                            Tampilkan bar pengumuman
                        </label>
                        <div style="flex: 1; max-width: 220px; min-width: 180px;">
                            <select name="announcement_size" class="form-select">
                                <option value="small" <?= settingVal('announcement_size', 'medium') === 'small' ? 'selected' : '' ?>>Kecil</option>
                                <option value="medium" <?= settingVal('announcement_size', 'medium') === 'medium' ? 'selected' : '' ?>>Sedang</option>
                                <option value="large" <?= settingVal('announcement_size', 'medium') === 'large' ? 'selected' : '' ?>>Besar</option>
                            </select>
                            <small style="color: var(--text-muted); font-size: 11px;">Ukuran bar — Besar lebih mencolok sebagai penarik perhatian.</small>
                        </div>
                        <div style="flex: 1; max-width: 160px; min-width: 130px;">
                            <select name="announcement_color" class="form-select">
                                <option value="gold" <?= settingVal('announcement_color', 'gold') === 'gold' ? 'selected' : '' ?>>Emas</option>
                                <option value="dark" <?= settingVal('announcement_color', 'gold') === 'dark' ? 'selected' : '' ?>>Dark</option>
                                <option value="green" <?= settingVal('announcement_color', 'gold') === 'green' ? 'selected' : '' ?>>Hijau</option>
                                <option value="white" <?= settingVal('announcement_color', 'gold') === 'white' ? 'selected' : '' ?>>Putih</option>
                            </select>
                            <small style="color: var(--text-muted); font-size: 11px;">Warna bar.</small>
                        </div>
                        <div style="flex: 1; max-width: 160px; min-width: 130px;">
                            <select name="announcement_speed" class="form-select">
                                <option value="slow" <?= settingVal('announcement_speed', 'medium') === 'slow' ? 'selected' : '' ?>>Lambat</option>
                                <option value="medium" <?= settingVal('announcement_speed', 'medium') === 'medium' ? 'selected' : '' ?>>Sedang</option>
                                <option value="fast" <?= settingVal('announcement_speed', 'medium') === 'fast' ? 'selected' : '' ?>>Cepat</option>
                            </select>
                            <small style="color: var(--text-muted); font-size: 11px;">Kecepatan marquee.</small>
                        </div>
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 500;">
                            <input type="checkbox" name="announcement_marquee" value="1" style="accent-color: var(--soft-gold); width: 18px; height: 18px;"
                                   <?= settingVal('announcement_marquee', '1') === '1' ? 'checked' : '' ?>>
                            Animasi teks berjalan (marquee)
                        </label>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Mulai Tampil</label>
                            <input type="datetime-local" name="announcement_start" class="form-input"
                                   value="<?= htmlspecialchars(settingVal('announcement_start', '')) ?>">
                            <small style="color: var(--text-muted); font-size: 11px;">Kosongkan = tampil sejak sekarang.</small>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Sampai Tanggal</label>
                            <input type="datetime-local" name="announcement_end" class="form-input"
                                   value="<?= htmlspecialchars(settingVal('announcement_end', '')) ?>">
                            <small style="color: var(--text-muted); font-size: 11px;">Kosongkan = tampil terus tanpa batas.</small>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Teks Pengumuman</label>
                            <input type="text" name="announcement_text" class="form-input"
                                   value="<?= htmlspecialchars(settingVal('announcement_text', 'Belanja Online Aja. Krisna Oleh Oleh Bali. Kini Bisa Pesan Via Online')) ?>"
                                   placeholder="Belanja Online Aja. Krisna Oleh Oleh Bali. Kini Bisa Pesan Via Online">
                            <small style="color: var(--text-muted); font-size: 11px;">Tampil di desktop &amp; tablet.</small>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Teks Ringkas (HP)</label>
                            <input type="text" name="announcement_text_mobile" class="form-input"
                                   value="<?= htmlspecialchars(settingVal('announcement_text_mobile', 'Kini Bisa Pesan Via Online')) ?>"
                                   placeholder="Kini Bisa Pesan Via Online">
                            <small style="color: var(--text-muted); font-size: 11px;">Tampil di layar kecil (≤480px). Kosongkan = pakai teks penuh.</small>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Teks Tombol</label>
                            <input type="text" name="announcement_label" class="form-input"
                                   value="<?= htmlspecialchars(settingVal('announcement_label', 'SHOP NOW')) ?>"
                                   placeholder="SHOP NOW">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Link Tombol</label>
                            <input type="text" name="announcement_link" class="form-input"
                                   value="<?= htmlspecialchars($annLinkVal) ?>"
                                   placeholder="<?= htmlspecialchars(SITE_URL) ?>/pages/products.php">
                            <small style="color: var(--text-muted); font-size: 11px;">
                                Contoh: <?= htmlspecialchars(SITE_URL) ?>/pages/products.php atau https://link-lain.com
                            </small>
                        </div>
                    </div>
                </div>

                <!-- Hero Section Settings -->
                <div class="admin-card">
                    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 16px;">
                        <h3 class="admin-card-title" style="margin: 0;">
                            <i class="fas fa-image" style="color: #D4A853; margin-right: 8px;"></i>
                            Hero Section <span style="font-size: 12px; color: var(--text-muted); font-family: 'Inter', sans-serif; font-weight: 400; margin-left: 4px;">(foto background halaman awal)</span>
                        </h3>
                        <a href="hero-slides.php" class="btn btn-primary btn-sm" style="background: linear-gradient(135deg, #D4A853, #B8860B);">
                            <i class="fas fa-images"></i> Kelola Slider Foto
                        </a>
                    </div>
                    <div style="padding: 10px 14px; background: #FFFBEB; border: 1px solid #FDE68A; border-radius: 10px; font-size: 12px; color: #92400E; margin-bottom: 16px;">
                        <i class="fas fa-info-circle"></i> Homepage sekarang menggunakan <strong>slider multi-foto</strong>. Kelola foto slider di menu <strong>Hero Slider</strong>. Pengaturan di bawah hanya menjadi fallback bila tidak ada slide.
                    </div>
                    <?php $heroBg = settingVal('hero_background_image', ASSETS_URL . '/images/hero-bg.jpg'); ?>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label">Foto Background Hero</label>
                        <div style="margin-bottom: var(--space-sm);">
                            <img id="heroBgPreview" src="<?= htmlspecialchars($heroBg) ?>" 
                                 alt="Preview Hero" 
                                 style="width: 100%; max-width: 480px; aspect-ratio: 16/9; object-fit: cover; border-radius: var(--radius-lg); border: 2px solid var(--border-color);">
                        </div>
                        <div style="display: flex; gap: var(--space-sm); align-items: center; flex-wrap: wrap;">
                            <input type="file" name="hero_bg_image" id="heroBgInput" accept="image/jpeg,image/png,image/webp,image/gif" 
                                   style="font-size: 13px;">
                            <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('heroBgInput').value=''; document.getElementById('heroBgPreview').src='<?= htmlspecialchars($heroBg) ?>';">
                                <i class="fas fa-undo"></i> Reset
                            </button>
                        </div>
                        <small style="color: var(--text-muted); font-size: 11px; display: block; margin-top: 6px;">
                            Format: JPG, PNG, WebP, GIF. Maks: 20MB.
                            Foto otomatis dibuat 2 versi: desktop (1920×1080) & mobile (1080×1920).
                            Atau langsung edit URL gambar di bawah ini.
                        </small>
                        <input type="text" name="hero_background_image" class="form-input" style="margin-top: 8px;"
                               value="<?= htmlspecialchars($heroBg) ?>"
                               placeholder="URL foto background hero (desktop)"
                               onchange="document.getElementById('heroBgPreview').src = this.value">

                        <!-- Mobile portrait version -->
                        <div style="margin-top: var(--space-lg); display: flex; gap: var(--space-md); align-items: flex-start;">
                            <img id="heroBgMobilePreview" src="<?= htmlspecialchars(settingVal('hero_background_image_mobile', '')) ?>" 
                                 alt="Preview Mobile" 
                                 onerror="this.style.visibility='hidden'"
                                 style="width: 80px; aspect-ratio: 9/16; object-fit: cover; border-radius: var(--radius-md); border: 2px solid var(--border-color); background: var(--soft-grey); flex-shrink: 0;">
                            <div style="flex: 1;">
                                <label class="form-label" style="margin-bottom: var(--space-xs);">Versi Mobile (portrait 9:16)</label>
                                <input type="text" name="hero_background_image_mobile" class="form-input" 
                                       value="<?= htmlspecialchars(settingVal('hero_background_image_mobile', '')) ?>"
                                       placeholder="URL foto mobile (otomatis terisi saat upload)"
                                       onchange="document.getElementById('heroBgMobilePreview').src = this.value">
                                <small style="color: var(--text-muted); font-size: 11px;">Diisi otomatis saat upload. Kosongkan untuk memakai foto desktop.</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Why Us Settings -->
                <div class="admin-card">
                    <h3 class="admin-card-title">
                        <i class="fas fa-thumbs-up" style="color: #D4A853; margin-right: 8px;"></i>
                        Why Us <span style="font-size: 12px; color: var(--text-muted); font-family: 'Inter', sans-serif; font-weight: 400; margin-left: 4px;">(4 fitur di homepage)</span>
                    </h3>
                    <?php for ($w = 1; $w <= 4; $w++): ?>
                    <div style="padding: 14px 0; <?= $w < 4 ? 'border-bottom: 1px dashed var(--soft-grey);' : 'padding-bottom: 0;' ?>">
                        <div class="form-row">
                            <div class="form-group" style="flex: 1.2;">
                                <label class="form-label">Fitur <?= $w ?> — Ikon</label>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <input type="text" name="whyus_<?= $w ?>_icon" class="form-input" style="font-family: monospace;"
                                           value="<?= htmlspecialchars(settingVal('whyus_' . $w . '_icon', ['fa-award','fa-leaf','fa-gem','fa-truck'][$w-1])) ?>"
                                           placeholder="fa-award">
                                    <span style="font-size: 22px; color: #D4A853; width: 28px; text-align: center;"><i class="fas <?= htmlspecialchars(settingVal('whyus_' . $w . '_icon', ['fa-award','fa-leaf','fa-gem','fa-truck'][$w-1])) ?>"></i></span>
                                </div>
                                <small style="color: var(--text-muted); font-size: 11px;">Nama ikon FontAwesome (mis. fa-award, fa-leaf, fa-gem, fa-truck, fa-heart, dll.)</small>
                            </div>
                            <div class="form-group" style="flex: 2;">
                                <label class="form-label">Judul</label>
                                <input type="text" name="whyus_<?= $w ?>_title" class="form-input"
                                       value="<?= htmlspecialchars(settingVal('whyus_' . $w . '_title', ['Bahan Premium','Fresh Product','Kemasan Premium','Pengiriman Nasional'][$w-1])) ?>">
                            </div>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">Deskripsi</label>
                            <textarea name="whyus_<?= $w ?>_text" class="form-textarea" rows="2"><?= htmlspecialchars(settingVal('whyus_' . $w . '_text', '')) ?></textarea>
                        </div>
                    </div>
                    <?php endfor; ?>
                </div>

                <!-- Our Story Settings -->
                <div class="admin-card">
                    <h3 class="admin-card-title">
                        <i class="fas fa-book-open" style="color: #D4A853; margin-right: 8px;"></i>
                        Our Story <span style="font-size: 12px; color: var(--text-muted); font-family: 'Inter', sans-serif; font-weight: 400; margin-left: 4px;">(ditampilkan di homepage)</span>
                    </h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Judul (baris 1)</label>
                            <input type="text" name="story_title" class="form-input" 
                                   value="<?= htmlspecialchars(settingVal('story_title', 'Warisan Rasa')) ?>"
                                   placeholder="Warisan Rasa">
                            <small style="color: var(--text-muted); font-size: 11px;">Teks biasa (tanpa HTML), akan tampil dengan warna default</small>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Judul (baris 2) <span style="color: var(--soft-gold);">★ emas</span></label>
                            <input type="text" name="story_title_suffix" class="form-input" 
                                   value="<?= htmlspecialchars(settingVal('story_title_suffix', 'Nusantara')) ?>"
                                   placeholder="Nusantara">
                            <small style="color: var(--text-muted); font-size: 11px;">Akan tampil dengan aksen warna emas</small>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Subtitle</label>
                        <input type="text" name="story_subtitle" class="form-input" 
                               value="<?= htmlspecialchars(settingVal('story_subtitle', 'Perjalanan Nadhira Napoleon dalam menghadirkan oleh-oleh premium khas Riau')) ?>"
                               placeholder="Subtitle di bawah judul section">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Konten Paragraf</label>
                        <textarea name="story_content" class="form-textarea" rows="6"
                                  placeholder="Tulis cerita..."><?= htmlspecialchars(settingVal('story_content', '')) ?></textarea>
                        <small style="color: var(--text-muted); font-size: 11px;">Setiap baris baru akan menjadi paragraf baru di homepage</small>
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label">Tanda Tangan</label>
                        <input type="text" name="story_signature" class="form-input" 
                               value="<?= htmlspecialchars(settingVal('story_signature', '— Nadhira Napoleon, Founder')) ?>"
                               placeholder="— Nadhira Napoleon, Founder">
                    </div>
                    <div class="form-group" style="margin-top: var(--space-lg);">
                        <label class="form-label">Foto Story</label>
                        <?php $storyImg = settingVal('story_image', 'https://images.unsplash.com/photo-1551024601-bec78aea704b?w=800&q=80'); ?>
                        <div style="margin-bottom: var(--space-sm);">
                            <img id="storyImagePreview" src="<?= htmlspecialchars($storyImg) ?>" 
                                 alt="Preview" style="max-width: 280px; max-height: 180px; border-radius: var(--radius-lg); object-fit: cover; border: 2px solid var(--border-color);">
                        </div>
                        <div style="display: flex; gap: var(--space-sm); align-items: center; flex-wrap: wrap;">
                            <input type="file" name="story_image" id="storyImageInput" accept="image/jpeg,image/png,image/webp,image/gif" 
                                   style="font-size: 13px;">
                            <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('storyImageInput').value=''; document.getElementById('storyImagePreview').src='';">
                                <i class="fas fa-undo"></i> Reset
                            </button>
                        </div>
                        <small style="color: var(--text-muted); font-size: 11px; display: block; margin-top: 6px;">
                            Format: JPG, PNG, WebP, GIF. Maks: 2MB.
                            Atau langsung edit URL gambar di bawah ini.
                        </small>
                        <input type="text" name="story_image" class="form-input" style="margin-top: 8px;"
                               value="<?= htmlspecialchars($storyImg) ?>"
                               placeholder="URL gambar story"
                               onchange="document.getElementById('storyImagePreview').src = this.value">
                    </div>
                </div>

                <!-- Kontak -->
                <div class="admin-card">
                    <h3 class="admin-card-title">
                        <i class="fas fa-phone-alt" style="color: #D4A853; margin-right: 8px;"></i>
                        Kontak
                    </h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">No. Telepon</label>
                            <input type="text" name="contact_phone" class="form-input" 
                                   value="<?= htmlspecialchars(settingVal('contact_phone', '0821-1234-5678')) ?>"
                                   placeholder="0821-1234-5678">
                        </div>
                        <div class="form-group">
                            <label class="form-label">WhatsApp <span style="color: #25D366;">(nomor dengan kode negara)</span></label>
                            <input type="text" name="contact_whatsapp" class="form-input" 
                                   value="<?= htmlspecialchars(settingVal('contact_whatsapp', '6282112345678')) ?>"
                                   placeholder="6282112345678">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Email</label>
                            <input type="email" name="contact_email" class="form-input" 
                                   value="<?= htmlspecialchars(settingVal('contact_email', 'info@nadhiranapoleon.com')) ?>"
                                   placeholder="info@nadhiranapoleon.com">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Jam Operasional</label>
                            <input type="text" name="operational_hours" class="form-input" 
                                   value="<?= htmlspecialchars(settingVal('operational_hours', 'Setiap Hari, 08.00 - 21.00 WIB')) ?>"
                                   placeholder="Setiap Hari, 08.00 - 21.00 WIB">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Alamat</label>
                        <textarea name="contact_address" class="form-textarea" rows="3"
                                  placeholder="Alamat lengkap toko"><?= htmlspecialchars(settingVal('contact_address', 'Jl. Sudirman No. 123, Pekanbaru, Riau')) ?></textarea>
                    </div>
                </div>

                <!-- Tombol WhatsApp Mengambang -->
                <div class="admin-card">
                    <h3 class="admin-card-title">
                        <i class="fab fa-whatsapp" style="color: #25D366; margin-right: 8px;"></i>
                        Tombol WhatsApp Mengambang
                        <span style="font-size: 12px; color: var(--text-muted); font-family: 'Inter', sans-serif; font-weight: 400; margin-left: 4px;">(tombol hijau di kanan-bawah layar)</span>
                    </h3>
                    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;">
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 500;">
                            <input type="checkbox" name="wa_floating_enabled" value="1" style="accent-color: #25D366; width: 18px; height: 18px;"
                                   <?= settingVal('wa_floating_enabled', '1') === '1' ? 'checked' : '' ?>>
                            Tampilkan tombol WhatsApp mengambang
                        </label>
                        <small style="color: var(--text-muted); font-size: 11px;">Hanya untuk tombol mengambang di kanan-bawah. Tombol di halaman detail produk selalu tampil.</small>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Teks Tombol</label>
                            <input type="text" name="wa_floating_label" class="form-input"
                                   value="<?= htmlspecialchars(settingVal('wa_floating_label', 'Chat Kami')) ?>"
                                   placeholder="Chat Kami">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Pesan Awal (auto-fill saat chat)</label>
                            <input type="text" name="wa_floating_message" class="form-input"
                                   value="<?= htmlspecialchars(settingVal('wa_floating_message', 'Halo Nadhira Napoleon, saya ingin bertanya tentang produk')) ?>"
                                   placeholder="Halo, saya ingin bertanya tentang produk">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">Link Kustom (opsional)</label>
                            <input type="text" name="wa_floating_link" class="form-input"
                                   value="<?= htmlspecialchars(settingVal('wa_floating_link', '')) ?>"
                                   placeholder="https://wa.me/628xxx atau https://chat.whatsapp.com/xxx">
                            <small style="color: var(--text-muted); font-size: 11px;">
                                Kosongkan = otomatis ke nomor WhatsApp toko (diatur pada kartu <strong>Kontak</strong>) dengan pesan awal di atas.
                                Isi jika ingin mengarahkan ke link lain (mis. grup WhatsApp).
                            </small>
                        </div>
                    </div>
                </div>

                <!-- Sosial Media -->
                <div class="admin-card">
                    <h3 class="admin-card-title">
                        <i class="fas fa-share-alt" style="color: #D4A853; margin-right: 8px;"></i>
                        Sosial Media
                    </h3>
                    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">
                                <i class="fab fa-instagram" style="color: #E4405F;"></i> Instagram
                            </label>
                            <input type="text" name="social_instagram" class="form-input" 
                                   value="<?= htmlspecialchars(settingVal('social_instagram', '@nadhiranapoleon')) ?>"
                                   placeholder="@nadhiranapoleon">
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">
                                <i class="fab fa-facebook" style="color: #1877F2;"></i> Facebook
                            </label>
                            <input type="text" name="social_facebook" class="form-input" 
                                   value="<?= htmlspecialchars(settingVal('social_facebook', 'nadhiranapoleon')) ?>"
                                   placeholder="nadhiranapoleon">
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">
                                <i class="fab fa-tiktok"></i> TikTok
                            </label>
                            <input type="text" name="social_tiktok" class="form-input" 
                                   value="<?= htmlspecialchars(settingVal('social_tiktok', '@nadhiranapoleon')) ?>"
                                   placeholder="@nadhiranapoleon">
                        </div>
                    </div>
                </div>

                <!-- Midtrans Payment Gateway -->
                <div class="admin-card">
                    <h3 class="admin-card-title">
                        <i class="fas fa-credit-card" style="color: #D4A853; margin-right: 8px;"></i>
                        Midtrans Payment Gateway
                        <span style="font-size: 12px; color: var(--text-muted); font-family: 'Inter', sans-serif; font-weight: 400; margin-left: 8px;">
                            (metode pembayaran utama: VA, QRIS, E-Wallet, Kartu Kredit)
                        </span>
                    </h3>

                    <div style="padding: 12px 16px; background: #FFFBEB; border: 1px solid #FDE68A; border-radius: 10px; font-size: 12px; color: #92400E; margin-bottom: 16px; line-height: 1.7;">
                        <i class="fas fa-info-circle"></i> Cara mendapatkan kunci:<br>
                        1. Daftar di <a href="https://dashboard.midtrans.com" target="_blank" style="color: #B45309; font-weight: 600;">dashboard.midtrans.com</a>.<br>
                        2. Buka <strong>Settings → Access Keys</strong>, salin <strong>Server Key</strong> dan <strong>Client Key</strong>.<br>
                        3. Untuk uji coba pakai mode <strong>Sandbox</strong>; setelah siap terima pembayaran, ganti ke <strong>Production</strong>.<br>
                        4. Di dashboard: <strong>Settings → Configuration → Payment Notification URL</strong> isi:<br>
                        <code style="background: #FEF3C7; padding: 2px 8px; border-radius: 4px; font-size: 11px;"><?= htmlspecialchars(SITE_URL) ?>/midtrans-notification.php</code><br>
                        <small>(Webhook perlu URL publik — belum berfungsi saat masih di localhost, tapi status tetap bisa dicek otomatis dari halaman invoice.)</small>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Mode</label>
                            <select name="midtrans_is_production" class="form-select">
                                <option value="0" <?= settingVal('midtrans_is_production', '0') === '0' ? 'selected' : '' ?>>Sandbox (uji coba)</option>
                                <option value="1" <?= settingVal('midtrans_is_production', '0') === '1' ? 'selected' : '' ?>>Production (live)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Client Key</label>
                            <input type="text" name="midtrans_client_key" class="form-input" 
                                   value="<?= htmlspecialchars(settingVal('midtrans_client_key', '')) ?>"
                                   placeholder="Midtrans-client-xxxx" autocomplete="off">
                        </div>
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label">Server Key</label>
                        <input type="password" name="midtrans_server_key" class="form-input" 
                               value="<?= htmlspecialchars(settingVal('midtrans_server_key', '')) ?>"
                               placeholder="SB-Mid-server-xxxx" autocomplete="new-password">
                        <small style="color: var(--text-muted); font-size: 11px;">Server Key bersifat rahasia — hanya dipakai dari sisi server, tidak pernah dikirim ke browser.</small>
                    </div>

                    <div class="form-row" style="margin-top: 16px;">
                        <div class="form-group">
                            <label class="form-label">Auto-Expire Pesanan Belum Dibayar (jam)</label>
                            <input type="number" name="order_expiry_hours" class="form-input" min="1" max="720"
                                   value="<?= htmlspecialchars(settingVal('order_expiry_hours', '24')) ?>">
                            <small style="color: var(--text-muted); font-size: 11px;">
                                Pesanan berstatus <strong>pending</strong> yang tidak dibayar dalam X jam otomatis dibatalkan —
                                stok &amp; kuota promo dikembalikan. Default 24 jam (sama dengan masa berlaku token Midtrans).
                                Berlaku sebagai jaring pengaman bila webhook Midtrans 'expire' tidak sampai ke server.
                            </small>
                        </div>
                    </div>
                </div>

                <!-- Cloudinary Image Storage -->
                <div class="admin-card">
                    <h3 class="admin-card-title">
                        <i class="fas fa-cloud-upload-alt" style="color: #D4A853; margin-right: 8px;"></i>
                        Cloudinary — Penyimpanan Foto
                        <span style="font-size: 12px; color: var(--text-muted); font-family: 'Inter', sans-serif; font-weight: 400; margin-left: 8px;">
                            (foto produk, cabang, hero & story disimpan di CDN Cloudinary)
                        </span>
                    </h3>

                    <div style="padding: 12px 16px; background: #FFFBEB; border: 1px solid #FDE68A; border-radius: 10px; font-size: 12px; color: #92400E; margin-bottom: 16px; line-height: 1.7;">
                        <i class="fas fa-info-circle"></i> Cara mendapatkan kunci:<br>
                        1. Daftar di <a href="https://cloudinary.com" target="_blank" style="color: #B45309; font-weight: 600;">cloudinary.com</a> (gratis).<br>
                        2. Buka <strong>Dashboard → Settings → Access Keys</strong> (atau <strong>API Keys</strong>).<br>
                        3. Salin <strong>Cloud Name</strong>, <strong>API Key</strong>, dan <strong>API Secret</strong> ke bawah.<br>
                        4. Aktifkan toggle <strong>Pakai Cloudinary</strong> — foto baru otomatis diupload ke Cloudinary.
                        <br><small>Foto lama yang sudah ada di folder <code>uploads/</code> tetap tampil; hanya upload <strong>baru</strong> yang dialihkan.</small>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Aktifkan Cloudinary</label>
                            <select name="cloudinary_enabled" class="form-select">
                                <option value="1" <?= settingVal('cloudinary_enabled', '0') === '1' ? 'selected' : '' ?>>Aktif — foto baru ke Cloudinary</option>
                                <option value="0" <?= settingVal('cloudinary_enabled', '0') === '0' ? 'selected' : '' ?>>Nonaktif — simpan lokal seperti biasa</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Cloud Name</label>
                            <input type="text" name="cloudinary_cloud_name" class="form-input" 
                                   value="<?= htmlspecialchars(settingVal('cloudinary_cloud_name', '')) ?>"
                                   placeholder="contoh: dxk2abcde" autocomplete="off">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">API Key</label>
                            <input type="text" name="cloudinary_api_key" class="form-input" 
                                   value="<?= htmlspecialchars(settingVal('cloudinary_api_key', '')) ?>"
                                   placeholder="123456789012345" autocomplete="off">
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">API Secret</label>
                            <input type="password" name="cloudinary_api_secret" class="form-input" 
                                   value="<?= htmlspecialchars(settingVal('cloudinary_api_secret', '')) ?>"
                                   placeholder="abcdefghijklmnopqrstuvwxyz" autocomplete="new-password">
                            <small style="color: var(--text-muted); font-size: 11px;">API Secret bersifat rahasia — hanya dipakai dari sisi server, tidak pernah dikirim ke browser.</small>
                        </div>
                    </div>
                </div>

                <!-- WhatsApp OTP -->
                <div class="admin-card">
                    <h3 class="admin-card-title">
                        <i class="fab fa-whatsapp" style="color: #25D366; margin-right: 8px;"></i>
                        WhatsApp OTP — Verifikasi Pendaftaran
                        <span style="font-size: 12px; color: var(--text-muted); font-family: 'Inter', sans-serif; font-weight: 400; margin-left: 8px;">
                            (kode OTP dikirim ke WhatsApp saat pengguna mendaftar)
                        </span>
                    </h3>

                    <div style="padding: 12px 16px; background: #ECFDF5; border: 1px solid #A7F3D0; border-radius: 10px; font-size: 12px; color: #065F46; margin-bottom: 16px; line-height: 1.7;">
                        <i class="fas fa-info-circle"></i> Cara mengaktifkan:<br>
                        1. Daftar di <a href="https://www.fonnte.com" target="_blank" style="color: #047857; font-weight: 600;">fonnte.com</a>.<br>
                        2. Hubungkan <strong>nomor WhatsApp toko</strong> (scan QR dari dashboard Fonnte).<br>
                        3. Salin <strong>token API</strong> dari dashboard dan tempel di bawah.<br>
                        4. Matikan <strong>Mode Uji</strong> agar kode benar-benar terkirim ke WhatsApp (bukan tampil di layar).
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Verifikasi OTP</label>
                            <select name="wa_otp_enabled" class="form-select">
                                <option value="1" <?= settingVal('wa_otp_enabled', '1') === '1' ? 'selected' : '' ?>>Aktif — wajib verifikasi kode saat daftar</option>
                                <option value="0" <?= settingVal('wa_otp_enabled', '1') === '0' ? 'selected' : '' ?>>Nonaktif — daftar langsung tanpa OTP</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Mode Uji (tanpa kirim WhatsApp)</label>
                            <select name="wa_otp_test_mode" class="form-select">
                                <option value="1" <?= settingVal('wa_otp_test_mode', '1') === '1' ? 'selected' : '' ?>>Aktif — kode tampil di layar (uji coba)</option>
                                <option value="0" <?= settingVal('wa_otp_test_mode', '1') === '0' ? 'selected' : '' ?>>Nonaktif — kirim benar-benar ke WhatsApp</option>
                            </select>
                            <small style="color: var(--text-muted); font-size: 11px;">Default <strong>Aktif</strong> agar bisa diuji sebelum memiliki token Fonnte.</small>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Token API Fonnte</label>
                            <input type="password" name="wa_otp_token" class="form-input" 
                                   value="<?= htmlspecialchars(settingVal('wa_otp_token', '')) ?>"
                                   placeholder="Token dari dashboard fonnte.com" autocomplete="new-password">
                            <small style="color: var(--text-muted); font-size: 11px;">Token bersifat rahasia — hanya dipakai dari sisi server.</small>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Masa Berlaku Kode (menit)</label>
                            <input type="number" name="wa_otp_expiry_minutes" class="form-input" min="1" max="30"
                                   value="<?= htmlspecialchars(settingVal('wa_otp_expiry_minutes', '5')) ?>"
                                   placeholder="5">
                            <small style="color: var(--text-muted); font-size: 11px;">Kode kadaluarsa otomatis; maksimal 5 percobaan per kode.</small>
                        </div>
                    </div>
                </div>

                <!-- Email Notifikasi (SMTP) -->
                <div class="admin-card">
                    <h3 class="admin-card-title">
                        <i class="fas fa-envelope" style="color: #D4A853; margin-right: 8px;"></i>
                        Email Notifikasi (SMTP)
                        <span style="font-size: 12px; color: var(--text-muted); font-family: 'Inter', sans-serif; font-weight: 400; margin-left: 8px;">
                            (konfirmasi pesanan, nomor resi, reset password, newsletter)
                        </span>
                    </h3>

                    <div style="padding: 12px 16px; background: #FFFBEB; border: 1px solid #FDE68A; border-radius: 10px; font-size: 12px; color: #92400E; margin-bottom: 16px; line-height: 1.7;">
                        <i class="fas fa-info-circle"></i> Cara pakai Gmail:<br>
                        1. Aktifkan <strong>2 Langkah Verifikasi</strong> di akun Google Anda.<br>
                        2. Buat <strong>App Password</strong>: <em>myaccount.google.com → Keamanan → Kata Sandi Aplikasi</em> (16 karakter, contoh: <code>abcd efgh ijkl mnop</code>).<br>
                        3. Isi <strong>email Gmail</strong> di kolom Username &amp; <strong>App Password</strong> di kolom Password. <em>Password Gmail biasa TIDAK berfungsi untuk SMTP.</em><br>
                        4. Klik <strong>Simpan Semua Pengaturan</strong>, lalu uji kirim dengan tombol di bawah.<br>
                        <small>Catatan: Gmail membatasi ±500 email/hari — cukup untuk toko; broadcast newsletter sangat banyak sebaiknya pakai layanan lain (Brevo/MailerSend).</small>
                    </div>

                    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;">
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 500;">
                            <input type="checkbox" name="mail_enabled" value="1" style="accent-color: var(--soft-gold); width: 18px; height: 18px;"
                                   <?= settingVal('mail_enabled', '1') === '1' ? 'checked' : '' ?>>
                            Aktifkan notifikasi email
                        </label>
                        <small style="color: var(--text-muted); font-size: 11px;">Jika nonaktif, semua email (order, resi, reset, newsletter) dilewati tanpa error.</small>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">SMTP Host</label>
                            <input type="text" name="mail_host" class="form-input"
                                   value="<?= htmlspecialchars(settingVal('mail_host', 'smtp.gmail.com')) ?>"
                                   placeholder="smtp.gmail.com">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Port</label>
                            <input type="number" name="mail_port" class="form-input" min="1" max="65535"
                                   value="<?= htmlspecialchars(settingVal('mail_port', '587')) ?>"
                                   placeholder="587">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Enkripsi</label>
                            <select name="mail_encryption" class="form-select">
                                <option value="tls" <?= settingVal('mail_encryption', 'tls') === 'tls' ? 'selected' : '' ?>>TLS (port 587)</option>
                                <option value="ssl" <?= settingVal('mail_encryption', 'tls') === 'ssl' ? 'selected' : '' ?>>SSL (port 465)</option>
                                <option value="" <?= settingVal('mail_encryption', 'tls') === '' ? 'selected' : '' ?>>Tanpa enkripsi</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Username (email pengirim)</label>
                            <input type="text" name="mail_user" class="form-input"
                                   value="<?= htmlspecialchars(settingVal('mail_user', '')) ?>"
                                   placeholder="namaanda@gmail.com" autocomplete="off">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Password (App Password Gmail)</label>
                            <input type="password" name="mail_pass" class="form-input"
                                   value="<?= htmlspecialchars(settingVal('mail_pass', '')) ?>"
                                   placeholder="16 karakter App Password" autocomplete="new-password">
                            <small style="color: var(--text-muted); font-size: 11px;">Bersifat rahasia — hanya dipakai dari sisi server untuk login SMTP.</small>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Email Pengirim (From)</label>
                            <input type="email" name="mail_from_email" class="form-input"
                                   value="<?= htmlspecialchars(settingVal('mail_from_email', '')) ?>"
                                   placeholder="namaanda@gmail.com">
                            <small style="color: var(--text-muted); font-size: 11px;">Biasanya sama dengan username di atas.</small>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Nama Pengirim</label>
                            <input type="text" name="mail_from_name" class="form-input"
                                   value="<?= htmlspecialchars(settingVal('mail_from_name', 'Nadhira Napoleon')) ?>"
                                   placeholder="Nadhira Napoleon">
                        </div>
                    </div>

                    <div style="display: flex; align-items: flex-end; gap: 12px; flex-wrap: wrap; margin-top: 8px;">
                        <div class="form-group" style="margin-bottom: 0; flex: 1; min-width: 240px;">
                            <label class="form-label">Uji Kirim Email ke</label>
                            <input type="email" name="test_email" class="form-input"
                                   value="<?= htmlspecialchars(settingVal('contact_email', '')) ?>"
                                   placeholder="email-penerima-uji@contoh.com">
                        </div>
                        <button type="submit" name="test_mail" value="1" class="btn btn-outline">
                            <i class="fas fa-paper-plane"></i> Kirim Email Uji
                        </button>
                        <small style="color: var(--text-muted); font-size: 11px;">Konfigurasi SMTP di kartu ini ikut tersimpan saat tombol ditekan, lalu langsung dicoba kirim.</small>
                    </div>
                </div>

                <!-- Harga Khusus Member -->
                <div class="admin-card">
                    <h3 class="admin-card-title">
                        <i class="fas fa-crown" style="color: #D4A853; margin-right: 8px;"></i>
                        Harga Khusus Member
                        <span style="font-size: 12px; color: var(--text-muted); font-family: 'Inter', sans-serif; font-weight: 400; margin-left: 8px;">
                            (diskon otomatis per level di keranjang &amp; checkout)
                        </span>
                    </h3>
                    <div style="padding: 12px 16px; background: #FFFBEB; border: 1px solid #FDE68A; border-radius: 10px; font-size: 12px; color: #92400E; margin-bottom: 16px; line-height: 1.7;">
                        <i class="fas fa-info-circle"></i> Diskon dihitung dari subtotal belanja dan otomatis diterapkan saat member login.
                        Berfungsi bersamaan dengan kode promo &amp; tukar poin. Level member ditentukan otomatis dari total belanja / langganan aktif.
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Silver (%)</label>
                            <input type="number" name="member_discount_silver" class="form-input" min="0" max="100" step="0.5"
                                   value="<?= htmlspecialchars(settingVal('member_discount_silver', '0')) ?>">
                            <small style="color: var(--text-muted); font-size: 11px;">Level awal (semua member)</small>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Gold (%)</label>
                            <input type="number" name="member_discount_gold" class="form-input" min="0" max="100" step="0.5"
                                   value="<?= htmlspecialchars(settingVal('member_discount_gold', '5')) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Platinum (%)</label>
                            <input type="number" name="member_discount_platinum" class="form-input" min="0" max="100" step="0.5"
                                   value="<?= htmlspecialchars(settingVal('member_discount_platinum', '10')) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Diamond (%)</label>
                            <input type="number" name="member_discount_diamond" class="form-input" min="0" max="100" step="0.5"
                                   value="<?= htmlspecialchars(settingVal('member_discount_diamond', '15')) ?>">
                        </div>
                    </div>
                </div>

                <!-- Pengiriman -->
                <div class="admin-card">
                    <h3 class="admin-card-title">
                        <i class="fas fa-truck" style="color: #D4A853; margin-right: 8px;"></i>
                        Pengiriman / Ongkos Kirim
                        <span style="font-size: 12px; color: var(--text-muted); font-family: 'Inter', sans-serif; font-weight: 400; margin-left: 8px;">
                            (dikenakan di checkout untuk semua pesanan)
                        </span>
                    </h3>
                    <div class="form-row">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">Ongkos Kirim (Rp)</label>
                            <input type="number" name="shipping_cost" class="form-input" min="0" step="500"
                                   value="<?= htmlspecialchars(settingVal('shipping_cost', '0')) ?>"
                                   placeholder="0">
                            <small style="color: var(--text-muted); font-size: 11px;">
                                <i class="fas fa-info-circle"></i> Isi <strong>0</strong> untuk <strong>GRATIS ONGKIR</strong>. Isi nominal (mis. 25000) jika ingin menarik ongkos kirim lagi.
                            </small>
                        </div>
                    </div>
                </div>

                <!-- Promo Membership -->
                <div class="admin-card">
                    <h3 class="admin-card-title">
                        <i class="fas fa-tags" style="color: #D4A853; margin-right: 8px;"></i>
                        Promo Membership
                        <span style="font-size: 12px; color: var(--text-muted); font-family: 'Inter', sans-serif; font-weight: 400; margin-left: 8px;">
                            (countdown + diskon paket tahunan di homepage)
                        </span>
                    </h3>
                    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;">
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 500;">
                            <input type="checkbox" name="membership_promo_active" value="1" style="accent-color: var(--soft-gold); width: 18px; height: 18px;"
                                   <?= settingVal('membership_promo_active', '1') === '1' ? 'checked' : '' ?>>
                            Aktifkan promo membership
                        </label>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Judul Promo</label>
                            <input type="text" name="membership_promo_title" class="form-input"
                                   value="<?= htmlspecialchars(settingVal('membership_promo_title', 'Promo Paket Tahunan')) ?>"
                                   placeholder="Promo Paket Tahunan">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Diskon Paket Tahunan (%)</label>
                            <input type="number" name="membership_promo_discount" class="form-input" min="1" max="90"
                                   value="<?= htmlspecialchars(settingVal('membership_promo_discount', '20')) ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Deskripsi Singkat</label>
                            <input type="text" name="membership_promo_desc" class="form-input"
                                   value="<?= htmlspecialchars(settingVal('membership_promo_desc', '')) ?>"
                                   placeholder="Hemat lebih banyak dengan paket tahunan...">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Berakhir Pada</label>
                            <input type="datetime-local" name="membership_promo_end" class="form-input"
                                   value="<?= htmlspecialchars(settingVal('membership_promo_end', '')) ?>">
                            <small style="color: var(--text-muted); font-size: 11px;">Kosongkan = promo berjalan terus dengan countdown +7 hari bergulir (tidak pernah habis). Isi tanggal agar promo berakhir otomatis.</small>
                        </div>
                    </div>
                    <p style="font-size: 12px; color: var(--text-muted); margin: 0;">
                        <i class="fas fa-info-circle"></i> Diskon diterapkan otomatis di checkout untuk paket <strong>tahunan</strong>, dan harga diskon ditampilkan di kartu paket homepage &amp; halaman membership.
                    </p>
                </div>

                <!-- Lainnya -->
                <div class="admin-card">
                    <h3 class="admin-card-title">
                        <i class="fas fa-pen" style="color: #D4A853; margin-right: 8px;"></i>
                        Lainnya
                    </h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Footer Tagline</label>
                            <textarea name="footer_tagline" class="form-textarea" rows="2"
                                      placeholder='"Membawa Cita Rasa Khas Riau Dalam Setiap Gigitan"'><?= htmlspecialchars(settingVal('footer_tagline', '')) ?></textarea>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Hari Buka (statistik di hero homepage)</label>
                            <input type="number" name="hero_open_days" class="form-input" min="1" max="7"
                                   value="<?= htmlspecialchars(settingVal('hero_open_days', '7')) ?>"
                                   placeholder="7">
                            <small style="color: var(--text-muted); font-size: 11px;">Jumlah hari operasional toko per minggu (muncul di angka statistik hero).</small>
                        </div>
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label">Role Penerima Notifikasi Suara Transaksi</label>
                        <?php $allRoles = $conn->query("SELECT slug, name FROM roles WHERE is_active = 1 ORDER BY id"); ?>
                        <select name="sound_notify_role" class="form-select">
                            <?php if ($allRoles): while ($rl = $allRoles->fetch_assoc()): ?>
                                <option value="<?= htmlspecialchars($rl['slug']) ?>" <?= settingVal('sound_notify_role', 'admin-penjualan-online') === $rl['slug'] ? 'selected' : '' ?>><?= htmlspecialchars($rl['name']) ?></option>
                            <?php endwhile; endif; ?>
                        </select>
                        <small style="color: var(--text-muted); font-size: 11px;">
                            <i class="fas fa-bell"></i> Saat ada transaksi baru, role ini dibunyikan notifikasi suara di panel admin. Default: <strong>Admin Penjualan Online</strong>.
                        </small>
                        <div style="display: flex; align-items: center; gap: 12px; margin-top: 10px; flex-wrap: wrap;">
                            <button type="button" class="btn btn-outline btn-sm" onclick="testNotifSound()" title="Putar preview bunyi notifikasi transaksi">
                                <i class="fas fa-volume-up"></i> Tes Suara
                            </button>
                            <small style="color: var(--text-muted); font-size: 11px;">Klik untuk mendengar bunyi notifikasi transaksi baru (ding-dong).</small>
                        </div>
                    </div>
                </div>

                <script>
                // Preview bunyi notifikasi transaksi ("Tes Suara")
                function testNotifSound() {
                    if (typeof window.nnPlayChime === 'function') {
                        window.nnPlayChime();
                    } else {
                        // Fallback bila script layout belum siap
                        try {
                            var ctx = new (window.AudioContext || window.webkitAudioContext)();
                            var t = ctx.currentTime;
                            [880, 1174.66].forEach(function (freq, i) {
                                var osc = ctx.createOscillator();
                                var gain = ctx.createGain();
                                var start = t + i * 0.18;
                                osc.type = 'sine';
                                osc.frequency.value = freq;
                                gain.gain.setValueAtTime(0.0001, start);
                                gain.gain.exponentialRampToValueAtTime(0.22, start + 0.03);
                                gain.gain.exponentialRampToValueAtTime(0.0001, start + 0.6);
                                osc.connect(gain);
                                gain.connect(ctx.destination);
                                osc.start(start);
                                osc.stop(start + 0.65);
                            });
                            setTimeout(function () { try { ctx.close(); } catch (e) {} }, 1800);
                        } catch (e) {}
                    }
                }
                </script>

                <!-- Submit -->
                <div class="admin-card" style="background: transparent; box-shadow: none; border: none; padding: 0;">
                    <button type="submit" class="btn btn-primary btn-lg" style="width: 100%; justify-content: center; padding: 16px;">
                        <i class="fas fa-save"></i>
                        Simpan Semua Pengaturan
                    </button>
                </div>
            </form>

        </main>
    </div>
</body>
</html>
