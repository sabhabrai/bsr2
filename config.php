<?php
// Database configuration
define('DB_HOST', 'sql306.infinityfree.com');
define('DB_USER', 'if0_40206297');
define('DB_PASS', 'Khotang33');
define('DB_NAME', 'if0_40206297_bsr');

// Application configuration
define('APP_NAME', 'BSR Marketplace');
define('APP_VERSION', '2.0.0');
define('APP_ENV', 'production'); // development, production
define('APP_DEBUG', false);

// Security configuration
define('JWT_SECRET', 'your-jwt-secret-key-here'); // Change this in production
define('ENCRYPTION_KEY', 'your-encryption-key-here'); // Change this in production
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900); // 15 minutes in seconds

// Removed: Payment (Stripe/PayPal), SMS (Twilio), and SMTP/Email configuration

// File upload configuration
define('UPLOAD_MAX_SIZE', 5242880); // 5MB in bytes
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
define('UPLOAD_DIR', __DIR__ . '/uploads/');

// Rate limiting
define('RATE_LIMIT_REQUESTS', 100);
define('RATE_LIMIT_WINDOW', 3600); // 1 hour in seconds

// Create database connection with enhanced error handling
function getDatabase() {
    static $pdo = null;
    
    if ($pdo === null) {
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ];
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            if (APP_DEBUG) {
                die('Database connection failed: ' . $e->getMessage());
            } else {
                die('Database connection failed. Please try again later.');
            }
        }
    }
    
    return $pdo;
}

// Enhanced CORS headers for API requests
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');

    $allowedOrigins = [
        'https://bsr-buysellrent.netlify.app',
        'https://bsr-api.42web.io',
        'http://localhost:3000',
        'http://localhost:5173'
    ];
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin && in_array($origin, $allowedOrigins, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
    } else {
        header('Access-Control-Allow-Origin: *');
    }

    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    header('Access-Control-Max-Age: 86400'); // 24 hours
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
    if (APP_ENV === 'production') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

// Handle preflight OPTIONS requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Helper function to send JSON response with enhanced security
function sendJsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    
    // Add security headers if not already sent
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
    }
    
    // Sanitize output for XSS prevention
    $jsonData = json_encode($data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    
    if ($jsonData === false) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to encode response']);
    } else {
        echo $jsonData;
    }
    
    exit();
}

// Enhanced input validation functions
function validateRequired($data, $required) {
    foreach ($required as $field) {
        if (!isset($data[$field]) || (is_string($data[$field]) && empty(trim($data[$field])))) {
            sendJsonResponse(['error' => "Field '$field' is required"], 400);
        }
    }
}

function sanitizeInput($input) {
    if (is_string($input)) {
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
    return $input;
}

function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function validatePhone($phone) {
    // Basic phone number validation (adjust regex as needed)
    return preg_match('/^[+]?[1-9]?\d{1,3}[\s.-]?\d{3,4}[\s.-]?\d{3,4}$/', $phone);
}

function validatePassword($password) {
    // Password must be at least 8 characters with at least one letter and one number
    return strlen($password) >= 8 && preg_match('/^(?=.*[A-Za-z])(?=.*\d)/', $password);
}

function validatePrice($price) {
    return is_numeric($price) && $price >= 0 && $price <= 999999.99;
}

function rateLimitCheck($identifier, $maxRequests = RATE_LIMIT_REQUESTS, $timeWindow = RATE_LIMIT_WINDOW) {
    $pdo = getDatabase();
    
    // Clean old entries
    $stmt = $pdo->prepare("DELETE FROM rate_limits WHERE created_at < DATE_SUB(NOW(), INTERVAL ? SECOND)");
    $stmt->execute([$timeWindow]);
    
    // Count current requests
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM rate_limits WHERE identifier = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)");
    $stmt->execute([$identifier, $timeWindow]);
    $count = $stmt->fetch()['count'] ?? 0;
    
    if ($count >= $maxRequests) {
        sendJsonResponse(['error' => 'Rate limit exceeded. Please try again later.'], 429);
    }
    
    // Log this request
    $stmt = $pdo->prepare("INSERT INTO rate_limits (identifier, created_at) VALUES (?, NOW())");
    $stmt->execute([$identifier]);
}

// Create rate limits table if it doesn't exist
function createRateLimitTable() {
    $pdo = getDatabase();
    $sql = "CREATE TABLE IF NOT EXISTS rate_limits (
        id INT AUTO_INCREMENT PRIMARY KEY,
        identifier VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_identifier (identifier),
        INDEX idx_created_at (created_at)
    )";
    $pdo->exec($sql);
}

// Initialize rate limiting
if (APP_ENV === 'production') {
    createRateLimitTable();
    $clientIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    rateLimitCheck($clientIp);
}

// Helper function to clean expired listings with status update
function cleanExpiredListings() {
    $pdo = getDatabase();
    
    // Update status to expired instead of deleting
    $stmt = $pdo->prepare("
        UPDATE listings 
        SET status = 'expired' 
        WHERE status = 'active' 
        AND (created_at + INTERVAL duration_hours HOUR) < NOW()
    ");
    $stmt->execute();
    
    return $stmt->rowCount();
}

// Get system setting value
function getSystemSetting($key, $default = null) {
    static $cache = [];
    
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    
    $pdo = getDatabase();
    $stmt = $pdo->prepare("SELECT setting_value, data_type FROM system_settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $setting = $stmt->fetch();
    
    if (!$setting) {
        $cache[$key] = $default;
        return $default;
    }
    
    $value = $setting['setting_value'];
    
    // Type casting based on data_type
    switch ($setting['data_type']) {
        case 'integer':
            $value = (int)$value;
            break;
        case 'float':
            $value = (float)$value;
            break;
        case 'boolean':
            $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            break;
        case 'json':
            $value = json_decode($value, true);
            break;
    }
    
    $cache[$key] = $value;
    return $value;
}

// Log activity for audit trail
function logActivity($userId, $action, $description, $metadata = null, $adminId = null) {
    $pdo = getDatabase();
    
    $activityType = $adminId ? 'admin_action' : ($userId ? 'user_action' : 'system_event');
    $clientIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    
    $stmt = $pdo->prepare("
        INSERT INTO activity_logs 
        (user_id, admin_id, activity_type, action, description, ip_address, user_agent, metadata) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $userId,
        $adminId,
        $activityType,
        $action,
        $description,
        $clientIp,
        $userAgent,
        $metadata ? json_encode($metadata) : null
    ]);
}

// Error handling function
function handleError($message, $code = 500, $logError = true) {
    if ($logError) {
        error_log($message);
        logActivity(null, 'error', $message);
    }
    
    if (APP_DEBUG && APP_ENV === 'development') {
        sendJsonResponse(['error' => $message, 'debug' => true], $code);
    } else {
        sendJsonResponse(['error' => 'An error occurred. Please try again later.'], $code);
    }
}

// Initialize error handling
set_error_handler(function($severity, $message, $filename, $lineno) {
    if (error_reporting() & $severity) {
        handleError("Error in $filename:$lineno - $message", 500, true);
    }
});

set_exception_handler(function($exception) {
    handleError($exception->getMessage(), 500, true);
});

// Ensure upload directory exists
if (!file_exists(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}
?>
