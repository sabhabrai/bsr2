-- BSR Marketplace - Check Account Status and Troubleshoot Login
-- Run this to see if the account was created and identify issues

USE bsr_marketplace;

-- 1. Check if the database and tables exist
SHOW TABLES LIKE 'users';

-- 2. Check if the email exists in the database
SELECT 
    'Account Check' as test_type,
    CASE 
        WHEN EXISTS (SELECT 1 FROM users WHERE email = 'sabhabrai@gmail.com')
        THEN 'FOUND: Account exists in database'
        ELSE 'NOT FOUND: Account does not exist - SQL script may not have been executed'
    END as result;

-- 3. If account exists, show full account details
SELECT 
    'Account Details' as section,
    id,
    name,
    email,
    account_type,
    status,
    phone_verified,
    is_verified_seller,
    seller_rating,
    created_at,
    updated_at,
    LENGTH(password) as password_hash_length,
    SUBSTRING(password, 1, 10) as password_hash_preview
FROM users 
WHERE email = 'sabhabrai@gmail.com';

-- 4. Check if required columns exist (enhanced schema)
DESCRIBE users;

-- 5. Check if there are any users in the table
SELECT 
    'User Count' as info,
    COUNT(*) as total_users,
    COUNT(CASE WHEN account_type = 'seller' THEN 1 END) as seller_accounts,
    COUNT(CASE WHEN status = 'active' THEN 1 END) as active_accounts
FROM users;

-- 6. Check activity logs for the account
SELECT 
    'Activity Log' as section,
    user_id,
    activity_type,
    action,
    description,
    created_at
FROM activity_logs 
WHERE user_id IN (SELECT id FROM users WHERE email = 'sabhabrai@gmail.com')
ORDER BY created_at DESC
LIMIT 5;

-- 7. Check for any system settings that might affect login
SELECT 
    'System Settings' as section,
    setting_key,
    setting_value,
    description
FROM system_settings 
WHERE setting_key IN ('site_maintenance_mode', 'max_login_attempts', 'login_lockout_time')
LIMIT 10;
