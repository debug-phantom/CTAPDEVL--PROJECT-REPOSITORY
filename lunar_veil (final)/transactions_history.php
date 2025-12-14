<?php
session_start();
header("Content-Type: application/json");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once "db.php"; 

$user_id = $_SESSION['user_id'] ?? null;

$input = file_get_contents("php://input");
$data = json_decode($input, true);
$action = $data['action'] ?? null;

if (!$user_id) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Not logged in."]);
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

        // Ensure amount is formatted for display
        $formatted_transactions = array_map(function($tx) {
            $tx['amount'] = number_format(floatval($tx['amount']), 2, '.', '');
            return $tx;
        }, $transactions);

        echo json_encode([
            "success" => true,
            "wallet_transactions" => $formatted_transactions
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
// ADMIN: GET ALL ORDER HISTORY
// =============================================
if ($action === "get_all_orders") {
    if ($user_id != 1) { // Simple check for admin ID 1
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "Access denied. Admin privileges required."]);
        if (isset($conn)) $conn->close();
        exit;
    }

    try {
        $history_stmt = $conn->prepare(
            "SELECT 
                o.id, 
                o.user_id,
                o.order_date, 
                o.total_amount, 
                o.status,
                GROUP_CONCAT(CONCAT(oi.quantity, 'x ', IFNULL(p.name, oi.item_name)) SEPARATOR ', ') AS items_summary
             FROM orders o
             JOIN order_items oi ON o.id = oi.order_id
             LEFT JOIN products p ON oi.product_id = p.id 
             GROUP BY o.id
             ORDER BY o.order_date DESC
             LIMIT 50" 
        );
        $history_stmt->execute();
        $history_result = $history_stmt->get_result();
        $all_orders = $history_result->fetch_all(MYSQLI_ASSOC);
        $history_stmt->close();

        if (!$all_orders) {
             $all_orders = []; 
        }

        $formatted_orders = array_map(function($order) {
            $order['total_amount'] = number_format(floatval($order['total_amount']), 2, '.', '');
            return $order;
        }, $all_orders);

        echo json_encode([
            "success" => true,
            "orders" => $formatted_orders
        ]);

    } catch (Exception $e) {
        error_log("Admin order fetch failed: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Error loading all orders. (Server Error)"]);
    }
    if (isset($conn)) $conn->close();
    exit;
}

// =============================================
// ORDER CANCELLATION (User initiated)
// =============================================
if ($action === "cancel_order") {
    $order_id = $data['order_id'] ?? null;
    if (!$order_id) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Missing Order ID."]);
        if (isset($conn)) $conn->close();
        exit;
    }

    $conn->begin_transaction();
    try {
        // 1. Fetch order details (check status, get total_amount, verify user ownership)
        $stmt = $conn->prepare(
            "SELECT total_amount, status, user_id FROM orders WHERE id = ? AND user_id = ?"
        );
        $stmt->bind_param("ii", $order_id, $user_id); 
        $stmt->execute();
        $result = $stmt->get_result();
        $order = $result->fetch_assoc();
        $stmt->close();

        if (!$order) {
            throw new Exception("Order not found or you do not own this order.");
        }
        
        // Only allow cancellation of orders that are not yet delivered/cancelled
        if (in_array($order['status'], ['Delivered', 'Cancelled', 'ReadyForPickup'])) {
            throw new Exception("Order cannot be cancelled in status: " . $order['status']);
        }
        
        $refund_amount = floatval($order['total_amount']);

        // 2. Update order status to Cancelled
        $stmt = $conn->prepare("UPDATE orders SET status = 'Cancelled' WHERE id = ?");
        $stmt->bind_param("i", $order_id);
        $stmt->execute();
        
        if ($stmt->affected_rows === 0) {
            throw new Exception("Failed to update order status.");
        }
        $stmt->close();
        
        // 3. Perform Refund to the user's wallet
        // --- Wallet Refund Logic Start ---
        $w_stmt = $conn->prepare("SELECT balance FROM wallets WHERE user_id = ? FOR UPDATE");
        $w_stmt->bind_param("i", $order['user_id']); // Refund to the order's user_id
        $w_stmt->execute();
        $w_result = $w_stmt->get_result();
        $wallet = $w_result->fetch_assoc();
        $w_stmt->close();
        
        if (!$wallet) {
            throw new Exception("User wallet not found for refund.");
        }
        
        $current_balance = floatval($wallet['balance']);
        $new_balance = $current_balance + $refund_amount; 

        // Update balance
        $w_stmt = $conn->prepare("UPDATE wallets SET balance = ? WHERE user_id = ?");
        $w_stmt->bind_param("di", $new_balance, $order['user_id']); 
        $w_stmt->execute();
        $w_stmt->close();

        // Log transaction
        $refund_description = "Refund for Order #{$order_id} cancellation.";
        $w_stmt = $conn->prepare(
            "INSERT INTO wallet_transactions 
            (user_id, amount, type, description) 
            VALUES (?, ?, 'credit', ?)"
        );
        $w_stmt->bind_param("ids", $order['user_id'], $refund_amount, $refund_description);
        $w_stmt->execute();
        $w_stmt->close();
        // --- Wallet Refund Logic End ---

        $conn->commit(); 

        echo json_encode([
            "success" => true,
            "message" => "Order #{$order_id} cancelled and ₱" . number_format($refund_amount, 2) . " has been refunded to your wallet."
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        error_log("Order cancellation failed: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Cancellation failed: " . $e->getMessage()]);
    }
    if (isset($conn)) $conn->close();
    exit;
}

// =============================================
// GET ORDER HISTORY & SUMMARY (Used by tracking.html) - FIXED
// =============================================
if ($action === "get_order_history") {
    try {
        // SQL for summary
        $summary_stmt = $conn->prepare(
            "SELECT 
                COUNT(id) AS total_orders, 
                SUM(total_amount) AS total_spent
             FROM orders 
             WHERE user_id = ?"
        );
        $summary_stmt->bind_param("i", $user_id);
        $summary_stmt->execute();
        $summary_result = $summary_stmt->get_result();
        $summary = $summary_result->fetch_assoc();
        $summary_stmt->close();

        // SQL for recent orders - MODIFIED
        $history_stmt = $conn->prepare(
            "SELECT 
                o.id, 
                DATE(o.order_date) AS order_date, 
                o.order_type, /* ADDED */
                o.total_amount, 
                o.status,
                SUM(oi.quantity) AS total_item_count /* MODIFIED */
             FROM orders o
             JOIN order_items oi ON o.id = oi.order_id
             WHERE o.user_id = ?
             GROUP BY o.id, order_date, o.order_type, o.total_amount, o.status 
             ORDER BY o.order_date DESC
             LIMIT 6"
        );
        $history_stmt->bind_param("i", $user_id);
        $history_stmt->execute();
        $history_result = $history_stmt->get_result();
        $recent_orders = $history_result->fetch_all(MYSQLI_ASSOC);
        $history_stmt->close();

        if (!$recent_orders) {
             $recent_orders = []; 
        }

        // Ensure amount is formatted consistently as a string
        $formatted_orders = array_map(function($order) {
            $order['total_amount'] = number_format(floatval($order['total_amount']), 2, '.', '');
            return $order;
        }, $recent_orders);

        echo json_encode([
            "success" => true,
            "summary" => $summary,
            "recent_orders" => $formatted_orders
        ]);

    } catch (Exception $e) {
        error_log("Order history fetch failed: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Error loading summary. (Server Error)"]);
    }
    if (isset($conn)) $conn->close();
    exit;
}

// DEFAULT FALLBACK 
if (isset($conn)) $conn->close();
echo json_encode(["success" => false, "message" => "Invalid action specified."]);
?>