<?php
// Database credentials (ADJUST THESE FOR YOUR SETUP)
$servername = "localhost";
$username = "root";
$password = ""; // Use the password you set for your MySQL root user
$dbname = "my_lunar_veil_cafe";

// Connect to DB
$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    error_log("Database Connection Failed: " . $conn->connect_error);
    // Send a 500 Internal Server Error response for the client to catch
    http_response_code(500); 
    echo json_encode(['success' => false, 'message' => "Database connection failed on server."]);
    exit();
}
?>