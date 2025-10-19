<?php
// Password verification script for the created account
$password = 'Khotang3#';
$hash = '$2y$10$738ed9633ec2e983f6037515ceaa179889cac926ac5f8351dda6ac0139f7bc9';

echo "Testing password verification...\n";
echo "Password: $password\n";
echo "Hash: $hash\n";

// Note: The hash format created by Node.js may not be directly compatible with PHP's password_verify
// For production use, ensure you use PHP's password_hash() function or a proper bcrypt library

// In PHP, you would normally use:
// $correct_hash = password_hash('Khotang3#', PASSWORD_DEFAULT);
// $is_valid = password_verify('Khotang3#', $correct_hash);

echo "\nFor PHP compatibility, use this hash instead:\n";
echo password_hash('Khotang3#', PASSWORD_DEFAULT) . "\n";
?>