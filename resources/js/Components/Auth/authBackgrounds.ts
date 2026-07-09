export interface AuthBackgroundSlide {
  src: string;
  position: string;
}

// The original Zephyrus wind/wave carousel. These atmospheric flow images
// preserve the deep navy and cyan palette of the auth experience.
export const AUTH_BACKGROUND_SLIDES: AuthBackgroundSlide[] = [
  { src: '/images/auth/wind-01.jpg', position: 'center' },
  { src: '/images/auth/wind-02.jpg', position: 'center' },
  { src: '/images/auth/wind-03.jpg', position: 'center' },
  { src: '/images/auth/wind-04.jpg', position: 'center' },
  { src: '/images/auth/wind-05.jpg', position: 'center' },
  { src: '/images/auth/wind-06.jpg', position: 'center' },
];

export const AUTH_BACKGROUND_IMAGES: string[] = AUTH_BACKGROUND_SLIDES.map(({ src }) => src);

export const AUTH_BACKGROUND_FALLBACK: string =
  'radial-gradient(ellipse at 25% 30%, #1e2a5a 0%, transparent 60%),' +
  'radial-gradient(ellipse at 80% 70%, #0a2540 0%, transparent 55%),' +
  'linear-gradient(160deg, #0b1120 0%, #0a0f1f 100%)';
