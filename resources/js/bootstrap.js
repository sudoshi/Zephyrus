import axios from 'axios';

window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
window.axios.defaults.withCredentials = true; // This ensures cookies are sent with requests
window.axios.defaults.baseURL = '/';

// Add CSRF token to all requests
const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
if (token) {
    window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token;
}

// Improved error handling with better logging and redirect to login if session expired
window.axios.interceptors.response.use(
    response => response,
    error => {
        // If we get a 401, redirect to login
        if (error.response && error.response.status === 401) {
            if (window.location.pathname !== '/login') {
                console.error('Authentication error:', error.response.status, error.response.statusText);
                console.log('Session expired. Redirecting to login...');
                window.location.href = '/login';
                return Promise.reject(new Error('Session expired. Redirecting to login...'));
            }
        }
        return Promise.reject(error);
    }
);
