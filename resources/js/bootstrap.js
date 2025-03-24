import axios from 'axios';

window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
window.axios.defaults.withCredentials = true;
window.axios.defaults.baseURL = '/';

// Simple error handling with redirect to login if session expired
window.axios.interceptors.response.use(
    response => response,
    error => {
        // If we get a 401 or 419, just redirect to login
        if (error.response && (error.response.status === 401 || error.response.status === 419)) {
            if (window.location.pathname !== '/login') {
                console.log('Session expired. Redirecting to login...');
                window.location.href = '/login';
                return Promise.reject(new Error('Session expired. Redirecting to login...'));
            }
        }
        return Promise.reject(error);
    }
);
