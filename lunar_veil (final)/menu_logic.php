<?php
session_start();
header("Content-Type: application/json");

// Load central database connection
require_once "db.php"; 

// --- 1. Basic Validation ---
if (!isset($_SESSION["user_id"])) {
    http_response_code(401); 
    echo json_encode(["success" => false, "message" => "You must be logged in to place an order."]);
    $conn->close();
    exit;
}

$user_id = $_SESSION["user_id"];
$input = json_decode(file_get_contents("php://input"), true);
$action = $input["action"] ?? "";

if ($action !== 'place_order') {
    echo json_encode(["success" => false, "message" => "Invalid action."]);
    $conn->close();
    exit;
}

$cart_items = $input['cart_items'] ?? [];
$orderType = $input['order_type'] ?? 'Pickup'; // Default to Pickup
$deliveryAddress = $input['delivery_address'] ?? NULL;

if (empty($cart_items)) {
    echo json_encode(["success" => false, "message" => "Cart is empty."]);
    $conn->close();
    exit;
}

// --- 2. Calculate Total & Validate Product Prices ---
$orderTotal = 0;
$validatedItems = [];
$product_ids = array_column($cart_items, 'id');

// Fetch current prices from the database for security and integrity
$ids_list = implode(',', array_map('intval', $product_ids));
$product_prices = [];
if (!empty($ids_list)) {
    $price_query = $conn->query("SELECT id, price FROM products WHERE id IN ($ids_list) AND is_active = TRUE");
    while ($row = $price_query->fetch_assoc()) {
        $product_prices[$row['id']] = (float)$row['price'];
    }
}

foreach ($cart_items as $item) {
    $id = (int) $item['id'];
    $quantity = (int) $item['quantity'];

    if (!isset($product_prices[$id]) || $quantity <= 0) {
        // Validation failed
        echo json_encode(["success" => false, "message" => "Invalid item or quantity in cart."]);
        $conn->close();
        exit;
    }

    $price_at_order = $product_prices[$id];
    $orderTotal += $price_at_order * $quantity;
    
    $validatedItems[] = [
        'product_id' => $id,
        'quantity' => $quantity,
        'price_at_order' => $price_at_order
    ];
}

$orderTotal = round($orderTotal, 2); // Final total for the transaction

// --- 3. Start Transaction & Check Wallet Balance ---
$conn->begin_transaction();

try {
    // A. WALLET CHECK AND DEBIT
    $stmt = $conn->prepare("SELECT balance FROM wallet WHERE user_id = ? FOR UPDATE");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $wallet = $result->fetch_assoc();
    $stmt->close();

    $currentBalance = (float)($wallet['balance'] ?? 0);

    if ($currentBalance < $orderTotal) {
        $conn->rollback();
        // Currency change applied here
        echo json_encode(["success" => false, "message" => "Insufficient wallet balance to cover the order total of ₱" . number_format($orderTotal, 2) . "."]);
        $conn->close();
        exit;
    }

    $newBalance = $currentBalance - $orderTotal;
    
    // Update balance
    $stmt = $conn->prepare("UPDATE wallet SET balance = ? WHERE user_id = ?");
    $stmt->bind_param("di", $newBalance, $user_id);
    $stmt->execute();
    $stmt->close();
    
    // Log wallet transaction (DEBIT)
    $stmt = $conn->prepare("INSERT INTO wallet_transactions (user_id, amount, type, description) VALUES (?, ?, 'debit', ?)");
    // Currency change applied here
    $description = "Order Payment for ₱" . number_format($orderTotal, 2);
    $stmt->bind_param("ids", $user_id, $orderTotal, $description);
    $stmt->execute();
    $stmt->close();

    // B. ORDER INSERTION
    $order_sql = "INSERT INTO orders (user_id, total_amount, order_type, delivery_address) VALUES (?, ?, ?, ?)";
    $stmt = $conn->prepare($order_sql);
    $stmt->bind_param("idss", $user_id, $orderTotal, $orderType, $deliveryAddress);
    $stmt->execute();
    $order_id = $conn->insert_id; // Get the ID of the new order
    $stmt->close();

    // C. ORDER ITEMS INSERTION
    $item_sql = "INSERT INTO order_items (order_id, product_id, quantity, price_at_order) VALUES (?, ?, ?, ?)";
    $stmt = $conn->prepare($item_sql);

    foreach ($validatedItems as $item) {
        $stmt->bind_param(
            "iiid", 
            $order_id, 
            $item['product_id'], 
            $item['quantity'], 
            $item['price_at_order']
        );
        $stmt->execute();
    }
    $stmt->close();

    // D. COMMIT TRANSACTION
    $conn->commit();

    // E. Fetch new balance for response (Re-fetch for absolute confirmation)
    $stmt = $conn->prepare("SELECT balance FROM wallet WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->bind_result($finalNewBalance);
    $stmt->fetch();
    $stmt->close();

    // Success response
    echo json_encode([
        "success" => true,
        "message" => "Order successfully placed and paid!",
        "newBalance" => $finalNewBalance,
        "orderId" => $order_id
    ]);

} catch (Exception $e) {
    $conn->rollback();
    error_log("Order Placement Error: " . $e->getMessage()); 
    echo json_encode([
        "success" => false,
        "message" => "An internal error occurred during payment processing. Please try again."
    ]);
}

$conn->close();
?>