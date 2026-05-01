/**
 * BlockTicker Frontend Utilities Module
 * 
 * Modern ES6+ JavaScript utilities for frontend interactions.
 * Replaces inline scripts and monolithic revamp-v44.js file.
 * 
 * @package BlockTicker/Assets/JS
 * @since 119.29.0
 */

'use strict';

/**
 * Namespace for all BlockTicker frontend utilities
 */
const BlockTicker = window.BlockTicker || {};

/**
 * Loading skeleton utilities for better UX during data fetch
 */
BlockTicker.Skeleton = {
    /**
     * Show skeleton loader on an element
     * @param {HTMLElement} element - Target element
     * @param {string} type - Type: 'text', 'title', 'card'
     */
    show: function(element, type = 'text') {
        if (!element) return;
        
        element.classList.add('bt-skeleton');
        element.classList.add(`bt-skeleton--${type}`);
        element.setAttribute('aria-busy', 'true');
        element.setAttribute('aria-label', 'Loading...');
    },
    
    /**
     * Hide skeleton loader from an element
     * @param {HTMLElement} element - Target element
     */
    hide: function(element) {
        if (!element) return;
        
        element.classList.remove('bt-skeleton', 'bt-skeleton--text', 'bt-skeleton--title', 'bt-skeleton--card');
        element.removeAttribute('aria-busy');
        element.removeAttribute('aria-label');
    }
};

/**
 * Price formatting utilities
 */
BlockTicker.Price = {
    /**
     * Format price with appropriate decimals
     * @param {number} price - Raw price value
     * @param {string} currency - Currency code (USD, EUR, etc.)
     * @returns {string} Formatted price string
     */
    format: function(price, currency = 'USD') {
        if (price === null || price === undefined || isNaN(price)) {
            return 'N/A';
        }
        
        const isCrypto = ['BTC', 'ETH', 'SOL'].includes(currency);
        const decimals = price < 1 ? 6 : (isCrypto ? 2 : 2);
        
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: currency,
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals
        }).format(price);
    },
    
    /**
     * Format percentage change with color class
     * @param {number} change - Percentage change value
     * @returns {object} Object with formatted value and CSS class
     */
    formatChange: function(change) {
        if (change === null || change === undefined || isNaN(change)) {
            return { value: 'N/A', class: 'bt-price-neutral' };
        }
        
        const sign = change > 0 ? '+' : '';
        const cls = change > 0 ? 'bt-price-up' : (change < 0 ? 'bt-price-down' : 'bt-price-neutral');
        
        return {
            value: `${sign}${change.toFixed(2)}%`,
            class: cls
        };
    }
};

/**
 * AJAX utilities with rate limiting awareness
 */
BlockTicker.AJAX = {
    /**
     * Make AJAX request with error handling
     * @param {string} action - WordPress AJAX action name
     * @param {object} data - Request data
     * @param {object} options - Fetch options
     * @returns {Promise} Response promise
     */
    request: async function(action, data = {}, options = {}) {
        const formData = new FormData();
        formData.append('action', action);
        
        // Add nonce if available
        const nonce = document.querySelector('meta[name="bt-nonce"]')?.content;
        if (nonce) {
            formData.append('_wpnonce', nonce);
        }
        
        // Merge provided data
        Object.keys(data).forEach(key => {
            formData.append(key, data[key]);
        });
        
        try {
            const response = await fetch('/wp-admin/admin-ajax.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                ...options
            });
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            const result = await response.json();
            
            if (result.success === false) {
                throw new Error(result.data?.message || 'Request failed');
            }
            
            return result.data;
        } catch (error) {
            console.error('[BlockTicker] AJAX error:', error);
            throw error;
        }
    },
    
    /**
     * Debounce function to prevent rapid-fire requests
     * @param {Function} func - Function to debounce
     * @param {number} wait - Wait time in milliseconds
     * @returns {Function} Debounced function
     */
    debounce: function(func, wait = 300) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }
};

/**
 * Accessibility utilities
 */
BlockTicker.A11y = {
    /**
     * Announce message to screen readers
     * @param {string} message - Message to announce
     * @param {string} priority - Priority: 'polite' or 'assertive'
     */
    announce: function(message, priority = 'polite') {
        let announcer = document.getElementById('bt-a11y-announcer');
        
        if (!announcer) {
            announcer = document.createElement('div');
            announcer.id = 'bt-a11y-announcer';
            announcer.setAttribute('role', 'status');
            announcer.setAttribute('aria-live', priority);
            announcer.setAttribute('aria-atomic', 'true');
            announcer.className = 'sr-only';
            document.body.appendChild(announcer);
        }
        
        // Clear previous announcement
        announcer.textContent = '';
        
        // Set new announcement after brief delay
        setTimeout(() => {
            announcer.textContent = message;
        }, 100);
    },
    
    /**
     * Trap focus within a container (for modals/dialogs)
     * @param {HTMLElement} container - Container element
     * @returns {Function} Cleanup function to remove trap
     */
    trapFocus: function(container) {
        const focusableSelectors = [
            'button:not([disabled])',
            'input:not([disabled])',
            'select:not([disabled])',
            'textarea:not([disabled])',
            'a[href]',
            '[tabindex]:not([tabindex="-1"])'
        ].join(', ');
        
        const focusableElements = container.querySelectorAll(focusableSelectors);
        const firstElement = focusableElements[0];
        const lastElement = focusableElements[focusableElements.length - 1];
        
        function handleKeydown(e) {
            if (e.key !== 'Tab') return;
            
            if (e.shiftKey) {
                if (document.activeElement === firstElement) {
                    e.preventDefault();
                    lastElement.focus();
                }
            } else {
                if (document.activeElement === lastElement) {
                    e.preventDefault();
                    firstElement.focus();
                }
            }
        }
        
        container.addEventListener('keydown', handleKeydown);
        firstElement?.focus();
        
        // Return cleanup function
        return () => {
            container.removeEventListener('keydown', handleKeydown);
        };
    }
};

/**
 * Local storage wrapper with expiration
 */
BlockTicker.Storage = {
    /**
     * Set item with optional expiration
     * @param {string} key - Storage key
     * @param {any} value - Value to store
     * @param {number} ttl - Time to live in seconds (optional)
     */
    set: function(key, value, ttl = null) {
        const item = {
            value: value,
            expiry: ttl ? Date.now() + (ttl * 1000) : null
        };
        localStorage.setItem(`bt_${key}`, JSON.stringify(item));
    },
    
    /**
     * Get item checking expiration
     * @param {string} key - Storage key
     * @param {any} defaultValue - Default value if not found or expired
     * @returns {any} Stored value or default
     */
    get: function(key, defaultValue = null) {
        const itemStr = localStorage.getItem(`bt_${key}`);
        if (!itemStr) return defaultValue;
        
        try {
            const item = JSON.parse(itemStr);
            
            // Check expiration
            if (item.expiry && Date.now() > item.expiry) {
                localStorage.removeItem(`bt_${key}`);
                return defaultValue;
            }
            
            return item.value;
        } catch (e) {
            return defaultValue;
        }
    },
    
    /**
     * Remove item from storage
     * @param {string} key - Storage key
     */
    remove: function(key) {
        localStorage.removeItem(`bt_${key}`);
    }
};

// Export to global scope
window.BlockTicker = BlockTicker;

// Initialize on DOM ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        console.log('[BlockTicker] Frontend utilities loaded');
    });
} else {
    console.log('[BlockTicker] Frontend utilities loaded');
}
