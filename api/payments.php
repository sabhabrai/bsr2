<?php
require_once '../config.php';

// Payments are disabled/removed
http_response_code(410);
sendJsonResponse(['error' => 'Payments API is disabled'], 410);
?>
