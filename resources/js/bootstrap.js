import axios from 'axios';
import { setupCsrfRefresh } from './services/csrf';

window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
window.axios.defaults.withCredentials = true;
window.axios.defaults.baseURL = '/';

// Initialize CSRF token from meta tag
const initializeCsrf = async () => {
    try {
        // Fetch the CSRF cookie
        await window.axios.get('/sanctum/csrf-cookie');

        // Get the CSRF token from meta tag
        const token = document.head.querySelector('meta[name="csrf-token"]');
        if (token) {
            window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token.content;
        } else {
            console.error('CSRF token not found in meta tags');
        }
    } catch (error) {
        console.error('Error fetching initial CSRF cookie:', error);
    }
};

// Export the CSRF initialization promise
export const csrfInitialized = initializeCsrf().then(setupCsrfRefresh);
