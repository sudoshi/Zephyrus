import axios from 'axios';

// CSRF token refresh for critical operations.
// Laravel automatically sets the XSRF-TOKEN cookie, and Axios (with withXSRFToken: true)
// automatically reads it and sends it as the X-XSRF-TOKEN header.
// This function can be used to explicitly refresh the cookie if needed.
export const ensureValidToken = async () => {
  try {
    await axios.get('/sanctum/csrf-cookie');
    return true;
  } catch (error) {
    console.error('Failed to refresh CSRF cookie:', error);
    throw error;
  }
};
