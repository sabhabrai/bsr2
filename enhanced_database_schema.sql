-- Enhanced BSR Marketplace Database Schema
-- This extends the existing schema with new features for a multi-vendor eCommerce platform

USE bsr_marketplace;

-- Add new columns to existing users table for account types and verification
ALTER TABLE users 
ADD COLUMN account_type ENUM('buyer', 'seller') NOT NULL DEFAULT 'buyer',
ADD COLUMN phone_number VARCHAR(20) NULL,
ADD COLUMN phone_verified BOOLEAN DEFAULT FALSE,
ADD COLUMN verification_code VARCHAR(6) NULL,
ADD COLUMN verification_expires TIMESTAMP NULL,
ADD COLUMN is_verified_seller BOOLEAN DEFAULT FALSE,
ADD COLUMN seller_rating DECIMAL(3,2) DEFAULT 0.00,
ADD COLUMN total_sales INT DEFAULT 0,
ADD COLUMN is_flagged BOOLEAN DEFAULT FALSE,
ADD COLUMN flag_reason TEXT NULL,
ADD COLUMN status ENUM('active', 'suspended', 'banned') DEFAULT 'active';

-- Payments table for listing fees and transaction payments
CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type ENUM('listing_fee', 'purchase', 'refund', 'withdrawal') NOT NULL,
    amount DECIMAL(10, 2) NOT NULL,
    currency VARCHAR(3) DEFAULT 'USD',
    payment_method VARCHAR(50) NOT NULL,
    payment_provider VARCHAR(50) NOT NULL, -- stripe, paypal, etc.
    provider_transaction_id VARCHAR(255) NULL,
    status ENUM('pending', 'completed', 'failed', 'refunded') DEFAULT 'pending',
    related_listing_id INT NULL,
    related_transaction_id INT NULL,
    metadata TEXT NULL, -- JSON for additional payment data
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (related_listing_id) REFERENCES listings(id) ON DELETE SET NULL,
    INDEX idx_user_payments (user_id),
    INDEX idx_payment_status (status),
    INDEX idx_payment_type (type)
);

-- Transactions table for actual item purchases
CREATE TABLE IF NOT EXISTS transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    listing_id INT NOT NULL,
    buyer_id INT NOT NULL,
    seller_id INT NOT NULL,
    quantity INT DEFAULT 1,
    unit_price DECIMAL(10, 2) NOT NULL,
    total_amount DECIMAL(10, 2) NOT NULL,
    platform_fee DECIMAL(10, 2) DEFAULT 0.00,
    status ENUM('pending', 'paid', 'shipped', 'delivered', 'completed', 'cancelled', 'disputed') DEFAULT 'pending',
    payment_status ENUM('pending', 'authorized', 'captured', 'refunded') DEFAULT 'pending',
    payment_id INT NULL,
    shipping_address TEXT NULL,
    tracking_number VARCHAR(100) NULL,
    delivery_date TIMESTAMP NULL,
    buyer_rating INT NULL CHECK (buyer_rating >= 1 AND buyer_rating <= 5),
    seller_rating INT NULL CHECK (seller_rating >= 1 AND seller_rating <= 5),
    buyer_review TEXT NULL,
    seller_review TEXT NULL,
    dispute_reason TEXT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE,
    FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL,
    INDEX idx_transaction_buyer (buyer_id),
    INDEX idx_transaction_seller (seller_id),
    INDEX idx_transaction_status (status),
    INDEX idx_transaction_listing (listing_id)
);

-- Enhanced reports table for comprehensive reporting system
DROP TABLE IF EXISTS reports;
CREATE TABLE reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reporter_user_id INT NOT NULL,
    reported_user_id INT NULL,
    reported_listing_id INT NULL,
    reported_transaction_id INT NULL,
    report_type ENUM('user', 'listing', 'transaction', 'other') NOT NULL,
    reason ENUM('fraud', 'fake_listing', 'inappropriate_content', 'spam', 'scam', 'harassment', 'counterfeit', 'other') NOT NULL,
    description TEXT NOT NULL,
    evidence TEXT NULL, -- JSON array of evidence (screenshots, etc.)
    status ENUM('pending', 'investigating', 'resolved', 'dismissed') DEFAULT 'pending',
    admin_notes TEXT NULL,
    resolution TEXT NULL,
    handled_by_admin_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (reporter_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (reported_user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (reported_listing_id) REFERENCES listings(id) ON DELETE SET NULL,
    FOREIGN KEY (reported_transaction_id) REFERENCES transactions(id) ON DELETE SET NULL,
    INDEX idx_report_status (status),
    INDEX idx_report_type (report_type),
    INDEX idx_reported_user (reported_user_id),
    INDEX idx_reporter_user (reporter_user_id)
);

-- User flags and violations tracking
CREATE TABLE IF NOT EXISTS user_flags (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    flag_type ENUM('warning', 'suspension', 'ban') NOT NULL,
    reason TEXT NOT NULL,
    severity INT DEFAULT 1 CHECK (severity >= 1 AND severity <= 10),
    auto_generated BOOLEAN DEFAULT FALSE,
    related_report_id INT NULL,
    expires_at TIMESTAMP NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_by_admin_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (related_report_id) REFERENCES reports(id) ON DELETE SET NULL,
    INDEX idx_user_flags (user_id),
    INDEX idx_flag_type (flag_type),
    INDEX idx_flag_active (is_active)
);

-- Notifications system
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type ENUM('transaction', 'verification', 'report', 'system', 'promotion') NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
    action_url VARCHAR(255) NULL,
    related_transaction_id INT NULL,
    related_listing_id INT NULL,
    related_report_id INT NULL,
    send_email BOOLEAN DEFAULT FALSE,
    send_sms BOOLEAN DEFAULT FALSE,
    email_sent BOOLEAN DEFAULT FALSE,
    sms_sent BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (related_transaction_id) REFERENCES transactions(id) ON DELETE SET NULL,
    FOREIGN KEY (related_listing_id) REFERENCES listings(id) ON DELETE SET NULL,
    FOREIGN KEY (related_report_id) REFERENCES reports(id) ON DELETE SET NULL,
    INDEX idx_user_notifications (user_id),
    INDEX idx_notification_read (is_read),
    INDEX idx_notification_type (type)
);

-- Admin users table for system administration
CREATE TABLE IF NOT EXISTS admin_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(150) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('super_admin', 'moderator', 'support') DEFAULT 'support',
    is_active BOOLEAN DEFAULT TRUE,
    last_login TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_admin_username (username),
    INDEX idx_admin_role (role)
);

-- System settings for configuration management
CREATE TABLE IF NOT EXISTS system_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT NOT NULL,
    description TEXT NULL,
    data_type ENUM('string', 'integer', 'float', 'boolean', 'json') DEFAULT 'string',
    is_public BOOLEAN DEFAULT FALSE,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Activity logs for audit trail
CREATE TABLE IF NOT EXISTS activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    admin_id INT NULL,
    activity_type ENUM('user_action', 'admin_action', 'system_event') NOT NULL,
    action VARCHAR(100) NOT NULL,
    description TEXT NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    metadata TEXT NULL, -- JSON for additional data
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (admin_id) REFERENCES admin_users(id) ON DELETE SET NULL,
    INDEX idx_activity_user (user_id),
    INDEX idx_activity_type (activity_type),
    INDEX idx_activity_date (created_at)
);

-- Modify listings table to add payment requirement and enhanced features
ALTER TABLE listings 
ADD COLUMN listing_fee_paid BOOLEAN DEFAULT FALSE,
ADD COLUMN listing_fee_payment_id INT NULL,
ADD COLUMN views_count INT DEFAULT 0,
ADD COLUMN favorites_count INT DEFAULT 0,
ADD COLUMN is_featured BOOLEAN DEFAULT FALSE,
ADD COLUMN is_promoted BOOLEAN DEFAULT FALSE,
ADD COLUMN promotion_expires TIMESTAMP NULL,
ADD COLUMN requires_shipping BOOLEAN DEFAULT TRUE,
ADD COLUMN shipping_cost DECIMAL(10, 2) DEFAULT 0.00,
ADD COLUMN quantity_available INT DEFAULT 1,
ADD COLUMN condition_type ENUM('new', 'like_new', 'good', 'fair', 'poor') DEFAULT 'good',
ADD COLUMN return_policy TEXT NULL,
ADD COLUMN is_negotiable BOOLEAN DEFAULT TRUE,
ADD COLUMN min_offer_price DECIMAL(10, 2) NULL,
ADD COLUMN status ENUM('draft', 'active', 'sold', 'expired', 'suspended') DEFAULT 'draft',
ADD FOREIGN KEY (listing_fee_payment_id) REFERENCES payments(id) ON DELETE SET NULL;

-- Insert default system settings
INSERT INTO system_settings (setting_key, setting_value, description, data_type, is_public) VALUES
('listing_fee', '2.50', 'Fee charged for creating a listing', 'float', true),
('platform_commission', '5.0', 'Platform commission percentage on transactions', 'float', false),
('max_images_per_listing', '5', 'Maximum number of images allowed per listing', 'integer', true),
('verification_code_expiry', '15', 'Phone verification code expiry time in minutes', 'integer', false),
('min_seller_rating', '3.0', 'Minimum seller rating to avoid flagging', 'float', false),
('max_reports_before_flag', '3', 'Maximum reports before auto-flagging user', 'integer', false),
('site_maintenance_mode', 'false', 'Enable maintenance mode', 'boolean', true),
('payment_providers', '["stripe", "paypal"]', 'Enabled payment providers', 'json', false),
('supported_currencies', '["USD", "EUR", "GBP"]', 'Supported currencies', 'json', true);

-- Create default admin user (password: admin123 - should be changed immediately)
INSERT INTO admin_users (username, email, password, role) VALUES
('admin', 'admin@bsr-marketplace.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'super_admin');

-- Update existing users to have account_type
UPDATE users SET account_type = 'buyer' WHERE account_type IS NULL;

-- Create indexes for better performance
CREATE INDEX idx_listings_status ON listings(status);
CREATE INDEX idx_listings_featured ON listings(is_featured);
CREATE INDEX idx_listings_category_type ON listings(category, type);
CREATE INDEX idx_users_account_type ON users(account_type);
CREATE INDEX idx_users_verified ON users(is_verified_seller);
CREATE INDEX idx_users_status ON users(status);

-- Views for common queries
CREATE OR REPLACE VIEW active_listings AS
SELECT l.*, u.name as seller_name, u.email as seller_email, u.is_verified_seller, u.seller_rating
FROM listings l
JOIN users u ON l.user_id = u.id
WHERE l.status = 'active' 
AND u.status = 'active'
AND (l.created_at + INTERVAL l.duration_hours HOUR) > NOW();

CREATE OR REPLACE VIEW seller_stats AS
SELECT 
    u.id,
    u.name,
    u.email,
    u.seller_rating,
    u.total_sales,
    COUNT(DISTINCT l.id) as total_listings,
    COUNT(DISTINCT t.id) as total_transactions,
    AVG(t.seller_rating) as avg_transaction_rating,
    SUM(CASE WHEN t.status = 'completed' THEN t.total_amount ELSE 0 END) as total_revenue
FROM users u
LEFT JOIN listings l ON u.id = l.user_id
LEFT JOIN transactions t ON u.id = t.seller_id
WHERE u.account_type = 'seller'
GROUP BY u.id;

-- Triggers for automatic updates
DELIMITER $$

CREATE TRIGGER update_listing_counts AFTER INSERT ON bookmarks
FOR EACH ROW
BEGIN
    UPDATE listings SET favorites_count = favorites_count + 1 WHERE id = NEW.listing_id;
END$$

CREATE TRIGGER update_listing_counts_delete AFTER DELETE ON bookmarks
FOR EACH ROW
BEGIN
    UPDATE listings SET favorites_count = favorites_count - 1 WHERE id = OLD.listing_id;
END$$

CREATE TRIGGER update_seller_rating AFTER UPDATE ON transactions
FOR EACH ROW
BEGIN
    IF NEW.seller_rating IS NOT NULL AND OLD.seller_rating IS NULL THEN
        UPDATE users SET 
            seller_rating = (
                SELECT AVG(seller_rating) 
                FROM transactions 
                WHERE seller_id = NEW.seller_id AND seller_rating IS NOT NULL
            ),
            total_sales = total_sales + 1
        WHERE id = NEW.seller_id;
    END IF;
END$$

DELIMITER ;
