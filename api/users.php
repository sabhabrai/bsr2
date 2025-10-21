<?php
require_once '../config.php';

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

switch ($method) {
    case 'GET':
        if (isset($_GET['action'])) {
            switch ($_GET['action']) {
                case 'profile':
                    getUserProfile($_GET['user_id'] ?? null);
                    break;
                default:
                    sendJsonResponse(['error' => 'Invalid action'], 400);
            }
        }
        break;
    case 'POST':
        if (isset($_GET['action'])) {
            switch ($_GET['action']) {
                case 'register':
                    registerUser($input);
                    break;
                case 'login':
                    loginUser($input);
                    break;
                default:
                    sendJsonResponse(['error' => 'Invalid action'], 400);
            }
        }
        break;
    case 'PUT':
        if (isset($_GET['action'])) {
            switch ($_GET['action']) {
                case 'update_profile':
                    updateUserProfile($input);
                    break;
                case 'change_password':
                    changePassword($input);
                    break;
                default:
                    sendJsonResponse(['error' => 'Invalid action'], 400);
            }
        }
        break;
    default:
        sendJsonResponse(['error' => 'Method not allowed'], 405);
}

function registerUser($data) {
    validateRequired($data, ['name', 'email', 'password', 'account_type']);
    
    if (!validateEmail($data['email'])) {
        sendJsonResponse(['error' => 'Invalid email address'], 400);
    }
    if (!validatePassword($data['password'])) {
        sendJsonResponse(['error' => 'Password must be at least 8 characters with letters and numbers'], 400);
    }
    if (!in_array($data['account_type'], ['buyer', 'seller'])) {
        sendJsonResponse(['error' => 'Invalid account type'], 400);
    }
    
    $pdo = getDatabase();
    
    // Check if email already exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([sanitizeInput($data['email'])]);
    if ($stmt->fetch()) {
        sendJsonResponse(['error' => 'Email already registered'], 400);
    }
    
    // Hash password
    $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);
    
    // Insert new user (phone optional, no verification)
    $phoneNumber = isset($data['phone_number']) ? sanitizeInput($data['phone_number']) : null;
    
    $stmt = $pdo->prepare("
        INSERT INTO users (name, email, password, account_type, phone_number, phone_verified, is_verified_seller) 
        VALUES (?, ?, ?, ?, ?, 0, 0)
    ");
    
    $stmt->execute([
        sanitizeInput($data['name']),
        sanitizeInput($data['email']),
        $hashedPassword,
        $data['account_type'],
        $phoneNumber
    ]);
    
    $userId = $pdo->lastInsertId();
    logActivity($userId, 'register', "User registered (verification disabled)");
    
    $userData = [
        'id' => $userId,
        'name' => $data['name'],
        'email' => $data['email'],
        'account_type' => $data['account_type'],
        'phone_number' => $phoneNumber,
        'phone_verified' => false,
        'is_verified_seller' => false,
        'seller_rating' => 0.00
    ];
    
    sendJsonResponse([
        'success' => true,
        'user' => $userData,
        'message' => 'Account created successfully'
    ]);
}

function loginUser($data) {
    validateRequired($data, ['email', 'password']);
    
    if (!validateEmail($data['email'])) {
        sendJsonResponse(['error' => 'Invalid email address'], 400);
    }
    
    $pdo = getDatabase();
    
    // Get user by email first to tailor error message
    $stmt = $pdo->prepare("
        SELECT id, name, email, password, account_type, phone_number, phone_verified, 
               is_verified_seller, seller_rating, total_sales, is_flagged, status 
        FROM users 
        WHERE email = ?
    ");
    $stmt->execute([sanitizeInput($data['email'])]);
    $user = $stmt->fetch();

    // If no account exists, return 404 (frontend will show 'No account found')
    if (!$user) {
        logActivity(null, 'failed_login', "Login email not found: {$data['email']}");
        sendJsonResponse(['error' => 'Account not found'], 404);
    }

    // If password mismatch, return 401
    if (!password_verify($data['password'], $user['password'])) {
        logActivity($user['id'], 'failed_login', 'Invalid password');
        sendJsonResponse(['error' => 'Invalid password'], 401);
    }
    
    // Check if user account is active
    if ($user['status'] !== 'active') {
        logActivity($user['id'], 'blocked_login', "Login blocked for {$user['status']} account");
        sendJsonResponse(['error' => 'Account is suspended or banned'], 403);
    }
    
    // Log successful login
    logActivity($user['id'], 'login', 'User logged in successfully');
    
    // Update last login timestamp
    $stmt = $pdo->prepare("UPDATE users SET updated_at = NOW() WHERE id = ?");
    $stmt->execute([$user['id']]);
    
    // Return user data (without password)
    $userData = [
        'id' => $user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'account_type' => $user['account_type'],
        'phone_number' => $user['phone_number'],
        'phone_verified' => (bool)$user['phone_verified'],
        'is_verified_seller' => (bool)$user['is_verified_seller'],
        'seller_rating' => (float)$user['seller_rating'],
        'total_sales' => (int)$user['total_sales'],
        'is_flagged' => (bool)$user['is_flagged'],
        'status' => $user['status']
    ];
    
    sendJsonResponse([
        'success' => true,
        'user' => $userData
    ]);
}

// Removed: phone/email verification and Twilio/SMS-related functions

function getUserProfile($userId) {
    if (!$userId) {
        sendJsonResponse(['error' => 'User ID required'], 400);
    }
    
    $pdo = getDatabase();
    
    $stmt = $pdo->prepare("
        SELECT id, name, email, account_type, phone_number, phone_verified, 
               is_verified_seller, seller_rating, total_sales, created_at
        FROM users 
        WHERE id = ? AND status = 'active'
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        sendJsonResponse(['error' => 'User not found'], 404);
    }
    
    sendJsonResponse([
        'success' => true,
        'user' => $user
    ]);
}

// Removed: checkVerificationStatus (verification disabled)

function updateUserProfile($data) {
    validateRequired($data, ['user_id']);
    
    $pdo = getDatabase();
    
    $updateFields = [];
    $params = [];
    
    if (isset($data['name']) && !empty(trim($data['name']))) {
        $updateFields[] = 'name = ?';
        $params[] = sanitizeInput($data['name']);
    }
    
    if (isset($data['phone_number']) && !empty(trim($data['phone_number']))) {
        if (!validatePhone($data['phone_number'])) {
            sendJsonResponse(['error' => 'Invalid phone number format'], 400);
        }
        
        // Check if phone number is already used
        $stmt = $pdo->prepare("SELECT id FROM users WHERE phone_number = ? AND id != ?");
        $stmt->execute([sanitizeInput($data['phone_number']), $data['user_id']]);
        if ($stmt->fetch()) {
            sendJsonResponse(['error' => 'Phone number already in use'], 400);
        }
        
        $updateFields[] = 'phone_number = ?';
        $updateFields[] = 'phone_verified = FALSE'; // Reset verification if phone changed
        $updateFields[] = 'is_verified_seller = FALSE';
        $params[] = sanitizeInput($data['phone_number']);
    }
    
    if (empty($updateFields)) {
        sendJsonResponse(['error' => 'No valid fields to update'], 400);
    }
    
    $params[] = $data['user_id'];
    
    $sql = "UPDATE users SET " . implode(', ', $updateFields) . ", updated_at = NOW() WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    logActivity($data['user_id'], 'profile_updated', 'User profile updated');
    
    sendJsonResponse([
        'success' => true,
        'message' => 'Profile updated successfully'
    ]);
}

function changePassword($data) {
    validateRequired($data, ['user_id', 'current_password', 'new_password']);
    
    if (!validatePassword($data['new_password'])) {
        sendJsonResponse(['error' => 'New password must be at least 8 characters with letters and numbers'], 400);
    }
    
    $pdo = getDatabase();
    
    // Verify current password
    $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$data['user_id']]);
    $user = $stmt->fetch();
    
    if (!$user || !password_verify($data['current_password'], $user['password'])) {
        sendJsonResponse(['error' => 'Current password is incorrect'], 401);
    }
    
    // Update password
    $hashedPassword = password_hash($data['new_password'], PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$hashedPassword, $data['user_id']]);
    
    logActivity($data['user_id'], 'password_changed', 'User changed password');
    
    sendJsonResponse([
        'success' => true,
        'message' => 'Password changed successfully'
    ]);
}
?>
