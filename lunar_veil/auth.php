<?php
session_start();
header('Content-Type: application/json');

// --- REQUIRE THE CENTRALIZED DB CONNECTION ---
require_once "db.php"; 

// Read JSON input
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

switch ($action) {
    case 'register':
        handleRegister($conn, $input);
        break;

    case 'login':
        handleLogin($conn, $input);
        break;

    case 'status':
        handleStatus();
        break;

    case 'logout':
        handleLogout(); 
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        break;
}

// NOTE: We do NOT close the connection here. Let the script finish.


/**
 * ================================
 * USER REGISTRATION
 * ================================
 */
function handleRegister($conn, $input) {
    $email = trim($input['email'] ?? '');
    $username = trim($input['username'] ?? '');
    $password = $input['password'] ?? '';

    if (empty($email) || empty($username) || strlen($password) < 6) {
        echo json_encode(['success' => false, 'message' => 'Invalid input. Password must be at least 6 characters.']);
        return;
    }

    // Check if email already exists
    $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $check->bind_param("s", $email);
    $check->execute();
    $result = $check->get_result();

    if ($result->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'Email already registered.']);
        return;
    }

    // Check if username already exists
    $checkUser = $conn->prepare("SELECT id FROM users WHERE username = ?");
    $checkUser->bind_param("s", $username);
    $checkUser->execute();
    $resultUser = $checkUser->get_result();

    if ($resultUser->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'Username already taken.']);
        return;
    }

    // Register new user
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $conn->prepare("INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $username, $email, $passwordHash);

    if ($stmt->execute()) {
        // Automatically create a wallet entry on registration
        $user_id = $stmt->insert_id;
        $conn->query("INSERT INTO wallet (user_id, balance) VALUES ($user_id, 0.00)");
        
        // Log user in automatically after successful registration
        $_SESSION['user_id'] = $user_id;
        $_SESSION['username'] = $username;

        echo json_encode([
            'success' => true,
            'message' => 'Registration successful! You are now logged in.',
            'username' => $username
        ]);
    } else {
        error_log("Registration failed: " . $conn->error);
        echo json_encode(['success' => false, 'message' => 'Registration failed.']);
    }
}


/**
 * ================================
 * LOGIN
 * ================================
 */
function handleLogin($conn, $input) {
    $email = trim($input['email'] ?? '');
    $password = $input['password'] ?? '';

    if (empty($email) || empty($password)) {
        echo json_encode(['success' => false, 'message' => 'Email and password are required.']);
        return;
    }

    // Retrieve user safely using prepared statement
    $stmt = $conn->prepare("SELECT id, username, password_hash FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows !== 1) {
        echo json_encode(['success' => false, 'message' => 'Invalid email or password.']);
        return;
    }

    $user = $result->fetch_assoc();

    if (!password_verify($password, $user['password_hash'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid email or password.']);
        return;
    }

    // Login success
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];

    echo json_encode([
        'success' => true,
        'message' => 'Login successful!',
        'username' => $user['username']
    ]);
}


/**
 * ================================
 * LOGIN STATUS
 * ================================
 */
function handleStatus() {
    if (isset($_SESSION['user_id'])) {
        echo json_encode([
            'success' => true,
            'isLoggedIn' => true,
            'username' => $_SESSION['username']
        ]);
    } else {
        echo json_encode(['success' => true, 'isLoggedIn' => false]);
    }
}


/**
 * ================================
 * LOGOUT
 * ================================
 */
function handleLogout() {
    // Clear all session variables
    session_unset();
    // Destroy the session
    session_destroy();
    
    echo json_encode(['success' => true, 'message' => 'Logged out successfully.']);
}

?>