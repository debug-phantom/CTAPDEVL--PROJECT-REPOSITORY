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

// Start a transaction to ensure all or nothing
$conn->begin_transaction();

try {
    $stmt = $conn->prepare("SELECT balance FROM wallet WHERE user_id = ? FOR UPDATE");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $wallet_data = $result->fetch_assoc();
    $stmt->close();
    
    $current_balance = (float)($wallet_data['balance'] ?? 0);
    
    if ($current_balance < $total_amount) {
        $conn->rollback();
        // **FIX: Currency updated to ₱ in error message**
        echo json_encode(["success" => false, "message" => "Insufficient funds. Required: ₱" . number_format($total_amount, 2)]);
        exit;
    }

    $new_balance = $current_balance - $total_amount;

    // --- 3. Create the Order Entry ---
    $stmt = $conn->prepare(
        "INSERT INTO orders (user_id, total_amount, order_type, delivery_address, special_notes) 
        VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->bind_param("idsss", $user_id, $total_amount, $order_type, $delivery_address, $special_notes);
    $stmt->execute();
    $order_id = $conn->insert_id;
    $stmt->close();

    // --- 4. Insert Order Items ---
    $item_sql = "INSERT INTO order_items (order_id, item_name, price_at_order, quantity) VALUES (?, ?, ?, ?)";
    $stmt = $conn->prepare($item_sql);
    
    foreach ($cart_items as $item) {
        $item_name = $item['name'];
        $item_price = floatval($item['price']);
        $item_quantity = (int)$item['quantity'];

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
    
    // **FIX: Currency added to transaction description for logging**
    $description = "Order Payment #{$order_id} (₱" . number_format($total_amount, 2) . ")";
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
    // If anything fails, rollback
    $conn->rollback(); 
    error_log("PROCESS_ORDER Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "An internal processing error occurred. Please try again."]);
}
?>