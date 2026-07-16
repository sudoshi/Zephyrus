import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

declare global {
  interface Window {
    Pusher: typeof Pusher;
    Echo?: Echo<'reverb'>;
  }
}

window.Pusher = Pusher;

const key = import.meta.env.VITE_REVERB_APP_KEY?.trim();
const wsHost = import.meta.env.VITE_REVERB_HOST?.trim();
const sameOrigin = wsHost === window.location.hostname;
const realtimeEnabled = import.meta.env.VITE_REALTIME_ENABLED !== 'false'
  && Boolean(key && wsHost)
  && (sameOrigin || import.meta.env.VITE_REVERB_ALLOW_CROSS_ORIGIN === 'true');

export const echo: Echo<'reverb'> | null = realtimeEnabled
  ? new Echo({
      broadcaster: 'reverb',
      key,
      wsHost,
      wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
      wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
      forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'http') === 'https',
      enabledTransports: ['ws', 'wss'],
    })
  : null;

if (echo !== null) window.Echo = echo;
