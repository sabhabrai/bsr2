<?php
// PHP password verification test for the created account
$password = 'Khotang3#';
$hash = '$2b$10$ukV0qUkcey6gWZYtLwUJQ.ZMcGD8fOt7munGzN4Jw4LyN3SP1VXGy';

echo "Testing PHP password verification...\n";
echo "Password: $password\n";
echo "Hash: $hash\n\n";

// Test if the hash is compatible with PHP
if (password_verify($password, $hash)) {
    echo "✓ SUCCESS: Password verification works with PHP!\n";
    echo "✓ The bcrypt hash is fully compatible with PHP's password_verify()\n";
} else {
    echo "❌ ERROR: Password verification failed with PHP\n";
}

echo "\nAccount ready for use in BSR Marketplace!\n";
?>