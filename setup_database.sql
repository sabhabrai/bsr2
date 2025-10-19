-- BSR Marketplace Database Setup
-- Run this SQL script in your MySQL/phpMyAdmin

-- Create the database
CREATE DATABASE IF NOT EXISTS bsr_marketplace CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE bsr_marketplace;

-- Users table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Listings table
CREATE TABLE IF NOT EXISTS listings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT NOT NULL,
    price DECIMAL(10, 2) NOT NULL,
    category VARCHAR(50) NOT NULL,
    type ENUM('sell', 'rent', 'buy') NOT NULL,
    location VARCHAR(150) NOT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    duration_hours INT NOT NULL DEFAULT 48,
    images TEXT DEFAULT NULL, -- JSON string of image data
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_category (category),
    INDEX idx_type (type),
    INDEX idx_created_at (created_at)
);

-- Bookmarks table
CREATE TABLE IF NOT EXISTS bookmarks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    listing_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE,
    UNIQUE KEY unique_bookmark (user_id, listing_id),
    INDEX idx_user_id (user_id),
    INDEX idx_listing_id (listing_id)
);

-- Reports table (optional - for tracking reported listings)
CREATE TABLE IF NOT EXISTS reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    listing_id INT NOT NULL,
    reason TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE,
    INDEX idx_listing_id (listing_id)
);

-- Insert some sample data (optional)
-- You can remove this if you don't want sample data

-- Sample users (passwords are hashed versions of 'password123')
INSERT INTO users (name, email, password) VALUES 
('John Doe', 'john@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
('Jane Smith', 'jane@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi');

-- Sample listings
INSERT INTO listings (user_id, title, description, price, category, type, location, phone, duration_hours) VALUES 
(1, 'iPhone 13 Pro Max', 'Excellent condition iPhone 13 Pro Max, 256GB, unlocked. Comes with original box and accessories.', 899.99, 'electronics', 'sell', 'New York, NY', '555-0123', 168),
(1, 'Mountain Bike', 'Trek mountain bike in great condition. Perfect for trails and city riding.', 450.00, 'sports', 'sell', 'Los Angeles, CA', '555-0124', 72),
(2, 'Apartment for Rent', 'Beautiful 2-bedroom apartment in downtown. Fully furnished, utilities included.', 1200.00, 'home', 'rent', 'Chicago, IL', '555-0125', 336),
(2, 'Looking for Gaming Laptop', 'Need a good gaming laptop for college. Budget around $800-1000.', 1000.00, 'electronics', 'buy', 'Austin, TX', NULL, 168);
