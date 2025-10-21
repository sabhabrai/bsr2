<?php
require_once '../config.php';

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

switch ($method) {
    case 'GET':
        getListings();
        break;
    case 'POST':
        createListing($input);
        break;
    case 'DELETE':
        if (isset($_GET['id'])) {
            deleteListing($_GET['id'], $input['user_id'] ?? null);
        } else {
            sendJsonResponse(['error' => 'Listing ID required'], 400);
        }
        break;
    default:
        sendJsonResponse(['error' => 'Method not allowed'], 405);
}

function getListings() {
    $pdo = getDatabase();
    
    // Clean expired listings first
    cleanExpiredListings();
    
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
            case 'expiry':
                $orderBy = 'ORDER BY (l.created_at + INTERVAL l.duration_hours HOUR) ASC';
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
        
        // Add expiry information
        $expiryTime = strtotime($listing['created_at']) + ($listing['duration_hours'] * 3600);
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
    
    sendJsonResponse(['success' => true, 'listings' => $listings]);
}

function createListing($data) {
    validateRequired($data, ['user_id', 'title', 'description', 'price', 'category', 'type', 'location', 'duration']);
    
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
    
    // Insert listing
    $stmt = $pdo->prepare("
        INSERT INTO listings (
            user_id, title, description, price, category, type, 
            location, phone, duration_hours, images
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $data['user_id'],
        $data['title'],
        $data['description'],
        $data['price'],
        $data['category'],
        $data['type'],
        $data['location'],
        $data['phone'] ?? null,
        $data['duration'],
        $images
    ]);
    
    $listingId = $pdo->lastInsertId();
    
    // Get the created listing with user info
    $stmt = $pdo->prepare("
        SELECT 
            l.*,
            u.name as user_name,
            u.email as user_email
        FROM listings l
        JOIN users u ON l.user_id = u.id
        WHERE l.id = ?
    ");
    $stmt->execute([$listingId]);
    $listing = $stmt->fetch();
    
    // Process the listing data
    $listing['posted'] = 'Just now';
    $listing['images'] = $listing['images'] ? json_decode($listing['images'], true) : [];
    
    sendJsonResponse([
        'success' => true, 
        'listing' => $listing,
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
?>
