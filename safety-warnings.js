// BSR Marketplace Safety Warnings System
// This file contains functions for displaying safety warnings and scam prevention tips

// Safety warning banners and tips
const SAFETY_WARNINGS = {
    general: {
        title: "🛡️ Stay Safe While Trading",
        messages: [
            "Always meet in public places for in-person transactions",
            "Never wire money or use gift cards as payment",
            "Verify seller identity before making large purchases",
            "If a deal seems too good to be true, it probably is",
            "Report suspicious activity immediately"
        ]
    },
    unverified_seller: {
        title: "⚠️ Unverified Seller Warning",
        messages: [
            "This seller has not completed phone verification",
            "Exercise extra caution when dealing with unverified sellers",
            "Consider using secure payment methods only",
            "Ask for additional proof of item authenticity"
        ]
    },
    high_value: {
        title: "💰 High-Value Transaction Alert",
        messages: [
            "This is a high-value item - take extra precautions",
            "Consider meeting at a police station safe exchange zone",
            "Verify item authenticity before payment",
            "Use traceable payment methods",
            "Consider bringing a friend for safety"
        ]
    },
    electronics: {
        title: "📱 Electronics Safety Tips",
        messages: [
            "Check device IMEI/serial numbers",
            "Ensure devices are not stolen or blacklisted",
            "Test all functions before purchase",
            "Verify original purchase receipts when possible"
        ]
    },
    vehicles: {
        title: "🚗 Vehicle Purchase Safety",
        messages: [
            "Always inspect the vehicle in person",
            "Verify VIN and title ownership",
            "Get a professional inspection for expensive vehicles",
            "Never send money before seeing the vehicle"
        ]
    },
    red_flags: {
        title: "🚩 Common Scam Warning Signs",
        messages: [
            "Seller asks for payment before meeting",
            "Price is significantly below market value",
            "Seller refuses to meet in person",
            "Poor grammar or generic responses",
            "Requests unusual payment methods",
            "No phone number or verification",
            "Stock photos instead of actual item photos"
        ]
    }
};

// Initialize safety warnings system
function initializeSafetyWarnings() {
    // Add CSS styles for safety warnings
    addSafetyWarningStyles();
    
    // Show general safety banner on page load
    showGeneralSafetyBanner();
    
    // Monitor for high-risk scenarios
    monitorHighRiskScenarios();
}

// Add CSS styles for safety components
function addSafetyWarningStyles() {
    const styles = `
        <style>
        .safety-banner {
            background: linear-gradient(135deg, #fee2e2, #fecaca);
            border: 1px solid #f87171;
            border-radius: 12px;
            padding: 1rem 1.5rem;
            margin-bottom: 1.5rem;
            position: relative;
        }
        
        .safety-banner.warning {
            background: linear-gradient(135deg, #fef3c7, #fbbf24);
            border-color: #f59e0b;
        }
        
        .safety-banner.danger {
            background: linear-gradient(135deg, #fee2e2, #ef4444);
            border-color: #dc2626;
            color: white;
        }
        
        .safety-banner h3 {
            margin: 0 0 0.5rem 0;
            font-size: 1.1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .safety-banner ul {
            margin: 0;
            padding-left: 1.5rem;
            font-size: 0.9rem;
            line-height: 1.4;
        }
        
        .safety-banner li {
            margin-bottom: 0.25rem;
        }
        
        .safety-modal {
            display: none;
            position: fixed;
            z-index: 3000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(4px);
        }
        
        .safety-modal-content {
            background: white;
            margin: 5% auto;
            padding: 2rem;
            border-radius: 16px;
            width: 90%;
            max-width: 600px;
            max-height: 80vh;
            overflow-y: auto;
            position: relative;
        }
        
        .safety-tips-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-top: 1.5rem;
        }
        
        .safety-tip-card {
            background: #f8fafc;
            padding: 1.5rem;
            border-radius: 12px;
            border-left: 4px solid #3b82f6;
        }
        
        .safety-tip-card h4 {
            margin: 0 0 0.5rem 0;
            color: #1e40af;
            font-size: 1rem;
        }
        
        .verification-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            margin-left: 0.5rem;
        }
        
        .verification-badge.verified {
            background: #dcfce7;
            color: #16a34a;
        }
        
        .verification-badge.unverified {
            background: #fef3c7;
            color: #d97706;
        }
        
        .verification-badge.flagged {
            background: #fee2e2;
            color: #dc2626;
        }
        
        .scam-alert {
            position: fixed;
            top: 100px;
            right: 20px;
            background: #dc2626;
            color: white;
            padding: 1rem 1.5rem;
            border-radius: 12px;
            font-weight: 600;
            z-index: 3500;
            animation: slideIn 0.5s ease-out;
            max-width: 350px;
            box-shadow: 0 10px 25px rgba(220, 38, 38, 0.3);
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(100px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        .close-safety-banner {
            position: absolute;
            top: 0.5rem;
            right: 0.75rem;
            background: none;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            color: inherit;
            opacity: 0.7;
        }
        
        .close-safety-banner:hover {
            opacity: 1;
        }
        </style>
    `;
    
    if (!document.getElementById('safety-styles')) {
        const styleElement = document.createElement('div');
        styleElement.id = 'safety-styles';
        styleElement.innerHTML = styles;
        document.head.appendChild(styleElement);
    }
}

// Show general safety banner
function showGeneralSafetyBanner() {
    const existingBanner = document.getElementById('general-safety-banner');
    if (existingBanner || localStorage.getItem('safety_banner_dismissed')) {
        return;
    }
    
    const banner = createSafetyBanner('general', 'warning');
    banner.id = 'general-safety-banner';
    
    // Add dismiss button
    const dismissBtn = document.createElement('button');
    dismissBtn.className = 'close-safety-banner';
    dismissBtn.innerHTML = '×';
    dismissBtn.onclick = () => {
        banner.remove();
        localStorage.setItem('safety_banner_dismissed', 'true');
    };
    banner.appendChild(dismissBtn);
    
    // Insert at the top of the main container
    const container = document.querySelector('.container');
    if (container) {
        container.insertBefore(banner, container.firstChild);
    }
}

// Create safety banner element
function createSafetyBanner(type, severity = 'warning') {
    const warning = SAFETY_WARNINGS[type];
    if (!warning) return null;
    
    const banner = document.createElement('div');
    banner.className = `safety-banner ${severity}`;
    
    const title = document.createElement('h3');
    title.textContent = warning.title;
    
    const list = document.createElement('ul');
    warning.messages.forEach(message => {
        const listItem = document.createElement('li');
        listItem.textContent = message;
        list.appendChild(listItem);
    });
    
    banner.appendChild(title);
    banner.appendChild(list);
    
    return banner;
}

// Monitor for high-risk scenarios
function monitorHighRiskScenarios() {
    // Monitor listing cards for risk factors
    const observer = new MutationObserver((mutations) => {
        mutations.forEach(mutation => {
            mutation.addedNodes.forEach(node => {
                if (node.nodeType === 1 && node.classList?.contains('listing-card')) {
                    assessListingRisk(node);
                }
            });
        });
    });
    
    observer.observe(document.body, {
        childList: true,
        subtree: true
    });
    
    // Assess existing listings
    document.querySelectorAll('.listing-card').forEach(assessListingRisk);
}

// Assess individual listing risk
function assessListingRisk(listingCard) {
    try {
        const priceElement = listingCard.querySelector('.listing-price');
        const titleElement = listingCard.querySelector('.listing-title');
        const typeElement = listingCard.querySelector('.listing-type');
        
        if (!priceElement || !titleElement) return;
        
        const price = parseFloat(priceElement.textContent.replace(/[^0-9.-]+/g, ''));
        const title = titleElement.textContent.toLowerCase();
        const type = typeElement?.textContent.toLowerCase() || '';
        const category = listingCard.dataset.category || '';
        
        let riskLevel = 'low';
        const riskFactors = [];
        
        // High-value item check
        if (price > 1000) {
            riskLevel = 'medium';
            riskFactors.push('high_value');
        }
        
        // Electronics category risks
        if (category === 'electronics' || title.includes('iphone') || title.includes('laptop')) {
            riskFactors.push('electronics');
        }
        
        // Vehicle category risks
        if (category === 'vehicles' || title.includes('car') || title.includes('motorcycle')) {
            riskFactors.push('vehicles');
        }
        
        // Suspiciously low prices
        if (isUnusuallyLowPrice(title, price, category)) {
            riskLevel = 'high';
            riskFactors.push('suspicious_price');
        }
        
        // Add verification badge
        addVerificationBadge(listingCard);
        
        // Add click handler for safety warnings
        listingCard.addEventListener('click', (e) => {
            if (!e.target.closest('.owner-actions')) {
                showListingSafetyWarning(riskFactors, riskLevel);
            }
        });
        
    } catch (error) {
        console.error('Error assessing listing risk:', error);
    }
}

// Check if price is unusually low (potential scam indicator)
function isUnusuallyLowPrice(title, price, category) {
    const suspiciousKeywords = [
        'iphone', 'macbook', 'laptop', 'car', 'motorcycle', 'rolex', 'gucci', 'louis vuitton'
    ];
    
    const minExpectedPrices = {
        'iphone': 200,
        'macbook': 500,
        'laptop': 300,
        'car': 3000,
        'motorcycle': 1500,
        'rolex': 2000
    };
    
    for (const keyword of suspiciousKeywords) {
        if (title.includes(keyword)) {
            const minPrice = minExpectedPrices[keyword] || 100;
            if (price < minPrice * 0.3) { // Less than 30% of expected minimum
                return true;
            }
        }
    }
    
    return false;
}

// Add verification badge to listing
function addVerificationBadge(listingCard) {
    const titleElement = listingCard.querySelector('.listing-title');
    if (!titleElement || titleElement.querySelector('.verification-badge')) {
        return; // Badge already exists
    }
    
    // In a real implementation, you'd get this data from the API
    const isVerified = Math.random() > 0.3; // Simulate verification status
    const isFlagged = Math.random() > 0.9; // Simulate flagged status
    
    const badge = document.createElement('span');
    badge.className = 'verification-badge';
    
    if (isFlagged) {
        badge.classList.add('flagged');
        badge.innerHTML = '⚠️ Flagged';
        badge.title = 'This seller has been flagged for review';
    } else if (isVerified) {
        badge.classList.add('verified');
        badge.innerHTML = '✓ Verified';
        badge.title = 'Phone verified seller';
    } else {
        badge.classList.add('unverified');
        badge.innerHTML = '⚠️ Unverified';
        badge.title = 'Seller has not completed phone verification';
    }
    
    titleElement.appendChild(badge);
}

// Show listing-specific safety warning
function showListingSafetyWarning(riskFactors, riskLevel) {
    if (riskLevel === 'low' && riskFactors.length === 0) {
        return;
    }
    
    const modal = createSafetyModal(riskFactors, riskLevel);
    document.body.appendChild(modal);
    modal.style.display = 'block';
}

// Create safety warning modal
function createSafetyModal(riskFactors, riskLevel) {
    const modal = document.createElement('div');
    modal.className = 'safety-modal';
    
    const modalContent = document.createElement('div');
    modalContent.className = 'safety-modal-content';
    
    // Title
    const title = document.createElement('h2');
    title.innerHTML = `🛡️ Safety Information - ${riskLevel.toUpperCase()} Risk Level`;
    title.style.marginBottom = '1rem';
    title.style.color = riskLevel === 'high' ? '#dc2626' : '#d97706';
    
    // Close button
    const closeBtn = document.createElement('button');
    closeBtn.className = 'close-btn';
    closeBtn.innerHTML = '×';
    closeBtn.onclick = () => modal.remove();
    
    modalContent.appendChild(closeBtn);
    modalContent.appendChild(title);
    
    // Add relevant safety tips
    const tipsGrid = document.createElement('div');
    tipsGrid.className = 'safety-tips-grid';
    
    // Always include general tips
    const generalCard = createSafetyTipCard('general');
    tipsGrid.appendChild(generalCard);
    
    // Add specific risk factor tips
    riskFactors.forEach(factor => {
        if (SAFETY_WARNINGS[factor]) {
            const card = createSafetyTipCard(factor);
            tipsGrid.appendChild(card);
        }
    });
    
    // Add red flags warning for high risk
    if (riskLevel === 'high') {
        const redFlagsCard = createSafetyTipCard('red_flags');
        redFlagsCard.style.borderLeftColor = '#dc2626';
        tipsGrid.appendChild(redFlagsCard);
    }
    
    modalContent.appendChild(tipsGrid);
    
    // Continue button
    const continueBtn = document.createElement('button');
    continueBtn.className = 'submit-btn';
    continueBtn.style.marginTop = '1.5rem';
    continueBtn.textContent = 'I Understand - Continue';
    continueBtn.onclick = () => modal.remove();
    
    modalContent.appendChild(continueBtn);
    modal.appendChild(modalContent);
    
    // Close on outside click
    modal.onclick = (e) => {
        if (e.target === modal) modal.remove();
    };
    
    return modal;
}

// Create individual safety tip card
function createSafetyTipCard(type) {
    const warning = SAFETY_WARNINGS[type];
    if (!warning) return document.createElement('div');
    
    const card = document.createElement('div');
    card.className = 'safety-tip-card';
    
    const title = document.createElement('h4');
    title.textContent = warning.title;
    
    const list = document.createElement('ul');
    list.style.marginTop = '0.5rem';
    list.style.paddingLeft = '1rem';
    
    warning.messages.forEach(message => {
        const listItem = document.createElement('li');
        listItem.textContent = message;
        listItem.style.fontSize = '0.85rem';
        listItem.style.marginBottom = '0.25rem';
        list.appendChild(listItem);
    });
    
    card.appendChild(title);
    card.appendChild(list);
    
    return card;
}

// Show scam alert notification
function showScamAlert(message) {
    const existingAlert = document.querySelector('.scam-alert');
    if (existingAlert) {
        existingAlert.remove();
    }
    
    const alert = document.createElement('div');
    alert.className = 'scam-alert';
    alert.innerHTML = `
        <strong>⚠️ SCAM ALERT</strong><br>
        ${message}
    `;
    
    document.body.appendChild(alert);
    
    // Auto-remove after 8 seconds
    setTimeout(() => {
        if (alert.parentNode) {
            alert.remove();
        }
    }, 8000);
}

// Report suspicious activity
function reportSuspiciousActivity(listingId, reason) {
    // This would integrate with your reporting API
    console.log(`Reporting listing ${listingId} for: ${reason}`);
    showScamAlert('Thank you for reporting suspicious activity. Our team will investigate.');
}

// Enhanced contact seller function with safety warning
function contactSellerSafely(listing) {
    // Show safety reminder before contacting
    const modal = document.createElement('div');
    modal.className = 'safety-modal';
    modal.style.display = 'block';
    
    const modalContent = document.createElement('div');
    modalContent.className = 'safety-modal-content';
    
    modalContent.innerHTML = `
        <button class="close-btn" onclick="this.closest('.safety-modal').remove()">&times;</button>
        <h3 style="color: #d97706; margin-bottom: 1rem;">🛡️ Before You Contact This Seller</h3>
        <div class="safety-tip-card">
            <h4>Safety Reminders:</h4>
            <ul style="margin: 0.5rem 0; padding-left: 1rem;">
                <li>Never send money before seeing the item</li>
                <li>Meet in public places only</li>
                <li>Bring a friend if possible</li>
                <li>Trust your instincts - if something feels wrong, walk away</li>
            </ul>
        </div>
        <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
            <button class="submit-btn" onclick="proceedToContact(${JSON.stringify(listing).replace(/"/g, '&quot;')}); this.closest('.safety-modal').remove();">
                I Understand - Contact Seller
            </button>
            <button class="cancel-btn" onclick="this.closest('.safety-modal').remove();">
                Cancel
            </button>
        </div>
    `;
    
    modal.appendChild(modalContent);
    document.body.appendChild(modal);
}

// Proceed with original contact functionality
function proceedToContact(listing) {
    // Call the original contact function
    if (window.contactSeller) {
        window.contactSeller(listing);
    }
}

// Initialize safety system when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeSafetyWarnings);
} else {
    initializeSafetyWarnings();
}

// Export functions for global use
window.SafetyWarnings = {
    showScamAlert,
    reportSuspiciousActivity,
    contactSellerSafely,
    showListingSafetyWarning
};
