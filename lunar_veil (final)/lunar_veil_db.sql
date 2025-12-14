-- Database: my_lunar_veil_cafe
-- This script drops and recreates all relevant tables to ensure the new categories and data are applied.

DROP TABLE IF EXISTS wallet_transactions;
DROP TABLE IF EXISTS wallet;
DROP TABLE IF EXISTS order_items;
DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS users;


-- 1. Users Table
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 2. Products Table (UPDATED CATEGORIES: Espresso, Latte, Frappe, Dessert)
CREATE TABLE products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(255),
    price DECIMAL(6, 2) NOT NULL,
    category ENUM('Espresso', 'Latte', 'Frappe', 'Tea', 'Pastry', 'Dessert', 'Special') NOT NULL
);

-- 3. Orders Table
CREATE TABLE orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    order_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    total_amount DECIMAL(8, 2) NOT NULL,
    order_type ENUM('Delivery', 'Pickup') NOT NULL,
    status ENUM('Pending', 'Processing', 'Ready', 'Completed') DEFAULT 'Pending',
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- 4. Order Items Table
CREATE TABLE order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL,
    price_at_order DECIMAL(6, 2) NOT NULL, 
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
);

-- 5. Wallet Table
CREATE TABLE wallet (
    user_id INT PRIMARY KEY,
    balance DECIMAL(8, 2) NOT NULL DEFAULT 0.00,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- 6. Wallet Transactions Table
CREATE TABLE wallet_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    amount DECIMAL(8, 2) NOT NULL,
    type ENUM('credit', 'debit') NOT NULL,
    description VARCHAR(255),
    transaction_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Initial Menu Data - EXPANDED PRODUCT TYPES
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
('Almond Comet Slice', 'A slice of dense, moist almond cake.', 4.50, 'Pastry'),
-- Dessert
('Dark Side Brownie', 'Fudge brownie with a molten chocolate center.', 4.95, 'Dessert'),
('Milky Way Macarons', 'Assorted French macarons (set of 3).', 6.00, 'Dessert'),
-- Special
('Nebula Blue Drink', 'Our signature sparkling blueberry beverage.', 7.00, 'Special');