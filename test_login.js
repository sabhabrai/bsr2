const axios = require('axios');
const bcrypt = require('bcrypt');

// Test credentials
const credentials = {
    email: 'sabhabrai@gmail.com',
    password: 'Khotang3#'
};

console.log('BSR Marketplace - Login Troubleshooting');
console.log('=====================================\n');

async function testLogin() {
    console.log('1. Testing login credentials...');
    console.log(`   Email: ${credentials.email}`);
    console.log(`   Password: ${credentials.password}\n`);

    // Test different possible API endpoints
    const possibleEndpoints = [
        'http://localhost/bsr/api/users.php?action=login',
        'http://localhost:3000/api/users?action=login',
        'http://localhost:8080/api/users.php?action=login',
        'http://127.0.0.1/bsr/api/users.php?action=login',
        'http://localhost/api/users.php?action=login'
    ];

    console.log('2. Testing different API endpoints...\n');

    for (let endpoint of possibleEndpoints) {
        try {
            console.log(`Testing: ${endpoint}`);
            
            const response = await axios.post(endpoint, credentials, {
                headers: {
                    'Content-Type': 'application/json'
                },
                timeout: 5000
            });

            console.log(`✓ SUCCESS: ${endpoint}`);
            console.log('Response:', response.data);
            return;

        } catch (error) {
            if (error.code === 'ECONNREFUSED') {
                console.log(`❌ Connection refused - Server not running`);
            } else if (error.response) {
                console.log(`❌ HTTP ${error.response.status}: ${error.response.statusText}`);
                if (error.response.data) {
                    console.log('Error details:', error.response.data);
                }
            } else {
                console.log(`❌ Network error: ${error.message}`);
            }
        }
        console.log('');
    }

    console.log('3. No API endpoints responded successfully.\n');
    console.log('Troubleshooting steps:\n');
    
    console.log('a) Check if web server is running:');
    console.log('   - XAMPP: Start Apache and MySQL');
    console.log('   - WAMP: Start services');
    console.log('   - Node.js server: Run `node server.js`\n');

    console.log('b) Check database connection:');
    console.log('   - Run the check_account_status.sql script');
    console.log('   - Verify the account was created in the database\n');

    console.log('c) Check file paths:');
    console.log('   - Ensure BSR files are in the correct web server directory');
    console.log('   - Check that api/users.php exists and is accessible\n');

    console.log('4. Testing password hash compatibility...\n');
    await testPasswordHash();
}

async function testPasswordHash() {
    // Test with the hash from our generated script
    const testHashes = [
        '$2b$10$ukV0qUkcey6gWZYtLwUJQ.ZMcGD8fOt7munGzN4Jw4LyN3SP1VXGy', // From bcrypt script
        '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'   // Sample from database
    ];

    for (let hash of testHashes) {
        try {
            const isValid = await bcrypt.compare(credentials.password, hash);
            console.log(`Hash: ${hash.substring(0, 30)}...`);
            console.log(`Valid: ${isValid ? '✓ YES' : '❌ NO'}\n`);
        } catch (error) {
            console.log(`Hash: ${hash.substring(0, 30)}...`);
            console.log(`Error: ${error.message}\n`);
        }
    }
}

// Check if the Node.js server is running
async function checkNodeServer() {
    console.log('5. Checking if Node.js server is running...\n');
    
    try {
        const response = await axios.get('http://localhost:3000', { timeout: 3000 });
        console.log('✓ Node.js server is running');
        console.log('Response:', response.data);
    } catch (error) {
        console.log('❌ Node.js server is not running or not accessible');
        console.log('To start: Run `node server.js` in the BSR directory');
    }
}

// Main execution
async function main() {
    await testLogin();
    await checkNodeServer();
    
    console.log('\n📋 Summary of common login issues:\n');
    console.log('1. Account not created in database');
    console.log('2. Web server (Apache/PHP) not running');
    console.log('3. Database connection issues');
    console.log('4. Incorrect API endpoint URL');
    console.log('5. Password hash format incompatibility');
    console.log('6. CORS or security restrictions');
    
    console.log('\n💡 Quick fixes to try:');
    console.log('• Execute the final_create_owner.sql script if not done yet');
    console.log('• Start your web server (XAMPP, WAMP, etc.)');
    console.log('• Check if BSR files are in the web root directory');
    console.log('• Try accessing http://localhost/bsr/api/users.php directly in browser');
}

main().catch(console.error);
