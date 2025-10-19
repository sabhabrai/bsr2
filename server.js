const express = require('express');
const cors = require('cors');
const path = require('path');
const fs = require('fs');
const EnvEncryption = require('./encrypt-env');

// Handle encrypted .env files
if (fs.existsSync('.env.encrypted') && !fs.existsSync('.env')) {
    console.log('🔒 Encrypted .env file detected. Please decrypt first:');
    console.log('Run: node encrypt-env.js decrypt');
    process.exit(1);
}

require('dotenv').config();

const app = express();
const port = process.env.PORT || 3001;

// Middleware
app.use(cors());
app.use(express.json());
app.use(express.static(path.join(__dirname)));

// Twilio disabled
const accountSid = process.env.TWILIO_ACCOUNT_SID;
const authToken = process.env.TWILIO_AUTH_TOKEN;
const twilioPhoneNumber = process.env.TWILIO_PHONE_NUMBER;

// Rate limiting for SMS sending (simple in-memory store)
const smsRateLimit = new Map();
const SMS_RATE_LIMIT_WINDOW = 60000; // 1 minute
const SMS_RATE_LIMIT_MAX = 2; // Max 2 SMS per minute per phone number

// Global data storage (in-memory for simplicity - in production, use a database)
let globalListings = [];
let globalUsers = [];
let globalMessages = [];
let globalBookmarks = [];

// Helper function to check rate limiting
function checkRateLimit(phoneNumber) {
    const now = Date.now();
    const key = phoneNumber;
    
    if (!smsRateLimit.has(key)) {
        smsRateLimit.set(key, { count: 0, resetTime: now + SMS_RATE_LIMIT_WINDOW });
        return true;
    }
    
    const rateLimitData = smsRateLimit.get(key);
    
    // Reset if window has passed
    if (now > rateLimitData.resetTime) {
        smsRateLimit.set(key, { count: 0, resetTime: now + SMS_RATE_LIMIT_WINDOW });
        return true;
    }
    
    // Check if under limit
    if (rateLimitData.count < SMS_RATE_LIMIT_MAX) {
        rateLimitData.count++;
        return true;
    }
    
    return false;
}

// Phone number validation function
function validatePhoneNumber(phoneNumber) {
    // Remove all non-digit characters
    const cleaned = phoneNumber.replace(/\D/g, '');
    
    // Check if it's a valid US phone number (10 digits) or international (7-15 digits)
    if (cleaned.length === 10) {
        return '+1' + cleaned; // Add US country code
    } else if (cleaned.length >= 7 && cleaned.length <= 15) {
        return '+' + cleaned;
    }
    
    return null;
}

// SMS endpoint disabled
app.post('/api/send-sms', async (req, res) => {
    return res.status(410).json({ success: false, error: 'SMS service disabled' });
});

// API Endpoints for Global Data Storage

// Listings API
app.get('/api/listings', (req, res) => {
    res.json({ success: true, listings: globalListings });
});

app.post('/api/listings', (req, res) => {
    const listing = req.body;
    globalListings.push(listing);
    res.json({ success: true, message: 'Listing created', listing });
});

app.delete('/api/listings/:id', (req, res) => {
    const id = req.params.id;
    const index = globalListings.findIndex(l => l.id == id);
    if (index > -1) {
        globalListings.splice(index, 1);
        res.json({ success: true, message: 'Listing deleted' });
    } else {
        res.status(404).json({ success: false, error: 'Listing not found' });
    }
});

// Users API
app.get('/api/users', (req, res) => {
    res.json({ success: true, users: globalUsers });
});

app.post('/api/users', (req, res) => {
    const user = req.body;
    // Check if user already exists
    const existingUser = globalUsers.find(u => u.email === user.email);
    if (existingUser) {
        return res.status(400).json({ success: false, error: 'User already exists' });
    }
    globalUsers.push(user);
    res.json({ success: true, message: 'User created', user });
});

// Login API
app.post('/api/login', (req, res) => {
    const { email, password } = req.body;
    const user = globalUsers.find(u => u.email === email && u.password === password);
    if (user) {
        res.json({ success: true, user: { id: user.id, name: user.name, email: user.email, phone: user.phone } });
    } else {
        res.status(401).json({ success: false, error: 'Invalid credentials' });
    }
});

// Messages API
app.get('/api/messages', (req, res) => {
    res.json({ success: true, messages: globalMessages });
});

app.post('/api/messages', (req, res) => {
    const message = req.body;
    globalMessages.push(message);
    res.json({ success: true, message: 'Message sent', messageData: message });
});

app.put('/api/messages/:id/read', (req, res) => {
    const id = req.params.id;
    const message = globalMessages.find(m => m.id === id);
    if (message) {
        message.read = true;
        res.json({ success: true, message: 'Message marked as read' });
    } else {
        res.status(404).json({ success: false, error: 'Message not found' });
    }
});

// Bookmarks API
app.get('/api/bookmarks', (req, res) => {
    res.json({ success: true, bookmarks: globalBookmarks });
});

app.post('/api/bookmarks', (req, res) => {
    const bookmark = req.body;
    globalBookmarks.push(bookmark);
    res.json({ success: true, message: 'Bookmark added', bookmark });
});

app.delete('/api/bookmarks/:userId/:listingId', (req, res) => {
    const { userId, listingId } = req.params;
    const index = globalBookmarks.findIndex(b => b.user_id == userId && b.listing_id == listingId);
    if (index > -1) {
        globalBookmarks.splice(index, 1);
        res.json({ success: true, message: 'Bookmark removed' });
    } else {
        res.status(404).json({ success: false, error: 'Bookmark not found' });
    }
});

// Health check endpoint
app.get('/api/health', (req, res) => {
    res.json({ status: 'OK', timestamp: new Date().toISOString() });
});

// Serve the main HTML file
app.get('/', (req, res) => {
    res.sendFile(path.join(__dirname, 'index.html'));
});

// Start the server
app.listen(port, () => {
    console.log(`🚀 BSR SMS Server running on http://localhost:${port}`);
});
