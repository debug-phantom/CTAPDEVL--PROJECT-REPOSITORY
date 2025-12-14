CREATE TABLE orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    order_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    total_amount DECIMAL(10, 2) NOT NULL,
    subtotal DECIMAL(10, 2) NOT NULL,
    tax_amount DECIMAL(10, 2) NOT NULL,
    delivery_fee DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    
    -- Checkout Details
    order_type VARCHAR(50) NOT NULL DEFAULT 'Pickup',
    address_note VARCHAR(255) NULL,
    contact_number VARCHAR(50) NULL,
    special_notes TEXT NULL,
    
    status VARCHAR(50) NOT NULL DEFAULT 'Pending',
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);