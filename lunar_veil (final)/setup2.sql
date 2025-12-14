-- #############################################
-- 1. DATABASE SCHEMA SETUP
-- This section creates the necessary tables.
-- #############################################

-- Create the users table (Accounts)
CREATE TABLE users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    -- Columns needed for admin/customer features:
    name VARCHAR(255) NULL, 
    role VARCHAR(50) NOT NULL DEFAULT 'Standard Customer', 
    -- Additional fields based on common e-commerce setup:
    email VARCHAR(100) NULL,
    city_address VARCHAR(255) NULL,
    birthday DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create the products table (Menu Items)
CREATE TABLE products (
    product_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    price DECIMAL(10, 2) NOT NULL,
    description TEXT NULL,
    category_id INT NULL 
);

-- Create the orders table (Order Headers)
CREATE TABLE orders (
    order_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    order_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    total_amount DECIMAL(10, 2) NOT NULL,
    subtotal DECIMAL(10, 2) NULL,
    tax_amount DECIMAL(10, 2) NULL,
    delivery_fee DECIMAL(10, 2) DEFAULT 0.00,
    status VARCHAR(50) NOT NULL DEFAULT 'Pending',
    payment_method VARCHAR(50) NULL DEFAULT 'Wallet',
    contact_number VARCHAR(20) NULL,
    special_notes TEXT NULL,
    -- Columns required for tracking.html and admin.html:
    order_type ENUM('Delivery', 'Pickup') NOT NULL DEFAULT 'Pickup',
    delivery_address VARCHAR(255) NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(user_id)
);

-- Create the order_items table (Order Details/Line Items)
CREATE TABLE order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(10, 2) NULL,
    
    FOREIGN KEY (order_id) REFERENCES orders(order_id),
    FOREIGN KEY (product_id) REFERENCES products(product_id)
);


-- #############################################
-- 2. SAMPLE DATA INSERTION
-- This section populates tables with test data.
-- IMPORTANT: Replace '$2y$10$HASHED_PASSWORD_HERE' with a real PHP-generated password hash!
-- #############################################

-- Sample Users (user_id 1 and 2)
INSERT INTO users (username, password, name, role) 
VALUES 
('admin_user', '$2y$10$HASHED_PASSWORD_HERE', 'Elias Kael', 'Admin');

INSERT INTO users (username, password, name, role) 
VALUES 
('testuser', '$2y$10$HASHED_PASSWORD_HERE', 'Alice Johnson', 'Standard Customer');

-- Sample Products (product_id 1, 2, and 3)
INSERT INTO products (name, price) 
VALUES 
('Lunar Latte', 180.00),  
('Nebula Cheesecake', 250.00), 
('Sunspot Tea', 100.00); 

-- Sample Order for testuser (user_id 2). Assumed order_id 1.
-- Total: (2 * 180.00) + (1 * 250.00) = 610.00
INSERT INTO orders (user_id, total_amount, status, delivery_address, order_type) 
VALUES (2, 610.00, 'Pending', '101 Galaxy Road, Earth', 'Delivery'); 

-- Order Items for the Sample Order (order_id 1)
INSERT INTO order_items (order_id, product_id, quantity, unit_price) 
VALUES 
(1, 1, 2, 180.00), -- 2x Lunar Latte
(1, 2, 1, 250.00);  -- 1x Nebula Cheesecake