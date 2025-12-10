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
// GET ORDER HISTORY & SUMMARY 
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

        // SQL for recent orders (Uses LEFT JOIN to be forgiving)
        $history_stmt = $conn->prepare(
            "SELECT 
                o.id, 
                o.order_date, 
                o.total_amount, 
                o.status,
                GROUP_CONCAT(CONCAT(oi.quantity, 'x ', IFNULL(p.name, oi.item_name)) SEPARATOR ', ') AS items_summary
             FROM orders o
             JOIN order_items oi ON o.id = oi.order_id
             LEFT JOIN products p ON oi.product_id = p.id 
             WHERE o.user_id = ?
             GROUP BY o.id
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
        
        // Ensure amount is formatted consistently as a string for display
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