-- ============================================
-- DATABASE: nadhira_napoleon
-- Database untuk Website Nadhira Napoleon Pekanbaru
-- ============================================

CREATE DATABASE IF NOT EXISTS nadhira_napoleon;
USE nadhira_napoleon;

-- ============================================
-- TABEL: users (untuk membership & admin)
-- ============================================
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(255) NOT NULL,
    phone VARCHAR(20),
    avatar VARCHAR(255) DEFAULT 'default-avatar.png',
    role ENUM('admin', 'customer') DEFAULT 'customer',
    membership ENUM('silver', 'gold', 'platinum', 'diamond') DEFAULT 'silver',
    points INT DEFAULT 0,
    total_spent DECIMAL(15,2) DEFAULT 0.00,
    email_verified_at TIMESTAMP NULL,
    is_active BOOLEAN DEFAULT TRUE,
    is_locked BOOLEAN DEFAULT FALSE,
    failed_attempts INT DEFAULT 0,
    locked_until DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    INDEX idx_email (email),
    INDEX idx_membership (membership)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABEL: product_categories
-- ============================================
CREATE TABLE IF NOT EXISTS product_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL UNIQUE,
    description TEXT,
    icon VARCHAR(255),
    image VARCHAR(255),
    sort_order INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_slug (slug),
    INDEX idx_sort (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABEL: products
-- ============================================
CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL UNIQUE,
    description TEXT,
    composition TEXT,
    weight VARCHAR(100),
    expiration VARCHAR(100),
    storage_instructions TEXT,
    price DECIMAL(15,2) NOT NULL,
    discount_price DECIMAL(15,2) NULL,
    stock INT DEFAULT 0,
    rating DECIMAL(2,1) DEFAULT 0.0,
    total_sold INT DEFAULT 0,
    is_featured BOOLEAN DEFAULT FALSE,
    is_best_seller BOOLEAN DEFAULT FALSE,
    is_active BOOLEAN DEFAULT TRUE,
    meta_title VARCHAR(255),
    meta_description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES product_categories(id) ON DELETE CASCADE,
    INDEX idx_slug (slug),
    INDEX idx_category (category_id),
    INDEX idx_featured (is_featured),
    INDEX idx_bestseller (is_best_seller),
    INDEX idx_price (price),
    FULLTEXT idx_search (name, description)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABEL: product_images
-- ============================================
CREATE TABLE IF NOT EXISTS product_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    image VARCHAR(255) NOT NULL,
    is_primary BOOLEAN DEFAULT FALSE,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    INDEX idx_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABEL: product_videos
-- ============================================
CREATE TABLE IF NOT EXISTS product_videos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    video_url VARCHAR(500) NOT NULL,
    title VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    INDEX idx_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABEL: product_reviews
-- ============================================
CREATE TABLE IF NOT EXISTS product_reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    user_id INT,
    reviewer_name VARCHAR(255) NOT NULL,
    rating TINYINT NOT NULL CHECK (rating >= 1 AND rating <= 5),
    review TEXT,
    is_verified BOOLEAN DEFAULT FALSE,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_product (product_id),
    INDEX idx_rating (rating)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABEL: wishlist
-- ============================================
CREATE TABLE IF NOT EXISTS wishlists (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    product_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    UNIQUE KEY unique_wishlist (user_id, product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABEL: cart
-- ============================================
CREATE TABLE IF NOT EXISTS carts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    session_id VARCHAR(255),
    product_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_session (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABEL: orders
-- ============================================
CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(50) NOT NULL UNIQUE,
    user_id INT,
    customer_name VARCHAR(255) NOT NULL,
    customer_email VARCHAR(255) NOT NULL,
    customer_phone VARCHAR(20) NOT NULL,
    shipping_address TEXT NOT NULL,
    shipping_city VARCHAR(100),
    shipping_province VARCHAR(100),
    shipping_postal_code VARCHAR(10),
    subtotal DECIMAL(15,2) NOT NULL,
    shipping_cost DECIMAL(15,2) DEFAULT 0.00,
    discount DECIMAL(15,2) DEFAULT 0.00,
    promo_code VARCHAR(50) NULL,
    total DECIMAL(15,2) NOT NULL,
    payment_method ENUM('transfer_bank', 'cod', 'e_wallet', 'midtrans') DEFAULT 'midtrans',
    payment_status ENUM('pending', 'paid', 'failed', 'refunded') DEFAULT 'pending',
    sold_counted TINYINT(1) NOT NULL DEFAULT 0,
    points_awarded TINYINT(1) NOT NULL DEFAULT 0,
    stock_deducted TINYINT(1) NOT NULL DEFAULT 0,
    midtrans_transaction_id VARCHAR(100) NULL,
    midtrans_payment_type VARCHAR(50) NULL,
    midtrans_va_number VARCHAR(64) NULL,
    midtrans_bank VARCHAR(50) NULL,
    paid_at DATETIME NULL,
    order_status ENUM('pending', 'processing', 'shipped', 'delivered', 'cancelled') DEFAULT 'pending',
    notes TEXT,
    tracking_number VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_order_number (order_number),
    INDEX idx_user (user_id),
    INDEX idx_status (order_status),
    INDEX idx_payment (payment_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABEL: order_items
-- ============================================
CREATE TABLE IF NOT EXISTS order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    product_name VARCHAR(255) NOT NULL,
    product_image VARCHAR(255),
    price DECIMAL(15,2) NOT NULL,
    quantity INT NOT NULL,
    subtotal DECIMAL(15,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    INDEX idx_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABEL: branches
-- ============================================
CREATE TABLE IF NOT EXISTS branches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    address TEXT NOT NULL,
    phone VARCHAR(20),
    whatsapp VARCHAR(20),
    email VARCHAR(255),
    latitude DECIMAL(10,8),
    longitude DECIMAL(11,8),
    maps_url VARCHAR(500),
    open_hours TEXT,
    image VARCHAR(255),
    is_active BOOLEAN DEFAULT TRUE,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABEL: branch_products
-- ============================================
CREATE TABLE IF NOT EXISTS branch_products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL,
    product_id INT NOT NULL,
    is_available BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    UNIQUE KEY unique_branch_product (branch_id, product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABEL: promotions
-- ============================================
CREATE TABLE IF NOT EXISTS promotions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    code VARCHAR(50) NULL,
    slug VARCHAR(255) NOT NULL UNIQUE,
    description TEXT,
    discount_type ENUM('percentage', 'nominal') DEFAULT 'percentage',
    discount_value DECIMAL(15,2) NOT NULL,
    min_purchase DECIMAL(15,2) DEFAULT 0.00,
    max_uses INT NULL,
    used_count INT NOT NULL DEFAULT 0,
    start_date DATETIME NOT NULL,
    end_date DATETIME NOT NULL,
    image VARCHAR(255),
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_slug (slug),
    UNIQUE INDEX idx_code (code),
    INDEX idx_active (is_active),
    INDEX idx_date (start_date, end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABEL: articles
-- ============================================
CREATE TABLE IF NOT EXISTS articles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL UNIQUE,
    content TEXT,
    excerpt TEXT,
    image VARCHAR(255),
    author VARCHAR(255) DEFAULT 'Nadhira Napoleon',
    is_published BOOLEAN DEFAULT FALSE,
    published_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_slug (slug),
    INDEX idx_published (is_published, published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABEL: testimonials
-- ============================================
CREATE TABLE IF NOT EXISTS testimonials (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_name VARCHAR(255) NOT NULL,
    customer_avatar VARCHAR(255),
    rating TINYINT NOT NULL CHECK (rating >= 1 AND rating <= 5),
    content TEXT NOT NULL,
    is_featured BOOLEAN DEFAULT FALSE,
    is_active BOOLEAN DEFAULT TRUE,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_featured (is_featured),
    INDEX idx_sort (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABEL: faq
-- ============================================
CREATE TABLE IF NOT EXISTS faq (
    id INT AUTO_INCREMENT PRIMARY KEY,
    question TEXT NOT NULL,
    answer TEXT NOT NULL,
    category VARCHAR(100) DEFAULT 'general',
    sort_order INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_category (category),
    INDEX idx_sort (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABEL: contacts
-- ============================================
CREATE TABLE IF NOT EXISTS contacts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    phone VARCHAR(20),
    subject VARCHAR(255),
    message TEXT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_read (is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABEL: newsletter_subscribers
-- ============================================
CREATE TABLE IF NOT EXISTS newsletter_subscribers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    is_active BOOLEAN DEFAULT TRUE,
    subscribed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABEL: hero_slides
-- Slider foto background halaman utama
-- ============================================
CREATE TABLE IF NOT EXISTS hero_slides (
    id INT AUTO_INCREMENT PRIMARY KEY,
    image VARCHAR(500) NOT NULL,
    image_mobile VARCHAR(500),
    label VARCHAR(255),
    sort_order INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_active (is_active),
    INDEX idx_sort (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABEL: settings
-- ============================================
CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(255) NOT NULL UNIQUE,
    setting_value TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABEL: membership_benefits
-- ============================================
CREATE TABLE IF NOT EXISTS membership_benefits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    membership_level ENUM('silver', 'gold', 'platinum', 'diamond') NOT NULL,
    benefit_name VARCHAR(255) NOT NULL,
    benefit_description TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_level (membership_level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABEL: payment_confirmations
-- Menyimpan bukti konfirmasi pembayaran dari customer
-- ============================================
CREATE TABLE IF NOT EXISTS payment_confirmations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    customer_name VARCHAR(255) NOT NULL,
    bank_name VARCHAR(100) NOT NULL,
    account_number VARCHAR(100) NOT NULL,
    account_name VARCHAR(255) NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    transfer_date DATE NOT NULL,
    proof_image VARCHAR(255),
    notes TEXT,
    status ENUM('pending', 'verified', 'rejected') DEFAULT 'pending',
    verified_by INT,
    verified_at TIMESTAMP NULL,
    rejection_reason TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_order (order_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABEL: video_gallery
-- Video gallery YouTube/Instagram untuk landing page
-- ============================================
CREATE TABLE IF NOT EXISTS video_gallery (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    video_url VARCHAR(500) NOT NULL,
    thumbnail VARCHAR(500),
    description TEXT,
    sort_order INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_active (is_active),
    INDEX idx_sort (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- SEEDER DATA: Settings
-- ============================================
INSERT INTO settings (setting_key, setting_value) VALUES
('site_name', 'Nadhira Napoleon'),
('site_tagline', 'Premium Oleh-Oleh Khas Riau'),
('site_description', 'Pusat oleh-oleh premium khas Riau. Menghadirkan Napoleon, pancake durian, cake, snack premium, dan berbagai oleh-oleh khas Pekanbaru.'),
('site_logo', 'logo.png'),
('site_favicon', 'favicon.ico'),
('navbar_logo_height', '90'),
('contact_phone', '0821-1234-5678'),
('contact_whatsapp', '6282112345678'),
('contact_email', 'info@nadhiranapoleon.com'),
('contact_address', 'Jl. Sudirman No. 123, Pekanbaru, Riau'),
('social_instagram', '@nadhiranapoleon'),
('social_facebook', 'nadhiranapoleon'),
('social_tiktok', '@nadhiranapoleon'),
('footer_tagline', 'Membawa Cita Rasa Khas Riau Dalam Setiap Gigitan'),
('operational_hours', 'Setiap Hari, 08.00 - 21.00 WIB'),
('about_us', 'Nadhira Napoleon adalah pusat oleh-oleh premium khas Riau yang menghadirkan berbagai produk berkualitas. Kami berkomitmen untuk memberikan pengalaman berbelanja terbaik dengan produk-produk pilihan yang menggugah selera.'),
('bank_name', 'Bank Mandiri'),
('bank_account', '123-00-4567890-1'),
('bank_holder', 'Nadhira Napoleon');

-- ============================================
-- SEEDER DATA: Product Categories
-- ============================================
INSERT INTO product_categories (name, slug, description, sort_order) VALUES
('Napoleon', 'napoleon', 'Kue Napoleon premium dengan berbagai varian rasa', 1),
('Pancake Durian', 'pancake-durian', 'Pancake durian lembut dengan durian asli', 2),
('Mochi', 'mochi', 'Mochi premium dengan berbagai isian', 3),
('Cake', 'cake', 'Kue premium untuk berbagai acara', 4),
('Brownies', 'brownies', 'Brownies fudgy dan moist premium', 5),
('Snack Premium', 'snack-premium', 'Aneka snack premium berkualitas', 6),
('Oleh-Oleh Khas Riau', 'oleh-oleh-khas-riau', 'Koleksi oleh-oleh khas Riau', 7),
('Frozen Food', 'frozen-food', 'Produk frozen food siap saji', 8),
('Paket Oleh-Oleh', 'paket-oleh-oleh', 'Paket oleh-oleh lengkap untuk keluarga', 9),
('Produk Musiman', 'produk-musiman', 'Produk edisi musiman spesial', 10);

-- ============================================
-- SEEDER DATA: Admin User
-- ============================================
INSERT INTO users (username, email, password, full_name, role) VALUES
('admin', 'admin@nadhiranapoleon.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Admin Nadhira Napoleon', 'admin');

-- Password: password

-- ============================================
-- SEEDER DATA: Branches
-- ============================================
INSERT INTO branches (name, address, phone, whatsapp, latitude, longitude, open_hours) VALUES
('Nadhira Napoleon - Sudirman', 'Jl. Jenderal Sudirman No. 123, Pekanbaru', '0821-1234-5678', '6282112345678', 0.5070677, 101.4477793, 'Setiap Hari, 08.00 - 21.00 WIB'),
('Nadhira Napoleon - Hang Tuah', 'Jl. Hang Tuah No. 45, Pekanbaru', '0821-5678-1234', '6282156781234', 0.5323701, 101.4492104, 'Setiap Hari, 09.00 - 21.00 WIB'),
('Nadhira Napoleon - Bandara SSK II', 'Bandara Sultan Syarif Kasim II, Pekanbaru', '0821-9012-3456', '6282190123456', 0.4661486, 101.4446901, 'Setiap Hari, 06.00 - 20.00 WIB');

-- ============================================
-- SEEDER DATA: Membership Benefits
-- ============================================
INSERT INTO membership_benefits (membership_level, benefit_name, benefit_description) VALUES
('silver', 'Voucher 5%', 'Mendapatkan voucher diskon 5% setiap bulan'),
('silver', 'Point Reward', 'Mendapatkan 1 point untuk setiap pembelian'),
('gold', 'Voucher 10%', 'Mendapatkan voucher diskon 10% setiap bulan'),
('gold', 'Point Reward 2x', 'Mendapatkan 2 point untuk setiap pembelian'),
('gold', 'Free Ongkir', 'Gratis ongkos kirim untuk area Pekanbaru'),
('platinum', 'Voucher 15%', 'Mendapatkan voucher diskon 15% setiap bulan'),
('platinum', 'Point Reward 3x', 'Mendapatkan 3 point untuk setiap pembelian'),
('platinum', 'Free Ongkir Nasional', 'Gratis ongkos kirim seluruh Indonesia'),
('platinum', 'Birthday Voucher', 'Voucher spesial ulang tahun'),
('diamond', 'Voucher 20%', 'Mendapatkan voucher diskon 20% setiap bulan'),
('diamond', 'Point Reward 5x', 'Mendapatkan 5 point untuk setiap pembelian'),
('diamond', 'Free Ongkir Nasional', 'Gratis ongkos kirim seluruh Indonesia'),
('diamond', 'Birthday Voucher', 'Voucher spesial ulang tahun'),
('diamond', 'Priority Service', 'Layanan prioritas dan early access promo'),
('diamond', 'Exclusive Gift', 'Hadiah eksklusif setiap bulan');

-- ============================================
-- SEEDER DATA: FAQ
-- ============================================
INSERT INTO faq (question, answer, category, sort_order) VALUES
('Apa saja produk yang tersedia di Nadhira Napoleon?', 'Kami menyediakan berbagai produk premium seperti Napoleon, Pancake Durian, Mochi, Cake, Brownies, Snack Premium, dan berbagai Oleh-Oleh Khas Riau lainnya.', 'produk', 1),
('Apakah Nadhira Napoleon melayani pengiriman ke luar kota?', 'Ya, kami melayani pengiriman ke seluruh Indonesia. Produk kami dikemas dengan khusus untuk menjaga kualitas selama pengiriman.', 'pengiriman', 2),
('Bagaimana cara menyimpan produk Napoleon?', 'Produk Napoleon sebaiknya disimpan di dalam kulkas pada suhu 2-8°C untuk menjaga kesegaran dan kualitas terbaik.', 'produk', 3),
('Apakah tersedia paket oleh-oleh?', 'Ya, kami menyediakan berbagai paket oleh-oleh dengan harga spesial yang bisa disesuaikan dengan kebutuhan Anda.', 'produk', 4),
('Berapa lama masa kadaluarsa produk?', 'Setiap produk memiliki masa kadaluarsa yang berbeda. Informasi lengkap dapat dilihat pada kemasan masing-masing produk.', 'produk', 5),
('Apakah ada program membership?', 'Ya, kami memiliki program membership dengan 4 level: Silver, Gold, Platinum, dan Diamond. Setiap level memiliki benefit yang berbeda.', 'membership', 6),
('Bagaimana cara menjadi member?', 'Anda dapat mendaftar menjadi member melalui website kami secara gratis. Semakin sering berbelanja, level membership Anda akan naik.', 'membership', 7),
('Apakah tersedia gift box/custom hampers?', 'Ya, kami melayani pemesanan gift box dan hampers untuk berbagai acara seperti pernikahan, ulang tahun, dan corporate gift.', 'layanan', 8),
('Dimana lokasi toko Nadhira Napoleon?', 'Kami memiliki 3 cabang di Pekanbaru: Jl. Sudirman, Jl. Hang Tuah, dan Bandara SSK II. Cek halaman Cabang Kami untuk informasi lengkap.', 'cabang', 9),
('Apakah bisa memesan secara online?', 'Tentu! Anda dapat memesan melalui website ini dan memilih metode pengiriman atau pickup di cabang terdekat.', 'layanan', 10);

-- ============================================
-- SEEDER DATA: Testimonials
-- ============================================
INSERT INTO testimonials (customer_name, rating, content, is_featured) VALUES
('Siti Rahmawati', 5, 'Napoleon-nya enak banget! Renyah dan creamy. Jadi oleh-oleh favorit kalau ke Pekanbaru.', TRUE),
('Ahmad Fauzi', 5, 'Pancake duriannya luar biasa! Daging duriannya tebal dan fresh. Recommended banget!', TRUE),
('Dewi Sartika', 5, 'Pelayanan ramah, pengiriman cepat, dan produk berkualitas. Pasti balik lagi!', TRUE),
('Budi Hartono', 4, 'Cake premiumnya enak, cocok untuk acara keluarga. Kemasannya juga cantik.', TRUE),
('Rina Amelia', 5, 'Suka banget sama Mochi-nya! Lembut dan isiannya banyak. Anak-anak juga suka.', FALSE),
('Rudi Hermawan', 5, 'Langganan tiap lebaran. Napolennya jadi hidangan wajib keluarga. Mantap!', FALSE);
