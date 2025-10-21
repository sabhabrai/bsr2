<?php
require_once '../config.php';

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);
$action = $_GET['action'] ?? '';

switch ($method) {
    case 'GET':
        switch ($action) {
            case 'listings':
                getListings();
                break;
            default:
                sendJsonResponse(['error' => 'Invalid action'], 400);
        }
        break;
    
    case 'POST':
        switch ($action) {
            case 'register':
                registerUser($input);
                break;
            case 'login':
                loginUser($input);
                break;
            case 'create_listing':
                createListing($input);
                break;
            case 'toggle_bookmark':
                toggleBookmark($input);
                break;
            default:
                sendJsonResponse(['error' => 'Invalid action'], 400);
        }
        break;
        
    case 'DELETE':
        if ($action === 'delete_listing' && isset($_GET['id'])) {
            deleteListing($_GET['id'], $input['user_id'] ?? null);
        } else {
            sendJsonResponse(['error' => 'Invalid delete request'], 400);
        }
        break;
        
    default:
        sendJsonResponse(['error' => 'Method not allowed'], 405);
}

function registerUser($data) {
    if (!isset($data['name']) || !isset($data['email']) || !isset($data['password'])) {
        sendJsonResponse(['error' => 'Name, email, and password are required'], 400);
    }
    
    if (!validateEmail($data['email'])) {
        sendJsonResponse(['error' => 'Invalid email address'], 400);
    }
    
    if (strlen($data['password']) < 6) {
        sendJsonResponse(['error' => 'Password must be at least 6 characters'], 400);
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
    
    // Insert new user with default buyer account type
    $stmt = $pdo->prepare("
        INSERT INTO users (name, email, password, account_type) 
        VALUES (?, ?, ?, 'buyer')
    ");
    
    $stmt->execute([
        sanitizeInput($data['name']),
        sanitizeInput($data['email']),
        $hashedPassword
    ]);
    
    $userId = $pdo->lastInsertId();
    
    // Return user data (without password)
    $userData = [
        'id' => $userId,
        'name' => $data['name'],
        'email' => $data['email'],
        'account_type' => 'buyer'
    ];
    
    sendJsonResponse([
        'success' => true,
        'user' => $userData,
        'message' => 'Account created successfully'
    ]);
}

function loginUser($data) {
    if (!isset($data['email']) || !isset($data['password'])) {
        sendJsonResponse(['error' => 'Email and password are required'], 400);
    }
    
    if (!validateEmail($data['email'])) {
        sendJsonResponse(['error' => 'Invalid email address'], 400);
    }
    
    $pdo = getDatabase();
    
    // Get user
    $stmt = $pdo->prepare("
        SELECT id, name, email, password, account_type
        FROM users 
        WHERE email = ?
    ");
    $stmt->execute([sanitizeInput($data['email'])]);
    $user = $stmt->fetch();
    
    if (!$user || !password_verify($data['password'], $user['password'])) {
        sendJsonResponse(['error' => 'Invalid email or password'], 401);
    }
    
    // Return user data (without password)
    $userData = [
        'id' => $user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'account_type' => $user['account_type'] ?? 'buyer'
    ];
    
    sendJsonResponse([
        'success' => true,
        'user' => $userData
    ]);
}

function getListings() {
    $pdo = getDatabase();
    
    // Build query based on filters
    $whereConditions = [];
    $params = [];
    
    if (isset($_GET['category']) && !empty($_GET['category'])) {
        $whereConditions[] = "l.category = ?";
        $params[] = $_GET['category'];
    }
    
    if (isset($_GET['type']) && !empty($_GET['type'])) {
        $whereConditions[] = "l.type = ?";
        $params[] = $_GET['type'];
    }
    
    if (isset($_GET['user_id']) && !empty($_GET['user_id'])) {
        $whereConditions[] = "l.user_id = ?";
        $params[] = $_GET['user_id'];
    }
    
    if (isset($_GET['search']) && !empty($_GET['search'])) {
        $whereConditions[] = "(l.title LIKE ? OR l.description LIKE ? OR l.location LIKE ?)";
        $searchTerm = '%' . $_GET['search'] . '%';
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    $whereClause = empty($whereConditions) ? '' : 'WHERE ' . implode(' AND ', $whereConditions);
    
    // Determine sort order
    $orderBy = 'ORDER BY l.created_at DESC';
    if (isset($_GET['sort'])) {
        switch ($_GET['sort']) {
            case 'oldest':
                $orderBy = 'ORDER BY l.created_at ASC';
                break;
            case 'price-low':
                $orderBy = 'ORDER BY l.price ASC';
                break;
            case 'price-high':
                $orderBy = 'ORDER BY l.price DESC';
                break;
        }
    }
    
    $sql = "
        SELECT 
            l.id,
            l.user_id,
            l.title,
            l.description,
            l.price,
            l.category,
            l.type,
            l.location,
            l.phone,
            l.duration_hours,
            l.images,
            l.created_at,
            u.name as user_name,
            u.email as user_email
        FROM listings l
        JOIN users u ON l.user_id = u.id
        $whereClause
        $orderBy
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $listings = $stmt->fetchAll();
    
    // Process listings to add calculated fields
    foreach ($listings as &$listing) {
        // Calculate time since posting
        $timeDiff = time() - strtotime($listing['created_at']);
        if ($timeDiff < 3600) {
            $listing['posted'] = 'Just now';
        } elseif ($timeDiff < 86400) {
            $hours = floor($timeDiff / 3600);
            $listing['posted'] = $hours . 'h ago';
        } else {
            $days = floor($timeDiff / 86400);
            $listing['posted'] = $days . 'd ago';
        }
        
        // Parse images JSON
        $listing['images'] = $listing['images'] ? json_decode($listing['images'], true) : [];
        
        // Add expiry information (default to 48 hours if duration_hours is null)
        $durationHours = $listing['duration_hours'] ?? 48;
        $expiryTime = strtotime($listing['created_at']) + ($durationHours * 3600);
        $timeLeft = $expiryTime - time();
        
        if ($timeLeft <= 0) {
            $listing['expiry'] = ['expired' => true, 'display' => 'Expired'];
        } else {
            $hoursLeft = floor($timeLeft / 3600);
            if ($hoursLeft < 1) {
                $minutesLeft = floor($timeLeft / 60);
                $listing['expiry'] = ['expired' => false, 'display' => $minutesLeft . 'm left'];
            } elseif ($hoursLeft < 24) {
                $listing['expiry'] = ['expired' => false, 'display' => $hoursLeft . 'h left'];
            } else {
                $daysLeft = floor($hoursLeft / 24);
                $listing['expiry'] = ['expired' => false, 'display' => $daysLeft . 'd left'];
            }
        }
        
        // Add timestamp for JavaScript
        $listing['timestamp'] = strtotime($listing['created_at']) * 1000; // Convert to milliseconds
        $listing['duration'] = $durationHours;
    }
    
    sendJsonResponse(['success' => true, 'listings' => $listings]);
}

function createListing($data) {
    if (!isset($data['user_id']) || !isset($data['title']) || !isset($data['description']) || 
        !isset($data['price']) || !isset($data['category']) || !isset($data['type']) || 
        !isset($data['location'])) {
        sendJsonResponse(['error' => 'Missing required fields'], 400);
    }
    
    $pdo = getDatabase();
    
    // Verify user exists
    $stmt = $pdo->prepare("SELECT name, email FROM users WHERE id = ?");
    $stmt->execute([$data['user_id']]);
    $user = $stmt->fetch();
    
    if (!$user) {
        sendJsonResponse(['error' => 'User not found'], 404);
    }
    
    // Process images
    $images = isset($data['images']) ? json_encode($data['images']) : null;
    
    // Default duration to 48 hours if not provided
    $duration = $data['duration'] ?? 48;
    
    // Insert listing
    $stmt = $pdo->prepare("
        INSERT INTO listings (
            user_id, title, description, price, category, type, 
            location, phone, duration_hours, images
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $data['user_id'],
        sanitizeInput($data['title']),
        sanitizeInput($data['description']),
        $data['price'],
        $data['category'],
        $data['type'],
        sanitizeInput($data['location']),
        $data['phone'] ?? null,
        $duration,
        $images
    ]);
    
    $listingId = $pdo->lastInsertId();
    
    sendJsonResponse([
        'success' => true,
        'listing_id' => $listingId,
        'message' => 'Listing created successfully'
    ]);
}

function deleteListing($listingId, $userId) {
    if (!$userId) {
        sendJsonResponse(['error' => 'User ID required'], 401);
    }
    
    $pdo = getDatabase();
    
    // Check if listing belongs to user
    $stmt = $pdo->prepare("SELECT user_id FROM listings WHERE id = ?");
    $stmt->execute([$listingId]);
    $listing = $stmt->fetch();
    
    if (!$listing) {
        sendJsonResponse(['error' => 'Listing not found'], 404);
    }
    
    if ($listing['user_id'] != $userId) {
        sendJsonResponse(['error' => 'Unauthorized'], 403);
    }
    
    // Delete listing
    $stmt = $pdo->prepare("DELETE FROM listings WHERE id = ?");
    $stmt->execute([$listingId]);
    
    sendJsonResponse([
        'success' => true,
        'message' => 'Listing deleted successfully'
    ]);
}

function toggleBookmark($data) {
    if (!isset($data['user_id']) || !isset($data['listing_id'])) {
        sendJsonResponse(['error' => 'User ID and listing ID are required'], 400);
    }
    
    $pdo = getDatabase();
    
    // Check if bookmark already exists
    $stmt = $pdo->prepare("SELECT id FROM bookmarks WHERE user_id = ? AND listing_id = ?");
    $stmt->execute([$data['user_id'], $data['listing_id']]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        // Remove bookmark
        $stmt = $pdo->prepare("DELETE FROM bookmarks WHERE user_id = ? AND listing_id = ?");
        $stmt->execute([$data['user_id'], $data['listing_id']]);
        $message = 'Bookmark removed';
    } else {
        // Add bookmark
        $stmt = $pdo->prepare("INSERT INTO bookmarks (user_id, listing_id) VALUES (?, ?)");
        $stmt->execute([$data['user_id'], $data['listing_id']]);
        $message = 'Listing bookmarked';
    }
    
    sendJsonResponse([
        'success' => true,
        'message' => $message
    ]);
}

// Get bookmarks for a user
function getBookmarks($userId) {
    $pdo = getDatabase();
    
    $sql = "
        SELECT 
            l.id,
            l.user_id,
            l.title,
            l.description,
            l.price,
            l.category,
            l.type,
            l.location,
            l.phone,
            l.duration_hours,
            l.images,
            l.created_at,
            u.name as user_name,
            u.email as user_email
        FROM bookmarks b
        JOIN listings l ON b.listing_id = l.id
        JOIN users u ON l.user_id = u.id
        WHERE b.user_id = ?
        ORDER BY b.created_at DESC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    $bookmarks = $stmt->fetchAll();
    
    // Process bookmarks the same way as listings
    foreach ($bookmarks as &$listing) {
        // Calculate time since posting
        $timeDiff = time() - strtotime($listing['created_at']);
        if ($timeDiff < 3600) {
            $listing['posted'] = 'Just now';
        } elseif ($timeDiff < 86400) {
            $hours = floor($timeDiff / 3600);
            $listing['posted'] = $hours . 'h ago';
        } else {
            $days = floor($timeDiff / 86400);
            $listing['posted'] = $days . 'd ago';
        }
        
        // Parse images JSON
        $listing['images'] = $listing['images'] ? json_decode($listing['images'], true) : [];
        
        // Add expiry information
        $durationHours = $listing['duration_hours'] ?? 48;
        $expiryTime = strtotime($listing['created_at']) + ($durationHours * 3600);
        $timeLeft = $expiryTime - time();
        
        if ($timeLeft <= 0) {
            $listing['expiry'] = ['expired' => true, 'display' => 'Expired'];
        } else {
            $hoursLeft = floor($timeLeft / 3600);
            if ($hoursLeft < 1) {
                $minutesLeft = floor($timeLeft / 60);
                $listing['expiry'] = ['expired' => false, 'display' => $minutesLeft . 'm left'];
            } elseif ($hoursLeft < 24) {
                $listing['expiry'] = ['expired' => false, 'display' => $hoursLeft . 'h left'];
            } else {
                $daysLeft = floor($hoursLeft / 24);
                $listing['expiry'] = ['expired' => false, 'display' => $daysLeft . 'd left'];
            }
        }
    }
    
    sendJsonResponse(['success' => true, 'bookmarks' => $bookmarks]);
}

// Handle GET request for bookmarks
if ($method === 'GET' && $action === 'bookmarks' && isset($_GET['user_id'])) {
    getBookmarks($_GET['user_id']);
}
?>
