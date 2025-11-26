<?php
session_start();
header("Content-Type: application/json");

require_once "db.php";   // Contains the central $conn object
// require_once "auth.php"; // <--- REMOVED TO PREVENT CONFLICT

if (!isset($_SESSION["user_id"])) {
    http_response_code(401); // Unauthorized
    echo json_encode([
        "success" => false,
        "message" => "Authentication Required: Please log in to manage your wallet."
    ]);
    $conn->close();
    exit;
}

$user_id = $_SESSION["user_id"];
$input = json_decode(file_get_contents("php://input"), true);
$action = $input["action"] ?? "";

// ---------------------------------------------
// GET BALANCE
// ---------------------------------------------
if ($action === "get_balance") {

    $stmt = $conn->prepare("SELECT balance FROM wallet WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    
    if (!$stmt->execute()) {
        echo json_encode(["success" => false, "message" => "Database error retrieving balance."]);
        $conn->close();
        exit;
    }
    
    $result = $stmt->get_result();

    // If wallet row doesn't exist, create it
    if ($result->num_rows === 0) {
        $stmt_insert = $conn->prepare("INSERT INTO wallet (user_id, balance) VALUES (?, 0.00)");
        $stmt_insert->bind_param("i", $user_id);
        $stmt_insert->execute();
        $stmt_insert->close();
        
        echo json_encode(["success" => true, "balance" => "0.00"]);
        $conn->close();
        exit;
    }
    
    $balance = $result->fetch_assoc()['balance'];
    $stmt->close();

    echo json_encode([
        "success" => true,
        "balance" => $balance
    ]);
    $conn->close();
    exit;
}



// ---------------------------------------------
// ADD FUNDS
// ---------------------------------------------
if ($action === "add_funds") {

    $amount = floatval($input["amount"] ?? 0);

    if ($amount <= 0) {
        echo json_encode([
            "success" => false,
            "message" => "Invalid amount (must be positive)."
        ]);
        $conn->close();
        exit;
    }

    // Use transaction for safety
    $conn->begin_transaction();

    try {
        // Create wallet row if missing (safe via IGNORE)
        $conn->query("INSERT IGNORE INTO wallet (user_id, balance) VALUES ($user_id, 0.00)");

        // Update balance
        $stmt = $conn->prepare("UPDATE wallet SET balance = balance + ? WHERE user_id = ?");
        $stmt->bind_param("di", $amount, $user_id);
        $stmt->execute();
        $stmt->close();

        // Log transaction
        $stmt = $conn->prepare("
            INSERT INTO wallet_transactions (user_id, amount, type, description)
            VALUES (?, ?, 'credit', 'Funds Added')
        ");
        $stmt->bind_param("id", $user_id, $amount);
        $stmt->execute();
        $stmt->close();
        
        $conn->commit();
        
        echo json_encode([
            "success" => true,
            "message" => "Funds added successfully.",
            "added" => number_format($amount, 2, '.', '')
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        error_log("Wallet Add Funds Error: " . $e->getMessage()); 
        echo json_encode([
            "success" => false,
            "message" => "Transaction failed. Please try again later."
        ]);
    }

    $conn->close();
    exit;
}


// ---------------------------------------------
// INVALID ACTION
// ---------------------------------------------
echo json_encode([
    "success" => false,
    "message" => "Invalid action"
]);
$conn->close();
?>