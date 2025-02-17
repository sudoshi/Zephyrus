import axios from 'axios';

window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
window.axios.defaults.withCredentials = true;
window.axios.defaults.baseURL = '/';

// Initialize CSRF token from meta tag
const token = document.head.querySelector('meta[name="csrf-token"]');
if (token) {
    window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token.content;
}

let isRetrying = false;

// Add interceptor
window.axios.interceptors.response.use(
    response => response,
    async error => {
        if (error.response && error.response.status === 419 && !isRetrying) {
            isRetrying = true;
            try {
                await window.axios.get('/sanctum/csrf-cookie');
                // Update the CSRF token header
                const newToken = document.head.querySelector('meta[name="csrf-token"]');
                if (newToken) {
                    window.axios.defaults.headers.common['X-CSRF-TOKEN'] = newToken.content;
                    error.config.headers['X-CSRF-TOKEN'] = newToken.content;
                }
                isRetrying = false;
                return window.axios.request(error.config);
            } catch (csrfError) {
                isRetrying = false;
                return Promise.reject(csrfError);
            }
        }
        return Promise.reject(error);
    }
);
