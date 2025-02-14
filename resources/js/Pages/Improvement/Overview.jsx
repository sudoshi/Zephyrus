import { useEffect } from 'react';
import { router } from '@inertiajs/react';

export default function Overview() {
  useEffect(() => {
    // Redirect to the dashboard improvement page
    router.visit('/dashboard/improvement');
  }, []);

  return null; // No need to render anything since we're redirecting
}
