<?php
// ============================================
// PRODUCT SEEDER
// Mengisi database dengan data produk sample
// Website Nadhira Napoleon Pekanbaru
// ============================================
require_once __DIR__ . '/../config/database.php';

// Only allow via CLI or with INSTALL_KEY (browser blocked by default)
$isCLI = (php_sapi_name() === 'cli');
$keyOk = defined('INSTALL_KEY') && INSTALL_KEY !== ''
    && isset($_GET['key']) && hash_equals(INSTALL_KEY, (string)$_GET['key']);
if (!$isCLI && !$keyOk) {
    http_response_code(403);
    die('403 Forbidden - Akses ditolak. Jalankan dari terminal: php database/seeder.php');
}
if (!$isCLI && !isset($_GET['run'])) {
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Seeder Produk - Nadhira Napoleon</title>
        <style>
            body { font-family: system-ui, sans-serif; max-width: 700px; margin: 40px auto; padding: 20px; background: #f8f5f0; }
            h1 { color: #1a1a2e; }
            .warning { background: #FEF3C7; border-left: 4px solid #F59E0B; padding: 16px; border-radius: 8px; margin: 20px 0; }
            .warning h3 { margin: 0 0 8px 0; color: #92400E; }
            .warning p { margin: 0; color: #78350F; }
            .btn { display: inline-block; padding: 12px 32px; background: linear-gradient(135deg, #D4A853, #B8860B); color: #fff; text-decoration: none; border-radius: 8px; font-weight: 600; border: none; cursor: pointer; }
            .btn:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(212,168,83,0.4); }
        </style>
    </head>
    <body>
        <h1>🌾 Seeder Produk</h1>
        <div class="warning">
            <h3>⚠️ Perhatian!</h3>
            <p>Seeder ini akan menghapus semua data produk, gambar, dan review yang sudah ada, lalu mengisinya dengan 18 produk sample.<br>
            <strong>Data carts, orders, dan wishlists TIDAK akan terhapus.</strong></p>
        </div>
        <p style="margin: 24px 0;">
            <a href="?run=1" class="btn" onclick="return confirm('Yakin ingin menjalankan seeder? Semua produk lama akan dihapus!')">
                🚀 Jalankan Seeder
            </a>
        </p>
    </body>
    </html>
    <?php
    exit;
}

// ============================================
// EXECUTION
// ============================================
$conn = getConnection();
if (!$conn) {
    die("❌ Gagal terhubung ke database.\n");
}

echo $isCLI ? "🌾 NADHIRA NAPOLEON PRODUCT SEEDER\n" : "<pre style='background:#1a1a2e;color:#e0e0e0;padding:20px;border-radius:8px;max-width:700px;margin:20px auto;font-family:monospace;'>\n";
echo $isCLI ? "====================================\n\n" : "====================================\n\n";

// Clear existing product data (cascade will delete images, reviews, videos)
echo "🗑️  Menghapus data produk lama... ";
$conn->query("DELETE FROM product_reviews");
$conn->query("DELETE FROM product_images");
$conn->query("DELETE FROM product_videos");
$conn->query("DELETE FROM branch_products");
$conn->query("DELETE FROM wishlists WHERE product_id NOT IN (SELECT id FROM products)");
$conn->query("DELETE FROM products");
echo "OK!\n";

// ============================================
// PRODUCT DATA
// ============================================
$products = [
    // Category 1: Napoleon (id=1)
    [
        'category_id' => 1,
        'name' => 'Napoleon Classic',
        'slug' => 'napoleon-classic',
        'description' => 'Napoleon klasik dengan lapisan puff pastry renyah berpadu dengan vla vanilla lembut dan taburan almond slice. Camilan premium yang cocok dinikmati kapan saja, menjadi favorit pelanggan setia Nadhira Napoleon.',
        'composition' => 'Tepung terigu premium, mentega, telur, susu segar, gula pilihan, vanilla asli, almond slice, garam',
        'weight' => '250 gram',
        'expiration' => '7 hari (suhu kulkas 2-8°C)',
        'storage_instructions' => 'Simpan dalam kulkas pada suhu 2-8°C. Keluarkan 10 menit sebelum disajikan untuk tekstur terbaik.',
        'price' => 85000,
        'discount_price' => null,
        'stock' => 50,
        'rating' => 4.8,
        'total_sold' => 1280,
        'is_featured' => true,
        'is_best_seller' => true,
        'meta_title' => 'Napoleon Classic - Oleh-Oleh Khas Riau',
        'meta_description' => 'Napoleon klasik dengan puff pastry renyah dan vla vanilla lembut. Oleh-oleh premium khas Pekanbaru.',
    ],
    [
        'category_id' => 1,
        'name' => 'Napoleon Coklat',
        'slug' => 'napoleon-coklat',
        'description' => 'Varian Napoleon dengan lapisan coklat premium Belgian yang smooth dan creamy. Perpaduan renyahnya puff pastry dengan legitnya coklat menciptakan sensasi rasa yang tak terlupakan.',
        'composition' => 'Tepung terigu premium, mentega, telur, coklat Belgian, susu segar, gula, coklat bubuk, garam',
        'weight' => '250 gram',
        'expiration' => '7 hari (suhu kulkas 2-8°C)',
        'storage_instructions' => 'Simpan dalam kulkas pada suhu 2-8°C. Keluarkan 10 menit sebelum disajikan untuk tekstur terbaik.',
        'price' => 90000,
        'discount_price' => 81000,
        'stock' => 35,
        'rating' => 4.7,
        'total_sold' => 856,
        'is_featured' => false,
        'is_best_seller' => true,
        'meta_title' => 'Napoleon Coklat Belgian - Nadhira Napoleon',
        'meta_description' => 'Napoleon dengan coklat Belgian premium. Oleh-oleh favorit pecinta coklat.',
    ],
    [
        'category_id' => 1,
        'name' => 'Napoleon Keju',
        'slug' => 'napoleon-keju',
        'description' => 'Napoleon dengan vla keju gurih yang dipadukan dengan puff pastry renyah. Taburan keju parut di atasnya menambah cita rasa gurih yang bikin nagih!',
        'composition' => 'Tepung terigu premium, mentega, telur, keju edam, keju cheddar, susu segar, gula, garam',
        'weight' => '250 gram',
        'expiration' => '7 hari (suhu kulkas 2-8°C)',
        'storage_instructions' => 'Simpan dalam kulkas pada suhu 2-8°C.',
        'price' => 95000,
        'discount_price' => null,
        'stock' => 25,
        'rating' => 4.6,
        'total_sold' => 540,
        'is_featured' => false,
        'is_best_seller' => false,
        'meta_title' => 'Napoleon Keju - Nadhira Napoleon',
        'meta_description' => 'Napoleon dengan vla keju gurih yang lezat.',
    ],
    [
        'category_id' => 1,
        'name' => 'Napoleon Durian',
        'slug' => 'napoleon-durian',
        'description' => 'Perpaduan unik Napoleon dengan vla durian asli dari petani lokal Riau. Tekstur renyah berpadu dengan aroma dan rasa durian yang khas. Limited edition!',
        'composition' => 'Tepung terigu premium, mentega, telur, daging durian asli, susu segar, gula, garam',
        'weight' => '250 gram',
        'expiration' => '5 hari (suhu kulkas 2-8°C)',
        'storage_instructions' => 'Simpan dalam kulkas. Nikmati dalam 2-3 hari untuk rasa terbaik.',
        'price' => 110000,
        'discount_price' => 99000,
        'stock' => 15,
        'rating' => 4.9,
        'total_sold' => 320,
        'is_featured' => true,
        'is_best_seller' => true,
        'meta_title' => 'Napoleon Durian Limited - Nadhira Napoleon',
        'meta_description' => 'Napoleon dengan vla durian asli Riau. Edisi terbatas!',
    ],

    // Category 2: Pancake Durian (id=2)
    [
        'category_id' => 2,
        'name' => 'Pancake Durian Premium',
        'slug' => 'pancake-durian-premium',
        'description' => 'Pancake lembut berisi daging durian asli pilihan dengan krim segar. Setiap gigitan menghadirkan sensasi durian yang autentik. Menggunakan durian montong dan durian lokal Riau terbaik.',
        'composition' => 'Tepung terigu, telur, susu segar, daging durian asli, gula, mentega, krim segar, garam',
        'weight' => '350 gram (6 pcs)',
        'expiration' => '3 hari (suhu kulkas 2-8°C)',
        'storage_instructions' => 'Simpan dalam kulkas. Paling nikmat disantap dalam keadaan dingin.',
        'price' => 125000,
        'discount_price' => null,
        'stock' => 30,
        'rating' => 4.9,
        'total_sold' => 2100,
        'is_featured' => true,
        'is_best_seller' => true,
        'meta_title' => 'Pancake Durian Premium - Nadhira Napoleon',
        'meta_description' => 'Pancake lembut dengan daging durian asli. Oleh-oleh premium khas Pekanbaru.',
    ],
    [
        'category_id' => 2,
        'name' => 'Pancake Durian Mini',
        'slug' => 'pancake-durian-mini',
        'description' => 'Versi mini dari Pancake Durian Premium kami. Ukuran kecil yang pas untuk cemilan atau oleh-oleh dalam jumlah banyak. Tetap dengan kualitas dan rasa yang sama!',
        'composition' => 'Tepung terigu, telur, susu segar, daging durian asli, gula, mentega, krim segar',
        'weight' => '200 gram (10 pcs mini)',
        'expiration' => '3 hari (suhu kulkas 2-8°C)',
        'storage_instructions' => 'Simpan dalam kulkas. Cocok untuk bekal atau cemilan.',
        'price' => 75000,
        'discount_price' => null,
        'stock' => 40,
        'rating' => 4.7,
        'total_sold' => 1100,
        'is_featured' => false,
        'is_best_seller' => false,
        'meta_title' => 'Pancake Durian Mini - Nadhira Napoleon',
        'meta_description' => 'Pancake durian mini yang lucu dan lezat. Cocok untuk oleh-oleh.',
    ],
    [
        'category_id' => 2,
        'name' => 'Pancake Durian Coklat',
        'slug' => 'pancake-durian-coklat',
        'description' => 'Inovasi terbaru! Pancake durian dengan lapisan coklat Belgian yang smooth. Perpaduan durian dan coklat yang sempurna untuk pengalaman rasa yang unik.',
        'composition' => 'Tepung terigu, telur, susu segar, daging durian asli, coklat Belgian, gula, mentega, krim segar',
        'weight' => '350 gram (6 pcs)',
        'expiration' => '3 hari (suhu kulkas 2-8°C)',
        'storage_instructions' => 'Simpan dalam kulkas. Keluarkan 5 menit sebelum disantap.',
        'price' => 135000,
        'discount_price' => 115000,
        'stock' => 20,
        'rating' => 4.8,
        'total_sold' => 420,
        'is_featured' => true,
        'is_best_seller' => false,
        'meta_title' => 'Pancake Durian Coklat - Nadhira Napoleon',
        'meta_description' => 'Pancake durian dengan coklat Belgian. Perpaduan rasa yang unik!',
    ],

    // Category 3: Mochi (id=3)
    [
        'category_id' => 3,
        'name' => 'Mochi Matcha Premium',
        'slug' => 'mochi-matcha-premium',
        'description' => 'Mochi lembut dengan isian krim matcha asli Jepang. Tekstur kenyal yang sempurna dengan rasa matcha yang autentik. Dibuat dengan tepung mochi premium dan matcha grade A.',
        'composition' => 'Tepung mochi premium, matcha bubuk grade A, gula, krim segar, susu, mentega',
        'weight' => '200 gram (8 pcs)',
        'expiration' => '5 hari (suhu kulkas)',
        'storage_instructions' => 'Simpan dalam wadah kedap udara di kulkas.',
        'price' => 65000,
        'discount_price' => null,
        'stock' => 40,
        'rating' => 4.7,
        'total_sold' => 680,
        'is_featured' => false,
        'is_best_seller' => false,
        'meta_title' => 'Mochi Matcha Premium - Nadhira Napoleon',
        'meta_description' => 'Mochi lembut dengan isian krim matcha asli.',
    ],
    [
        'category_id' => 3,
        'name' => 'Mochi Red Velvet',
        'slug' => 'mochi-red-velvet',
        'description' => 'Mochi dengan isian cream cheese red velvet yang creamy. Warna merah alami dari bit menghasilkan tampilan yang cantik dan menggoda. Favorit kaum hawa!',
        'composition' => 'Tepung mochi premium, cream cheese, ekstrak bit, gula, krim segar, susu, mentega, vanilla',
        'weight' => '200 gram (8 pcs)',
        'expiration' => '5 hari (suhu kulkas)',
        'storage_instructions' => 'Simpan dalam wadah kedap udara di kulkas.',
        'price' => 70000,
        'discount_price' => null,
        'stock' => 30,
        'rating' => 4.6,
        'total_sold' => 520,
        'is_featured' => false,
        'is_best_seller' => false,
        'meta_title' => 'Mochi Red Velvet - Nadhira Napoleon',
        'meta_description' => 'Mochi red velvet dengan cream cheese yang creamy.',
    ],
    [
        'category_id' => 3,
        'name' => 'Mochi Durian',
        'slug' => 'mochi-durian',
        'description' => 'Mochi dengan isian daging durian asli! Perpaduan kenyalnya mochi dengan legitnya durian menciptakan camilan yang unik dan lezat. Wajib coba!',
        'composition' => 'Tepung mochi premium, daging durian asli, gula, krim segar, susu, mentega',
        'weight' => '200 gram (8 pcs)',
        'expiration' => '4 hari (suhu kulkas)',
        'storage_instructions' => 'Simpan dalam wadah kedap udara di kulkas.',
        'price' => 75000,
        'discount_price' => 65000,
        'stock' => 20,
        'rating' => 4.8,
        'total_sold' => 340,
        'is_featured' => false,
        'is_best_seller' => false,
        'meta_title' => 'Mochi Durian - Nadhira Napoleon',
        'meta_description' => 'Mochi dengan isian durian asli. Perpaduan unik yang lezat!',
    ],

    // Category 4: Cake (id=4)
    [
        'category_id' => 4,
        'name' => 'Black Forest Cake',
        'slug' => 'black-forest-cake',
        'description' => 'Black Forest Cake klasik dengan lapisan coklat sponge yang moist, krim segar, dan ceri. Cocok untuk acara spesial dan hadiah. Ukuran 20 cm untuk 8-10 orang.',
        'composition' => 'Tepung terigu, coklat bubuk, telur, gula, mentega, krim segar, ceri, coklat chips, vanilla',
        'weight' => '800 gram (ukuran 20 cm)',
        'expiration' => '4 hari (suhu kulkas)',
        'storage_instructions' => 'Simpan dalam kulkas. Keluarkan 15 menit sebelum disajikan.',
        'price' => 175000,
        'discount_price' => null,
        'stock' => 10,
        'rating' => 4.8,
        'total_sold' => 450,
        'is_featured' => false,
        'is_best_seller' => false,
        'meta_title' => 'Black Forest Cake - Nadhira Napoleon',
        'meta_description' => 'Black Forest Cake klasik premium untuk acara spesial.',
    ],
    [
        'category_id' => 4,
        'name' => 'Red Velvet Cake',
        'slug' => 'red-velvet-cake',
        'description' => 'Red Velvet Cake dengan cream cheese frosting yang lembut. Warna merah alami dan tekstur super moist membuat cake ini menjadi favorit di setiap acara. Ukuran 20 cm.',
        'composition' => 'Tepung terigu, coklat bubuk, telur, gula, mentega, buttermilk, cream cheese, ekstrak bit, vanilla',
        'weight' => '800 gram (ukuran 20 cm)',
        'expiration' => '4 hari (suhu kulkas)',
        'storage_instructions' => 'Simpan dalam kulkas. Keluarkan 15 menit sebelum disajikan.',
        'price' => 165000,
        'discount_price' => 145000,
        'stock' => 8,
        'rating' => 4.7,
        'total_sold' => 380,
        'is_featured' => true,
        'is_best_seller' => false,
        'meta_title' => 'Red Velvet Cake - Nadhira Napoleon',
        'meta_description' => 'Red Velvet Cake premium dengan cream cheese frosting.',
    ],

    // Category 5: Brownies (id=5)
    [
        'category_id' => 5,
        'name' => 'Brownies Fudgy Belgian',
        'slug' => 'brownies-fudgy-belgian',
        'description' => 'Brownies fudgy dengan coklat Belgian asli. Tekstur moist dan rich dengan potongan coklat di setiap gigitan. Dibuat dengan resep spesial yang dijamin bikin ketagihan!',
        'composition' => 'Coklat Belgian, mentega, telur, gula, tepung terigu, coklat bubuk, kacang almond, vanilla',
        'weight' => '350 gram (loyang 20x20 cm)',
        'expiration' => '7 hari (suhu ruang)',
        'storage_instructions' => 'Simpan dalam wadah kedap udara di suhu ruang atau kulkas.',
        'price' => 95000,
        'discount_price' => 85000,
        'stock' => 25,
        'rating' => 4.9,
        'total_sold' => 920,
        'is_featured' => true,
        'is_best_seller' => true,
        'meta_title' => 'Brownies Fudgy Belgian - Nadhira Napoleon',
        'meta_description' => 'Brownies fudgy dengan coklat Belgian asli. Super moist dan rich!',
    ],
    [
        'category_id' => 5,
        'name' => 'Brownies Kukus Keju',
        'slug' => 'brownies-kukus-keju',
        'description' => 'Brownies kukus lembut dengan topping keju yang melimpah. Teksturnya yang moist dan ringan berpadu sempurna dengan gurihnya keju. Camilan favorit semua kalangan.',
        'composition' => 'Tepung terigu, coklat bubuk, telur, gula, mentega, keju cheddar, susu kental manis, vanila',
        'weight' => '300 gram (loyang 18x18 cm)',
        'expiration' => '5 hari (suhu kulkas)',
        'storage_instructions' => 'Simpan dalam wadah kedap udara di kulkas.',
        'price' => 85000,
        'discount_price' => null,
        'stock' => 20,
        'rating' => 4.6,
        'total_sold' => 590,
        'is_featured' => false,
        'is_best_seller' => false,
        'meta_title' => 'Brownies Kukus Keju - Nadhira Napoleon',
        'meta_description' => 'Brownies kukus lembut dengan topping keju melimpah.',
    ],

    // Category 6: Snack Premium (id=6)
    [
        'category_id' => 6,
        'name' => 'Kastengel Premium',
        'slug' => 'kastengel-premium',
        'description' => 'Kastengel renyah dengan keju edam dan cheddar asli. Camilan premium yang cocok untuk teman minum teh atau sebagai oleh-oleh. Dikemas dalam toples elegan.',
        'composition' => 'Tepung terigu, keju edam, keju cheddar, mentega, telur, susu bubuk, garam',
        'weight' => '250 gram (toples)',
        'expiration' => '30 hari (suhu ruang)',
        'storage_instructions' => 'Simpan dalam wadah kedap udara di suhu ruang yang sejuk.',
        'price' => 75000,
        'discount_price' => null,
        'stock' => 30,
        'rating' => 4.7,
        'total_sold' => 680,
        'is_featured' => false,
        'is_best_seller' => false,
        'meta_title' => 'Kastengel Premium - Nadhira Napoleon',
        'meta_description' => 'Kastengel renyah dengan keju edam dan cheddar premium.',
    ],
    [
        'category_id' => 6,
        'name' => 'Lidah Kucing Keju',
        'slug' => 'lidah-kucing-keju',
        'description' => 'Kue lidah kucing yang renyah dengan rasa keju gurih. Tekstur tipis dan renyah, cocok untuk cemilan sehari-hari. Kemasan pouch yang praktis.',
        'composition' => 'Tepung terigu, keju, mentega, telur, gula, susu bubuk, garam',
        'weight' => '200 gram',
        'expiration' => '30 hari (suhu ruang)',
        'storage_instructions' => 'Simpan dalam wadah kedap udara di suhu ruang yang sejuk.',
        'price' => 65000,
        'discount_price' => 55000,
        'stock' => 35,
        'rating' => 4.5,
        'total_sold' => 410,
        'is_featured' => false,
        'is_best_seller' => false,
        'meta_title' => 'Lidah Kucing Keju - Nadhira Napoleon',
        'meta_description' => 'Kue lidah kucing renyah dengan rasa keju gurih.',
    ],

    // Category 7: Oleh-Oleh Khas Riau (id=7)
    [
        'category_id' => 7,
        'name' => 'Kemplang Ikan Asli',
        'slug' => 'kemplang-ikan-asli',
        'description' => 'Kerupuk kemplang ikan asli khas Riau. Dibuat dari ikan tenggiri segar pilihan, dipanggang hingga renyah. Cita rasa gurih khas laut yang autentik. Oleh-oleh wajib dari Pekanbaru!',
        'composition' => 'Ikan tenggiri segar, tepung tapioka, bawang putih, garam, gula, bumbu tradisional',
        'weight' => '250 gram',
        'expiration' => '60 hari (suhu ruang)',
        'storage_instructions' => 'Simpan dalam wadah kedap udara di suhu ruang yang sejuk dan kering.',
        'price' => 45000,
        'discount_price' => null,
        'stock' => 50,
        'rating' => 4.6,
        'total_sold' => 780,
        'is_featured' => false,
        'is_best_seller' => true,
        'meta_title' => 'Kemplang Ikan Asli Khas Riau - Nadhira Napoleon',
        'meta_description' => 'Kerupuk kemplang ikan tenggiri asli khas Riau. Oleh-oleh wajib dari Pekanbaru!',
    ],
    [
        'category_id' => 7,
        'name' => 'Kerupuk Jangek',
        'slug' => 'kerupuk-jangek',
        'description' => 'Kerupuk jangek khas Riau yang terbuat dari kulit sapi pilihan. Digoreng hingga renyah, memiliki tekstur yang unik dan gurih. Camilan tradisional yang tak lekang oleh waktu.',
        'composition' => 'Kulit sapi pilihan, bawang putih, garam, ketumbar, bumbu tradisional, minyak goreng',
        'weight' => '200 gram',
        'expiration' => '90 hari (suhu ruang)',
        'storage_instructions' => 'Simpan dalam wadah kedap udara di suhu ruang yang sejuk dan kering.',
        'price' => 35000,
        'discount_price' => null,
        'stock' => 45,
        'rating' => 4.5,
        'total_sold' => 560,
        'is_featured' => false,
        'is_best_seller' => false,
        'meta_title' => 'Kerupuk Jangek Khas Riau - Nadhira Napoleon',
        'meta_description' => 'Kerupuk jangek kulit sapi khas Riau yang renyah dan gurih.',
    ],

    // Category 8: Frozen Food (id=8)
    [
        'category_id' => 8,
        'name' => 'Pisang Goreng Krispi Frozen',
        'slug' => 'pisang-goreng-krispi-frozen',
        'description' => 'Pisang goreng krispi siap saji yang praktis. Tinggal goreng sebentar, nikmati camilan hangat kapan saja. Menggunakan pisang pilihan dengan balutan tepung krispi spesial.',
        'composition' => 'Pisang kepok, tepung terigu, tepung beras, mentega, gula, garam, vanili',
        'weight' => '500 gram (isi 20 pcs)',
        'expiration' => '6 bulan (suhu -18°C)',
        'storage_instructions' => 'Simpan dalam freezer pada suhu -18°C. Jangan dicairkan sebelum digoreng.',
        'price' => 35000,
        'discount_price' => null,
        'stock' => 30,
        'rating' => 4.4,
        'total_sold' => 230,
        'is_featured' => false,
        'is_best_seller' => false,
        'meta_title' => 'Pisang Goreng Krispi Frozen - Nadhira Napoleon',
        'meta_description' => 'Pisang goreng krispi frozen siap saji. Praktis dan lezat!',
    ],

    // Category 9: Paket Oleh-Oleh (id=9)
    [
        'category_id' => 9,
        'name' => 'Paket Keluarga',
        'slug' => 'paket-keluarga',
        'description' => 'Paket lengkap berisi Napoleon Classic 2 box + Pancake Durian Premium + Brownies Fudgy + Mochi Matcha. Cocok untuk oleh-oleh keluarga besar. Hemat dan lengkap!',
        'composition' => '-',
        'weight' => 'Paket box',
        'expiration' => 'Bervariasi (lihat masing-masing produk)',
        'storage_instructions' => 'Simpan produk dalam kulkas. Konsultasikan dengan tim kami untuk penyimpanan optimal.',
        'price' => 275000,
        'discount_price' => 245000,
        'stock' => 15,
        'rating' => 4.8,
        'total_sold' => 340,
        'is_featured' => true,
        'is_best_seller' => true,
        'meta_title' => 'Paket Keluarga - Oleh-Oleh Nadhira Napoleon',
        'meta_description' => 'Paket oleh-oleh lengkap untuk keluarga. Hemat dan praktis!',
    ],
    [
        'category_id' => 9,
        'name' => 'Paket Koleksi Premium',
        'slug' => 'paket-koleksi-premium',
        'description' => 'Koleksi lengkap 6 varian produk Nadhira Napoleon dalam kemasan gift box eksklusif. Mewah, elegan, dan menjadi hadiah istimewa untuk orang tersayang.',
        'composition' => '-',
        'weight' => 'Paket gift box premium',
        'expiration' => 'Bervariasi (lihat masing-masing produk)',
        'storage_instructions' => 'Simpan dalam kulkas. Gift box dapat disimpan sebagai kenang-kenangan.',
        'price' => 450000,
        'discount_price' => 395000,
        'stock' => 10,
        'rating' => 4.9,
        'total_sold' => 180,
        'is_featured' => true,
        'is_best_seller' => false,
        'meta_title' => 'Paket Koleksi Premium Gift Box - Nadhira Napoleon',
        'meta_description' => 'Koleksi 6 varian produk dalam gift box eksklusif. Hadiah istimewa!',
    ],
];

// ============================================
// INSERT PRODUCTS
// ============================================
echo "📦 Memasukkan " . count($products) . " produk... ";
$insertedIds = [];

$stmt = $conn->prepare("INSERT INTO products (category_id, name, slug, description, composition, weight, expiration, storage_instructions, price, discount_price, stock, rating, total_sold, is_featured, is_best_seller, meta_title, meta_description) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

foreach ($products as $p) {
    $stmt->bind_param(
        'isssssssddidiiiss',
        $p['category_id'],
        $p['name'],
        $p['slug'],
        $p['description'],
        $p['composition'],
        $p['weight'],
        $p['expiration'],
        $p['storage_instructions'],
        $p['price'],
        $p['discount_price'],
        $p['stock'],
        $p['rating'],
        $p['total_sold'],
        $p['is_featured'],
        $p['is_best_seller'],
        $p['meta_title'],
        $p['meta_description']
    );
    $stmt->execute();
    $insertedIds[] = $conn->insert_id;
}
echo "OK!\n";

// ============================================
// INSERT PRODUCT IMAGES
// ============================================
echo "🖼️  Memasukkan gambar produk... ";

$unsplashImages = [
    // Napoleon variations
    'napoleon' => [
        'https://images.unsplash.com/photo-1551024601-bec78aea704b?w=600&q=80',
        'https://images.unsplash.com/photo-1509365465985-25d11c17e812?w=600&q=80',
        'https://images.unsplash.com/photo-1556679343-c7306c1976bc?w=600&q=80',
    ],
    'pancake-durian' => [
        'https://images.unsplash.com/photo-1565958011703-44f9829ba187?w=600&q=80',
        'https://images.unsplash.com/photo-1556217477-d325251ece38?w=600&q=80',
    ],
    'mochi' => [
        'https://images.unsplash.com/photo-1578985545062-69928b1d9587?w=600&q=80',
        'https://images.unsplash.com/photo-1556045940-66e657080adb?w=600&q=80',
    ],
    'cake' => [
        'https://images.unsplash.com/photo-1578985545062-69928b1d9587?w=600&q=80',
        'https://images.unsplash.com/photo-1556679343-c7306c1976bc?w=600&q=80',
    ],
    'brownies' => [
        'https://images.unsplash.com/photo-1606313564200-e75d5e30476c?w=600&q=80',
        'https://images.unsplash.com/photo-1578985545062-69928b1d9587?w=600&q=80',
    ],
    'snack' => [
        'https://images.unsplash.com/photo-1558961363-fa8fdf82db35?w=600&q=80',
        'https://images.unsplash.com/photo-1604068549290-dea0e4a305ca?w=600&q=80',
    ],
    'traditional' => [
        'https://images.unsplash.com/photo-1486428263684-28ec9e4f2d1d?w=600&q=80',
        'https://images.unsplash.com/photo-1558961363-fa8fdf82db35?w=600&q=80',
    ],
    'frozen' => [
        'https://images.unsplash.com/photo-1558961363-fa8fdf82db35?w=600&q=80',
        'https://images.unsplash.com/photo-1486428263684-28ec9e4f2d1d?w=600&q=80',
    ],
    'paket' => [
        'https://images.unsplash.com/photo-1551024601-bec78aea704b?w=600&q=80',
        'https://images.unsplash.com/photo-1565958011703-44f9829ba187?w=600&q=80',
        'https://images.unsplash.com/photo-1578985545062-69928b1d9587?w=600&q=80',
    ],
];

$categoryImageMap = [
    1 => 'napoleon',
    2 => 'pancake-durian',
    3 => 'mochi',
    4 => 'cake',
    5 => 'brownies',
    6 => 'snack',
    7 => 'traditional',
    8 => 'frozen',
    9 => 'paket',
];

$imgStmt = $conn->prepare("INSERT INTO product_images (product_id, image, is_primary, sort_order) VALUES (?, ?, ?, ?)");

foreach ($insertedIds as $index => $productId) {
    $catId = $products[$index]['category_id'];
    $imageKey = $categoryImageMap[$catId] ?? 'napoleon';
    $images = $unsplashImages[$imageKey];
    
    foreach ($images as $i => $url) {
        $isPrimary = ($i === 0) ? 1 : 0;
        $imgStmt->bind_param('isii', $productId, $url, $isPrimary, $i);
        $imgStmt->execute();
    }
}
echo count($insertedIds) * 2 . "+ gambar ditambahkan!\n";

// ============================================
// INSERT PRODUCT REVIEWS
// ============================================
echo "⭐ Memasukkan review produk... ";

$reviewers = [
    ['Siti Rahmawati', 1],
    ['Ahmad Fauzi', 3],
    ['Dewi Sartika', 5],
    ['Budi Hartono', 7],
    ['Rina Amelia', 11],
    ['Rudi Hermawan', 13],
    ['Fitriani Siregar', 2],
    ['Andi Pratama', 4],
    ['Maya Anggraini', 6],
    ['Doni Kusuma', 8],
];

$reviewTexts = [
    'Produknya enak banget! Recommended banget buat oleh-oleh.',
    'Kualitasnya bagus, pengiriman cepat. Pasti order lagi!',
    'Rasanya enak, teksturnya pas. Suka banget!',
    'Cocok banget buat cemilan di rumah. Anak-anak juga suka.',
    'Kemasan cantik, cocok untuk gift. Produknya juga enak!',
    'Sudah langganan sejak lama. Kualitasnya konsisten selalu bagus.',
    'Harga sesuai dengan kualitas. Recommended!',
    'Pengiriman aman, produk sampai dengan baik. Terima kasih!',
    'Enak, fresh, dan pelayanan ramah. Jadi langganan tetap.',
    'Awalnya ragu, tapi setelah coba ternyata enak banget!',
];

$reviewStmt = $conn->prepare("INSERT INTO product_reviews (product_id, reviewer_name, rating, review, is_verified) VALUES (?, ?, ?, ?, 1)");

foreach ($insertedIds as $productId) {
    // Add 3-4 reviews per product
    $numReviews = rand(3, 4);
    $usedReviewers = [];
    
    for ($r = 0; $r < $numReviews; $r++) {
        // Pick a random reviewer that hasn't been used for this product
        do {
            $reviewer = $reviewers[array_rand($reviewers)];
        } while (in_array($reviewer[0], $usedReviewers));
        
        $usedReviewers[] = $reviewer[0];
        $rating = rand(4, 5); // Mostly 4-5 star reviews for quality products
        $reviewText = $reviewTexts[array_rand($reviewTexts)];
        
        $reviewStmt->bind_param('isis', $productId, $reviewer[0], $rating, $reviewText);
        $reviewStmt->execute();
    }
}
echo "60+ review ditambahkan!\n\n";

// ============================================
// ARTICLES SEEDER
// ============================================
echo "📰 Memasukkan artikel... ";
$conn->query("DELETE FROM articles");

$articles = [
    [
        'title' => 'Sejarah Napoleon: Kue Legendaris yang Mendunia',
        'slug' => 'sejarah-napoleon-kue-legendaris',
        'content' => '<p>Kue Napoleon, atau yang dikenal juga dengan nama Mille-feuille, adalah salah satu kue paling ikonik dalam sejarah kuliner dunia. Meskipun namanya terinspirasi dari Kaisar Napoleon Bonaparte, asal-usul kue ini sebenarnya bermula dari Prancis pada abad ke-17.</p><p>Kue Napoleon terdiri dari lapisan puff pastry yang renyah berlapis-lapis dengan isian krim pastry (crème pâtissière) yang lembut di setiap lapisannya. Teknik pembuatan puff pastry yang rumit dengan melipat adonan dan mentega berkali-kali hingga menghasilkan ratusan lapisan tipis adalah kunci dari kelezatan kue ini.</p><p>Di Indonesia, khususnya di Pekanbaru, Nadhira Napoleon hadir membawa resep autentik kue Napoleon dengan sentuhan khas Melayu Riau. Kami menggunakan bahan-bahan premium pilihan dan teknik pembuatan tradisional yang telah disempurnakan untuk menghasilkan Napoleon dengan cita rasa terbaik.</p><p>Setiap lapisan Napoleon kami dibuat dengan penuh ketelitian dan cinta, memastikan setiap gigitan menghadirkan sensasi renyah dan creamy yang sempurna. Tidak heran jika Napoleon menjadi salah satu oleh-oleh favorit dari Pekanbaru yang dicari oleh wisatawan dari berbagai kota.</p>',
        'excerpt' => 'Mengenal sejarah kue Napoleon yang legendaris, dari Prancis hingga menjadi ikon oleh-oleh khas Pekanbaru.',
        'image' => 'https://images.unsplash.com/photo-1551024601-bec78aea704b?w=600&q=80',
        'author' => 'Nadhira Napoleon',
        'is_published' => 1,
        'published_at' => '2026-07-15 08:00:00',
    ],
    [
        'title' => '5 Tips Memilih Oleh-Oleh Khas Pekanbaru yang Tepat',
        'slug' => 'tips-memilih-oleh-oleh-pekanbaru',
        'content' => '<p>Pekanbaru, ibu kota Provinsi Riau, dikenal sebagai surga kuliner dengan berbagai oleh-oleh khas yang menggugah selera. Namun, dengan banyaknya pilihan, bagaimana cara memilih oleh-oleh yang tepat? Berikut 5 tips dari Nadhira Napoleon untuk Anda.</p><p><strong>1. Perhatikan Kualitas Bahan</strong><br>Pilih oleh-oleh yang menggunakan bahan-bahan premium dan alami. Produk berkualitas biasanya menggunakan bahan segar tanpa pengawet berbahaya. Di Nadhira Napoleon, kami hanya menggunakan bahan terbaik untuk setiap produk.</p><p><strong>2. Cek Kemasan</strong><br>Kemasan yang baik tidak hanya menarik tapi juga melindungi produk. Pastikan kemasan kedap udara dan cocok untuk perjalanan jauh. Produk kami dikemas dengan standar premium untuk pengiriman ke seluruh Indonesia.</p><p><strong>3. Sesuaikan dengan Penerima</strong><br>Pertimbangkan selera penerima. Jika mereka suka manis, Napoleon atau cake bisa jadi pilihan. Untuk yang suka gurih, kastengel atau kerupuk jangek lebih cocok. Nadhira Napoleon menyediakan beragam varian untuk berbagai selera.</p><p><strong>4. Perhatikan Daya Tahan</strong><br>Sesuaikan oleh-oleh dengan lama perjalanan. Produk kering seperti kemplang atau kerupuk jangek cocok untuk perjalanan jauh, sementara produk segar seperti pancake durian lebih cocok untuk perjalanan pendek dengan pendingin.</p><p><strong>5. Beli di Tempat Terpercaya</strong><br>Pastikan membeli oleh-oleh di toko resmi dan terpercaya seperti Nadhira Napoleon yang sudah memiliki reputasi dan standar kualitas yang jelas. Kami memiliki 3 cabang di Pekanbaru yang siap melayani Anda.</p>',
        'excerpt' => 'Panduan lengkap memilih oleh-oleh khas Pekanbaru yang berkualitas, dari bahan hingga kemasan.',
        'image' => 'https://images.unsplash.com/photo-1565958011703-44f9829ba187?w=600&q=80',
        'author' => 'Tim Nadhira Napoleon',
        'is_published' => 1,
        'published_at' => '2026-07-10 10:00:00',
    ],
    [
        'title' => 'Pancake Durian: Perpaduan Sempurna Durian dan Kelembutan',
        'slug' => 'pancake-durian-perpaduan-sempurna',
        'content' => '<p>Durian, si raja buah, telah lama menjadi ikon kuliner Asia Tenggara. Di tangan Nadhira Napoleon, durian diolah menjadi Pancake Durian Premium yang lembut dan menggoda.</p><p>Pancake Durian Nadhira Napoleon dibuat dengan kulit pancake yang tipis dan lembut, diisi dengan daging durian asli pilihan yang creamy dan manis alami. Kami menggunakan durian montong dan durian lokal Riau terbaik untuk memastikan kualitas dan cita rasa yang autentik.</p><p>Proses pembuatan Pancake Durian kami membutuhkan ketelitian tinggi. Setiap pancake dibuat secara handmade, diisi dengan durian segar yang sudah dipilih dengan cermat, dan disajikan dalam kemasan yang elegan. Hasilnya adalah pancake durian dengan tekstur yang sempurna dan rasa yang tak terlupakan.</p><p>Tidak heran jika Pancake Durian Premium menjadi salah satu produk terlaris Nadhira Napoleon, dengan ribuan pelanggan puas dari berbagai kota di Indonesia. Bagi pecinta durian, pancake ini adalah surga dalam setiap gigitan!</p>',
        'excerpt' => 'Mengapa Pancake Durian Nadhira Napoleon menjadi favorit banyak pelanggan? Simak rahasia kelezatannya.',
        'image' => 'https://images.unsplash.com/photo-1578985545062-69928b1d9587?w=600&q=80',
        'author' => 'Nadhira Napoleon',
        'is_published' => 1,
        'published_at' => '2026-07-05 09:00:00',
    ],
    [
        'title' => 'Tips Menyimpan Kue Napoleon Agar Tetap Renyah',
        'slug' => 'tips-menyimpan-napoleon',
        'content' => '<p>Kue Napoleon terkenal dengan tekstur renyah berlapis yang menjadi ciri khasnya. Namun, penyimpanan yang salah bisa membuat Napoleon menjadi lembek dan kehilangan kelezatannya. Berikut tips menyimpan Napoleon dari Nadhira Napoleon.</p><p><strong>Simpan dalam Kulkas</strong><br>Napoleon sebaiknya disimpan dalam kulkas pada suhu 2-8°C. Suhu dingin membantu menjaga tekstur puff pastry tetap renyah dan krim tetap segar.</p><p><strong>Gunakan Wadah Kedap Udara</strong><br>Simpan Napoleon dalam wadah kedap udara untuk mencegah kelembaban berlebih yang bisa membuat tekstur menjadi lembek. Hindari menyimpan bersama makanan yang berbau tajam.</p><p><strong>Keluarkan Sebelum Disajikan</strong><br>Keluarkan Napoleon dari kulkas sekitar 10-15 menit sebelum disajikan untuk mendapatkan tekstur dan rasa terbaik. Napoleon yang terlalu dingin akan terasa keras, sementara terlalu lama di suhu ruang akan melelehkan krim.</p><p><strong>Jangan Simpan Lebih dari Seminggu</strong><br>Napoleon paling nikmat dinikmati dalam 3-5 hari setelah pembelian. Setelah itu, tekstur puff pastry akan mulai menurun meskipun disimpan dengan benar.</p>',
        'excerpt' => 'Panduan menyimpan kue Napoleon agar tetap renyah dan lezat lebih lama.',
        'image' => 'https://images.unsplash.com/photo-1556679343-c7306c1976bc?w=600&q=80',
        'author' => 'Tim Nadhira Napoleon',
        'is_published' => 1,
        'published_at' => '2026-06-28 08:30:00',
    ],
    [
        'title' => 'Mengenal Program Membership Nadhira Napoleon',
        'slug' => 'program-membership-nadhira-napoleon',
        'content' => '<p>Nadhira Napoleon memiliki program membership eksklusif dengan 4 level yang dirancang untuk memberikan pengalaman berbelanja terbaik bagi pelanggan setia. Setiap level menawarkan benefit yang semakin menarik.</p><p><strong>Level Silver</strong><br>Gratis untuk semua pelanggan yang mendaftar. Dapatkan voucher diskon 5% setiap bulan dan 1 point untuk setiap pembelian. Akses ke promo reguler.</p><p><strong>Level Gold</strong><br>Dicapai dengan minimal belanja Rp 500.000. Nikmati voucher 10% bulanan, 2 point per pembelian, gratis ongkir area Pekanbaru, dan voucher ulang tahun spesial.</p><p><strong>Level Platinum</strong><br>Minimal belanja Rp 2.000.000. Dapatkan voucher 15% bulanan, 3 point per pembelian, gratis ongkir nasional, dan voucher ulang tahun spesial.</p><p><strong>Level Diamond</strong><br>Minimal belanja Rp 5.000.000. Nikmati semua benefit Platinum plus voucher 20% bulanan, 5 point per pembelian, layanan prioritas, dan hadiah eksklusif bulanan.</p><p>Daftar sekarang dan nikmati berbagai keuntungan menarik hanya di Nadhira Napoleon!</p>',
        'excerpt' => 'Informasi lengkap program membership 4 level Nadhira Napoleon dengan berbagai benefit eksklusif.',
        'image' => 'https://images.unsplash.com/photo-1486428263684-28ec9e4f2d1d?w=600&q=80',
        'author' => 'Nadhira Napoleon',
        'is_published' => 1,
        'published_at' => '2026-06-20 07:00:00',
    ],
];

$artStmt = $conn->prepare("INSERT INTO articles (title, slug, content, excerpt, image, author, is_published, published_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
foreach ($articles as $a) {
    $artStmt->bind_param('ssssssis', $a['title'], $a['slug'], $a['content'], $a['excerpt'], $a['image'], $a['author'], $a['is_published'], $a['published_at']);
    $artStmt->execute();
}
echo count($articles) . " artikel ditambahkan!\n";

// ============================================
// TESTIMONIALS SEEDER
// ============================================
echo "⭐ Memasukkan testimoni... ";
$conn->query("DELETE FROM testimonials");

$testimonials = [
    ['customer_name' => 'Siti Rahmawati', 'customer_avatar' => 'https://i.pravatar.cc/100?img=1', 'rating' => 5, 'content' => 'Napoleon-nya enak banget! Renyah dan creamy. Jadi oleh-oleh favorit kalau ke Pekanbaru. Kemasannya juga cantik, cocok untuk gift.', 'is_featured' => 1, 'is_active' => 1],
    ['customer_name' => 'Ahmad Fauzi', 'customer_avatar' => 'https://i.pravatar.cc/100?img=3', 'rating' => 5, 'content' => 'Pancake duriannya luar biasa! Daging duriannya tebal dan fresh. Pengiriman cepat dan packaging aman. Recommended banget!', 'is_featured' => 1, 'is_active' => 1],
    ['customer_name' => 'Dewi Sartika', 'customer_avatar' => 'https://i.pravatar.cc/100?img=5', 'rating' => 5, 'content' => 'Pelayanan ramah, pengiriman cepat, dan produk berkualitas. Jadi langganan tetap untuk oleh-oleh keluarga di Jakarta. Pasti balik lagi!', 'is_featured' => 1, 'is_active' => 1],
    ['customer_name' => 'Budi Hartono', 'customer_avatar' => 'https://i.pravatar.cc/100?img=7', 'rating' => 4, 'content' => 'Cake premiumnya enak, cocok untuk acara keluarga. Kemasannya juga cantik dan elegan. Anak-anak saya suka banget!', 'is_featured' => 1, 'is_active' => 1],
    ['customer_name' => 'Rina Amelia', 'customer_avatar' => 'https://i.pravatar.cc/100?img=9', 'rating' => 5, 'content' => 'Suka banget sama Mochi-nya! Lembut dan isiannya banyak. Anak-anak juga suka. Pengiriman cepat dan aman.', 'is_featured' => 1, 'is_active' => 1],
    ['customer_name' => 'Rudi Hermawan', 'customer_avatar' => 'https://i.pravatar.cc/100?img=11', 'rating' => 5, 'content' => 'Langganan tiap lebaran. Napoleonnya jadi hidangan wajib keluarga. Mantap! Kualitasnya konsisten selalu bagus.', 'is_featured' => 1, 'is_active' => 1],
    ['customer_name' => 'Fitriani Siregar', 'customer_avatar' => 'https://i.pravatar.cc/100?img=13', 'rating' => 4, 'content' => 'Brownies fudgy-nya enak banget! Teksturnya moist dan rich. Cocok buat teman minum kopi sore.', 'is_featured' => 0, 'is_active' => 1],
    ['customer_name' => 'Andi Pratama', 'customer_avatar' => 'https://i.pravatar.cc/100?img=15', 'rating' => 5, 'content' => 'Kemplang ikannya renyah dan gurih. Oleh-oleh khas Riau yang wajib dibeli kalau ke Pekanbaru.', 'is_featured' => 0, 'is_active' => 1],
];

$testStmt = $conn->prepare("INSERT INTO testimonials (customer_name, customer_avatar, rating, content, is_featured, is_active) VALUES (?, ?, ?, ?, ?, ?)");
foreach ($testimonials as $t) {
    $testStmt->bind_param('ssisii', $t['customer_name'], $t['customer_avatar'], $t['rating'], $t['content'], $t['is_featured'], $t['is_active']);
    $testStmt->execute();
}
echo count($testimonials) . " testimoni ditambahkan!\n\n";

// ============================================
// FINAL REPORT
// ============================================
echo "====================================\n";
echo "✅ SEEDER BERHASIL!\n";
echo "====================================\n";

// Show summary
$productCount = $conn->query("SELECT COUNT(*) as c FROM products")->fetch_assoc()['c'];
$imageCount = $conn->query("SELECT COUNT(*) as c FROM product_images")->fetch_assoc()['c'];
$reviewCount = $conn->query("SELECT COUNT(*) as c FROM product_reviews")->fetch_assoc()['c'];
$articleCount = $conn->query("SELECT COUNT(*) as c FROM articles")->fetch_assoc()['c'];
$testimonialCount = $conn->query("SELECT COUNT(*) as c FROM testimonials")->fetch_assoc()['c'];

echo "📊 Ringkasan:\n";
echo "   Produk        : $productCount\n";
echo "   Gambar        : $imageCount\n";
echo "   Review        : $reviewCount\n";
echo "   Artikel        : $articleCount\n";
echo "   Testimoni      : $testimonialCount\n\n";
echo "🌐 http://localhost/nad\n";
echo "🔐 http://localhost/nad/admin/products.php\n";

$stmt->close();
$imgStmt->close();
$reviewStmt->close();
$artStmt->close();
$testStmt->close();
// Note: do NOT close $conn here — getConnection() returns a shared static
// connection that is reused by the caller (e.g. database/init.php), and closing
// it here would break subsequent queries. PHP closes it automatically at exit.

echo $isCLI ? "\n" : "</pre>\n";
