import axios from 'axios';

window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
window.axios.defaults.withCredentials = true; // This ensures cookies are sent with requests
window.axios.defaults.baseURL = '/';

// Automatically add the CSRF token from the cookie to the request headers
axios.interceptors.request.use(config => {
    // Get the XSRF token from the cookie
    const token = document.cookie
        .split('; ')
        .find(row => row.startsWith('XSRF-TOKEN='))
        ?.split('=')[1];
        
    // If found, decode it and add to the X-XSRF-TOKEN header
    if (token) {
        config.headers['X-XSRF-TOKEN'] = decodeURIComponent(token);
    }
    
    return config;
}, error => {
    return Promise.reject(error);
});

// Improved error handling with better logging and redirect to login if session expired
window.axios.interceptors.response.use(
    response => response,
    error => {
        // If we get a 401 or 419, redirect to login
        if (error.response && (error.response.status === 401 || error.response.status === 419)) {
            if (window.location.pathname !== '/login') {
                console.error('Authentication error:', error.response.status, error.response.statusText);
                console.log('Session expired or CSRF token mismatch. Redirecting to login...');
                window.location.href = '/login';
                return Promise.reject(new Error('Session expired. Redirecting to login...'));
            }
        }
        return Promise.reject(error);
    }
);
