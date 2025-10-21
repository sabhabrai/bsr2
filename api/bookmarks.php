<?php
require_once '../config.php';

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

switch ($method) {
    case 'GET':
        if (isset($_GET['user_id'])) {
            getBookmarks($_GET['user_id']);
        } else {
            sendJsonResponse(['error' => 'User ID required'], 400);
        }
        break;
    case 'POST':
        toggleBookmark($input);
        break;
    default:
        sendJsonResponse(['error' => 'Method not allowed'], 405);
}

function getBookmarks($userId) {
    $pdo = getDatabase();
    
    $stmt = $pdo->prepare("
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
            u.email as user_email,
            b.created_at as bookmarked_at
        FROM bookmarks b
        JOIN listings l ON b.listing_id = l.id
        JOIN users u ON l.user_id = u.id
        WHERE b.user_id = ?
        AND (l.created_at + INTERVAL l.duration_hours HOUR) > NOW()
        ORDER BY b.created_at DESC
    ");
    
    $stmt->execute([$userId]);
    $bookmarks = $stmt->fetchAll();
    
    // Process bookmarks to add calculated fields
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
    
    sendJsonResponse(['success' => true, 'bookmarks' => $bookmarks]);
}

function toggleBookmark($data) {
    validateRequired($data, ['user_id', 'listing_id']);
    
    $pdo = getDatabase();
    
    // Check if bookmark already exists
    $stmt = $pdo->prepare("SELECT id FROM bookmarks WHERE user_id = ? AND listing_id = ?");
    $stmt->execute([$data['user_id'], $data['listing_id']]);
    $exists = $stmt->fetch();
    
    if ($exists) {
        // Remove bookmark
        $stmt = $pdo->prepare("DELETE FROM bookmarks WHERE user_id = ? AND listing_id = ?");
        $stmt->execute([$data['user_id'], $data['listing_id']]);
        
        sendJsonResponse([
            'success' => true,
            'action' => 'removed',
            'message' => 'Removed from bookmarks'
        ]);
    } else {
        // Verify listing exists
        $stmt = $pdo->prepare("SELECT id FROM listings WHERE id = ?");
        $stmt->execute([$data['listing_id']]);
        if (!$stmt->fetch()) {
            sendJsonResponse(['error' => 'Listing not found'], 404);
        }
        
        // Add bookmark
        $stmt = $pdo->prepare("INSERT INTO bookmarks (user_id, listing_id) VALUES (?, ?)");
        $stmt->execute([$data['user_id'], $data['listing_id']]);
        
        sendJsonResponse([
            'success' => true,
            'action' => 'added',
            'message' => 'Added to bookmarks'
        ]);
    }
}
?>
