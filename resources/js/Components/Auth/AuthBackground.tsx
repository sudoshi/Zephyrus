import { useEffect, useState } from 'react';
import { AUTH_BACKGROUND_FALLBACK, AUTH_BACKGROUND_SLIDES } from '@/Components/Auth/authBackgrounds';

const INTERVAL_MS = 9500;

function prefersReducedMotion(): boolean {
  return typeof window !== 'undefined'
    && typeof window.matchMedia === 'function'
    && window.matchMedia('(prefers-reduced-motion: reduce)').matches === true;
}

export function AuthBackground() {
  const slides = AUTH_BACKGROUND_SLIDES;
  const reduced = prefersReducedMotion();
  const [index, setIndex] = useState(0);

  useEffect(() => {
    if (reduced || slides.length <= 1) return;
    const t = setInterval(() => setIndex((i) => (i + 1) % slides.length), INTERVAL_MS);
    return () => clearInterval(t);
  }, [reduced, slides.length]);

  return (
    <div className="absolute inset-0 z-0" data-active-index={index} aria-hidden="true">
      {/* Fallback gradient — always beneath the images */}
      <div className="absolute inset-0" style={{ background: AUTH_BACKGROUND_FALLBACK }} />

      {/* Crossfading slides */}
      {slides.map(({ src, position }, i) => (
        <div
          key={src}
          className="absolute inset-0 bg-cover transition-opacity duration-[3500ms] ease-in-out"
          style={{ backgroundImage: `url(${src})`, backgroundPosition: position, opacity: i === index ? 1 : 0 }}
        />
      ))}

      {/* Legibility overlay */}
      <div
        className="absolute inset-0"
        style={{
          background:
            'radial-gradient(ellipse at 30% 40%, rgba(8,10,20,.55) 0%, transparent 70%),'
            + 'linear-gradient(90deg, rgba(6,8,15,.70) 0%, rgba(6,8,15,.46) 46%, rgba(6,8,15,.78) 100%),'
            + 'linear-gradient(180deg, rgba(8,10,20,.36) 0%, rgba(8,10,20,.82) 100%)',
        }}
      />
    </div>
  );
}
