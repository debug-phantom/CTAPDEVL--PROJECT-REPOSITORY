<?php
// checkout.php - FIXED VERSION

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

// 2. Input Validation and Data Extraction
$cart_items = $data['cart_items'] ?? [];
$order_type = $data['order_type'] ?? 'Pickup';
$delivery_address = $data['delivery_address'] ?? null;
$special_notes = $data['special_notes'] ?? '';
$payment_method = $data['payment_method'] ?? 'Wallet';

// Calculate amounts
$subtotal_amount = 0;
foreach ($cart_items as $item) {
    $price = floatval($item['price'] ?? 0);
    $quantity = intval($item['quantity'] ?? 0);
    $subtotal_amount += ($price * $quantity);
}

// Tax calculation (12% VAT in Philippines)
$tax_rate = 0.12;
$tax_amount = $subtotal_amount * $tax_rate;

// Delivery fee (if delivery order)
$delivery_fee = ($order_type === 'Delivery') ? 50.00 : 0.00;

// Total amount
$total_amount = $subtotal_amount + $tax_amount + $delivery_fee;

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
    // 3. Check and Update Wallet Balance
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

    // 4. Create the Order (FIXED: Using correct column names)
    $stmt = $conn->prepare(
        "INSERT INTO orders (
            user_id, 
            total_amount, 
            subtotal_amount, 
            tax_amount, 
            tax_rate, 
            delivery_fee, 
            order_type, 
            delivery_address, 
            payment_method,
            status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')"
    );
    $stmt->bind_param(
        "idddddsss", 
        $user_id, 
        $total_amount, 
        $subtotal_amount, 
        $tax_amount, 
        $tax_rate, 
        $delivery_fee, 
        $order_type, 
        $delivery_address, 
        $payment_method
    );
    $stmt->execute();
    $order_id = $conn->insert_id;
    $stmt->close();

    // 5. Insert Order Items (FIXED: Using correct column names)
    $item_stmt = $conn->prepare(
        "INSERT INTO order_items (order_id, product_id, quantity, price_at_purchase) 
         VALUES (?, ?, ?, ?)"
    );

    foreach ($cart_items as $item) {
        $product_id = (int)($item['id'] ?? 0);
        $quantity = (int)($item['quantity'] ?? 0);
        $price = floatval($item['price'] ?? 0);

        if ($product_id <= 0 || $quantity <= 0) {
            throw new Exception("Invalid cart item data");
        }

        $item_stmt->bind_param("iiid", $order_id, $product_id, $quantity, $price);
        $item_stmt->execute();
    }
    $item_stmt->close();

    // 6. Log the Payment Transaction
    $transaction_description = "Order Payment #" . $order_id . " (₱" . number_format($total_amount, 2) . ")";
    $transaction_type = 'debit';
    
    $stmt = $conn->prepare(
        "INSERT INTO wallet_transactions (user_id, amount, type, description) 
        VALUES (?, ?, ?, ?)"
    );
    $stmt->bind_param("idss", $user_id, $total_amount, $transaction_type, $transaction_description);
    $stmt->execute();
    $stmt->close();

    // 7. Finalize Transaction
    $conn->commit(); 

    echo json_encode([
        'success' => true,
        'message' => 'Order placed and payment successful!',
        'order_id' => $order_id,
        'new_balance' => number_format($new_balance, 2, '.', ''),
        'breakdown' => [
            'subtotal' => number_format($subtotal_amount, 2, '.', ''),
            'tax' => number_format($tax_amount, 2, '.', ''),
            'delivery_fee' => number_format($delivery_fee, 2, '.', ''),
            'total' => number_format($total_amount, 2, '.', '')
        ]
    ]);

} catch (Exception $e) {
    // 8. Rollback on Failure
    $conn->rollback(); 
    error_log("Checkout Failed for user_id: {$user_id}. Error: " . $e->getMessage());
    http_response_code(500); 
    echo json_encode([
        'success' => false, 
        'message' => 'A fatal error occurred during checkout: ' . $e->getMessage()
    ]);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>