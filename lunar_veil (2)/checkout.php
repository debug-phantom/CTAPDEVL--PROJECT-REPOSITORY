<?php
session_start();
header('Content-Type: application/json');

// Enable exceptions for safer database transactions
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Assumes 'db.php' file sets up $conn variable
require_once "db.php"; 

$input = file_get_contents('php://input');
$data = json_decode($input, true);

$user_id = $_SESSION['user_id'] ?? null;

// 1. Basic Authentication Check
if (!$user_id) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated. Please log back in.']);
    exit;
}

// 2. Input Validation
$total_amount = floatval($data['total_amount'] ?? 0);
$order_type = $data['order_type'] ?? 'Pickup';
$delivery_address = $data['delivery_address'] ?? null;
$special_notes = $data['special_notes'] ?? '';
$cart_items = $data['cart_items'] ?? [];

if ($total_amount <= 0 || empty($cart_items)) {
    echo json_encode(['success' => false, 'message' => 'Invalid order data. Cart is empty or total is zero.']);
    exit;
}

// Use database transactions for safety (all or nothing)
$conn->begin_transaction();

try {
    // 3. Fetch Wallet Balance
    $stmt = $conn->prepare("SELECT balance FROM wallets WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows !== 1) {
        throw new Exception("Wallet not found or not initialized.");
    }

    $wallet = $result->fetch_assoc();
    $current_balance = floatval($wallet['balance']);
    $stmt->close();

    // 4. Final Balance Check (backend security)
    if ($current_balance < $total_amount) {
        throw new Exception("Insufficient funds. Please add balance to your wallet.");
    }

    // 5. Deduct Balance
    $new_balance = $current_balance - $total_amount;
    $stmt = $conn->prepare("UPDATE wallets SET balance = ? WHERE user_id = ?");
    // Use 'd' for double/float and 'i' for integer
    $stmt->bind_param("di", $new_balance, $user_id); 
    $stmt->execute();
    $stmt->close();

    // 6. Create Main Order Record
    // Set initial status, e.g., 'Processing'
    $order_status = 'Processing';
    $sql = "INSERT INTO orders (user_id, total_amount, order_type, delivery_address, special_notes, status) 
            VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    // Data Types: i, d, s, s, s, s
    $stmt->bind_param("idssss", 
        $user_id, 
        $total_amount, 
        $order_type, 
        $delivery_address, 
        $special_notes, 
        $order_status
    );
    $stmt->execute();
    $order_id = $conn->insert_id;
    $stmt->close();

    // 7. Insert Order Items
    $item_sql = "INSERT INTO order_items (order_id, menu_id, name, price, quantity) VALUES (?, ?, ?, ?, ?)";
    $item_stmt = $conn->prepare($item_sql);
    
    // Assumes cart items have id, name, price, quantity
    foreach ($cart_items as $item) {
        $menu_id = $item['id'] ?? null;
        $name = $item['name'] ?? 'Unknown Item';
        $price = floatval($item['price'] ?? 0);
        $quantity = intval($item['quantity'] ?? 1);
        
        // Data Types: i, i, s, d, i
        $item_stmt->bind_param("iisdi", $order_id, $menu_id, $name, $price, $quantity);
        $item_stmt->execute();
    }
    $item_stmt->close();

    // 8. Log the Payment Transaction
    $transaction_description = "Order Payment #{$order_id}";
    $transaction_type = 'debit';
    // Amount is positive for the transaction log, the type ('debit') indicates deduction
    $stmt = $conn->prepare(
        "INSERT INTO wallet_transactions (user_id, amount, type, description) 
        VALUES (?, ?, ?, ?)"
    );
    // Use the ABSOLUTE value of the total amount for the log
    $stmt->bind_param("idss", $user_id, $total_amount, $transaction_type, $transaction_description);
    $stmt->execute();
    $stmt->close();

    // 9. Finalize Transaction
    $conn->commit(); 

    echo json_encode([
        'success' => true,
        'message' => 'Order placed and payment successful!',
        'order_id' => $order_id,
        'new_balance' => number_format($new_balance, 2, '.', '')
    ]);

} catch (Exception $e) {
    // 10. Rollback on Failure
    $conn->rollback(); 
    error_log("Checkout Failed for user_id {$user_id}: " . $e->getMessage());
    http_response_code(500); // Internal Server Error for security
    
    echo json_encode([
        'success' => false,
        'message' => 'Order processing failed: ' . $e->getMessage()
    ]);

} finally {
    // 11. Close Connection
    if (isset($conn)) $conn->close();
}
?>