/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_REVERB_APP_KEY: string;
  readonly VITE_REVERB_HOST: string;
  readonly VITE_REVERB_PORT: string;
  readonly VITE_REVERB_SCHEME: string;
  readonly VITE_REALTIME_ENABLED?: string;
  readonly VITE_REVERB_ALLOW_CROSS_ORIGIN?: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}
