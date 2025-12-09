<?php
session_start();
header("Content-Type: application/json");

// Enable exceptions to catch SQL errors
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Load central database connection
require_once "db.php"; 

// --- 1. Basic Validation ---
if (!isset($_SESSION["user_id"])) {
    http_response_code(401); // Unauthorized
    echo json_encode(["success" => false, "message" => "You must be logged in to track orders."]);
    if (isset($conn)) $conn->close(); 
    exit;
}

$user_id = $_SESSION["user_id"];
$input = json_decode(file_get_contents("php://input"), true);
$action = $input["action"] ?? "latest"; // Default to fetching latest order

$latestOrder = null;
$history = [];

try {
    // =============================================
    // I. ACTION: FETCH LATEST ORDER (For Homepage & Tracking Status)
    // =============================================
    if ($action === 'latest') {
        // Find the ID of the most recent order
        $stmt = $conn->prepare("SELECT id FROM orders WHERE user_id = ? ORDER BY order_date DESC LIMIT 1");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            $stmt->close();
            echo json_encode(["success" => true, "message" => "No recent orders found."]);
            $conn->close();
            exit;
        }

        $row = $result->fetch_assoc();
        $latest_order_id = $row['id'];
        $stmt->close();

        // Fetch the details of that latest order, including a summary of items
        $sql = "
            SELECT 
                o.id as order_id, 
                o.order_date, 
                o.total_amount, 
                o.order_type, 
                o.status,
                o.delivery_address,
                GROUP_CONCAT(CONCAT(oi.quantity, 'x ', p.name) SEPARATOR '; ') as items_summary
            FROM orders o
            JOIN order_items oi ON o.id = oi.order_id
            JOIN products p ON oi.product_id = p.id
            WHERE o.id = ?
            GROUP BY o.id
        ";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $latest_order_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $latestOrder = $result->fetch_assoc();
            // Format for display
            $latestOrder['total_amount'] = number_format((float)$latestOrder['total_amount'], 2, '.', ''); 
            $latestOrder['order_date'] = (new DateTime($latestOrder['order_date']))->format('M d, Y h:i A');
        }
        $stmt->close();

        echo json_encode([
            "success" => true,
            "latestOrder" => $latestOrder
        ]);
    }
    
    // =============================================
    // II. ACTION: FETCH ORDER HISTORY (For Tracking History List)
    // =============================================
    if ($action === 'history') {
        $summary = [
            'total_orders' => 0,
            'total_spent' => 0.00
        ];
        
        // Calculate total orders and total spent
        $stmt = $conn->prepare("SELECT COUNT(id) as total_orders, SUM(total_amount) as total_spent FROM orders WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $summary['total_orders'] = (int)$row['total_orders'];
            $summary['total_spent'] = number_format((float)$row['total_spent'], 2, '.', '');
        }
        $stmt->close();

        // Fetch the history of orders with associated items (limiting to 20 recent orders)
        $sql = "
            SELECT 
                o.id as order_id, 
                o.order_date, 
                o.total_amount, 
                o.order_type, 
                o.status,
                GROUP_CONCAT(CONCAT(oi.quantity, 'x ', p.name) SEPARATOR '; ') as items_summary
            FROM orders o
            JOIN order_items oi ON o.id = oi.order_id
            JOIN products p ON oi.product_id = p.id
            WHERE o.user_id = ?
            GROUP BY o.id
            ORDER BY o.order_date DESC
            LIMIT 20
        ";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $row['total_amount'] = number_format((float)$row['total_amount'], 2, '.', '');
            // Format date for better display
            $row['order_date'] = (new DateTime($row['order_date']))->format('M d, Y h:i A');
            $history[] = $row;
        }
        $stmt->close();

        echo json_encode([
            "success" => true,
            "summary" => $summary,
            "history" => $history
        ]);
    }

} catch (mysqli_sql_exception $e) {
    http_response_code(500); 
    error_log("Order Tracking SQL Error: " . $e->getMessage());
    echo json_encode(["success" => false, "message" => "A server error occurred during order processing. Please check logs."]);
} catch (Exception $e) {
    http_response_code(500); 
    error_log("Order Tracking General Error: " . $e->getMessage());
    echo json_encode(["success" => false, "message" => "An unexpected error occurred."]);
} finally {
    if (isset($conn)) $conn->close();
    exit;
}
?>