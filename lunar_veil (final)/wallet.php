<?php
// wallet.php - FIXED: Table name corrected to 'wallet' (singular)

session_start();
header("Content-Type: application/json");

// Enable exceptions to catch SQL errors
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Load central database connection
require_once "db.php"; 

$input = file_get_contents("php://input");
$data = json_decode($input, true);

$action = $data['action'] ?? null;
$amount = isset($data['amount']) ? floatval($data['amount']) : 0.00;
$description = $data['description'] ?? 'Wallet Transaction'; 

$user_id = $_SESSION['user_id'] ?? null; 

if (!$user_id) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Not logged in. Please log back in."]);
    if (isset($conn)) $conn->close();
    exit;
}

/**
 * Ensures a wallet row exists for the current user.
 * FIXED: Changed 'wallets' to 'wallet'
 */
function ensureWalletExists($conn, $user_id) {
    $stmt = $conn->prepare("SELECT user_id FROM wallet WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $stmt->close();
        $stmt = $conn->prepare("INSERT INTO wallet (user_id, balance) VALUES (?, 0.00)");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
    }
    $stmt->close();
}

try {
    ensureWalletExists($conn, $user_id);

    // Start transaction for all operations that modify the balance
    if (in_array($action, ['add_funds', 'withdraw_funds', 'process_payment'])) {
        $conn->begin_transaction();
    }

    switch ($action) {
        case 'get_balance':
            // Fetch username from session
            $userName = $_SESSION['username'] ?? "Customer"; 
            
            $stmt = $conn->prepare("SELECT balance FROM wallet WHERE user_id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $wallet = $result->fetch_assoc();
            $stmt->close();

            echo json_encode([
                "success" => true,
                "balance" => number_format(floatval($wallet['balance']), 2, '.', ''),
                "userName" => $userName 
            ]);
            break;

        // NOTE: get_wallet_history logic is now handled by transactions_history.php
        
        case 'add_funds':
            if ($amount <= 0) throw new Exception("Amount must be greater than zero.");

            $stmt = $conn->prepare("SELECT balance FROM wallet WHERE user_id = ? FOR UPDATE");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $wallet = $result->fetch_assoc();
            $stmt->close();
            
            $current_balance = floatval($wallet['balance']);
            $new_balance = $current_balance + $amount;

            $stmt = $conn->prepare("UPDATE wallet SET balance = ? WHERE user_id = ?");
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
            break;

        case 'withdraw_funds':
            if ($amount <= 0) throw new Exception("Amount must be greater than zero.");

            $stmt = $conn->prepare("SELECT balance FROM wallet WHERE user_id = ? FOR UPDATE");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $wallet = $result->fetch_assoc();
            $stmt->close();
            
            $current_balance = floatval($wallet['balance']);

            if ($current_balance < $amount) {
                $conn->rollback();
                echo json_encode(["success" => false, "message" => "Insufficient balance."]);
                break;
            }
            
            $new_balance = $current_balance - $amount;

            $stmt = $conn->prepare("UPDATE wallet SET balance = ? WHERE user_id = ?");
            $stmt->bind_param("di", $new_balance, $user_id); 
            $stmt->execute();
            $stmt->close();

            $description = "Funds withdrawn";
            $stmt = $conn->prepare(
                "INSERT INTO wallet_transactions 
                (user_id, amount, type, description) 
                VALUES (?, ?, 'debit', ?)"
            );
            $stmt->bind_param("ids", $user_id, $amount, $description);
            $stmt->execute();
            $stmt->close();

            $conn->commit();

            echo json_encode([
                "success" => true,
                "newBalance" => number_format($new_balance, 2, '.', '')
            ]);
            break;

        case 'process_payment':
            if ($amount <= 0) throw new Exception("Payment amount must be greater than zero.");
            
            $stmt = $conn->prepare("SELECT balance FROM wallet WHERE user_id = ? FOR UPDATE");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $wallet = $result->fetch_assoc();
            $stmt->close();
            
            $current_balance = floatval($wallet['balance']);

            if ($current_balance < $amount) {
                $conn->rollback();
                http_response_code(402); 
                echo json_encode(["success" => false, "message" => "Insufficient funds for this payment."]);
                break;
            }
            
            $new_balance = $current_balance - $amount;

            $stmt = $conn->prepare("UPDATE wallet SET balance = ? WHERE user_id = ?");
            $stmt->bind_param("di", $new_balance, $user_id); 
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare(
                "INSERT INTO wallet_transactions 
                (user_id, amount, type, description) 
                VALUES (?, ?, 'debit', ?)"
            );
            $stmt->bind_param("ids", $user_id, $amount, $description);
            $stmt->execute();
            $transactionId = $conn->insert_id; 
            $stmt->close();

            $conn->commit();

            echo json_encode([
                "success" => true,
                "newBalance" => number_format($new_balance, 2, '.', ''),
                "transactionId" => $transactionId 
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Invalid action."]);
            break;
    }

} catch (mysqli_sql_exception $e) {
    if (isset($conn) && in_array($action, ['add_funds', 'withdraw_funds', 'process_payment'])) {
        $conn->rollback();
    }
    http_response_code(500);
    error_log("Wallet SQL Error ({$action}): " . $e->getMessage());
    echo json_encode([
        "success" => false,
        "message" => "A database error occurred. Ensure 'wallet' and 'wallet_transactions' tables exist and columns match."
    ]);
} catch (Exception $e) {
    if (isset($conn) && in_array($action, ['add_funds', 'withdraw_funds', 'process_payment'])) {
        $conn->rollback();
    }
    http_response_code(500);
    error_log("Wallet Processing Error ({$action}): " . $e->getMessage());
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
} finally {
    if (isset($conn)) $conn->close();
}
?>