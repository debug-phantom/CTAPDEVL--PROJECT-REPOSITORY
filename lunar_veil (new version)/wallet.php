<?php
session_start();
header("Content-Type: application/json");

// Enable exceptions to catch SQL errors
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Load central database connection (using the correct dbname: my_lunar_veil_cafe)
require_once "db.php"; 

$input = file_get_contents("php://input");
$data = json_decode($input, true);

$action = $data['action'] ?? null;
$amount = isset($data['amount']) ? floatval($data['amount']) : 0.00;

$user_id = $_SESSION['user_id'] ?? null; 

if (!$user_id) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Not logged in. Please log back in."]);
    if (isset($conn)) $conn->close();
    exit;
}

/**
 * Ensures a wallet row exists for the current user.
 */
function ensureWalletExists($conn, $user_id) {
    $stmt = $conn->prepare("SELECT user_id FROM wallets WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $stmt->close();
        $stmt = $conn->prepare("INSERT INTO wallets (user_id, balance) VALUES (?, 0.00)");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();
        return true;
    }
    $stmt->close();
    return false;
}

// =============================================
// GET WALLET BALANCE (FIX: Explicitly handles fetching the current balance)
// =============================================
if ($action === "get_balance") {
    try {
        ensureWalletExists($conn, $user_id); 
        
        $stmt = $conn->prepare("SELECT balance FROM wallets WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $wallet = $result->fetch_assoc();
        $stmt->close();

        $balance = $wallet ? floatval($wallet['balance']) : 0.00;
        
        echo json_encode(["success" => true, "balance" => number_format($balance, 2, '.', '')]);
        
    } catch (Exception $e) {
        error_log("Wallet balance error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Error fetching balance. (DB Error)"]);
    }
    if (isset($conn)) $conn->close();
    exit;
}

// =============================================
// GET WALLET HISTORY 
// =============================================
if ($action === "get_wallet_history") {
    try {
        $stmt = $conn->prepare(
            "SELECT transaction_date, amount, type, description 
             FROM wallet_transactions 
             WHERE user_id = ? 
             ORDER BY transaction_date DESC 
             LIMIT 15"
        );
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $transactions = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        echo json_encode([
            "success" => true,
            "wallet_transactions" => $transactions
        ]);

    } catch (Exception $e) {
        error_log("Wallet history fetch failed: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Could not load wallet history. (Server Error)"]);
    }
    if (isset($conn)) $conn->close();
    exit;
}

// =============================================
// ADD FUNDS
// =============================================
if ($action === "add_funds") {
    
    if ($amount <= 0) {
        echo json_encode(["success" => false, "message" => "Amount must be greater than zero."]);
        if (isset($conn)) $conn->close();
        exit;
    }

    $conn->begin_transaction(); 

    try {
        ensureWalletExists($conn, $user_id);
        
        $stmt = $conn->prepare("SELECT balance FROM wallets WHERE user_id = ? FOR UPDATE");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $wallet = $result->fetch_assoc();
        $stmt->close();
        
        $current_balance = floatval($wallet['balance']);
        $new_balance = $current_balance + $amount;

        $stmt = $conn->prepare("UPDATE wallets SET balance = ? WHERE user_id = ?");
        $stmt->bind_param("di", $new_balance, $user_id); 
        $stmt->execute();
        $stmt->close();

        $description = "Funds added";
        $stmt = $conn->prepare(
            "INSERT INTO wallet_transactions 
            (user_id, amount, type, description) 
            VALUES (?, ?, 'credit', ?)"
        );
        $stmt->bind_param("ids", $user_id, $amount, $description);
        $stmt->execute();
        $stmt->close();

        $conn->commit(); 

        echo json_encode([
            "success" => true,
            "newBalance" => number_format($new_balance, 2, '.', '')
        ]);

    } catch (Exception $e) {
        $conn->rollback(); 
        error_log("Add Funds Failed for user_id {$user_id}: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Failed to add funds. Please try again. (Server Error)" 
        ]);
    }
    
    if (isset($conn)) $conn->close();
    exit;
}

// DEFAULT
if (isset($conn)) $conn->close();
echo json_encode(["success" => false, "message" => "Invalid action specified."]);
?>