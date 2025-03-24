import axios from 'axios';

window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
window.axios.defaults.withCredentials = true;
window.axios.defaults.baseURL = '/';

// Function to update CSRF token from meta tag
const updateCsrfToken = () => {
    const token = document.head.querySelector('meta[name="csrf-token"]');
    if (token) {
        window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token.content;
        
        // Also set X-XSRF-TOKEN header from cookie if available
        const xsrfToken = getCookie('XSRF-TOKEN');
        if (xsrfToken) {
            window.axios.defaults.headers.common['X-XSRF-TOKEN'] = decodeURIComponent(xsrfToken);
        }
    }
};

// Helper function to get cookie value by name
const getCookie = (name) => {
    const value = `; ${document.cookie}`;
    const parts = value.split(`; ${name}=`);
    if (parts.length === 2) return parts.pop().split(';').shift();
    return null;
};

// Initialize CSRF token
updateCsrfToken();

// Set up an event listener to update the CSRF token when the page changes
// This is important for SPA navigation
document.addEventListener('DOMContentLoaded', updateCsrfToken);

// For Inertia.js pages, update token after page visits
if (window.Inertia) {
    window.Inertia.on('success', updateCsrfToken);
}

let isRetrying = false;

// Add interceptor to handle 419 CSRF token errors
window.axios.interceptors.response.use(
    response => response,
    async error => {
        if (error.response && error.response.status === 419 && !isRetrying) {
            isRetrying = true;
            try {
                // Fetch a new CSRF token
                await window.axios.get('/sanctum/csrf-cookie');
                
                // Update the token in the headers
                updateCsrfToken();
                
                // Update the token in the failed request and retry
                if (error.config) {
                    const token = document.head.querySelector('meta[name="csrf-token"]');
                    if (token) {
                        error.config.headers['X-CSRF-TOKEN'] = token.content;
                    }
                    
                    const xsrfToken = getCookie('XSRF-TOKEN');
                    if (xsrfToken) {
                        error.config.headers['X-XSRF-TOKEN'] = decodeURIComponent(xsrfToken);
                    }
                }
                
                isRetrying = false;
                return window.axios.request(error.config);
            } catch (csrfError) {
                console.error('Failed to refresh CSRF token:', csrfError);
                isRetrying = false;
                
                // If we still can't get a token, redirect to login
                if (window.location.pathname !== '/login') {
                    window.location.href = '/login';
                    return Promise.reject(new Error('Session expired. Redirecting to login...'));
                }
                
                return Promise.reject(csrfError);
            }
        }
        return Promise.reject(error);
    }
);
