<?php
session_start();
header("Content-Type: application/json");

// Enable exceptions to catch SQL errors
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Load central database connection (Assuming db.php exists and connects)
require_once "db.php"; 

// Read JSON body
$input = file_get_contents("php://input");
$data = json_decode($input, true);

// --- 1. Basic Validation ---
if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Authentication required."]);
    exit;
}

if (empty($data['cart_items']) || !isset($data['total_amount'])) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Invalid order data."]);
    exit;
}

$user_id = $_SESSION["user_id"];
$total_amount = floatval($data['total_amount']);
$order_type = $data['order_type'] ?? 'Pickup';
$delivery_address = $data['delivery_address'] ?? null;
$special_notes = $data['special_notes'] ?? null;
$cart_items = $data['cart_items'];


// --- 2. Check Wallet Balance & Transaction Start ---

// Start a transaction to ensure all or nothing happens
$conn->begin_transaction();
try {
    // 2a. Lock the wallet row and get the current balance
    $stmt = $conn->prepare("SELECT balance FROM wallets WHERE user_id = ? FOR UPDATE");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $wallet = $result->fetch_assoc();
    $stmt->close();

    $current_balance = floatval($wallet['balance']);

    if ($total_amount <= 0) {
        throw new Exception("Invalid order total.");
    }
    
    if ($current_balance < $total_amount) {
        throw new Exception("Insufficient funds in wallet.");
    }

    $new_balance = $current_balance - $total_amount;


    // --- 3. Insert Order Record ---

    $stmt = $conn->prepare(
        "INSERT INTO orders 
        (user_id, total_amount, order_type, delivery_address, special_notes, status) 
        VALUES (?, ?, ?, ?, ?, 'Pending')" // Default status is Pending
    );
    $stmt->bind_param("idsss", 
        $user_id, 
        $total_amount, 
        $order_type, 
        $delivery_address, 
        $special_notes
    );
    $stmt->execute();
    $order_id = $conn->insert_id;
    $stmt->close();

    // --- 4. Insert Order Items ---

    foreach ($cart_items as $item) {
        $item_name = $item['name'] ?? 'Unknown Item';
        $item_price = floatval($item['price'] ?? 0);
        $item_quantity = intval($item['quantity'] ?? 1);

        $stmt = $conn->prepare(
            "INSERT INTO order_items 
            (order_id, item_name, price, quantity) 
            VALUES (?, ?, ?, ?)"
        );
        $stmt->bind_param("isdi", 
            $order_id, 
            $item_name, 
            $item_price, 
            $item_quantity
        );
        $stmt->execute();
        $stmt->close();
    }

    // --- 5. Update Wallet Balance (DEBIT) ---

    $stmt = $conn->prepare("UPDATE wallets SET balance = ? WHERE user_id = ?");
    $stmt->bind_param("di", $new_balance, $user_id);
    $stmt->execute();
    $stmt->close();

    // --- 6. Record Wallet Transaction ---
    
    $description = "Order Payment #{$order_id}";
    $stmt = $conn->prepare(
        "INSERT INTO wallet_transactions 
        (user_id, amount, type, description) 
        VALUES (?, ?, 'debit', ?)"
    );
    $stmt->bind_param("ids", $user_id, $total_amount, $description);
    $stmt->execute();
    $stmt->close();


    // --- 7. Commit Transaction & Success ---
    $conn->commit();
    echo json_encode([
        "success" => true, 
        "message" => "Order successfully placed and wallet debited.",
        "order_id" => $order_id
    ]);

} catch (Exception $e) {
    // If anything fails, rollback the transaction
    $conn->rollback();
    error_log("Order processing failed for user_id: {$user_id}. Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "success" => false, 
        "message" => "Order processing failed: " . $e->getMessage()
    ]);
} finally {
    if (isset($conn)) $conn->close();
}
?>