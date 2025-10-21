<?php
require_once '../config.php';

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

switch ($method) {
    case 'GET':
        if (isset($_GET['user_id'])) {
            getMessages($_GET['user_id']);
        } else {
            sendJsonResponse(['error' => 'User ID required'], 400);
        }
        break;
    case 'POST':
        createMessage($input);
        break;
    case 'PUT':
        markRead($input);
        break;
    default:
        sendJsonResponse(['error' => 'Method not allowed'], 405);
}

function ensureMessagesTable() {
    $pdo = getDatabase();
    $sql = "CREATE TABLE IF NOT EXISTS messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        from_id INT NOT NULL,
        to_id INT NOT NULL,
        listing_title VARCHAR(255) NULL,
        message TEXT NOT NULL,
        is_read BOOLEAN DEFAULT FALSE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (from_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (to_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_to_id (to_id),
        INDEX idx_from_id (from_id),
        INDEX idx_created_at (created_at)
    )";
    $pdo->exec($sql);
}

function getMessages($userId) {
    ensureMessagesTable();
    $pdo = getDatabase();

    $stmt = $pdo->prepare("SELECT m.id, m.from_id, fu.name as from_name, m.to_id, tu.name as to_name, m.message, m.listing_title, m.is_read as `read`, m.created_at 
                           FROM messages m
                           JOIN users fu ON fu.id = m.from_id
                           JOIN users tu ON tu.id = m.to_id
                           WHERE m.from_id = ? OR m.to_id = ?
                           ORDER BY m.created_at DESC");
    $stmt->execute([$userId, $userId]);
    $rows = $stmt->fetchAll();

    // Normalize to frontend expected fields
    $messages = array_map(function($r) {
        return [
            'id' => (int)$r['id'],
            'from_id' => (int)$r['from_id'],
            'from_name' => $r['from_name'],
            'to_id' => (int)$r['to_id'],
            'to_name' => $r['to_name'],
            'message' => $r['message'],
            'listing_title' => $r['listing_title'],
            'read' => (bool)$r['read'],
            'timestamp' => strtotime($r['created_at']) * 1000
        ];
    }, $rows);

    sendJsonResponse(['success' => true, 'messages' => $messages]);
}

function createMessage($data) {
    ensureMessagesTable();
    validateRequired($data, ['from_id', 'to_id', 'message']);

    $pdo = getDatabase();

    $stmt = $pdo->prepare("INSERT INTO messages (from_id, to_id, listing_title, message) VALUES (?, ?, ?, ?)");
    $stmt->execute([
        $data['from_id'],
        $data['to_id'],
        $data['listing_title'] ?? null,
        $data['message']
    ]);

    $id = $pdo->lastInsertId();

    $stmt = $pdo->prepare("SELECT m.id, m.from_id, fu.name as from_name, m.to_id, tu.name as to_name, m.message, m.listing_title, m.is_read as `read`, m.created_at 
                           FROM messages m
                           JOIN users fu ON fu.id = m.from_id
                           JOIN users tu ON tu.id = m.to_id
                           WHERE m.id = ?");
    $stmt->execute([$id]);
    $r = $stmt->fetch();

    $message = [
        'id' => (int)$r['id'],
        'from_id' => (int)$r['from_id'],
        'from_name' => $r['from_name'],
        'to_id' => (int)$r['to_id'],
        'to_name' => $r['to_name'],
        'message' => $r['message'],
        'listing_title' => $r['listing_title'],
        'read' => (bool)$r['read'],
        'timestamp' => strtotime($r['created_at']) * 1000
    ];

    sendJsonResponse(['success' => true, 'messageData' => $message]);
}

function markRead($data) {
    ensureMessagesTable();
    validateRequired($data, ['id', 'user_id']);

    $pdo = getDatabase();

    // Only recipient can mark a message as read
    $stmt = $pdo->prepare("UPDATE messages SET is_read = TRUE WHERE id = ? AND to_id = ?");
    $stmt->execute([$data['id'], $data['user_id']]);

    sendJsonResponse(['success' => true, 'message' => 'Message marked as read']);
}
