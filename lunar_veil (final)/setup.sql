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

-- Index for fast category filtering on the menu page
CREATE INDEX idx_product_category ON products (category, is_active);


-- =============================================
-- 3. Orders Table (Improved with payment_method and detailed status)
-- Stores a record of each completed order.
-- =============================================
CREATE TABLE orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    order_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    total_amount DECIMAL(8, 2) NOT NULL,
    order_type ENUM('Delivery', 'Pickup') NOT NULL,
    delivery_address VARCHAR(255) NULL, -- Only used for Delivery
    delivery_fee DECIMAL(4, 2) NOT NULL DEFAULT 0.00, -- Explicitly track the fee
    payment_method ENUM('Wallet', 'Card') NOT NULL, -- NEW: Track how the user paid
    status ENUM('Pending', 'Processing', 'Ready_for_Pickup', 'In_Delivery', 'Completed', 'Canceled') NOT NULL DEFAULT 'Pending',
    
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Index for fast lookup by user and status
CREATE INDEX idx_order_user ON orders (user_id, status);


-- =============================================
-- 4. Order Items Table
-- Links products to orders, preserving price at time of order.
-- =============================================
CREATE TABLE order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL,
    price_at_order DECIMAL(6, 2) NOT NULL, -- Price is fixed at the time of order
    
    FOREIGN KEY (order_id) REFERENCES orders(id),
    FOREIGN KEY (product_id) REFERENCES products(id)
);

-- Composite index for efficient fetching of items for a specific order
CREATE INDEX idx_order_item ON order_items (order_id, product_id);


-- =============================================
-- 5. Wallet Table
-- Stores the current balance for each user.
-- =============================================
CREATE TABLE wallet (
    user_id INT PRIMARY KEY,
    balance DECIMAL(8, 2) NOT NULL DEFAULT 0.00,
    
    FOREIGN KEY (user_id) REFERENCES users(id)
);


-- =============================================
-- 6. Wallet Transactions Table
-- Logs all changes to the user's wallet balance.
-- =============================================
CREATE TABLE wallet_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    transaction_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    amount DECIMAL(8, 2) NOT NULL, -- Positive for deposits, negative for payments
    type ENUM('Deposit', 'Payment', 'Refund') NOT NULL,
    description VARCHAR(255),
    
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Index for retrieving transaction history efficiently
CREATE INDEX idx_transaction_user ON wallet_transactions (user_id, transaction_date);


-- =============================================
-- DUMMY DATA FOR INITIAL POPULATION
-- =============================================

-- Products Data (Using the categories: Espresso, Latte, Frappe, Tea, Pastry, Dessert, Special)
INSERT INTO products (name, description, price, category) VALUES
-- Espresso
('Midnight Espresso', 'Dark roast with a velvet finish, single shot.', 4.50, 'Espresso'),
('Double Shot Veil', 'Potent double shot of our signature blend.', 5.50, 'Espresso'),
-- Latte
('Starlight Latte', 'Espresso with steamed milk and vanilla syrup.', 5.75, 'Latte'),
('Lunar Chai Latte', 'Spiced chai concentrate mixed with steamed milk.', 5.95, 'Latte'),
('Caramel Nebula Latte', 'Rich caramel and espresso, topped with whipped cream.', 6.50, 'Latte'),
-- Frappe
('Galaxy Mocha Frappe', 'Blended mocha, ice, and espresso, smooth and cold.', 7.50, 'Frappe'),
('Cosmic Vanilla Frappe', 'A sweet, creamy vanilla blended drink.', 6.95, 'Frappe'),
('Asteroid Java Chip Frappe', 'Chocolate chips and coffee blended to perfection.', 7.95, 'Frappe'),
-- Tea
('Eclipse Tea', 'Earl Grey with a twist of lemon and honey.', 4.00, 'Tea'),
('Herbal Zen Flow', 'Calming blend of chamomile and lavender.', 4.25, 'Tea'),
-- Pastry
('Veil Croissant', 'Flaky butter croissant baked to perfection.', 3.20, 'Pastry'),
('Star Dust Muffin', 'Moist blueberry muffin with a hint of cinnamon.', 3.75, 'Pastry'),
-- Dessert
('Almond Comet Slice', 'A slice of dense, moist almond cake.', 4.50, 'Dessert'),
('Black Hole Brownie', 'Fudgy chocolate brownie with dark cocoa.', 3.95, 'Dessert'),
-- Special
('Moon Rock Smoothie', 'A blend of mango, pineapple, and coconut milk.', 8.50, 'Special'),
('Seasonal Solar Brew', 'Limited time special blend. Ask for details!', 6.00, 'Special');

-- Set one item as inactive for demonstration (e.g., Seasonal Solar Brew)
UPDATE products SET is_active = FALSE WHERE name = 'Seasonal Solar Brew';