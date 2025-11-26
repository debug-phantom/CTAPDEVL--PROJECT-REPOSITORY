<?php
session_start();
header("Content-Type: application/json");

require_once "db.php"; 

// ---------------------------------------------
// GET MENU ITEMS
// ---------------------------------------------
// ORDER BY FIELD ensures the new categories appear in a logical order.
$result = $conn->query("
    SELECT id, name, description, price, category 
    FROM products 
    ORDER BY FIELD(category, 'Espresso', 'Latte', 'Frappe', 'Tea', 'Pastry', 'Dessert', 'Special'), name
");

$products = [];
if ($result) {
    while($row = $result->fetch_assoc()) {
        // Format price for JavaScript display
        $row['price'] = number_format((float)$row['price'], 2, '.', ''); 
        $products[] = $row;
    }
}

echo json_encode(["success" => true, "menu" => $products]);
$conn->close();
?>