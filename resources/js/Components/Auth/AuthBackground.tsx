import { useEffect, useState } from 'react';
import { AUTH_BACKGROUND_IMAGES, AUTH_BACKGROUND_FALLBACK } from '@/Components/Auth/authBackgrounds';

const INTERVAL_MS = 8000;

function prefersReducedMotion(): boolean {
  return typeof window !== 'undefined'
    && typeof window.matchMedia === 'function'
    && window.matchMedia('(prefers-reduced-motion: reduce)').matches === true;
}

export function AuthBackground() {
  const images = AUTH_BACKGROUND_IMAGES;
  const reduced = prefersReducedMotion();
  const [index, setIndex] = useState(0);

  useEffect(() => {
    if (reduced || images.length <= 1) return;
    const t = setInterval(() => setIndex((i) => (i + 1) % images.length), INTERVAL_MS);
    return () => clearInterval(t);
  }, [reduced, images.length]);

  return (
    <div className="absolute inset-0 z-0" data-active-index={index} aria-hidden="true">
      {/* Fallback gradient — always beneath the images */}
      <div className="absolute inset-0" style={{ background: AUTH_BACKGROUND_FALLBACK }} />

      {/* Crossfading slides */}
      {images.map((src, i) => (
        <div
          key={src}
          className="absolute inset-0 bg-cover bg-center transition-opacity duration-[2500ms] ease-in-out"
          style={{ backgroundImage: `url(${src})`, opacity: i === index ? 1 : 0 }}
        />
      ))}

      {/* Legibility overlay */}
      <div
        className="absolute inset-0"
        style={{
          background:
            'radial-gradient(ellipse at 30% 40%, rgba(8,10,20,.55) 0%, transparent 70%),'
            + 'linear-gradient(180deg, rgba(8,10,20,.45) 0%, rgba(8,10,20,.80) 100%)',
        }}
      />
    </div>
  );
}
