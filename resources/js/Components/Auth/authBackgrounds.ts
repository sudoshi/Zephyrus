// Atmospheric wind/sky slideshow sources (deep navy + cyan tonality).
// The fallback gradient renders BENEATH the images, so missing/slow/404
// images degrade gracefully to an intentional-looking background.
export const AUTH_BACKGROUND_IMAGES: string[] = [
  '/images/auth/wind-01.jpg',
  '/images/auth/wind-02.jpg',
  '/images/auth/wind-03.jpg',
  '/images/auth/wind-04.jpg',
  '/images/auth/wind-05.jpg',
  '/images/17017066_8_blue.jpg', // existing particle-flow image already in repo
];

export const AUTH_BACKGROUND_FALLBACK: string =
  'radial-gradient(ellipse at 25% 30%, #1e2a5a 0%, transparent 60%),' +
  'radial-gradient(ellipse at 80% 70%, #0a2540 0%, transparent 55%),' +
  'linear-gradient(160deg, #0b1120 0%, #0a0f1f 100%)';
