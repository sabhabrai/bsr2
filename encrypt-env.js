const crypto = require('crypto');
const fs = require('fs');
const path = require('path');

// Simple encryption utility for .env files
class EnvEncryption {
    constructor(password) {
        this.password = password;
        this.algorithm = 'aes-256-gcm';
        this.keyDerivation = 'pbkdf2';
    }

    // Derive key from password
    deriveKey(salt) {
        return crypto.pbkdf2Sync(this.password, salt, 100000, 32, 'sha256');
    }

    // Encrypt the .env file content
    encrypt(plaintext) {
        const salt = crypto.randomBytes(16);
        const key = this.deriveKey(salt);
        const iv = crypto.randomBytes(16);
        
        const cipher = crypto.createCipher(this.algorithm, key);
        cipher.setAAD(salt);
        
        let encrypted = cipher.update(plaintext, 'utf8', 'hex');
        encrypted += cipher.final('hex');
        
        const authTag = cipher.getAuthTag();
        
        return {
            salt: salt.toString('hex'),
            iv: iv.toString('hex'),
            authTag: authTag.toString('hex'),
            encrypted: encrypted
        };
    }

    // Decrypt the .env file content
    decrypt(encryptedData) {
        const salt = Buffer.from(encryptedData.salt, 'hex');
        const key = this.deriveKey(salt);
        const iv = Buffer.from(encryptedData.iv, 'hex');
        const authTag = Buffer.from(encryptedData.authTag, 'hex');
        
        const decipher = crypto.createDecipher(this.algorithm, key);
        decipher.setAAD(salt);
        decipher.setAuthTag(authTag);
        
        let decrypted = decipher.update(encryptedData.encrypted, 'hex', 'utf8');
        decrypted += decipher.final('utf8');
        
        return decrypted;
    }
}

// Command line interface
async function main() {
    const args = process.argv.slice(2);
    const command = args[0];
    
    if (!command || !['encrypt', 'decrypt'].includes(command)) {
        console.log('Usage:');
        console.log('  node encrypt-env.js encrypt   # Encrypt .env file');
        console.log('  node encrypt-env.js decrypt   # Decrypt .env.encrypted file');
        return;
    }

    // Get password from user
    const readline = require('readline').createInterface({
        input: process.stdin,
        output: process.stdout
    });

    const askPassword = (prompt) => {
        return new Promise((resolve) => {
            process.stdout.write(prompt);
            process.stdin.setRawMode(true);
            process.stdin.resume();
            
            let password = '';
            process.stdin.on('data', (char) => {
                char = char.toString();
                
                if (char === '\r' || char === '\n') {
                    process.stdin.setRawMode(false);
                    process.stdin.pause();
                    console.log('');
                    resolve(password);
                } else if (char === '\u0003') { // Ctrl+C
                    process.exit();
                } else if (char === '\u007f') { // Backspace
                    if (password.length > 0) {
                        password = password.slice(0, -1);
                        process.stdout.write('\b \b');
                    }
                } else {
                    password += char;
                    process.stdout.write('*');
                }
            });
        });
    };

    try {
        if (command === 'encrypt') {
            // Read .env file
            if (!fs.existsSync('.env')) {
                console.error('❌ .env file not found!');
                return;
            }

            const envContent = fs.readFileSync('.env', 'utf8');
            const password = await askPassword('🔐 Enter encryption password: ');
            
            const encryptor = new EnvEncryption(password);
            const encrypted = encryptor.encrypt(envContent);
            
            // Save encrypted data
            fs.writeFileSync('.env.encrypted', JSON.stringify(encrypted, null, 2));
            
            // Optionally backup and remove original
            fs.renameSync('.env', '.env.backup');
            
            console.log('✅ .env file encrypted successfully!');
            console.log('📁 Original saved as .env.backup');
            console.log('🔒 Encrypted file: .env.encrypted');
            
        } else if (command === 'decrypt') {
            // Read encrypted file
            if (!fs.existsSync('.env.encrypted')) {
                console.error('❌ .env.encrypted file not found!');
                return;
            }

            const encryptedData = JSON.parse(fs.readFileSync('.env.encrypted', 'utf8'));
            const password = await askPassword('🔓 Enter decryption password: ');
            
            const encryptor = new EnvEncryption(password);
            
            try {
                const decrypted = encryptor.decrypt(encryptedData);
                fs.writeFileSync('.env', decrypted);
                
                console.log('✅ .env file decrypted successfully!');
                console.log('📁 Decrypted file: .env');
                
            } catch (error) {
                console.error('❌ Failed to decrypt. Wrong password or corrupted data.');
            }
        }
        
    } catch (error) {
        console.error('❌ Error:', error.message);
    }
    
    readline.close();
}

if (require.main === module) {
    main();
}

module.exports = EnvEncryption;
