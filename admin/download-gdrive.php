<?php
// ============================================
// DOWNLOAD REMAINING GOOGLE DRIVE IMAGES
// Jalankan script ini untuk melanjutkan download
// ============================================
require_once __DIR__ . '/../config/rbac.php';

requirePermission('products', 'import');

$output = '';

if (isset($_GET['run'])) {
    $output .= "🚀 Memulai download gambar dari Google Drive...\n\n";
    
    // Check if Python and gdown are available
    $pythonCheck = shell_exec('python --version 2>&1');
    if (strpos($pythonCheck, 'Python') === false) {
        $output .= "❌ Python tidak ditemukan!\n";
    } else {
        $output .= "✅ $pythonCheck\n";
        
        // Run the Python download script (already handles copying to uploads/)
        $pythonScript = __DIR__ . '/../download_gdrive.py';
        $cmd = 'python "' . $pythonScript . '" 2>&1';
        $output .= "Menjalankan: python download_gdrive.py\n\n";
        $result = shell_exec($cmd);
        $output .= $result;
        
        // Count files using PHP glob (cross-platform)
        $imageFiles = glob(__DIR__ . '/../uploads/products/gdrive_images/*.{jpg,JPG,jpeg,JPEG,png,PNG}', GLOB_BRACE);
        $count = count($imageFiles);
        $output .= "\n📊 Total gambar di folder: $count file\n";
    }
}

// Count current images
$imageCount = count(glob(__DIR__ . '/../uploads/products/gdrive_images/*.{jpg,JPG,jpeg,JPEG,png,PNG}', GLOB_BRACE));
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Download Google Drive Images</title>
    <style>
        body { font-family: 'Courier New', monospace; background: #1a1a2e; color: #e0e0e0; padding: 20px; max-width: 800px; margin: 0 auto; }
        h1 { color: #FFE400; }
        .info { background: rgba(255,255,255,0.05); border-radius: 8px; padding: 16px; margin: 16px 0; }
        .btn { display: inline-block; padding: 12px 32px; background: linear-gradient(135deg, #FFE400, #D4A030); color: #1a1a2e; text-decoration: none; border-radius: 8px; font-weight: 700; margin: 16px 0; border: none; cursor: pointer; }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(212,160,48,0.4); }
        pre { background: rgba(0,0,0,0.3); padding: 16px; border-radius: 8px; overflow-x: auto; white-space: pre-wrap; font-size: 13px; line-height: 1.5; }
        .warning { background: rgba(255,193,7,0.1); border-left: 4px solid #FFC107; padding: 12px; border-radius: 4px; margin: 12px 0; }
        .warning h3 { margin: 0 0 8px; color: #FFC107; }
    </style>
</head>
<body>
    <h1>📥 Download Gambar Google Drive</h1>
    <div class="info">
        <strong>Status:</strong> <?= $imageCount ?> gambar sudah terdownload<br>
        <strong>Target:</strong> 200+ gambar dari 16 kategori
    </div>
    <div class="warning">
        <h3>⚠️ Perhatian</h3>
        <p>Google Drive memiliki batas akses (rate limit). Jika download gagal, 
        tunggu beberapa saat lalu coba lagi. Script akan melanjutkan dari file 
        yang belum terdownload.</p>
    </div>
    
    <div style="margin: 20px 0;">
        <a href="?run=1" class="btn" onclick="this.textContent='⏳ Mendownload...'">🚀 Jalankan Download</a>
        <a href="import-gdrive-images.php" class="btn" style="background:#555;color:white;margin-left:8px;">📷 Kelola Gambar</a>
    </div>
    
    <?php if ($output): ?>
        <h2 style="color:#FFE400;">Output:</h2>
        <pre><?= htmlspecialchars($output) ?></pre>
    <?php endif; ?>
</body>
</html>
