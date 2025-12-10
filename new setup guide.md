WAMP Setup Guide for Lunar Veil Cafe (Full Application)
1. 📂 File Placement (WAMP Directory)
The first step is to ensure all your website files are in the correct directory for WAMP's Apache server to run them.

Action: Create a project folder inside the WAMP web root.

Project Path: C:\wamp64\www\lunar_veil\

Action: Copy ALL your files (all HTML, PHP, and SQL files) into this new lunar_veil folder.

Access URL: Your local website will be accessible at: http://localhost/lunar_veil/

2. 🗄️ Database Creation and Import
You will use WAMP's bundled phpMyAdmin to set up the database. We will use the setup.sql file as it appears to be the most current schema for your application.

A. Access phpMyAdmin:

Ensure your WAMP server is running (Green icon).

Open your browser and go to: http://localhost/phpmyadmin/

Login:

Username: root

Password: Leave this field blank (WAMP default).

B. Create the Database:

Click on the Databases tab.

Database Name: Enter my_lunar_veil_cafe (This name is used in your db.php file).

Click Create.

C. Import the Schema:

Select the newly created database my_lunar_veil_cafe from the left sidebar.

Click on the Import tab at the top.

Click Choose file and select the schema script: setup.sql

Click the Go button at the bottom right. This will create all the necessary tables (users, products, orders, wallet, wallet_transactions, etc.).

3. 🔑 Configure the Database Connection (db.php)
Your application uses db.php for its database connection details.

A. Verify db.php:

Open the db.php file in a text editor.

Since you are using WAMP with the default settings, your connection details should already be correct, assuming you did not set a MySQL password:

PHP

// Database credentials (WAMP Default Configuration)
$servername = "localhost";
$username = "root";
$password = "";           // Leave blank if you did not set a password in WAMP
$dbname = "my_lunar_veil_cafe"; // Must match the name you created
B. Final Action: Only change the $password if you specifically set a root password for your WAMP MySQL server. Otherwise, save the file (or leave it as is).

4. 🚀 Run and Test the Application
The application is now set up! You should verify that all major features are connecting to the database correctly.

Access: Open your browser to http://localhost/lunar-veil-cafe/

Test 1: Menu & Database Read

Navigate to the Menu page. If the menu items load (Espresso, Latte, etc.), your PHP scripts are successfully reading data from the products table via get_menu.php.

Test 2: Registration & Database Write

Click on Login / Register and register a new user using the register.html page.

Check your users table in phpMyAdmin. The new user should appear.

Check the wallet table. A new wallet row should have been created for the user.

Test 3: Login, Wallet & Profile

Log in with your new user's credentials on login.html.

You should be redirected to profile.html.

Navigate to wallet.html. Test the Add Funds feature. This verifies the wallet.php script is working.

Test 4: Order & Transaction

Add items to the cart from the Menu.

Go to checkout.html and place the order.


Verify that the order total is deducted from your wallet balance and that a new order entry appears in the orders and order_items tables in phpMyAdmin.

