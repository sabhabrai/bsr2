<?php
require_once '../config.php';

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

switch ($method) {
    case 'GET':
        if (isset($_GET['action'])) {
            switch ($_GET['action']) {
                case 'user_transactions':
                    getUserTransactions($_GET['user_id'] ?? null, $_GET['type'] ?? 'all');
                    break;
                case 'transaction_details':
                    getTransactionDetails($_GET['transaction_id'] ?? null);
                    break;
                case 'transaction_history':
                    getTransactionHistory($_GET['user_id'] ?? null);
                    break;
                default:
                    sendJsonResponse(['error' => 'Invalid action'], 400);
            }
        }
        break;
    case 'POST':
        if (isset($_GET['action'])) {
            switch ($_GET['action']) {
                case 'update_shipping':
                    updateShippingInfo($input);
                    break;
                case 'mark_shipped':
                    markAsShipped($input);
                    break;
                case 'mark_delivered':
                    markAsDelivered($input);
                    break;
                case 'initiate_dispute':
                    initiateDispute($input);
                    break;
                case 'resolve_dispute':
                    resolveDispute($input);
                    break;
                case 'rate_transaction':
                    rateTransaction($input);
                    break;
                default:
                    sendJsonResponse(['error' => 'Invalid action'], 400);
            }
        }
        break;
    case 'PUT':
        if (isset($_GET['action'])) {
            switch ($_GET['action']) {
                case 'cancel_transaction':
                    cancelTransaction($input);
                    break;
                default:
                    sendJsonResponse(['error' => 'Invalid action'], 400);
            }
        }
        break;
    default:
        sendJsonResponse(['error' => 'Method not allowed'], 405);
}

function getUserTransactions($userId, $type = 'all') {
    if (!$userId) {
        sendJsonResponse(['error' => 'User ID required'], 400);
    }
    
    $pdo = getDatabase();
    
    // Build query based on type (buyer, seller, or all)
    $whereClause = '';
    $params = [$userId];
    
    if ($type === 'buyer') {
        $whereClause = 'WHERE t.buyer_id = ?';
    } elseif ($type === 'seller') {
        $whereClause = 'WHERE t.seller_id = ?';
    } else {
        $whereClause = 'WHERE (t.buyer_id = ? OR t.seller_id = ?)';
        $params[] = $userId;
    }
    
    $stmt = $pdo->prepare("
        SELECT 
            t.*,
            l.title as listing_title,
            l.images as listing_images,
            buyer.name as buyer_name,
            buyer.email as buyer_email,
            seller.name as seller_name,
            seller.email as seller_email,
            seller.seller_rating,
            p.status as payment_status_detail
        FROM transactions t
        JOIN listings l ON t.listing_id = l.id
        JOIN users buyer ON t.buyer_id = buyer.id
        JOIN users seller ON t.seller_id = seller.id
        LEFT JOIN payments p ON t.payment_id = p.id
        $whereClause
        ORDER BY t.created_at DESC
        LIMIT 50
    ");
    
    $stmt->execute($params);
    $transactions = $stmt->fetchAll();
    
    // Process transactions to add calculated fields and format data
    foreach ($transactions as &$transaction) {
        $transaction['listing_images'] = $transaction['listing_images'] ? 
            json_decode($transaction['listing_images'], true) : [];
        
        // Add user role for this transaction
        $transaction['user_role'] = ($transaction['buyer_id'] == $userId) ? 'buyer' : 'seller';
        
        // Format amounts
        $transaction['unit_price'] = (float)$transaction['unit_price'];
        $transaction['total_amount'] = (float)$transaction['total_amount'];
        $transaction['platform_fee'] = (float)$transaction['platform_fee'];
        
        // Calculate days since transaction
        $daysSince = floor((time() - strtotime($transaction['created_at'])) / 86400);
        $transaction['days_since'] = $daysSince;
        
        // Add status display text
        $transaction['status_display'] = getTransactionStatusDisplay($transaction['status']);
        
        // Format dates
        $transaction['created_at_formatted'] = date('M j, Y g:i A', strtotime($transaction['created_at']));
        
        if ($transaction['delivery_date']) {
            $transaction['delivery_date_formatted'] = date('M j, Y', strtotime($transaction['delivery_date']));
        }
    }
    
    sendJsonResponse([
        'success' => true,
        'transactions' => $transactions
    ]);
}

function getTransactionDetails($transactionId) {
    if (!$transactionId) {
        sendJsonResponse(['error' => 'Transaction ID required'], 400);
    }
    
    $pdo = getDatabase();
    
    $stmt = $pdo->prepare("
        SELECT 
            t.*,
            l.title as listing_title,
            l.description as listing_description,
            l.images as listing_images,
            l.category as listing_category,
            buyer.name as buyer_name,
            buyer.email as buyer_email,
            buyer.phone_number as buyer_phone,
            seller.name as seller_name,
            seller.email as seller_email,
            seller.phone_number as seller_phone,
            seller.seller_rating,
            seller.total_sales,
            p.provider_transaction_id,
            p.payment_method
        FROM transactions t
        JOIN listings l ON t.listing_id = l.id
        JOIN users buyer ON t.buyer_id = buyer.id
        JOIN users seller ON t.seller_id = seller.id
        LEFT JOIN payments p ON t.payment_id = p.id
        WHERE t.id = ?
    ");
    
    $stmt->execute([$transactionId]);
    $transaction = $stmt->fetch();
    
    if (!$transaction) {
        sendJsonResponse(['error' => 'Transaction not found'], 404);
    }
    
    // Process data
    $transaction['listing_images'] = $transaction['listing_images'] ? 
        json_decode($transaction['listing_images'], true) : [];
    
    $transaction['unit_price'] = (float)$transaction['unit_price'];
    $transaction['total_amount'] = (float)$transaction['total_amount'];
    $transaction['platform_fee'] = (float)$transaction['platform_fee'];
    
    $transaction['status_display'] = getTransactionStatusDisplay($transaction['status']);
    $transaction['created_at_formatted'] = date('M j, Y g:i A', strtotime($transaction['created_at']));
    
    // Get transaction timeline/history
    $stmt = $pdo->prepare("
        SELECT action, description, created_at
        FROM activity_logs
        WHERE metadata LIKE ?
        ORDER BY created_at ASC
    ");
    $stmt->execute(['%"transaction_id":' . $transactionId . '%']);
    $timeline = $stmt->fetchAll();
    
    foreach ($timeline as &$event) {
        $event['created_at_formatted'] = date('M j, Y g:i A', strtotime($event['created_at']));
    }
    
    $transaction['timeline'] = $timeline;
    
    sendJsonResponse([
        'success' => true,
        'transaction' => $transaction
    ]);
}

function updateShippingInfo($data) {
    validateRequired($data, ['transaction_id', 'user_id', 'tracking_number']);
    
    $pdo = getDatabase();
    
    // Verify user is the seller
    $stmt = $pdo->prepare("SELECT seller_id, status FROM transactions WHERE id = ?");
    $stmt->execute([$data['transaction_id']]);
    $transaction = $stmt->fetch();
    
    if (!$transaction) {
        sendJsonResponse(['error' => 'Transaction not found'], 404);
    }
    
    if ($transaction['seller_id'] != $data['user_id']) {
        sendJsonResponse(['error' => 'Only the seller can update shipping information'], 403);
    }
    
    if (!in_array($transaction['status'], ['paid', 'shipped'])) {
        sendJsonResponse(['error' => 'Cannot update shipping for transaction in current status'], 400);
    }
    
    // Update shipping information
    $stmt = $pdo->prepare("
        UPDATE transactions 
        SET tracking_number = ?, notes = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([
        sanitizeInput($data['tracking_number']),
        sanitizeInput($data['notes'] ?? ''),
        $data['transaction_id']
    ]);
    
    logActivity($data['user_id'], 'shipping_updated', 'Shipping information updated', [
        'transaction_id' => $data['transaction_id'],
        'tracking_number' => $data['tracking_number']
    ]);
    
    sendJsonResponse([
        'success' => true,
        'message' => 'Shipping information updated successfully'
    ]);
}

function markAsShipped($data) {
    validateRequired($data, ['transaction_id', 'user_id', 'tracking_number']);
    
    $pdo = getDatabase();
    
    // Verify user is the seller and transaction is in correct status
    $stmt = $pdo->prepare("
        SELECT t.seller_id, t.status, t.buyer_id, l.title as listing_title
        FROM transactions t
        JOIN listings l ON t.listing_id = l.id
        WHERE t.id = ?
    ");
    $stmt->execute([$data['transaction_id']]);
    $transaction = $stmt->fetch();
    
    if (!$transaction) {
        sendJsonResponse(['error' => 'Transaction not found'], 404);
    }
    
    if ($transaction['seller_id'] != $data['user_id']) {
        sendJsonResponse(['error' => 'Only the seller can mark items as shipped'], 403);
    }
    
    if ($transaction['status'] !== 'paid') {
        sendJsonResponse(['error' => 'Can only ship paid transactions'], 400);
    }
    
    try {
        $pdo->beginTransaction();
        
        // Update transaction status
        $stmt = $pdo->prepare("
            UPDATE transactions 
            SET status = 'shipped', tracking_number = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([sanitizeInput($data['tracking_number']), $data['transaction_id']]);
        
        // Create notification for buyer
        createNotification($transaction['buyer_id'], 'transaction', 
            'Item Shipped', 
            "Your order for '{$transaction['listing_title']}' has been shipped! Tracking: {$data['tracking_number']}",
            'medium');
        
        $pdo->commit();
        
        logActivity($data['user_id'], 'item_shipped', 'Item marked as shipped', [
            'transaction_id' => $data['transaction_id'],
            'tracking_number' => $data['tracking_number']
        ]);
        
        sendJsonResponse([
            'success' => true,
            'message' => 'Item marked as shipped successfully'
        ]);
        
    } catch (Exception $e) {
        $pdo->rollback();
        handleError("Failed to mark as shipped: " . $e->getMessage());
    }
}

function markAsDelivered($data) {
    validateRequired($data, ['transaction_id', 'user_id']);
    
    $pdo = getDatabase();
    
    // Verify user is buyer or seller and transaction is shipped
    $stmt = $pdo->prepare("
        SELECT t.buyer_id, t.seller_id, t.status, l.title as listing_title
        FROM transactions t
        JOIN listings l ON t.listing_id = l.id
        WHERE t.id = ?
    ");
    $stmt->execute([$data['transaction_id']]);
    $transaction = $stmt->fetch();
    
    if (!$transaction) {
        sendJsonResponse(['error' => 'Transaction not found'], 404);
    }
    
    $isBuyer = $transaction['buyer_id'] == $data['user_id'];
    $isSeller = $transaction['seller_id'] == $data['user_id'];
    
    if (!$isBuyer && !$isSeller) {
        sendJsonResponse(['error' => 'Access denied'], 403);
    }
    
    if ($transaction['status'] !== 'shipped') {
        sendJsonResponse(['error' => 'Can only mark shipped items as delivered'], 400);
    }
    
    try {
        $pdo->beginTransaction();
        
        // Update transaction status
        $stmt = $pdo->prepare("
            UPDATE transactions 
            SET status = 'delivered', delivery_date = NOW(), updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$data['transaction_id']]);
        
        // Notify the other party
        if ($isBuyer) {
            createNotification($transaction['seller_id'], 'transaction', 
                'Item Delivered', 
                "The buyer has confirmed delivery of '{$transaction['listing_title']}'",
                'medium');
        } else {
            createNotification($transaction['buyer_id'], 'transaction', 
                'Delivery Confirmed', 
                "The seller has marked '{$transaction['listing_title']}' as delivered",
                'medium');
        }
        
        $pdo->commit();
        
        logActivity($data['user_id'], 'item_delivered', 'Item marked as delivered', [
            'transaction_id' => $data['transaction_id'],
            'marked_by' => $isBuyer ? 'buyer' : 'seller'
        ]);
        
        sendJsonResponse([
            'success' => true,
            'message' => 'Item marked as delivered successfully'
        ]);
        
    } catch (Exception $e) {
        $pdo->rollback();
        handleError("Failed to mark as delivered: " . $e->getMessage());
    }
}

function rateTransaction($data) {
    validateRequired($data, ['transaction_id', 'user_id', 'rating']);
    
    if (!is_numeric($data['rating']) || $data['rating'] < 1 || $data['rating'] > 5) {
        sendJsonResponse(['error' => 'Rating must be between 1 and 5'], 400);
    }
    
    $pdo = getDatabase();
    
    // Get transaction details
    $stmt = $pdo->prepare("
        SELECT buyer_id, seller_id, status, buyer_rating, seller_rating
        FROM transactions 
        WHERE id = ?
    ");
    $stmt->execute([$data['transaction_id']]);
    $transaction = $stmt->fetch();
    
    if (!$transaction) {
        sendJsonResponse(['error' => 'Transaction not found'], 404);
    }
    
    if (!in_array($transaction['status'], ['delivered', 'completed'])) {
        sendJsonResponse(['error' => 'Can only rate completed or delivered transactions'], 400);
    }
    
    $isBuyer = $transaction['buyer_id'] == $data['user_id'];
    $isSeller = $transaction['seller_id'] == $data['user_id'];
    
    if (!$isBuyer && !$isSeller) {
        sendJsonResponse(['error' => 'Access denied'], 403);
    }
    
    // Check if already rated
    $ratingField = $isBuyer ? 'buyer_rating' : 'seller_rating';
    $reviewField = $isBuyer ? 'buyer_review' : 'seller_review';
    
    if ($transaction[$ratingField]) {
        sendJsonResponse(['error' => 'You have already rated this transaction'], 400);
    }
    
    try {
        $pdo->beginTransaction();
        
        // Update transaction with rating
        $stmt = $pdo->prepare("
            UPDATE transactions 
            SET $ratingField = ?, $reviewField = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([
            $data['rating'],
            sanitizeInput($data['review'] ?? ''),
            $data['transaction_id']
        ]);
        
        // If both parties have rated, mark as completed
        $stmt = $pdo->prepare("
            SELECT buyer_rating, seller_rating 
            FROM transactions 
            WHERE id = ?
        ");
        $stmt->execute([$data['transaction_id']]);
        $ratings = $stmt->fetch();
        
        if ($ratings['buyer_rating'] && $ratings['seller_rating']) {
            $stmt = $pdo->prepare("
                UPDATE transactions 
                SET status = 'completed' 
                WHERE id = ?
            ");
            $stmt->execute([$data['transaction_id']]);
        }
        
        $pdo->commit();
        
        logActivity($data['user_id'], 'transaction_rated', 'Transaction rated', [
            'transaction_id' => $data['transaction_id'],
            'rating' => $data['rating'],
            'role' => $isBuyer ? 'buyer' : 'seller'
        ]);
        
        sendJsonResponse([
            'success' => true,
            'message' => 'Rating submitted successfully'
        ]);
        
    } catch (Exception $e) {
        $pdo->rollback();
        handleError("Failed to submit rating: " . $e->getMessage());
    }
}

function initiateDispute($data) {
    validateRequired($data, ['transaction_id', 'user_id', 'reason']);
    
    $pdo = getDatabase();
    
    // Verify user is part of the transaction
    $stmt = $pdo->prepare("
        SELECT t.buyer_id, t.seller_id, t.status, l.title as listing_title
        FROM transactions t
        JOIN listings l ON t.listing_id = l.id
        WHERE t.id = ?
    ");
    $stmt->execute([$data['transaction_id']]);
    $transaction = $stmt->fetch();
    
    if (!$transaction) {
        sendJsonResponse(['error' => 'Transaction not found'], 404);
    }
    
    $isBuyer = $transaction['buyer_id'] == $data['user_id'];
    $isSeller = $transaction['seller_id'] == $data['user_id'];
    
    if (!$isBuyer && !$isSeller) {
        sendJsonResponse(['error' => 'Access denied'], 403);
    }
    
    if ($transaction['status'] === 'completed') {
        sendJsonResponse(['error' => 'Cannot dispute completed transactions'], 400);
    }
    
    try {
        $pdo->beginTransaction();
        
        // Update transaction status to disputed
        $stmt = $pdo->prepare("
            UPDATE transactions 
            SET status = 'disputed', dispute_reason = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([sanitizeInput($data['reason']), $data['transaction_id']]);
        
        // Create a report for the dispute
        $reportedUserId = $isBuyer ? $transaction['seller_id'] : $transaction['buyer_id'];
        
        $stmt = $pdo->prepare("
            INSERT INTO reports 
            (reporter_user_id, reported_user_id, reported_transaction_id, report_type, reason, description)
            VALUES (?, ?, ?, 'transaction', 'other', ?)
        ");
        $stmt->execute([
            $data['user_id'],
            $reportedUserId,
            $data['transaction_id'],
            "Transaction dispute: " . sanitizeInput($data['reason'])
        ]);
        
        // Notify the other party
        $otherPartyId = $isBuyer ? $transaction['seller_id'] : $transaction['buyer_id'];
        createNotification($otherPartyId, 'transaction', 
            'Transaction Disputed', 
            "A dispute has been initiated for '{$transaction['listing_title']}'",
            'high');
        
        $pdo->commit();
        
        logActivity($data['user_id'], 'dispute_initiated', 'Transaction dispute initiated', [
            'transaction_id' => $data['transaction_id'],
            'reason' => $data['reason']
        ]);
        
        sendJsonResponse([
            'success' => true,
            'message' => 'Dispute initiated successfully. Our support team will review this case.'
        ]);
        
    } catch (Exception $e) {
        $pdo->rollback();
        handleError("Failed to initiate dispute: " . $e->getMessage());
    }
}

function cancelTransaction($data) {
    validateRequired($data, ['transaction_id', 'user_id', 'reason']);
    
    $pdo = getDatabase();
    
    // Get transaction details
    $stmt = $pdo->prepare("
        SELECT buyer_id, seller_id, status, payment_id, total_amount, listing_id
        FROM transactions 
        WHERE id = ?
    ");
    $stmt->execute([$data['transaction_id']]);
    $transaction = $stmt->fetch();
    
    if (!$transaction) {
        sendJsonResponse(['error' => 'Transaction not found'], 404);
    }
    
    $isBuyer = $transaction['buyer_id'] == $data['user_id'];
    $isSeller = $transaction['seller_id'] == $data['user_id'];
    
    if (!$isBuyer && !$isSeller) {
        sendJsonResponse(['error' => 'Access denied'], 403);
    }
    
    // Check if transaction can be cancelled
    if (!in_array($transaction['status'], ['pending', 'paid'])) {
        sendJsonResponse(['error' => 'Cannot cancel transaction in current status'], 400);
    }
    
    // Buyers can cancel pending transactions, sellers can cancel paid transactions with valid reason
    if ($isBuyer && $transaction['status'] === 'paid') {
        sendJsonResponse(['error' => 'Cannot cancel paid transactions. Please contact the seller or initiate a dispute.'], 400);
    }
    
    try {
        $pdo->beginTransaction();
        
        // Update transaction status
        $stmt = $pdo->prepare("
            UPDATE transactions 
            SET status = 'cancelled', notes = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([sanitizeInput($data['reason']), $data['transaction_id']]);
        
        // If payment exists, initiate refund
        if ($transaction['payment_id'] && $transaction['status'] === 'paid') {
            $stmt = $pdo->prepare("
                UPDATE payments 
                SET status = 'refunded'
                WHERE id = ?
            ");
            $stmt->execute([$transaction['payment_id']]);
            
            // Restore listing quantity
            $stmt = $pdo->prepare("
                UPDATE listings 
                SET quantity_available = quantity_available + (
                    SELECT quantity FROM transactions WHERE id = ?
                ),
                status = CASE WHEN status = 'sold' THEN 'active' ELSE status END
                WHERE id = ?
            ");
            $stmt->execute([$data['transaction_id'], $transaction['listing_id']]);
        }
        
        // Notify the other party
        $otherPartyId = $isBuyer ? $transaction['seller_id'] : $transaction['buyer_id'];
        $role = $isBuyer ? 'buyer' : 'seller';
        
        createNotification($otherPartyId, 'transaction', 
            'Transaction Cancelled', 
            "The $role has cancelled the transaction. Reason: " . $data['reason'],
            'medium');
        
        $pdo->commit();
        
        logActivity($data['user_id'], 'transaction_cancelled', 'Transaction cancelled', [
            'transaction_id' => $data['transaction_id'],
            'reason' => $data['reason'],
            'cancelled_by' => $isBuyer ? 'buyer' : 'seller'
        ]);
        
        sendJsonResponse([
            'success' => true,
            'message' => 'Transaction cancelled successfully'
        ]);
        
    } catch (Exception $e) {
        $pdo->rollback();
        handleError("Failed to cancel transaction: " . $e->getMessage());
    }
}

function getTransactionHistory($userId) {
    if (!$userId) {
        sendJsonResponse(['error' => 'User ID required'], 400);
    }
    
    $pdo = getDatabase();
    
    // Get summary statistics
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_transactions,
            SUM(CASE WHEN buyer_id = ? THEN total_amount ELSE 0 END) as total_spent,
            SUM(CASE WHEN seller_id = ? THEN total_amount ELSE 0 END) as total_earned,
            COUNT(CASE WHEN buyer_id = ? THEN 1 END) as purchases,
            COUNT(CASE WHEN seller_id = ? THEN 1 END) as sales,
            AVG(CASE WHEN buyer_id = ? AND seller_rating IS NOT NULL THEN seller_rating END) as avg_rating_given,
            AVG(CASE WHEN seller_id = ? AND buyer_rating IS NOT NULL THEN buyer_rating END) as avg_rating_received
        FROM transactions 
        WHERE buyer_id = ? OR seller_id = ?
    ");
    $stmt->execute(array_fill(0, 8, $userId));
    $stats = $stmt->fetch();
    
    // Format stats
    $stats['total_spent'] = (float)$stats['total_spent'];
    $stats['total_earned'] = (float)$stats['total_earned'];
    $stats['avg_rating_given'] = $stats['avg_rating_given'] ? round($stats['avg_rating_given'], 1) : null;
    $stats['avg_rating_received'] = $stats['avg_rating_received'] ? round($stats['avg_rating_received'], 1) : null;
    
    sendJsonResponse([
        'success' => true,
        'stats' => $stats
    ]);
}

function getTransactionStatusDisplay($status) {
    $statusMap = [
        'pending' => 'Pending Payment',
        'paid' => 'Payment Confirmed',
        'shipped' => 'Shipped',
        'delivered' => 'Delivered',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        'disputed' => 'Disputed'
    ];
    
    return $statusMap[$status] ?? ucfirst($status);
}

// Helper function to create notifications (already defined in payments.php, but including here for completeness)
if (!function_exists('createNotification')) {
    function createNotification($userId, $type, $title, $message, $priority = 'medium') {
        $pdo = getDatabase();
        
        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, type, title, message, priority, send_email) 
            VALUES (?, ?, ?, ?, ?, TRUE)
        ");
        $stmt->execute([$userId, $type, $title, $message, $priority]);
        
        return $pdo->lastInsertId();
    }
}

?>
