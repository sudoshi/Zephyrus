import axios from 'axios';

let isRefreshing = false;
let refreshSubscribers = [];
let lastTokenRefresh = 0;
const TOKEN_REFRESH_INTERVAL = 30 * 60 * 1000; // 30 minutes

// Add a callback to the stack of subscribers
const subscribeTokenRefresh = (callback) => refreshSubscribers.push(callback);

// Execute all subscribers with the new token
const onTokenRefreshed = () => {
  refreshSubscribers.map(callback => callback());
  refreshSubscribers = [];
};

// Verify if token needs refresh
const shouldRefreshToken = () => {
  return Date.now() - lastTokenRefresh >= TOKEN_REFRESH_INTERVAL;
};

// Get new CSRF token
const refreshCsrfToken = async (force = false) => {
  try {
    if (!force && !shouldRefreshToken()) {
      return document.head.querySelector('meta[name="csrf-token"]')?.content;
    }

    const response = await axios.get('/sanctum/csrf-cookie');
    const token = document.head.querySelector('meta[name="csrf-token"]');
    
    if (token) {
      window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token.content;
      lastTokenRefresh = Date.now();
      return token.content;
    }
    
    throw new Error('CSRF token not found in meta tags');
  } catch (error) {
    console.error('Error refreshing CSRF token:', error);
    throw error;
  }
};

// Ensure token is valid before a critical operation
export const ensureValidToken = async () => {
  try {
    await refreshCsrfToken(true);
    return true;
  } catch (error) {
    console.error('Failed to ensure valid token:', error);
    return false;
  }
};

// Setup axios interceptor for CSRF token refresh
export const setupCsrfRefresh = () => {
  // Request interceptor to check token before critical operations
  window.axios.interceptors.request.use(
    async config => {
      // Check if this is a critical operation (like login)
      if (config.url === '/login' || config.method !== 'get') {
        try {
          await refreshCsrfToken();
        } catch (error) {
          console.error('Failed to refresh token before critical operation:', error);
        }
      }
      return config;
    },
    error => Promise.reject(error)
  );

  // Response interceptor to handle 419 errors
  window.axios.interceptors.response.use(
    response => response,
    async error => {
      const { config, response } = error;
      
      // If error is not 419 or request already retried, reject
      if (!response || response.status !== 419 || config._retry) {
        return Promise.reject(error);
      }

      config._retry = true;

      if (!isRefreshing) {
        isRefreshing = true;

        try {
          await refreshCsrfToken(true);
          onTokenRefreshed();
        } catch (refreshError) {
          refreshSubscribers = [];
          return Promise.reject(refreshError);
        } finally {
          isRefreshing = false;
        }
      }

      // Create new promise to retry original request
      return new Promise(resolve => {
        subscribeTokenRefresh(() => {
          resolve(window.axios(config));
        });
      });
    }
  );
};
