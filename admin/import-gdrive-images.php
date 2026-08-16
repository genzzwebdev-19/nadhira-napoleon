<?php
// ============================================
// GOOGLE DRIVE IMAGE IMPORTER
// Mengelola gambar dari Google Drive untuk produk
// Website Nadhira Napoleon Pekanbaru
// ============================================
require_once __DIR__ . '/../config/rbac.php';
require_once __DIR__ . '/../config/cloudinary.php'; // penyimpanan foto Cloudinary

// Check if user is admin (RBAC)
requirePermission('products', 'import');

// Get database connection
$conn = getConnection();

$action = $_GET['action'] ?? 'list';

// Handle update database action
if ($action === 'update_db' && isset($_POST['product_id']) && isset($_POST['image_path'])) {
    $productId = (int)$_POST['product_id'];
    $imagePath = trim($_POST['image_path']);
    $isPrimary = isset($_POST['is_primary']) ? 1 : 0;
    
    // Get existing primary image
    $result = $conn->query("SELECT id FROM product_images WHERE product_id = $productId AND is_primary = 1 LIMIT 1");
    $existingPrimary = $result->fetch_assoc();
    
    // If setting as primary, remove existing primary first
    if ($isPrimary && $existingPrimary) {
        $conn->query("UPDATE product_images SET is_primary = 0 WHERE product_id = $productId");
    }
    
    // Build full URL
    $fullUrl = SITE_URL . '/uploads/products/gdrive_images/' . basename($imagePath);

    // Jika Cloudinary aktif, upload file lokal ke Cloudinary lalu simpan URL Cloudinary
    if (cloudinaryEnabled()) {
        $localFile = __DIR__ . '/../uploads/products/gdrive_images/' . basename($imagePath);
        if (file_exists($localFile)) {
            $up = cloudinaryUploadFile($localFile, 'nadhira/products', 'gdrive_' . $productId . '_' . time());
            if ($up['success']) {
                $fullUrl = $up['url'];
            } else {
                $msg = "❌ Gagal upload ke Cloudinary: " . $up['message'];
            }
        }
    }
    
    // Insert new image record
    $stmt = $conn->prepare("INSERT INTO product_images (product_id, image, is_primary, sort_order) VALUES (?, ?, ?, ?)");
    $sortOrder = $isPrimary ? 0 : 1;
    $stmt->bind_param('isii', $productId, $fullUrl, $isPrimary, $sortOrder);
    
    if ($stmt->execute()) {
        $msg = "✅ Gambar berhasil ditambahkan ke produk!";
    } else {
        $msg = "❌ Gagal: " . $conn->error;
    }
}

// Handle delete all Unsplash images
if ($action === 'remove_unsplash') {
    $conn->query("DELETE FROM product_images WHERE image LIKE '%unsplash%'");
    $msg = "✅ Semua gambar Unsplash telah dihapus!";
}

// Get all products
$products = [];
$result = $conn->query("
    SELECT p.*, pc.name as category_name 
    FROM products p 
    LEFT JOIN product_categories pc ON p.category_id = pc.id 
    ORDER BY p.id
");
while ($row = $result->fetch_assoc()) {
    $products[] = $row;
}

// Get current product images
$productImages = [];
$result = $conn->query("SELECT * FROM product_images ORDER BY product_id, sort_order");
while ($row = $result->fetch_assoc()) {
    $productImages[$row['product_id']][] = $row;
}

// Get local images from gdrive_images folder
$localImages = glob(__DIR__ . '/../uploads/products/gdrive_images/*.{jpg,JPG,jpeg,JPEG,png,PNG}', GLOB_BRACE);
sort($localImages);

// Get image count per folder category mapping
$gdriveCategories = [
    'Aneka Bolu Gulung' => ['category' => 'Cake', 'desc' => 'Aneka Bolu Gulung (Swiss Rolls)'],
    'Aneka Bolu Jadul' => ['category' => 'Cake', 'desc' => 'Aneka Bolu Jadul (Classic Cakes)'],
    'Aneka Bolu Murah' => ['category' => 'Cake', 'desc' => 'Aneka Bolu Murah (Affordable Cakes)'],
    'Aneka Brownies' => ['category' => 'Brownies', 'desc' => 'Aneka Brownies'],
    'Asinan' => ['category' => 'Snack Premium', 'desc' => 'Asinan'],
    'Bolen Pisang' => ['category' => 'Snack Premium', 'desc' => 'Bolen Pisang'],
    'Cake Banana Strobery' => ['category' => 'Cake', 'desc' => 'Cake Banana Strawberry'],
    'Kemojo Mix dan Bingka' => ['category' => 'Oleh-Oleh Khas Riau', 'desc' => 'Kemojo Mix & Bingka'],
    'Ketan Talam' => ['category' => 'Oleh-Oleh Khas Riau', 'desc' => 'Ketan Talam'],
    'Lapis Ubi' => ['category' => 'Cake', 'desc' => 'Lapis Ubi'],
    'Millecrepes' => ['category' => 'Cake', 'desc' => 'Mille Crepes'],
    'Misu-misu' => ['category' => 'Snack Premium', 'desc' => 'Misu-misu (Traditional snack)'],
    'Paha Ayam' => ['category' => 'Snack Premium', 'desc' => 'Paha Ayam'],
    'Salad' => ['category' => 'Snack Premium', 'desc' => 'Salad'],
    'Snack Box' => ['category' => 'Snack Premium', 'desc' => 'Snack Box'],
    'Strudel' => ['category' => 'Cake', 'desc' => 'Strudel'],
];

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Gambar Google Drive - Nadhira Napoleon</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Playfair+Display:wght@400;600;700&family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --gold: #D4A030;
            --gold-light: #FFE400;
            --brown: #B8940F;
            --cream: #F7F7F3;
            --warm-white: #F5F5F0;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #f0f0ea;
            color: #333;
            padding: 20px;
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        h1 {
            font-family: 'Playfair Display', serif;
            color: var(--brown);
            margin-bottom: 8px;
        }
        .subtitle {
            color: #888;
            margin-bottom: 30px;
        }
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: var(--gold);
        }
        .stat-label {
            font-size: 13px;
            color: #888;
            margin-top: 4px;
        }
        .msg {
            padding: 16px;
            background: #d4edda;
            border: 1px solid #c3e6cb;
            border-radius: 8px;
            color: #155724;
            margin-bottom: 20px;
        }
        .gdrive-structure {
            background: white;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        .gdrive-structure h2 {
            font-family: 'Playfair Display', serif;
            color: var(--brown);
            margin-bottom: 16px;
        }
        .gdrive-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 12px;
        }
        .gdrive-folder {
            background: #f8f8f4;
            border-radius: 10px;
            padding: 16px;
            border-left: 4px solid var(--gold);
        }
        .gdrive-folder h3 {
            font-size: 15px;
            font-weight: 600;
            margin-bottom: 4px;
        }
        .gdrive-folder p {
            font-size: 13px;
            color: #888;
        }
        .gdrive-folder .status {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-top: 8px;
        }
        .status-downloaded {
            background: #d4edda;
            color: #155724;
        }
        .status-pending {
            background: #fff3cd;
            color: #856404;
        }
        table {
            width: 100%;
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            border-collapse: collapse;
        }
        th {
            background: var(--brown);
            color: white;
            padding: 14px 16px;
            text-align: left;
            font-weight: 600;
            font-size: 14px;
        }
        td {
            padding: 12px 16px;
            border-bottom: 1px solid #eee;
            font-size: 14px;
            vertical-align: middle;
        }
        tr:hover { background: #fafaf5; }
        .product-img {
            width: 60px;
            height: 60px;
            border-radius: 8px;
            object-fit: cover;
        }
        .badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-primary { background: var(--gold); color: white; }
        .badge-unsplash { background: #e3f2fd; color: #1565c0; }
        .badge-local { background: #e8f5e9; color: #2e7d32; }
        select, input[type="checkbox"] {
            padding: 6px 10px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 13px;
        }
        .btn {
            display: inline-block;
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
        }
        .btn-gold {
            background: linear-gradient(135deg, #FFE400, #D4A030);
            color: white;
        }
        .btn-gold:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(212,160,48,0.3); }
        .btn-danger {
            background: #ef4444;
            color: white;
        }
        .btn-danger:hover { background: #dc2626; }
        .btn-outline {
            background: transparent;
            border: 2px solid var(--gold);
            color: var(--brown);
        }
        .btn-outline:hover { background: var(--gold); color: white; }
        .btn-sm { padding: 6px 14px; font-size: 13px; }
        .actions { margin: 20px 0; display: flex; gap: 12px; flex-wrap: wrap; }
        form-inline { display: inline; }
        .image-preview {
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 300px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.15);
            padding: 16px;
            display: none;
            z-index: 1000;
        }
        .image-preview img {
            width: 100%;
            height: 200px;
            object-fit: cover;
            border-radius: 8px;
        }
        .image-preview .close-preview {
            position: absolute;
            top: 8px;
            right: 8px;
            background: rgba(0,0,0,0.5);
            color: white;
            border: none;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            cursor: pointer;
        }
        .product-card-images {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
        }
        .product-card-images img {
            width: 40px;
            height: 40px;
            border-radius: 4px;
            object-fit: cover;
            cursor: pointer;
        }
        .assign-form {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📷 Import Gambar dari Google Drive</h1>
        <p class="subtitle">Kelola gambar produk dari Google Drive Nadhira Napoleon</p>

        <?php if (isset($msg)): ?>
            <div class="msg"><?= htmlspecialchars($msg) ?></div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="stats">
            <div class="stat-card">
                <div class="stat-value">36</div>
                <div class="stat-label">Gambar sudah terdownload ✅</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= count($products) ?></div>
                <div class="stat-label">Total Produk di Database</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= count($localImages) ?></div>
                <div class="stat-label">File Gambar Lokal</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">16</div>
                <div class="stat-label">Kategori Folder Google Drive</div>
            </div>
        </div>

        <!-- Actions -->
        <div class="actions">
            <a href="?action=remove_unsplash" class="btn btn-danger btn-sm" onclick="return confirm('Hapus SEMUA gambar Unsplash?')">
                🗑️ Hapus Gambar Unsplash
            </a>
            <a href="import-gdrive-images.php" class="btn btn-outline btn-sm">
                🔄 Refresh
            </a>
        </div>

        <!-- Google Drive Structure -->
        <div class="gdrive-structure">
            <h2>📂 Struktur Folder Google Drive</h2>
            <p style="color:#888;margin-bottom:16px;">16 kategori folder dengan total 200+ foto produk</p>
            <div class="gdrive-grid">
                <?php foreach ($gdriveCategories as $folder => $info): ?>
                    <div class="gdrive-folder">
                        <h3>📁 <?= $folder ?></h3>
                        <p><?= $info['desc'] ?></p>
                        <p style="font-size:12px;color:#aaa;">→ Kategori Website: <strong><?= $info['category'] ?></strong></p>
                        <span class="status <?= ($folder === 'Aneka Bolu Gulung') ? 'status-downloaded' : 'status-pending' ?>">
                            <?= ($folder === 'Aneka Bolu Gulung') ? '✅ 36 gambar terdownload' : '⏳ Menunggu download' ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Products Table -->
        <h2 style="font-family:'Playfair Display',serif;color:var(--brown);margin:30px 0 16px;">
            🏷️ Mapping Gambar ke Produk
        </h2>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Nama Produk</th>
                    <th>Kategori</th>
                    <th>Gambar Saat Ini</th>
                    <th>Gambar Local Tersedia</th>
                    <th>Assign Gambar</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($products as $p): 
                    $currentImages = $productImages[$p['id']] ?? [];
                    $hasLocalImage = false;
                    foreach ($currentImages as $img) {
                        if (strpos($img['image'], 'uploads/') !== false) {
                            $hasLocalImage = true;
                            break;
                        }
                    }
                ?>
                <tr>
                    <td><?= $p['id'] ?></td>
                    <td><strong><?= htmlspecialchars($p['name']) ?></strong></td>
                    <td><?= htmlspecialchars($p['category_name']) ?></td>
                    <td>
                        <div class="product-card-images">
                            <?php foreach ($currentImages as $img): 
                                $isLocal = strpos($img['image'], 'uploads/') !== false;
                            ?>
                                <div style="position:relative;">
                                    <img src="<?= htmlspecialchars($img['image']) ?>" 
                                         onerror="this.src='https://via.placeholder.com/40?text=No+Img'"
                                         title="<?= htmlspecialchars($img['image']) ?>"
                                         onclick="previewImage('<?= htmlspecialchars($img['image']) ?>')">
                                    <?php if ($img['is_primary']): ?>
                                        <span style="position:absolute;top:-4px;right:-4px;font-size:10px;">⭐</span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                            <?php if (empty($currentImages)): ?>
                                <span style="color:#999;font-size:12px;">Tidak ada gambar</span>
                            <?php endif; ?>
                        </div>
                        <?php if ($hasLocalImage): ?>
                            <span class="badge badge-local" style="margin-top:4px;">✅ Lokal</span>
                        <?php else: ?>
                            <span class="badge badge-unsplash" style="margin-top:4px;">🌐 Unsplash</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <form method="POST" action="?action=update_db" class="assign-form">
                            <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                            <select name="image_path" style="max-width:180px;">
                                <option value="">-- Pilih gambar --</option>
                                <?php foreach ($localImages as $img): 
                                    $basename = basename($img);
                                ?>
                                    <option value="<?= htmlspecialchars($basename) ?>">
                                        <?= htmlspecialchars($basename) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <label style="font-size:12px;">
                                <input type="checkbox" name="is_primary" value="1"> Utama
                            </label>
                            <button type="submit" class="btn btn-gold btn-sm">Assign</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Image Preview Modal -->
        <div class="image-preview" id="previewModal">
            <button class="close-preview" onclick="document.getElementById('previewModal').style.display='none'">✕</button>
            <img id="previewImage" src="" alt="Preview">
        </div>
    </div>

    <script>
        function previewImage(src) {
            const modal = document.getElementById('previewModal');
            const img = document.getElementById('previewImage');
            img.src = src;
            modal.style.display = 'block';
        }
        // Click outside to close
        document.addEventListener('click', function(e) {
            const modal = document.getElementById('previewModal');
            if (e.target.closest('.image-preview') === null && 
                !e.target.closest('.product-card-images img')) {
                modal.style.display = 'none';
            }
        });
    </script>
</body>
</html>
