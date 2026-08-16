<?php
// ============================================
// ADMIN: Update Products
// Update existing products and add new ones
// ============================================
require_once __DIR__ . '/../config/rbac.php';

// Auth check (RBAC)
requirePermission('products', 'edit');

echo "🔐 ADMIN PRODUCT UPDATER\n";
echo "========================\n\n";

$conn = getConnection();
if (!$conn) die("❌ Connection failed\n");

// 1. EDIT Napoleon Classic - promo price, more stock
echo "📝 EDITING: Napoleon Classic\n";
echo "----------------------------\n";
$r = $conn->query("SELECT id, name, price, stock FROM products WHERE name LIKE '%Napoleon Classic%' AND is_active = 1 ORDER BY id LIMIT 1");
if ($row = $r->fetch_assoc()) {
    $id = $row['id'];
    echo "Found: {$row['name']} (ID: $id)\n";
    echo "  Before: Rp " . number_format($row['price'],0,',','.') . " | Stock: {$row['stock']}\n";
    
    $conn->query("UPDATE products SET 
        price = 75000, 
        stock = 150, 
        discount_price = 65000,
        description = 'Napoleon Classic dengan puff pastry renyah dan vla vanilla lembut. EDISI PROMO! Harga lebih terjangkau dengan kualitas yang sama! Cocok untuk oleh-oleh dalam jumlah banyak.',
        is_featured = 1, 
        is_best_seller = 1 
        WHERE id = $id");
    
    echo "  ✅ Price: Rp 85.000 → Rp 75.000 (with disc Rp 65.000)\n";
    echo "  ✅ Stock: 50 → 150\n";
    echo "  ✅ Featured & Best Seller: YES\n";
} else {
    echo "⚠️  Napoleon Classic not found!\n";
}

echo "\n";

// 2. ADD NEW Napoleon Tiramisu
echo "🆕 ADDING: Napoleon Tiramisu\n";
echo "---------------------------\n";
$check = $conn->query("SELECT id FROM products WHERE slug = 'napoleon-tiramisu'");
if ($check && $check->num_rows == 0) {
    $sql = "INSERT INTO products (category_id, name, slug, description, composition, weight, expiration, storage_instructions, price, discount_price, stock, rating, total_sold, is_featured, is_best_seller, is_active, meta_title, meta_description) VALUES 
    (1, 'Napoleon Tiramisu', 'napoleon-tiramisu', 
    'Perpaduan unik Napoleon klasik dengan rasa Tiramisu Italia! Lapisan puff pastry renyah dengan vla kopi mascarpone yang creamy dan taburan coklat bubuk. Crossover kuliner Italia-Riau yang memanjakan lidah!',
    'Tepung terigu premium, mentega, telur, mascarpone, kopi espresso, coklat bubuk, susu segar, gula, garam',
    '250 gram', '7 hari (suhu kulkas 2-8C)', 
    'Simpan dalam kulkas pada suhu 2-8C. Keluarkan 10 menit sebelum disajikan.',
    98000, 85000, 30, 4.8, 0, 1, 1, 1, 
    'Napoleon Tiramisu - Nadhira Napoleon', 
    'Napoleon dengan rasa Tiramisu Italia yang unik dan lezat. Perpaduan puff pastry renyah dengan vla kopi mascarpone.')";
    
    if ($conn->query($sql)) {
        $newId = $conn->insert_id;
        echo "  ✅ Created! ID: $newId | Rp 98.000 (disc: Rp 85.000) | Stock: 30\n";
        
        // Add product images
        $conn->query("INSERT INTO product_images (product_id, image, is_primary, sort_order) VALUES 
            ($newId, 'https://images.unsplash.com/photo-1551024601-bec78aea704b?w=600&q=80', 1, 0),
            ($newId, 'https://images.unsplash.com/photo-1509365465985-25d11c17e812?w=600&q=80', 0, 1),
            ($newId, 'https://images.unsplash.com/photo-1556679343-c7306c1976bc?w=600&q=80', 0, 2)");
        echo "  ✅ 3 images added\n";
    } else {
        echo "  ❌ Failed: " . $conn->error . "\n";
    }
} else {
    echo "⚠️  Napoleon Tiramisu already exists\n";
}

echo "\n";

// 3. UPDATE category description for Napoleon
echo "📂 UPDATING: Category description\n";
echo "--------------------------------\n";
$conn->query("UPDATE product_categories SET description = 'Kue Napoleon premium renyah berlapis dengan berbagai varian rasa. Tersedia dalam kemasan elegan, cocok untuk oleh-oleh khas Pekanbaru.' WHERE id = 1");
echo "  ✅ Napoleon category description updated\n";

echo "\n";

// 4. VERIFICATION
echo "📊 VERIFICATION\n";
echo "==============\n";
$active = $conn->query("SELECT COUNT(*) as c FROM products WHERE is_active = 1")->fetch_assoc()['c'];
echo "Active products: $active\n";

$featured = $conn->query("SELECT COUNT(*) as c FROM products WHERE is_featured = 1")->fetch_assoc()['c'];
echo "Featured products: $featured\n";

$bestSellers = $conn->query("SELECT COUNT(*) as c FROM products WHERE is_best_seller = 1")->fetch_assoc()['c'];
echo "Best sellers: $bestSellers\n";

echo "\n--- Latest 5 Products ---\n";
$latest = $conn->query("SELECT id, name, price, stock FROM products WHERE is_active = 1 ORDER BY id DESC LIMIT 5");
while ($row = $latest->fetch_assoc()) {
    echo "  ID {$row['id']}: {$row['name']} | Rp " . number_format($row['price'],0,',','.') . " | Stock: {$row['stock']}\n";
}

echo "\n✅ ALL CHANGES COMPLETE!\n";
$conn->close();
