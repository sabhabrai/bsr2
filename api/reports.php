<?php
require_once '../config.php';

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

switch ($method) {
    case 'GET':
        if (isset($_GET['action'])) {
            switch ($_GET['action']) {
                case 'user_reports':
                    getUserReports($_GET['user_id'] ?? null);
                    break;
                case 'report_details':
                    getReportDetails($_GET['report_id'] ?? null);
                    break;
                case 'moderation_queue':
                    getModerationQueue($_GET['status'] ?? 'pending');
                    break;
                default:
                    sendJsonResponse(['error' => 'Invalid action'], 400);
            }
        }
        break;
    case 'POST':
        if (isset($_GET['action'])) {
            switch ($_GET['action']) {
                case 'report_user':
                    reportUser($input);
                    break;
                case 'report_listing':
                    reportListing($input);
                    break;
                case 'report_transaction':
                    reportTransaction($input);
                    break;
                case 'flag_user':
                    flagUser($input);
                    break;
                default:
                    sendJsonResponse(['error' => 'Invalid action'], 400);
            }
        }
        break;
    case 'PUT':
        if (isset($_GET['action'])) {
            switch ($_GET['action']) {
                case 'update_report_status':
                    updateReportStatus($input);
                    break;
                case 'resolve_report':
                    resolveReport($input);
                    break;
                default:
                    sendJsonResponse(['error' => 'Invalid action'], 400);
            }
        }
        break;
    default:
        sendJsonResponse(['error' => 'Method not allowed'], 405);
}

function reportUser($data) {
    validateRequired($data, ['reporter_user_id', 'reported_user_id', 'reason', 'description']);
    
    if ($data['reporter_user_id'] == $data['reported_user_id']) {
        sendJsonResponse(['error' => 'Cannot report yourself'], 400);
    }
    
    $validReasons = ['fraud', 'fake_listing', 'inappropriate_content', 'spam', 'scam', 'harassment', 'counterfeit', 'other'];
    if (!in_array($data['reason'], $validReasons)) {
        sendJsonResponse(['error' => 'Invalid report reason'], 400);
    }
    
    $pdo = getDatabase();
    
    // Check if user exists
    $stmt = $pdo->prepare("SELECT name, status FROM users WHERE id = ?");
    $stmt->execute([$data['reported_user_id']]);
    $reportedUser = $stmt->fetch();
    
    if (!$reportedUser) {
        sendJsonResponse(['error' => 'Reported user not found'], 404);
    }
    
    // Check if reporter already reported this user recently
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count 
        FROM reports 
        WHERE reporter_user_id = ? AND reported_user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    $stmt->execute([$data['reporter_user_id'], $data['reported_user_id']]);
    $recentReports = $stmt->fetch()['count'];
    
    if ($recentReports >= 3) {
        sendJsonResponse(['error' => 'You have already reported this user recently. Please wait before submitting another report.'], 429);
    }
    
    try {
        $pdo->beginTransaction();
        
        // Create report
        $stmt = $pdo->prepare("
            INSERT INTO reports 
            (reporter_user_id, reported_user_id, report_type, reason, description, evidence)
            VALUES (?, ?, 'user', ?, ?, ?)
        ");
        
        $evidence = isset($data['evidence']) ? json_encode($data['evidence']) : null;
        
        $stmt->execute([
            $data['reporter_user_id'],
            $data['reported_user_id'],
            $data['reason'],
            sanitizeInput($data['description']),
            $evidence
        ]);
        
        $reportId = $pdo->lastInsertId();
        
        // Check if this user should be auto-flagged
        $autoFlagResult = checkAutoFlagging($data['reported_user_id'], $data['reason']);
        
        $pdo->commit();
        
        // Log the report
        logActivity($data['reporter_user_id'], 'user_reported', 'User reported another user', [
            'report_id' => $reportId,
            'reported_user_id' => $data['reported_user_id'],
            'reason' => $data['reason']
        ]);
        
        $message = 'Report submitted successfully. Our moderation team will review this case.';
        if ($autoFlagResult['flagged']) {
            $message .= ' The reported user has been automatically flagged for review.';
        }
        
        sendJsonResponse([
            'success' => true,
            'report_id' => $reportId,
            'message' => $message
        ]);
        
    } catch (Exception $e) {
        $pdo->rollback();
        handleError("Failed to submit report: " . $e->getMessage());
    }
}

function reportListing($data) {
    validateRequired($data, ['reporter_user_id', 'reported_listing_id', 'reason', 'description']);
    
    $validReasons = ['fraud', 'fake_listing', 'inappropriate_content', 'spam', 'scam', 'counterfeit', 'other'];
    if (!in_array($data['reason'], $validReasons)) {
        sendJsonResponse(['error' => 'Invalid report reason'], 400);
    }
    
    $pdo = getDatabase();
    
    // Get listing and owner details
    $stmt = $pdo->prepare("
        SELECT l.title, l.user_id as owner_id, u.name as owner_name
        FROM listings l
        JOIN users u ON l.user_id = u.id
        WHERE l.id = ?
    ");
    $stmt->execute([$data['reported_listing_id']]);
    $listing = $stmt->fetch();
    
    if (!$listing) {
        sendJsonResponse(['error' => 'Listing not found'], 404);
    }
    
    if ($listing['owner_id'] == $data['reporter_user_id']) {
        sendJsonResponse(['error' => 'Cannot report your own listing'], 400);
    }
    
    // Check for recent reports
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count 
        FROM reports 
        WHERE reporter_user_id = ? AND reported_listing_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ");
    $stmt->execute([$data['reporter_user_id'], $data['reported_listing_id']]);
    $recentReports = $stmt->fetch()['count'];
    
    if ($recentReports >= 1) {
        sendJsonResponse(['error' => 'You have already reported this listing recently.'], 429);
    }
    
    try {
        $pdo->beginTransaction();
        
        // Create report
        $stmt = $pdo->prepare("
            INSERT INTO reports 
            (reporter_user_id, reported_user_id, reported_listing_id, report_type, reason, description, evidence)
            VALUES (?, ?, ?, 'listing', ?, ?, ?)
        ");
        
        $evidence = isset($data['evidence']) ? json_encode($data['evidence']) : null;
        
        $stmt->execute([
            $data['reporter_user_id'],
            $listing['owner_id'],
            $data['reported_listing_id'],
            $data['reason'],
            sanitizeInput($data['description']),
            $evidence
        ]);
        
        $reportId = $pdo->lastInsertId();
        
        // Check if listing should be suspended automatically
        $suspensionResult = checkListingSuspension($data['reported_listing_id'], $data['reason']);
        
        $pdo->commit();
        
        logActivity($data['reporter_user_id'], 'listing_reported', 'Listing reported', [
            'report_id' => $reportId,
            'listing_id' => $data['reported_listing_id'],
            'reason' => $data['reason']
        ]);
        
        $message = 'Report submitted successfully. Our moderation team will review this listing.';
        if ($suspensionResult['suspended']) {
            $message .= ' The listing has been temporarily suspended pending review.';
        }
        
        sendJsonResponse([
            'success' => true,
            'report_id' => $reportId,
            'message' => $message
        ]);
        
    } catch (Exception $e) {
        $pdo->rollback();
        handleError("Failed to submit listing report: " . $e->getMessage());
    }
}

function reportTransaction($data) {
    validateRequired($data, ['reporter_user_id', 'reported_transaction_id', 'reason', 'description']);
    
    $pdo = getDatabase();
    
    // Verify user is part of the transaction
    $stmt = $pdo->prepare("
        SELECT t.buyer_id, t.seller_id, l.title as listing_title
        FROM transactions t
        JOIN listings l ON t.listing_id = l.id
        WHERE t.id = ?
    ");
    $stmt->execute([$data['reported_transaction_id']]);
    $transaction = $stmt->fetch();
    
    if (!$transaction) {
        sendJsonResponse(['error' => 'Transaction not found'], 404);
    }
    
    $isPartyToTransaction = ($transaction['buyer_id'] == $data['reporter_user_id'] || 
                             $transaction['seller_id'] == $data['reporter_user_id']);
    
    if (!$isPartyToTransaction) {
        sendJsonResponse(['error' => 'You can only report transactions you are involved in'], 403);
    }
    
    $reportedUserId = $transaction['buyer_id'] == $data['reporter_user_id'] ? 
                     $transaction['seller_id'] : $transaction['buyer_id'];
    
    try {
        $pdo->beginTransaction();
        
        // Create report
        $stmt = $pdo->prepare("
            INSERT INTO reports 
            (reporter_user_id, reported_user_id, reported_transaction_id, report_type, reason, description, evidence)
            VALUES (?, ?, ?, 'transaction', ?, ?, ?)
        ");
        
        $evidence = isset($data['evidence']) ? json_encode($data['evidence']) : null;
        
        $stmt->execute([
            $data['reporter_user_id'],
            $reportedUserId,
            $data['reported_transaction_id'],
            $data['reason'],
            sanitizeInput($data['description']),
            $evidence
        ]);
        
        $reportId = $pdo->lastInsertId();
        
        // Auto-escalate transaction disputes
        if (in_array($data['reason'], ['fraud', 'scam'])) {
            $stmt = $pdo->prepare("
                UPDATE reports 
                SET status = 'investigating', admin_notes = 'Auto-escalated due to fraud/scam allegation'
                WHERE id = ?
            ");
            $stmt->execute([$reportId]);
        }
        
        $pdo->commit();
        
        logActivity($data['reporter_user_id'], 'transaction_reported', 'Transaction reported', [
            'report_id' => $reportId,
            'transaction_id' => $data['reported_transaction_id'],
            'reason' => $data['reason']
        ]);
        
        sendJsonResponse([
            'success' => true,
            'report_id' => $reportId,
            'message' => 'Transaction report submitted successfully. Our support team will investigate.'
        ]);
        
    } catch (Exception $e) {
        $pdo->rollback();
        handleError("Failed to submit transaction report: " . $e->getMessage());
    }
}

function flagUser($data) {
    validateRequired($data, ['user_id', 'flag_type', 'reason']);
    
    // This would typically be called by admin users
    $validFlagTypes = ['warning', 'suspension', 'ban'];
    if (!in_array($data['flag_type'], $validFlagTypes)) {
        sendJsonResponse(['error' => 'Invalid flag type'], 400);
    }
    
    $pdo = getDatabase();
    
    // Get user details
    $stmt = $pdo->prepare("SELECT name, status FROM users WHERE id = ?");
    $stmt->execute([$data['user_id']]);
    $user = $stmt->fetch();
    
    if (!$user) {
        sendJsonResponse(['error' => 'User not found'], 404);
    }
    
    try {
        $pdo->beginTransaction();
        
        // Create user flag
        $expiresAt = null;
        if (isset($data['duration_hours']) && $data['duration_hours'] > 0) {
            $expiresAt = date('Y-m-d H:i:s', time() + ($data['duration_hours'] * 3600));
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO user_flags 
            (user_id, flag_type, reason, severity, auto_generated, expires_at, related_report_id)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $data['user_id'],
            $data['flag_type'],
            sanitizeInput($data['reason']),
            $data['severity'] ?? 5,
            $data['auto_generated'] ?? false,
            $expiresAt,
            $data['related_report_id'] ?? null
        ]);
        
        $flagId = $pdo->lastInsertId();
        
        // Update user status based on flag type
        $newStatus = 'active';
        if ($data['flag_type'] === 'suspension') {
            $newStatus = 'suspended';
        } elseif ($data['flag_type'] === 'ban') {
            $newStatus = 'banned';
        }
        
        if ($newStatus !== 'active') {
            $stmt = $pdo->prepare("
                UPDATE users 
                SET status = ?, is_flagged = TRUE, flag_reason = ?
                WHERE id = ?
            ");
            $stmt->execute([$newStatus, sanitizeInput($data['reason']), $data['user_id']]);
            
            // Suspend all active listings if user is suspended/banned
            if (in_array($newStatus, ['suspended', 'banned'])) {
                $stmt = $pdo->prepare("
                    UPDATE listings 
                    SET status = 'suspended' 
                    WHERE user_id = ? AND status = 'active'
                ");
                $stmt->execute([$data['user_id']]);
            }
        }
        
        // Create notification
        $flagMessages = [
            'warning' => 'You have received a warning for violating our community guidelines.',
            'suspension' => 'Your account has been suspended due to violations of our terms of service.',
            'ban' => 'Your account has been permanently banned due to serious violations.'
        ];
        
        createNotification($data['user_id'], 'system', 
            ucfirst($data['flag_type']) . ' Notice', 
            $flagMessages[$data['flag_type']] . ' Reason: ' . $data['reason'],
            'urgent');
        
        $pdo->commit();
        
        logActivity(null, 'user_flagged', "User {$data['flag_type']} applied", [
            'flagged_user_id' => $data['user_id'],
            'flag_type' => $data['flag_type'],
            'flag_id' => $flagId
        ]);
        
        sendJsonResponse([
            'success' => true,
            'flag_id' => $flagId,
            'message' => "User {$data['flag_type']} applied successfully"
        ]);
        
    } catch (Exception $e) {
        $pdo->rollback();
        handleError("Failed to flag user: " . $e->getMessage());
    }
}

function checkAutoFlagging($userId, $reason) {
    $pdo = getDatabase();
    
    // Count recent reports against this user
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as report_count
        FROM reports 
        WHERE reported_user_id = ? AND status IN ('pending', 'investigating') 
        AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
    ");
    $stmt->execute([$userId]);
    $reportCount = $stmt->fetch()['report_count'];
    
    $maxReportsBeforeFlag = getSystemSetting('max_reports_before_flag', 3);
    
    if ($reportCount >= $maxReportsBeforeFlag) {
        // Auto-flag user for review
        try {
            $stmt = $pdo->prepare("
                INSERT INTO user_flags 
                (user_id, flag_type, reason, severity, auto_generated)
                VALUES (?, 'warning', ?, ?, TRUE)
            ");
            
            $autoReason = "Automatically flagged due to multiple reports ($reportCount reports in 7 days)";
            $stmt->execute([$userId, $autoReason, 3]);
            
            // Update user status
            $stmt = $pdo->prepare("
                UPDATE users 
                SET is_flagged = TRUE, flag_reason = ?
                WHERE id = ?
            ");
            $stmt->execute([$autoReason, $userId]);
            
            return ['flagged' => true, 'reason' => 'multiple_reports'];
        } catch (Exception $e) {
            error_log("Auto-flagging failed: " . $e->getMessage());
            return ['flagged' => false];
        }
    }
    
    // Check for serious allegations
    if (in_array($reason, ['fraud', 'scam'])) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO user_flags 
                (user_id, flag_type, reason, severity, auto_generated)
                VALUES (?, 'warning', ?, ?, TRUE)
            ");
            
            $autoReason = "Automatically flagged due to serious allegation: $reason";
            $stmt->execute([$userId, $autoReason, 7]);
            
            return ['flagged' => true, 'reason' => 'serious_allegation'];
        } catch (Exception $e) {
            error_log("Auto-flagging for serious allegation failed: " . $e->getMessage());
            return ['flagged' => false];
        }
    }
    
    return ['flagged' => false];
}

function checkListingSuspension($listingId, $reason) {
    $pdo = getDatabase();
    
    // Count reports against this listing
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as report_count
        FROM reports 
        WHERE reported_listing_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    $stmt->execute([$listingId]);
    $reportCount = $stmt->fetch()['report_count'];
    
    // Auto-suspend for certain reasons or multiple reports
    $shouldSuspend = in_array($reason, ['fraud', 'scam', 'counterfeit']) || $reportCount >= 2;
    
    if ($shouldSuspend) {
        try {
            $stmt = $pdo->prepare("
                UPDATE listings 
                SET status = 'suspended' 
                WHERE id = ?
            ");
            $stmt->execute([$listingId]);
            
            return ['suspended' => true, 'reason' => $reason];
        } catch (Exception $e) {
            error_log("Auto-suspension failed: " . $e->getMessage());
            return ['suspended' => false];
        }
    }
    
    return ['suspended' => false];
}

function getUserReports($userId) {
    if (!$userId) {
        sendJsonResponse(['error' => 'User ID required'], 400);
    }
    
    $pdo = getDatabase();
    
    // Get reports made by the user
    $stmt = $pdo->prepare("
        SELECT r.*, 
               u.name as reported_user_name,
               l.title as reported_listing_title
        FROM reports r
        LEFT JOIN users u ON r.reported_user_id = u.id
        LEFT JOIN listings l ON r.reported_listing_id = l.id
        WHERE r.reporter_user_id = ?
        ORDER BY r.created_at DESC
        LIMIT 20
    ");
    
    $stmt->execute([$userId]);
    $reports = $stmt->fetchAll();
    
    // Process reports
    foreach ($reports as &$report) {
        $report['evidence'] = $report['evidence'] ? json_decode($report['evidence'], true) : [];
        $report['created_at_formatted'] = date('M j, Y g:i A', strtotime($report['created_at']));
        $report['status_display'] = ucfirst($report['status']);
    }
    
    sendJsonResponse([
        'success' => true,
        'reports' => $reports
    ]);
}

function getReportDetails($reportId) {
    if (!$reportId) {
        sendJsonResponse(['error' => 'Report ID required'], 400);
    }
    
    $pdo = getDatabase();
    
    $stmt = $pdo->prepare("
        SELECT r.*,
               reporter.name as reporter_name,
               reported.name as reported_user_name,
               l.title as reported_listing_title,
               t.id as transaction_id
        FROM reports r
        JOIN users reporter ON r.reporter_user_id = reporter.id
        LEFT JOIN users reported ON r.reported_user_id = reported.id
        LEFT JOIN listings l ON r.reported_listing_id = l.id
        LEFT JOIN transactions t ON r.reported_transaction_id = t.id
        WHERE r.id = ?
    ");
    
    $stmt->execute([$reportId]);
    $report = $stmt->fetch();
    
    if (!$report) {
        sendJsonResponse(['error' => 'Report not found'], 404);
    }
    
    $report['evidence'] = $report['evidence'] ? json_decode($report['evidence'], true) : [];
    $report['created_at_formatted'] = date('M j, Y g:i A', strtotime($report['created_at']));
    $report['updated_at_formatted'] = date('M j, Y g:i A', strtotime($report['updated_at']));
    
    sendJsonResponse([
        'success' => true,
        'report' => $report
    ]);
}

function getModerationQueue($status = 'pending') {
    // This would typically require admin authentication
    
    $pdo = getDatabase();
    
    $validStatuses = ['pending', 'investigating', 'resolved', 'dismissed'];
    if (!in_array($status, $validStatuses)) {
        $status = 'pending';
    }
    
    $stmt = $pdo->prepare("
        SELECT r.*,
               reporter.name as reporter_name,
               reported.name as reported_user_name,
               l.title as reported_listing_title
        FROM reports r
        JOIN users reporter ON r.reporter_user_id = reporter.id
        LEFT JOIN users reported ON r.reported_user_id = reported.id
        LEFT JOIN listings l ON r.reported_listing_id = l.id
        WHERE r.status = ?
        ORDER BY r.created_at ASC
        LIMIT 50
    ");
    
    $stmt->execute([$status]);
    $reports = $stmt->fetchAll();
    
    foreach ($reports as &$report) {
        $report['created_at_formatted'] = date('M j, Y g:i A', strtotime($report['created_at']));
        $report['priority'] = calculateReportPriority($report);
    }
    
    // Sort by priority (higher priority first)
    usort($reports, function($a, $b) {
        return $b['priority'] - $a['priority'];
    });
    
    sendJsonResponse([
        'success' => true,
        'reports' => $reports,
        'status' => $status
    ]);
}

function calculateReportPriority($report) {
    $priority = 1;
    
    // Higher priority for certain reasons
    if (in_array($report['reason'], ['fraud', 'scam'])) {
        $priority += 5;
    } elseif (in_array($report['reason'], ['harassment', 'counterfeit'])) {
        $priority += 3;
    }
    
    // Higher priority for transaction reports
    if ($report['report_type'] === 'transaction') {
        $priority += 2;
    }
    
    // Age factor (older reports get higher priority)
    $hoursSinceReport = (time() - strtotime($report['created_at'])) / 3600;
    if ($hoursSinceReport > 24) {
        $priority += 2;
    } elseif ($hoursSinceReport > 12) {
        $priority += 1;
    }
    
    return $priority;
}

function updateReportStatus($data) {
    validateRequired($data, ['report_id', 'status']);
    
    $validStatuses = ['pending', 'investigating', 'resolved', 'dismissed'];
    if (!in_array($data['status'], $validStatuses)) {
        sendJsonResponse(['error' => 'Invalid status'], 400);
    }
    
    $pdo = getDatabase();
    
    $stmt = $pdo->prepare("
        UPDATE reports 
        SET status = ?, admin_notes = ?, handled_by_admin_id = ?, updated_at = NOW()
        WHERE id = ?
    ");
    
    $stmt->execute([
        $data['status'],
        sanitizeInput($data['admin_notes'] ?? ''),
        $data['admin_id'] ?? null,
        $data['report_id']
    ]);
    
    if ($stmt->rowCount() === 0) {
        sendJsonResponse(['error' => 'Report not found'], 404);
    }
    
    logActivity($data['admin_id'] ?? null, 'report_status_updated', 'Report status updated', [
        'report_id' => $data['report_id'],
        'new_status' => $data['status']
    ]);
    
    sendJsonResponse([
        'success' => true,
        'message' => 'Report status updated successfully'
    ]);
}

function resolveReport($data) {
    validateRequired($data, ['report_id', 'resolution']);
    
    $pdo = getDatabase();
    
    try {
        $pdo->beginTransaction();
        
        // Update report
        $stmt = $pdo->prepare("
            UPDATE reports 
            SET status = 'resolved', resolution = ?, admin_notes = ?, 
                handled_by_admin_id = ?, updated_at = NOW()
            WHERE id = ?
        ");
        
        $stmt->execute([
            sanitizeInput($data['resolution']),
            sanitizeInput($data['admin_notes'] ?? ''),
            $data['admin_id'] ?? null,
            $data['report_id']
        ]);
        
        if ($stmt->rowCount() === 0) {
            throw new Exception('Report not found');
        }
        
        // Get report details for notification
        $stmt = $pdo->prepare("SELECT reporter_user_id, report_type FROM reports WHERE id = ?");
        $stmt->execute([$data['report_id']]);
        $report = $stmt->fetch();
        
        if ($report) {
            // Notify reporter of resolution
            createNotification($report['reporter_user_id'], 'report', 
                'Report Resolved', 
                "Your {$report['report_type']} report has been resolved by our moderation team.",
                'medium');
        }
        
        $pdo->commit();
        
        logActivity($data['admin_id'] ?? null, 'report_resolved', 'Report resolved', [
            'report_id' => $data['report_id']
        ]);
        
        sendJsonResponse([
            'success' => true,
            'message' => 'Report resolved successfully'
        ]);
        
    } catch (Exception $e) {
        $pdo->rollback();
        handleError("Failed to resolve report: " . $e->getMessage());
    }
}

// Helper function to create notifications (already defined in other files)
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
