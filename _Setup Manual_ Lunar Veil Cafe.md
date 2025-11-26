🚀 Setup Manual: Lunar Veil Cafe  
To get your application running, you need a local web server environment (like XAMPP, MAMP, or WAMP) to run the PHP and host the HTML/JavaScript files.

Step 1: Set Up the Database (MySQL)  
You must create the database and the required tables. This setup assumes you are using XAMPP and connecting with the default root user and no password.

1\. Create the Database  
Open phpMyAdmin in your browser (usually at http://localhost/phpmyadmin).

Click the Databases tab.

Enter the name: my\_lunar\_veil\_cafe and click Create.

2\. Run the SQL Script  
The following SQL script creates all necessary tables for users, menu, wallet, and orders.

1\. Click on the new my\_lunar\_veil\_cafe database.

2\. Click the SQL tab.

3\. Copy and paste the entire script below into the text area, and click Go.

SQL code:  
\-- 1\. Users Table (For Registration and Login)  
CREATE TABLE users (  
    id INT AUTO\_INCREMENT PRIMARY KEY,  
    username VARCHAR(50) NOT NULL UNIQUE,  
    email VARCHAR(100) NOT NULL UNIQUE,  
    \-- password\_hash stores the hashed, secure password  
    password\_hash VARCHAR(255) NOT NULL,  
    created\_at TIMESTAMP DEFAULT CURRENT\_TIMESTAMP  
);

\-- 2\. Products Table (The Cafe Menu)  
CREATE TABLE products (  
    id INT AUTO\_INCREMENT PRIMARY KEY,  
    name VARCHAR(100) NOT NULL,  
    description VARCHAR(255),  
    price DECIMAL(6, 2\) NOT NULL,  
    category ENUM('Coffee', 'Tea', 'Pastry', 'Special') NOT NULL  
);

\-- 3\. Wallet Table (Holds user balances)  
CREATE TABLE wallet (  
    user\_id INT PRIMARY KEY,  
    balance DECIMAL(8, 2\) NOT NULL DEFAULT 0.00,  
    FOREIGN KEY (user\_id) REFERENCES users(id) ON DELETE CASCADE  
);

\-- 4\. Wallet Transactions Table (For logging adds/payments)  
CREATE TABLE wallet\_transactions (  
    id INT AUTO\_INCREMENT PRIMARY KEY,  
    user\_id INT NOT NULL,  
    amount DECIMAL(8, 2\) NOT NULL, \-- Positive for credit (add funds), Negative for debit (payment)  
    type ENUM('credit', 'debit') NOT NULL,  
    description VARCHAR(255),  
    transaction\_date TIMESTAMP DEFAULT CURRENT\_TIMESTAMP,  
    FOREIGN KEY (user\_id) REFERENCES users(id) ON DELETE CASCADE  
);

\-- 5\. Orders Table  
CREATE TABLE orders (  
    id INT AUTO\_INCREMENT PRIMARY KEY,  
    user\_id INT NOT NULL,  
    order\_date TIMESTAMP DEFAULT CURRENT\_TIMESTAMP,  
    total\_amount DECIMAL(8, 2\) NOT NULL,  
    \-- order\_type can be 'delivery' or 'pickup'  
    order\_type ENUM('Delivery', 'Pickup') NOT NULL,  
    delivery\_address VARCHAR(255) NULL, \-- Stores address if Delivery, otherwise NULL  
    status ENUM('Pending', 'Processing', 'Ready', 'Completed') DEFAULT 'Pending',  
    FOREIGN KEY (user\_id) REFERENCES users(id) ON DELETE CASCADE  
);

\-- 6\. Order Items Table (What was in the order)  
CREATE TABLE order\_items (  
    id INT AUTO\_INCREMENT PRIMARY KEY,  
    order\_id INT NOT NULL,  
    product\_id INT NOT NULL,  
    quantity INT NOT NULL,  
    price\_at\_order DECIMAL(6, 2\) NOT NULL, \-- Price when the order was placed  
    FOREIGN KEY (order\_id) REFERENCES orders(id) ON DELETE CASCADE,  
    FOREIGN KEY (product\_id) REFERENCES products(id) ON DELETE RESTRICT  
);

\-- Initial Menu Data  
INSERT INTO products (name, description, price, category) VALUES  
('Midnight Espresso', 'Dark roast with a velvet finish.', 4.50, 'Coffee'),  
('Starlight Latte', 'Espresso with steamed milk and vanilla syrup.', 5.75, 'Coffee'),  
('Moon Dust Mocha', 'Rich dark chocolate and espresso blend.', 6.25, 'Coffee'),  
('Eclipse Tea', 'Earl Grey with a twist of lemon and honey.', 4.00, 'Tea'),  
('Celestial Scone', 'A flaky pastry with spiced fruit.', 3.50, 'Pastry');

After that, open the website here:  
[http://localhost/lunar\_veil/index.html](http://localhost/lunar_veil/index.html)

Step 3: Launch the Application

1. Make sure your web server (e.g., Apache and MySQL in XAMPP) is running.  
2. Make sure you have all the files in GitHub (to make it run)  
3. After having all of the files, make sure to open the local host server provided above.

That’s all for the setup. Have fun with our Cafe Website

Lunar Veil Cafe Website by James Adrian B. Castro

