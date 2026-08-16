<?php
// ============================================
// AJAX HANDLER - CART
// Nadhira Napoleon Pekanbaru
// ============================================
require_once '../config/database.php';

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';

switch ($action) {
    case 'add_to_cart':
        $productId = (int)($_POST['product_id'] ?? 0);
        $quantity = max(1, (int)($_POST['quantity'] ?? 1));
        
        if ($productId <= 0) {
            jsonResponse(['success' => false, 'message' => 'Produk tidak valid']);
        }

        $conn = getConnection();
        if (!$conn) {
            jsonResponse(['success' => false, 'message' => 'Koneksi database gagal']);
        }

        // Paket membership hanya bisa dibeli oleh member yang login (aktivasi butuh akun)
        if (isMembershipProduct($productId) && !isLoggedIn()) {
            jsonResponse(['success' => false, 'message' => 'Silakan login terlebih dahulu untuk berlangganan membership']);
        }

        addProductToCart($productId, $quantity);

        jsonResponse([
            'success' => true,
            'message' => 'Produk berhasil ditambahkan ke keranjang',
            'cart_count' => getCartCount()
        ]);
        break;

    case 'update_cart':
        $cartId = (int)($_POST['cart_id'] ?? 0);
        $quantity = max(1, (int)($_POST['quantity'] ?? 1));
        
        $conn = getConnection();
        if (!$conn) {
            jsonResponse(['success' => false, 'message' => 'Koneksi database gagal']);
        }
        
        $conn->query("UPDATE carts SET quantity = $quantity WHERE id = $cartId");
        jsonResponse(['success' => true, 'cart_count' => getCartCount()]);
        break;

    case 'remove_from_cart':
        $cartId = (int)($_POST['cart_id'] ?? 0);
        
        $conn = getConnection();
        if (!$conn) {
            jsonResponse(['success' => false, 'message' => 'Koneksi database gagal']);
        }
        
        $conn->query("DELETE FROM carts WHERE id = $cartId");
        jsonResponse(['success' => true, 'cart_count' => getCartCount()]);
        break;

    case 'clear_cart':
        $conn = getConnection();
        if (!$conn) {
            jsonResponse(['success' => false, 'message' => 'Koneksi database gagal']);
        }
        
        if (isLoggedIn()) {
            $userId = (int)$_SESSION['user_id'];
            $conn->query("DELETE FROM carts WHERE user_id = $userId");
        } else {
            $sessionId = $conn->real_escape_string(session_id());
            $conn->query("DELETE FROM carts WHERE session_id = '$sessionId'");
        }
        
        jsonResponse(['success' => true, 'cart_count' => 0]);
        break;

    default:
        jsonResponse(['success' => false, 'message' => 'Aksi tidak valid'], 400);
}
