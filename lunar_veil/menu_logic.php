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
$id_string = implode(',', array_map('intval', $product_ids));

if (empty($id_string)) {
    echo json_encode(["success" => false, "message" => "No valid items found in cart."]);
    $conn->close();
    exit;
}

// Fetch current prices from the database for security
$price_result = $conn->query("SELECT id, price FROM products WHERE id IN ($id_string)");

if (!$price_result) {
    echo json_encode(["success" => false, "message" => "Error retrieving product prices."]);
    $conn->close();
    exit;
}

$dbPrices = [];
while ($row = $price_result->fetch_assoc()) {
    $dbPrices[$row['id']] = floatval($row['price']);
}

// Calculate total using database prices
foreach ($cart_items as $item) {
    $product_id = intval($item['id']);
    $quantity = intval($item['quantity']);

    if (isset($dbPrices[$product_id]) && $quantity > 0) {
        $price = $dbPrices[$product_id];
        $orderTotal += $price * $quantity;
        
        $validatedItems[] = [
            'product_id' => $product_id,
            'quantity' => $quantity,
            'price_at_order' => $price
        ];
    }
}

if ($orderTotal <= 0 || empty($validatedItems)) {
    echo json_encode(["success" => false, "message" => "Order total is zero or cart validation failed."]);
    $conn->close();
    exit;
}


// --- 3. Start Transaction: Payment and Order Insertion ---
$conn->begin_transaction();
$useWallet = true; 

try {
    // A. WALLET PAYMENT 
    if ($useWallet) {
        
        // 1. Get wallet balance
        $stmt = $conn->prepare("SELECT balance FROM wallet WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->bind_result($currentBalance);
        $stmt->fetch();
        $stmt->close();
    
        // 2. Check if enough balance
        if ($currentBalance < $orderTotal) {
            $conn->rollback();
            echo json_encode([
                "success" => false,
                "message" => "Insufficient wallet balance. Current: $" . number_format($currentBalance, 2)
            ]);
            $conn->close();
            exit;
        }
    
        // 3. Deduct wallet
        $stmt = $conn->prepare("
            UPDATE wallet 
            SET balance = balance - ?
            WHERE user_id = ?
        ");
        $stmt->bind_param("di", $orderTotal, $user_id);
        $stmt->execute();
        $stmt->close();

        // 4. LOG TRANSACTION (debit)
        $stmt = $conn->prepare("
            INSERT INTO wallet_transactions (user_id, amount, type, description)
            VALUES (?, ?, 'debit', 'Food Order Payment')
        ");
        // *** FIX: amount is logged as a positive value when type is 'debit' ***
        $stmt->bind_param("id", $user_id, $orderTotal); 
        $stmt->execute();
        $stmt->close();
    }
    
    // B. ORDER INSERTION (Requires delivery_address column in 'orders' table)
    $stmt = $conn->prepare("
        INSERT INTO orders (user_id, total_amount, order_type, delivery_address) 
        VALUES (?, ?, ?, ?)
    ");
    $stmt->bind_param("idss", $user_id, $orderTotal, $orderType, $deliveryAddress);
    $stmt->execute();
    $order_id = $conn->insert_id;
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

    // E. Fetch new balance for response
    $stmt = $conn->prepare("SELECT balance FROM wallet WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->bind_result($newBalance);
    $stmt->fetch();
    $stmt->close();

    // Success response
    echo json_encode([
        "success" => true,
        "message" => "Order successfully placed and paid!",
        "newBalance" => $newBalance
    ]);

} catch (Exception $e) {
    $conn->rollback();
    error_log("Order Checkout Error: " . $e->getMessage()); 
    // This message is only shown if a database error occurs *after* the transaction begins
    echo json_encode([
        "success" => false,
        "message" => "An internal server error occurred during payment processing."
    ]);
}

$conn->close();
?>