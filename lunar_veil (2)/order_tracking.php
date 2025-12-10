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
$action = $input["action"] ?? "latest"; 

$summary = null;
$history = [];

try {
    // =============================================
    // II. ACTION: FETCH LATEST ORDER AND FULL HISTORY
    // =============================================
    if ($action === 'latest') {
        
        // 1. Fetch ALL Order History with a single query
        $sql = "
            SELECT 
                o.id, 
                o.order_date, 
                o.total_amount, 
                o.status,
                GROUP_CONCAT(CONCAT(oi.quantity, 'x ', p.name) SEPARATOR ', ') AS items_summary
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
            
            // The first row is the latest order, which serves as the summary
            if ($summary === null) {
                $summary = [
                    'id' => $row['id'],
                    'status' => $row['status'],
                    'total_amount' => $row['total_amount'],
                    'items_summary' => $row['items_summary'],
                    'order_date' => $row['order_date']
                ];
            }
            
            $history[] = $row;
        }
        $stmt->close();
        
        // Final Response
        echo json_encode([
            "success" => true,
            "summary" => $summary,
            "history" => $history
        ]);
        
    } else {
        echo json_encode(["success" => false, "message" => "Invalid action specified."]);
    }

} catch (mysqli_sql_exception $e) {
    http_response_code(500); 
    error_log("Order Tracking SQL Error: " . $e->getMessage());
    echo json_encode(["success" => false, "message" => "A server error occurred during order tracking. Please check server logs."]);
} catch (Exception $e) {
    http_response_code(500); 
    error_log("Order Tracking Error: " . $e->getMessage());
    echo json_encode(["success" => false, "message" => "An unexpected error occurred."]);
} finally {
    if (isset($conn)) $conn->close();
}
?>