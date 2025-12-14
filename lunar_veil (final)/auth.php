<?php
session_start();
header('Content-Type: application/json');

// NOTE: Ensure your 'db.php' is in the same directory or adjust the path.
require_once "db.php"; 

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
        handleStatus($conn);
        break;

    case 'logout':
        handleLogout(); 
        break;
        
    case 'profile':
        handleProfile($conn);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        break;
}

// ===============================================
// HELPER FUNCTIONS
// ===============================================

/**
 * Calculates age from birthdate string (YYYY-MM-DD).
 */
function calculateAge($birthdate) {
    if (empty($birthdate)) return 0;
    try {
        $dob = new DateTime($birthdate);
        $now = new DateTime();
        $age = $now->diff($dob)->y;
        return $age;
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Converts a role string (from registration) into a numeric ID.
 * 0: Standard Customer, 1: Staff, 2: Manager, 3: VIP Customer, 4: Cafe Manager, 5: Admin
 */
function getRoleID($role_name) {
    $standardized_role = strtolower(trim($role_name));

    switch ($standardized_role) {
        case 'vip customer':
            return 3;
        case 'cafe manager':
            return 4;
        case 'manager':
            return 2;
        case 'staff':
            return 1;
        case 'admin':
            return 5;
        case 'customer': 
        default:
            return 0; // Default to Standard Customer
    }
}

/**
 * Gets the descriptive role name from the ID for session storage and responses.
 */
function getRoleNameByID($role_id) {
    switch ((int)$role_id) {
        case 0: return 'Standard Customer';
        case 1: return 'Staff';
        case 2: return 'Manager';
        case 3: return 'VIP Customer';
        case 4: return 'Cafe Manager';
        case 5: return 'Admin';
        default: return 'Unknown Role';
    }
}


// ===============================================
// MAIN FUNCTIONS
// ===============================================

/**
 * Handles user registration, calculates age, and initializes wallet balance.
 */
function handleRegister($conn, $input) {
    $email = trim($input['email'] ?? '');
    $username = trim($input['username'] ?? ''); 
    $password = $input['password'] ?? '';
    $birthdate = $input['birthdate'] ?? ''; 
    $city_address = trim($input['city_address'] ?? ''); 
    
    $input_role_name = trim($input['role'] ?? 'customer'); 
    $role_id = getRoleID($input_role_name); 

    $age = calculateAge($birthdate); 

    if (empty($email) || empty($username) || strlen($password) < 6 || !filter_var($email, FILTER_VALIDATE_EMAIL) || empty($birthdate) || empty($city_address)) {
        echo json_encode(['success' => false, 'message' => 'Invalid input. Please complete all fields correctly.']);
        return;
    }

    $password_hash = password_hash($password, PASSWORD_DEFAULT);

    $conn->begin_transaction();

    try {
        // 1. Insert into users table
        $sql = "INSERT INTO users (name, email, age, city_address, birthdate, role, password) 
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        
        $stmt->bind_param("ssissis", 
            $username,          
            $email, 
            $age,               
            $city_address,      
            $birthdate,         
            $role_id,           
            $password_hash      
        );
        $stmt->execute();
        $user_id = $conn->insert_id;
        $stmt->close();
        
        // 2. Initialize wallet balance
        $stmt = $conn->prepare("INSERT INTO wallets (user_id, balance) VALUES (?, 0.00)");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
        
        // Set session variables upon successful registration
        $_SESSION['user_id'] = $user_id;
        $_SESSION['username'] = $username;
        $_SESSION['role'] = $role_id; 

        $role_name_response = getRoleNameByID($role_id);

        echo json_encode([
            'success' => true,
            'message' => 'Registration successful! Welcome to Lunar Veil Cafe.',
            'username' => $username,
            'role' => $role_name_response 
        ]);

    } catch (mysqli_sql_exception $e) {
        $conn->rollback();
        $error_code = $e->getCode();
        $friendly_message = 'Registration failed. This email or username may already be in use.';
        
        // You might want to log the full message on the server, but provide a friendly message to the client
        error_log("DB Error during registration: " . $e->getMessage()); 

        echo json_encode(['success' => false, 'message' => $friendly_message]);

    } catch (Exception $e) {
        $conn->rollback();
        error_log("Registration Error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'An unexpected general error occurred.']);
    }
}

/**
 * Handles user login.
 */
function handleLogin($conn, $input) {
    $email = trim($input['email'] ?? '');
    $password = $input['password'] ?? '';

    if (empty($email) || empty($password)) {
        echo json_encode(['success' => false, 'message' => 'Email and password are required.']);
        return;
    }

    $stmt = $conn->prepare("SELECT id, name, role, password FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows !== 1) {
        echo json_encode(['success' => false, 'message' => 'Invalid email or password.']);
        return;
    }

    $user = $result->fetch_assoc();
    
    if (!password_verify($password, $user['password'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid email or password.']);
        return;
    }

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['name']; 
    $_SESSION['role'] = (int)$user['role']; 

    $role_name_response = getRoleNameByID($user['role']);


    echo json_encode([
        'success' => true,
        'message' => 'Login successful!',
        'username' => $user['name'],
        'role' => $role_name_response 
    ]);
}

/**
 * Fetches comprehensive profile data for the logged-in user.
 */
function handleProfile($conn) {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
        return;
    }

    $user_id = $_SESSION['user_id'];

    try {
        $sql = "SELECT name, email, role, age, birthdate, city_address, created_at FROM users WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $profile = $result->fetch_assoc();
            
            // Ensure 'role' is an integer before sending
            $profile['role'] = (int)$profile['role']; 

            echo json_encode([
                'success' => true,
                'profile' => $profile
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Profile not found.']);
        }
        $stmt->close();
    } catch (Exception $e) {
        error_log("Profile Fetch Error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error fetching profile.']);
    }
}


/**
 * Reports the current login status, including the user's role and balance.
 */
function handleStatus($conn) {
    if (isset($_SESSION['user_id'])) {
        $user_id = $_SESSION['user_id'];
        $balance = 0.00; 
        
        $role_id = $_SESSION['role'] ?? 0; 
        
        // **FIX IMPLEMENTED HERE**: Convert the ID to a name for the tracking page's Admin link logic
        $role_name = getRoleNameByID($role_id); 

        // Fetch balance from wallet table
        try {
            $stmt = $conn->prepare("SELECT balance FROM wallets WHERE user_id = ?"); 
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows === 1) {
                $wallet = $result->fetch_assoc();
                $balance = $wallet['balance'];
            }
            $stmt->close();
        } catch (Exception $e) {
            error_log("Balance Fetch Error: " . $e->getMessage());
        }

        echo json_encode([
            'success' => true,
            'isLoggedIn' => true,
            'username' => $_SESSION['username'],
            'role' => $role_name, // Reports the descriptive name (e.g., 'Admin')
            'balance' => $balance
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'isLoggedIn' => false,
            'username' => null,
            'role' => null,
            'balance' => 0.00
        ]);
    }
}


/**
 * Handles user logout.
 */
function handleLogout() {
    $_SESSION = array();

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }

    session_destroy();

    echo json_encode(['success' => true, 'message' => 'Logged out successfully.']);
}
?>