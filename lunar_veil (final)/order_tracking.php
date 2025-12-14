<?php
// order_tracking.php - FINAL WORKING CODE: Consolidated data fetching into one action for tracking.html

session_start();
header("Content-Type: application/json");

// 1. REQUIRE the database file which establishes the global $conn variable
require_once "db.php"; 

// Check for connection failure immediately after including db.php
if (!isset($conn) || $conn->connect_error) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Database connection failed. Please check db.php."]);
    exit();
}

$user_id = $_SESSION['user_id'] ?? null;
$input = file_get_contents("php://input");
$data = json_decode($input, true);
$action = $data['action'] ?? null;

// --- Security Check: Must be logged in ---
if (!$user_id) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Authentication required. Please log in to view order tracking."]);
    exit();
}

$response_data = ["success" => false, "message" => "An unknown error occurred."];

try {
    // Enable exceptions for prepared statements to catch SQL errors
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    switch ($action) {
        // CONSOLIDATED HANDLER: Fetches both the order history and the specific/latest order summary
        case 'get_tracking_data':
            $target_order_id = $data['target_order_id'] ?? null;
            $response_data = handleGetTrackingData($conn, $user_id, $target_order_id);
            break;
            
        case 'cancel_order':
            $order_id = $data['order_id'] ?? null;
            $response_data = handleCancelOrder($conn, $order_id, $user_id);
            break;

        default:
            http_response_code(400);
            $response_data = ["success" => false, "message" => "Invalid action specified."];
            break;
    }

} catch (\mysqli_sql_exception $e) {
    error_log("Order Tracking DB Error: " . $e->getMessage());
    http_response_code(500);
    $response_data = [
        "success" => false, 
        "message" => "Database Error: Details: " . $e->getMessage()
    ];
} catch (\Exception $e) {
    error_log("Order Tracking General Error: " . $e->getMessage());
    http_response_code(500);
    $response_data = ["success" => false, "message" => "Server Error: " . $e->getMessage()];
} finally {
    if (isset($conn) && $conn instanceof mysqli) $conn->close();
}

echo json_encode($response_data);


// ===============================================
// HANDLER FUNCTIONS
// ===============================================

/**
 * Fetches all orders (history) and the summary of a specific or latest order.
 */
function handleGetTrackingData($conn, $user_id, $target_order_id = null) {
    $response = ['success' => true, 'summary' => null, 'history' => []];

    // 1. Fetch History (All Orders)
    $history_sql = "
        SELECT 
            o.order_id AS id,       
            o.order_date, 
            o.status, 
            o.total_amount,
            COALESCE(GROUP_CONCAT(CONCAT(oi.quantity, 'x ', p.name) SEPARATOR ', '), 'No Items') AS items_summary
        FROM orders o               
        LEFT JOIN order_items oi ON o.order_id = oi.order_id   
        LEFT JOIN products p ON oi.product_id = p.product_id     
        WHERE o.user_id = ?
        GROUP BY o.order_id
        ORDER BY o.order_date DESC
    ";
    
    $stmt = $conn->prepare($history_sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $history = [];
    $latest_order_id = null;
    $latest_order_total = null;
    $latest_order_status = null;
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $row['total_amount'] = number_format($row['total_amount'], 2);
            
            // Capture the latest order details if no target is set
            if (!$latest_order_id) {
                $latest_order_id = $row['id'];
                $latest_order_total = $row['total_amount'];
                $latest_order_status = $row['status'];
            }
            // If the row matches the target ID, grab its details for the summary
            if ($row['id'] == $target_order_id) {
                 $latest_order_id = $row['id'];
                 $latest_order_total = $row['total_amount'];
                 $latest_order_status = $row['status'];
            }
            
            $history[] = $row;
        }
        $response['history'] = $history;
        $stmt->close();
    } else {
        $stmt->close();
        throw new \mysqli_sql_exception("Failed to fetch order history.");
    }
    
    // 2. Set Summary for the Latest/Targeted Order
    if ($latest_order_id) {
        // Use the collected data from the history fetch for the summary card
        $response['summary'] = [
            'id' => $latest_order_id,
            'status' => $latest_order_status,
            'total' => $latest_order_total, // Already formatted
        ];
    }
    
    return $response;
}


/**
 * Attempts to cancel an order only if it is in 'Pending' or 'Making' status.
 */
function handleCancelOrder($conn, $order_id, $user_id) {
    if (!$order_id) {
        http_response_code(400);
        return ["success" => false, "message" => "Missing Order ID."];
    }

    $allowed_statuses = ['Pending', 'Making'];
    $status_list = "'" . implode("','", $allowed_statuses) . "'";

    $sql = "UPDATE orders SET status = 'Cancelled', updated_at = NOW() WHERE order_id = ? AND user_id = ? AND status IN ($status_list)";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $order_id, $user_id);
    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        $stmt->close();
        return ["success" => true, "message" => "Order successfully cancelled."];
    } else {
        $stmt->close();
        
        // Check why the update failed (Order not found, or wrong status)
        $check_sql = "SELECT status FROM orders WHERE order_id = ? AND user_id = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("ii", $order_id, $user_id);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        $order_row = $result->fetch_assoc();
        $check_stmt->close();

        if ($order_row) {
            return ["success" => false, "message" => "Order cannot be cancelled. Current status is '{$order_row['status']}'."];
        } else {
            return ["success" => false, "message" => "Order not found."];
        }
    }
}
?>