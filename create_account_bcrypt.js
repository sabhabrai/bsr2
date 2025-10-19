const bcrypt = require('bcrypt');
const fs = require('fs');
const path = require('path');

// Account details
const accountDetails = {
    name: 'owner',
    email: 'sabhabrai@gmail.com',
    password: 'Khotang3#',
    account_type: 'seller'
};

console.log('BSR Marketplace - Creating Owner Account (with proper bcrypt)');
console.log('===========================================================\n');

// Validate password strength
function validatePassword(password) {
    return password.length >= 8 && 
           /[A-Za-z]/.test(password) && 
           /\d/.test(password);
}

async function createAccount() {
    try {
        console.log('1. Validating account details...');
        if (!validatePassword(accountDetails.password)) {
            console.log('❌ Password does not meet requirements');
            process.exit(1);
        }
        console.log('✓ Password validation passed\n');

        console.log('2. Generating bcrypt hash (compatible with PHP)...');
        // Use salt rounds of 10 (same as PHP default)
        const saltRounds = 10;
        const passwordHash = await bcrypt.hash(accountDetails.password, saltRounds);
        console.log('✓ Bcrypt hash generated\n');

        console.log('3. Testing password verification...');
        const isValid = await bcrypt.compare(accountDetails.password, passwordHash);
        if (!isValid) {
            console.log('❌ Password verification test failed');
            process.exit(1);
        }
        console.log('✓ Password verification test passed\n');

        console.log('4. Creating SQL script...');
        const sqlScript = `-- BSR Marketplace - Create Owner Account (Generated with bcrypt)
-- Timestamp: ${new Date().toISOString()}
-- This hash is fully compatible with PHP password_verify()

USE bsr_marketplace;

-- Check if email already exists first
SELECT 
    CASE 
        WHEN EXISTS (SELECT 1 FROM users WHERE email = '${accountDetails.email}')
        THEN 'ERROR: Email already exists!'
        ELSE 'OK: Email available'
    END as email_check;

-- Only proceed if email doesn't exist
INSERT INTO users (
    name, 
    email, 
    password, 
    account_type,
    status,
    phone_verified,
    is_verified_seller,
    seller_rating,
    created_at,
    updated_at
) 
SELECT * FROM (SELECT
    '${accountDetails.name}' as name,
    '${accountDetails.email}' as email,
    '${passwordHash}' as password,
    '${accountDetails.account_type}' as account_type,
    'active' as status,
    TRUE as phone_verified,
    TRUE as is_verified_seller,
    5.00 as seller_rating,
    NOW() as created_at,
    NOW() as updated_at
) AS tmp
WHERE NOT EXISTS (
    SELECT email FROM users WHERE email = '${accountDetails.email}'
) LIMIT 1;

-- Get the result of the insertion
SELECT ROW_COUNT() as rows_affected;

-- If successful, show the created account
SELECT 
    id,
    name,
    email,
    account_type,
    status,
    phone_verified,
    is_verified_seller,
    seller_rating,
    created_at
FROM users 
WHERE email = '${accountDetails.email}';

-- Add activity log (only if account was created)
INSERT INTO activity_logs (
    user_id,
    activity_type,
    action,
    description,
    created_at
)
SELECT 
    id,
    'system_event',
    'account_creation',
    'Owner account created with bcrypt hash',
    NOW()
FROM users 
WHERE email = '${accountDetails.email}' 
AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE);

-- Final success message
SELECT 
    'Account creation completed!' as message,
    'Email: ${accountDetails.email}' as login_email,
    'Password: ${accountDetails.password}' as login_password,
    'Ready for use!' as status;
`;

        // Write the SQL script to file
        const sqlFilePath = path.join(__dirname, 'final_create_owner.sql');
        fs.writeFileSync(sqlFilePath, sqlScript);

        console.log('✓ SQL script created successfully\n');
        console.log('5. Account details prepared:');
        console.log(`   - Name: ${accountDetails.name}`);
        console.log(`   - Email: ${accountDetails.email}`);
        console.log(`   - Account Type: ${accountDetails.account_type}`);
        console.log(`   - Password Hash: ${passwordHash.substring(0, 30)}...`);
        console.log(`   - SQL Script: ${sqlFilePath}\n`);

        // Create a PHP verification script
        const phpVerifyScript = `<?php
// PHP password verification test for the created account
$password = '${accountDetails.password}';
$hash = '${passwordHash}';

echo "Testing PHP password verification...\\n";
echo "Password: $password\\n";
echo "Hash: $hash\\n\\n";

// Test if the hash is compatible with PHP
if (password_verify($password, $hash)) {
    echo "✓ SUCCESS: Password verification works with PHP!\\n";
    echo "✓ The bcrypt hash is fully compatible with PHP's password_verify()\\n";
} else {
    echo "❌ ERROR: Password verification failed with PHP\\n";
}

echo "\\nAccount ready for use in BSR Marketplace!\\n";
?>`;

        fs.writeFileSync(path.join(__dirname, 'test_php_verification.php'), phpVerifyScript);

        console.log('6. Instructions:');
        console.log('   a) To complete account creation, execute the SQL script:');
        console.log(`      - Import ${sqlFilePath} into your MySQL database`);
        console.log('      - Or run: mysql -u root -p bsr_marketplace < final_create_owner.sql');
        console.log('\n   b) To test PHP compatibility:');
        console.log('      - Run: php test_php_verification.php');
        console.log('\n🎉 Account setup completed successfully!');
        console.log('\n📧 Login Credentials:');
        console.log(`   Email: ${accountDetails.email}`);
        console.log(`   Password: ${accountDetails.password}`);
        console.log('\n✅ The account is ready to be created in the database!');

    } catch (error) {
        console.log('❌ Error:', error.message);
        process.exit(1);
    }
}

// Run the account creation process
createAccount();
