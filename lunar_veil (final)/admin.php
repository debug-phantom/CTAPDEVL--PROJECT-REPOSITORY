<?php
// admin.php - FINAL CONFIRMED CODE: All SQL column and key names resolved.

// Force all errors to display immediately for debugging (Optional, but good practice)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
header("Content-Type: application/json");

// 1. REQUIRE the database file
require_once "db.php"; 

$user_id = $_SESSION['user_id'] ?? null;
$user_role_id = $_SESSION['role'] ?? 0; 
// CRITICAL: Ensure file_get_contents is working correctly
$input_raw = file_get_contents('php://input'); 
$input = json_decode($input_raw, true);       
$action = $input['action'] ?? '';

// ===============================================
// ACCESS CONTROL CHECK
// ===============================================

if ($user_id === null || $user_role_id === 0 || $user_role_id === 3) {
    http_response_code(403);
    echo json_encode([
        'success' => false, 
        'message' => 'Access Denied. You must be authenticated with Staff privileges or higher.'
    ]);
    exit();
}

$response_data = ["success" => false, "message" => "A server or database error occurred. Check server logs for details."];

try {
    // Enable exceptions for prepared statements
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    switch ($action) {
        case 'get_all_orders':
            $response_data = handleGetAllOrders($conn);
            break;
            
        case 'update_order_status':
            $order_id = $input['order_id'] ?? null;
            $new_status = $input['new_status'] ?? null;
            $response_data = handleUpdateOrderStatus($conn, $order_id, $new_status);
            break;

        case 'get_order_details':
            $order_id = $input['order_id'] ?? null;
            $response_data = handleGetOrderDetails($conn, $order_id);
            break;

        default:
            // This is the error you are seeing. It means $action was empty or invalid.
            http_response_code(400);
            $response_data = ['success' => false, 'message' => 'Invalid action specified.'];
            break;
    }
    
} catch (\mysqli_sql_exception $e) {
    error_log("Admin DB Error: " . $e->getMessage());
    http_response_code(500);
    $response_data = [
        "success" => false, 
        "message" => "Database Error: Details: " . $e->getMessage()
    ];
} catch (\Exception $e) {
    error_log("Admin General Error: " . $e->getMessage());
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
 * Fetches all recent orders, grouped by ID, with item summary and customer name.
 */
function handleGetAllOrders($conn) {
    $sql = "
        SELECT 
            o.order_id AS id,       
            o.user_id,
            o.order_date, 
            o.status, 
            o.total_amount,
            u.name AS customer,     /* Customer name aliased as 'customer' */
            COALESCE(GROUP_CONCAT(CONCAT(oi.quantity, 'x ', p.name) SEPARATOR ', '), 'No Items') AS items_summary
        FROM orders o               
        JOIN users u ON o.user_id = u.id              
        LEFT JOIN order_items oi ON o.order_id = oi.order_id   
        LEFT JOIN products p ON oi.product_id = p.product_id     
        GROUP BY o.order_id                                     
        ORDER BY o.order_date DESC
        LIMIT 50
    ";
    
    $result = $conn->query($sql);
    $orders = [];

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $row['total_amount'] = number_format($row['total_amount'], 2); 
            $orders[] = $row;
        }
        return ['success' => true, 'orders' => $orders];
    } else {
        throw new \mysqli_sql_exception("Failed to execute order fetching query.");
    }
}

/**
 * Fetches comprehensive details for a specific order, including all items.
 */
function handleGetOrderDetails($conn, $order_id) {
    if (!$order_id) {
        http_response_code(400);
        return ['success' => false, 'message' => 'Missing Order ID.'];
    }

    // 1. Fetch Order Header Details
    $sql = "
        SELECT 
            o.order_id AS id,        
            o.order_date, 
            o.status, 
            o.total_amount, 
            o.subtotal,              
            o.tax_amount,
            o.delivery_fee,
            o.order_type,
            o.delivery_address,
            o.payment_method,
            u.name AS customer       /* Customer name aliased as 'customer' */
        FROM orders o
        JOIN users u ON o.user_id = u.id
        WHERE o.order_id = ?         
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $order = $result->fetch_assoc();
    $stmt->close();

    if (!$order) {
        return ['success' => false, 'message' => 'Order details not found.'];
    }

    // 2. Fetch Order Items
    $sql_items = "
        SELECT 
            oi.quantity, 
            oi.price_at_purchase AS price, 
            p.name
        FROM order_items oi
        JOIN products p ON oi.product_id = p.product_id    
        WHERE oi.order_id = ?        
    ";
    $stmt_items = $conn->prepare($sql_items);
    $stmt_items->bind_param("i", $order_id);
    $stmt_items->execute();
    $items_result = $stmt_items->get_result();
    $items = [];
    while ($row = $items_result->fetch_assoc()) {
        $items[] = $row;
    }
    $stmt_items->close();

    $order['items'] = $items;

    return ['success' => true, 'order' => $order];
}


/**
 * Updates the status of a specific order.
 */
function handleUpdateOrderStatus($conn, $order_id, $new_status) {
    if (!$order_id || !$new_status) {
        http_response_code(400);
        return ['success' => false, 'message' => 'Missing Order ID or new status.'];
    }
    
    $valid_statuses = ['Pending', 'Making', 'ReadyForPickup', 'Delivered', 'Cancelled'];
    if (!in_array($new_status, $valid_statuses)) {
        http_response_code(400);
        return ['success' => false, 'message' => 'Invalid status provided.'];
    }

    $sql = "UPDATE orders SET status = ?, updated_at = NOW() WHERE order_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $new_status, $order_id);
    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        $stmt->close();
        return ['success' => true, 'message' => 'Order status updated successfully.'];
    } else {
        $stmt->close();
        return ['success' => false, 'message' => 'Order not found or status is already set to ' . $new_status];
    }
}
?>