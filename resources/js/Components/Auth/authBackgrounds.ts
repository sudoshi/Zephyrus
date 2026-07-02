export interface AuthBackgroundSlide {
  src: string;
  position: string;
}

// Hummingbird slideshow sources copied from hummingbird/images and web-sized
// under public/images/auth/hummingbirds for the login surface.
export const AUTH_BACKGROUND_SLIDES: AuthBackgroundSlide[] = [
  { src: '/images/auth/hummingbirds/hummingbird-10.jpg', position: '48% 44%' },
  { src: '/images/auth/hummingbirds/hummingbird-05.jpg', position: '50% 42%' },
  { src: '/images/auth/hummingbirds/hummingbird-04.jpg', position: '45% 46%' },
  { src: '/images/auth/hummingbirds/hummingbird-11.jpg', position: '48% 43%' },
  { src: '/images/auth/hummingbirds/hummingbird-12.jpg', position: '50% 44%' },
  { src: '/images/auth/hummingbirds/hummingbird-01.jpg', position: '48% 46%' },
  { src: '/images/auth/hummingbirds/hummingbird-08.jpg', position: '50% 42%' },
  { src: '/images/auth/hummingbirds/hummingbird-09.jpg', position: '50% 44%' },
  { src: '/images/auth/hummingbirds/hummingbird-06.jpg', position: '50% 42%' },
  { src: '/images/auth/hummingbirds/hummingbird-03.jpg', position: '48% 44%' },
  { src: '/images/auth/hummingbirds/hummingbird-02.jpg', position: '50% 43%' },
  { src: '/images/auth/hummingbirds/hummingbird-07.jpg', position: '50% 45%' },
];

export const AUTH_BACKGROUND_IMAGES: string[] = AUTH_BACKGROUND_SLIDES.map(({ src }) => src);

export const AUTH_BACKGROUND_FALLBACK: string =
  'radial-gradient(ellipse at 18% 22%, #123626 0%, transparent 58%),' +
  'radial-gradient(ellipse at 82% 70%, #17264a 0%, transparent 56%),' +
  'linear-gradient(160deg, #06100f 0%, #09111f 100%)';
