import axios from 'axios';

// Simple CSRF token refresh for critical operations
export const ensureValidToken = async () => {
  try {
    await axios.get('/sanctum/csrf-cookie');
    const token = document.head.querySelector('meta[name="csrf-token"]');
    if (token) {
      window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token.content;
      return true;
    }
    throw new Error('CSRF token not found in meta tags');
  } catch (error) {
    console.error('Failed to ensure valid token:', error);
    throw error;
  }
};
