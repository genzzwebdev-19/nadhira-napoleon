-- ============================================
-- MODULES SCHEMA - Nadhira Napoleon
-- Tabel tambahan untuk modul operasional:
-- Stock/Gudang, Marketing, Affiliate, Support
-- Dieksekusi oleh database/init.php dan manual
-- ============================================

-- ============================================
-- TABEL: stock_movements (Riwayat stock masuk/keluar/opname)
-- ============================================
CREATE TABLE IF NOT EXISTS stock_movements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    branch_id INT NULL,
    type ENUM('in','out','opname','adjust') NOT NULL DEFAULT 'adjust',
    quantity INT NOT NULL DEFAULT 0,
    stock_before INT DEFAULT 0,
    stock_after INT DEFAULT 0,
    notes TEXT,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_product (product_id),
    INDEX idx_branch (branch_id),
    INDEX idx_type (type),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABEL: marketing_campaigns (Kampanye pemasaran)
-- ============================================
CREATE TABLE IF NOT EXISTS marketing_campaigns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    channel VARCHAR(100) DEFAULT 'sosial_media',
    budget DECIMAL(15,2) DEFAULT 0.00,
    start_date DATETIME NULL,
    end_date DATETIME NULL,
    status ENUM('draft','active','ended','cancelled') DEFAULT 'draft',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_date (start_date, end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABEL: affiliates (Affiliate & Reseller)
-- ============================================
CREATE TABLE IF NOT EXISTS affiliates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255),
    phone VARCHAR(20),
    type ENUM('affiliate','reseller') DEFAULT 'affiliate',
    referral_code VARCHAR(50) NOT NULL UNIQUE,
    commission_rate DECIMAL(5,2) DEFAULT 10.00,
    balance DECIMAL(15,2) DEFAULT 0.00,
    status ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABEL: membership_plans (Paket langganan membership yang dijual)
-- ============================================
CREATE TABLE IF NOT EXISTS membership_plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NULL,
    level ENUM('gold','platinum','diamond') NOT NULL,
    period ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly',
    price DECIMAL(15,2) NOT NULL DEFAULT 0,
    duration_days INT NOT NULL DEFAULT 30,
    is_active BOOLEAN DEFAULT TRUE,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
    UNIQUE KEY unique_plan (level, period),
    INDEX idx_product (product_id),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABEL: membership_purchases (Riwayat langganan member)
-- ============================================
CREATE TABLE IF NOT EXISTS membership_purchases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    order_id INT NULL,
    level ENUM('gold','platinum','diamond') NOT NULL,
    period ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly',
    duration_days INT NOT NULL DEFAULT 30,
    price DECIMAL(15,2) NOT NULL DEFAULT 0,
    starts_at DATETIME NULL,
    expires_at DATETIME NULL,
    status ENUM('active','expired','cancelled') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
    INDEX idx_user (user_id),
    INDEX idx_order (order_id),
    INDEX idx_status (status),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- SEEDER: Paket membership default (harga bisa diubah dari admin)
-- ============================================
INSERT INTO membership_plans (level, period, price, duration_days, is_active, sort_order) VALUES
('gold', 'monthly', 99000, 30, 1, 1),
('gold', 'yearly', 990000, 365, 1, 2),
('platinum', 'monthly', 199000, 30, 1, 3),
('platinum', 'yearly', 1990000, 365, 1, 4),
('diamond', 'monthly', 399000, 30, 1, 5),
('diamond', 'yearly', 3990000, 365, 1, 6)
ON DUPLICATE KEY UPDATE id = id;

-- ============================================
-- TABEL: point_redeems (Penukaran poin member menjadi diskon)
-- ============================================
CREATE TABLE IF NOT EXISTS point_redeems (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    order_id INT NULL,
    points INT NOT NULL DEFAULT 0,
    amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    status ENUM('used','refunded') DEFAULT 'used',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
    INDEX idx_user (user_id),
    INDEX idx_order (order_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABEL: point_history (Riwayat perolehan & pemakaian poin per member)
-- type: earned=poin belanja, spent=tukar diskon, refunded=refund, reversed=ditarik saat order batal, adjusted=penyesuaian admin
-- ============================================
CREATE TABLE IF NOT EXISTS point_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    order_id INT NULL,
    points INT NOT NULL DEFAULT 0,
    type ENUM('earned','spent','refunded','reversed','adjusted') NOT NULL DEFAULT 'adjusted',
    description VARCHAR(255) DEFAULT '',
    balance_after INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
    INDEX idx_user (user_id),
    INDEX idx_order (order_id),
    INDEX idx_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABEL: support_tickets (Ticket Support CS)
-- ============================================
CREATE TABLE IF NOT EXISTS support_tickets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_name VARCHAR(255) NOT NULL,
    customer_email VARCHAR(255),
    customer_phone VARCHAR(20),
    subject VARCHAR(255) NOT NULL,
    message TEXT,
    priority ENUM('low','medium','high') DEFAULT 'medium',
    status ENUM('open','in_progress','resolved','closed') DEFAULT 'open',
    assigned_to INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_status (status),
    INDEX idx_priority (priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
