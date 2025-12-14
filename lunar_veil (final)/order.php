<?php
// order.php - FINAL VERSION with Corrected Column Names and Foreign Key Check

session_start();
header('Content-Type: application/json');

// Enable exceptions for safer database transactions
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Assumes 'db.php' file sets up $conn variable
require_once "db.php"; 

$input = file_get_contents('php://input');
$data = json_decode($input, true);

$user_id = $_SESSION['user_id'] ?? null;
$action = $data['action'] ?? null;

// 1. Basic Authentication Check
if (!$user_id) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated. Please log back in.']);
    exit;
}

// 2. Handle the place_order action
if ($action !== 'place_order') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid action specified.']);
    exit;
}

// 3. Input Validation and Data Extraction
$cart_items = $data['cart'] ?? [];
$order_type = $data['order_type'] ?? 'Pickup';
$delivery_address = $data['address_note'] ?? null;
$contact_number = $data['contact_number'] ?? null;
$special_notes = $data['special_notes'] ?? '';
$payment_method = 'Wallet';
$status = 'Pending'; // Defined here for binding

// Get calculated amounts from frontend
$subtotal_amount = floatval($data['subtotal'] ?? 0);
$tax_amount = floatval($data['tax_amount'] ?? 0);
$delivery_fee = floatval($data['delivery_fee'] ?? 0);
$total_amount = floatval($data['total_amount'] ?? 0);

// Verify the calculation matches
$calculated_total = $subtotal_amount + $tax_amount + $delivery_fee;
if (abs($calculated_total - $total_amount) > 0.01) {
    echo json_encode(['success' => false, 'message' => 'Total amount mismatch. Please refresh and try again.']);
    exit;
}

if ($total_amount <= 0 || empty($cart_items)) {
    echo json_encode(['success' => false, 'message' => 'Invalid order data. Cart is empty or total is zero.']);
    exit;
}

// Validate delivery address for delivery orders
if ($order_type === 'Delivery' && empty($delivery_address)) {
    echo json_encode(['success' => false, 'message' => 'Delivery address is required for delivery orders.']);
    exit;
}

// Use database transactions for safety (all or nothing)
$conn->begin_transaction();

try {
    // 4. Check and Update Wallet Balance
    $stmt = $conn->prepare("SELECT balance FROM wallet WHERE user_id = ? FOR UPDATE");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $wallet_data = $result->fetch_assoc();
    $stmt->close();
    
    $current_balance = (float)($wallet_data['balance'] ?? 0);

    if ($current_balance < $total_amount) {
        $conn->rollback();
        echo json_encode([
            'success' => false, 
            'message' => "Insufficient wallet balance. Needed: ₱" . number_format($total_amount, 2) . ", Available: ₱" . number_format($current_balance, 2)
        ]);
        exit;
    }

    $new_balance = $current_balance - $total_amount;
    
    $stmt = $conn->prepare("UPDATE wallet SET balance = ? WHERE user_id = ?");
    $stmt->bind_param("di", $new_balance, $user_id);
    $stmt->execute();
    $stmt->close();

    // 5. Create the Order (11 Columns, 11 Placeholders)
    $stmt = $conn->prepare(
        "INSERT INTO orders (
            user_id, 
            total_amount, 
            subtotal,          
            tax_amount,        
            delivery_fee, 
            order_type, 
            delivery_address,  
            payment_method,
            status,             
            contact_number,    
            special_notes      
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)" // 11 placeholders
    );
    
    // Bind 11 parameters: i d d d d s s s s s s
    $stmt->bind_param(
        "iddddssssss", 
        $user_id, 
        $total_amount, 
        $subtotal_amount, 
        $tax_amount, 
        $delivery_fee, 
        $order_type, 
        $delivery_address, 
        $payment_method,
        $status,           /* Binding 'Pending' */
        $contact_number,
        $special_notes
    );
    $stmt->execute();
    $order_id = $conn->insert_id;
    $stmt->close();

    // 6. Insert Order Items (FIXED SQL QUERY HERE)
    
    // FIX: Changed 'id' to 'product_id' to match the database schema
    $item_check_stmt = $conn->prepare("SELECT price FROM products WHERE product_id = ?"); 
    
    $item_stmt = $conn->prepare(
        "INSERT INTO order_items (order_id, product_id, quantity, price_at_purchase) 
         VALUES (?, ?, ?, ?)"
    );

    foreach ($cart_items as $item) {
        $product_id = (int)($item['id'] ?? 0);
        $quantity = (int)($item['quantity'] ?? 0);

        if ($product_id <= 0 || $quantity <= 0) {
            throw new Exception("Invalid cart item data received."); 
        }
        
        // Check if the product exists
        $item_check_stmt->bind_param("i", $product_id);
        $item_check_stmt->execute();
        $product_result = $item_check_stmt->get_result();
        $product_data = $product_result->fetch_assoc();
        
        if (!$product_data) {
            throw new Exception("Product ID {$product_id} not found in catalog. Please clear your cart and re-add items.");
        }
        
        // Use the official price from the database
        $price = floatval($product_data['price']); 

        $item_stmt->bind_param("iiid", $order_id, $product_id, $quantity, $price);
        $item_stmt->execute();
    }
    $item_check_stmt->close();
    $item_stmt->close();

    // 7. Log the Payment Transaction
    $transaction_description = "Order Payment #" . $order_id . " (₱" . number_format($total_amount, 2) . ")";
    $transaction_type = 'debit';
    
    $stmt = $conn->prepare(
        "INSERT INTO wallet_transactions (user_id, amount, type, description) 
        VALUES (?, ?, ?, ?)"
    );
    $stmt->bind_param("idss", $user_id, $total_amount, $transaction_type, $transaction_description);
    $stmt->execute();
    $stmt->close();

    // 8. Finalize Transaction
    $conn->commit(); 

    echo json_encode([
        'success' => true,
        'message' => 'Order placed and payment successful!',
        'orderId' => $order_id,
        'new_balance' => number_format($new_balance, 2, '.', '')
    ]);

} catch (Exception $e) {
    // 9. Rollback on Failure
    $conn->rollback(); 
    error_log("Order Failed for user_id: {$user_id}. Error: " . $e->getMessage());
    http_response_code(500); 
    echo json_encode([
        'success' => false, 
        'message' => 'Order placement failed: ' . $e->getMessage()
    ]);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>