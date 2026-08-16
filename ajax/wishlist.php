<?php
// ============================================
// AJAX HANDLER - WISHLIST
// Nadhira Napoleon Pekanbaru
// ============================================
require_once '../config/database.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isLoggedIn()) {
    jsonResponse(['success' => false, 'redirect' => BASE_PATH . '/auth/login.php', 'message' => 'Silakan login terlebih dahulu']);
}

$action = $_POST['action'] ?? '';

switch ($action) {
    case 'toggle_wishlist':
        $productId = (int)($_POST['product_id'] ?? 0);
        
        if ($productId <= 0) {
            jsonResponse(['success' => false, 'message' => 'Produk tidak valid']);
        }

        $conn = getConnection();
        if (!$conn) {
            jsonResponse(['success' => false, 'message' => 'Koneksi database gagal']);
        }

        $userId = (int)$_SESSION['user_id'];
        
        // Check if already in wishlist
        $check = $conn->query("SELECT id FROM wishlists WHERE user_id = $userId AND product_id = $productId");
        
        if ($check && $check->num_rows > 0) {
            // Remove from wishlist
            $conn->query("DELETE FROM wishlists WHERE user_id = $userId AND product_id = $productId");
            jsonResponse(['success' => true, 'added' => false, 'message' => 'Produk dihapus dari wishlist']);
        } else {
            // Add to wishlist
            $conn->query("INSERT INTO wishlists (user_id, product_id) VALUES ($userId, $productId)");
            jsonResponse(['success' => true, 'added' => true, 'message' => 'Produk ditambahkan ke wishlist']);
        }
        break;

    case 'get_wishlist':
        $conn = getConnection();
        if (!$conn) {
            jsonResponse(['success' => false, 'message' => 'Koneksi database gagal']);
        }

        $userId = (int)$_SESSION['user_id'];
        $result = $conn->query("
            SELECT w.id, w.product_id, w.created_at
            FROM wishlists w
            WHERE w.user_id = $userId
            ORDER BY w.created_at DESC
        ");

        $wishlist = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $wishlist[] = $row;
            }
        }

        jsonResponse(['success' => true, 'data' => $wishlist]);
        break;

    default:
        jsonResponse(['success' => false, 'message' => 'Aksi tidak valid'], 400);
}
