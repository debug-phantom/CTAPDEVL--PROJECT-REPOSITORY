-- Database: my_lunar_veil_cafe V2
-- This script drops and recreates all relevant tables to ensure the new, improved schema is applied.

DROP TABLE IF EXISTS wallet_transactions;
DROP TABLE IF EXISTS wallet;
DROP TABLE IF EXISTS order_items;
DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS users;


-- =============================================
-- 1. Users Table
-- Stores user registration data.
-- =============================================
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Index for faster lookup by email during login
CREATE UNIQUE INDEX idx_user_email ON users (email);


-- =============================================
-- 2. Products Table (Improved with is_active flag)
-- Stores the cafe menu items.
-- =============================================
CREATE TABLE products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(255),
    price DECIMAL(6, 2) NOT NULL,
    category ENUM('Espresso', 'Latte', 'Frappe', 'Tea', 'Pastry', 'Dessert', 'Special') NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE, -- New flag to easily hide items from the menu
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);


-- =============================================
-- 3. Orders Table
-- Stores the high-level order information.
-- =============================================
CREATE TABLE orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    order_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    total_amount DECIMAL(8, 2) NOT NULL,
    order_type ENUM('Delivery', 'Pickup') NOT NULL,
    delivery_address VARCHAR(255),
    special_notes TEXT,
    status ENUM('Pending', 'Processing', 'Ready', 'Out For Delivery', 'Completed', 'Cancelled') NOT NULL DEFAULT 'Pending',
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);


-- =============================================
-- 4. Order Items Table
-- Stores the details of each item in an order.
-- =============================================
CREATE TABLE order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT, -- NULLABLE if the item is custom or special, but should reference products
    quantity INT NOT NULL,
    price_at_order DECIMAL(6, 2) NOT NULL,
    item_name VARCHAR(100), -- Store name redundancy in case product is deleted
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
);


-- =============================================
-- 5. Wallet Table
-- Stores the user's current balance (one-to-one relationship with users).
-- =============================================
CREATE TABLE wallet (
    user_id INT PRIMARY KEY,
    balance DECIMAL(8, 2) NOT NULL DEFAULT 0.00,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);


-- =============================================
-- 6. Wallet Transactions Table
-- Stores the history of all funds added and used.
-- =============================================
CREATE TABLE wallet_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    transaction_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    amount DECIMAL(8, 2) NOT NULL,
    type ENUM('credit', 'debit') NOT NULL,
    description VARCHAR(255),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);


-- =============================================
-- 7. Initial Data Insertion (Menu Items)
-- These are the items that will populate the products table.
-- =============================================
INSERT INTO products (name, description, price, category, is_active) VALUES
-- Espresso
('Midnight Espresso', 'Dark roast with a velvet finish, single shot.', 4.50, 'Espresso', TRUE),
('Double Shot Veil', 'Potent double shot of our signature blend.', 5.50, 'Espresso', TRUE),
-- Latte
('Starlight Latte', 'Espresso with steamed milk and vanilla syrup.', 5.75, 'Latte', TRUE),
('Lunar Chai Latte', 'Spiced chai concentrate mixed with steamed milk.', 5.95, 'Latte', TRUE),
('Caramel Nebula Latte', 'Rich caramel and espresso, topped with whipped cream.', 6.50, 'Latte', TRUE),
-- Frappe
('Galaxy Mocha Frappe', 'Blended mocha, ice, and espresso, smooth and cold.', 7.50, 'Frappe', TRUE),
('Cosmic Vanilla Frappe', 'A sweet, creamy vanilla blended drink.', 6.95, 'Frappe', TRUE),
('Asteroid Java Chip Frappe', 'Chocolate chips and coffee blended to perfection.', 7.95, 'Frappe', TRUE),
-- Tea
('Eclipse Tea', 'Earl Grey with a twist of lemon and honey.', 4.00, 'Tea', TRUE),
('Herbal Zen Flow', 'Calming blend of chamomile and lavender.', 4.25, 'Tea', TRUE),
-- Pastry
('Veil Croissant', 'Flaky butter croissant baked to perfection.', 3.20, 'Pastry', TRUE),
('Star Dust Muffin', 'Moist blueberry muffin with a hint of cinnamon.', 3.75, 'Pastry', TRUE),
-- Dessert
('Almond Comet Slice', 'A slice of dense, moist almond cake.', 4.50, 'Dessert', TRUE),
('Black Hole Brownie', 'Fudgy chocolate brownie with dark cocoa.', 3.95, 'Dessert', TRUE),
-- Special
('Moon Rock Cookie', 'Oatmeal cookie with white chocolate chunks.', 3.50, 'Special', TRUE),
('Aurora Smoothie', 'Mixed berry smoothie with a hint of mint.', 6.00, 'Special', TRUE),
('Planetary Punch', 'Seasonal fruit juice blend.', 4.75, 'Special', TRUE);