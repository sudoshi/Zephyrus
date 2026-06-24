import { useEffect, type ReactNode } from 'react';
import { ZephyrusMark } from '@/Components/Auth/ZephyrusMark';

interface AuthLayoutProps {
  children: ReactNode;
}

/**
 * Zephyrus guest/auth shell — "split-elegant" cinematic sign-in.
 * Left = fixed brand panel (aurora + headline + sparkline); right = a glass
 * card whose contents are supplied per-page via `children`. Dark-only; all
 * visuals come from the scoped `.zauth` stylesheet in resources/css/auth.css.
 */
export default function AuthLayout({ children }: AuthLayoutProps) {
  useEffect(() => {
    document.documentElement.classList.add('dark');
  }, []);

  return (
    <div className="zauth">
      <main className="za-shell">
        {/* ===== Left brand panel (shared) ===== */}
        <section className="za-brand">
          <div className="za-aurora">
            <span className="za-orb za-orb-1" />
            <span className="za-orb za-orb-2" />
            <span className="za-orb za-orb-3" />
          </div>
          <div className="za-grid-overlay" />
          <svg className="za-contours" viewBox="0 0 600 800" preserveAspectRatio="none" fill="none" aria-hidden="true">
            <defs>
              <linearGradient id="za-cgrad" x1="0" y1="0" x2="600" y2="800">
                <stop stopColor="#818cf8" stopOpacity="0.5" />
                <stop offset="1" stopColor="#22d3ee" stopOpacity="0.3" />
              </linearGradient>
            </defs>
            <path d="M-40 540 C 120 480 220 600 360 520 C 470 460 540 540 660 480" stroke="url(#za-cgrad)" strokeWidth="1" opacity="0.35" />
            <path d="M-40 600 C 140 540 240 660 380 580 C 500 520 560 600 680 540" stroke="url(#za-cgrad)" strokeWidth="1" opacity="0.25" />
            <path d="M-40 660 C 160 600 260 720 400 640 C 520 580 580 660 700 600" stroke="url(#za-cgrad)" strokeWidth="1" opacity="0.18" />
          </svg>

          <div className="za-brand-top">
            <div className="za-lockup">
              <ZephyrusMark className="za-mark" gradId="za-mark-brand" />
              <span className="za-wordmark">Zephyrus</span>
            </div>
          </div>

          <div className="za-brand-mid">
            <span className="za-eyebrow">Operations Command Center</span>
            <h2 className="za-headline">
              See hospital <span className="za-accent">demand &amp; capacity</span> the moment it shifts.
            </h2>
            <p className="za-sub">
              Real-time demand and capacity intelligence — bed flow, boarding, and throughput,
              surfaced before they become bottlenecks.
            </p>
            <div className="za-sparkwrap" aria-hidden="true">
              <svg className="za-sparkline" viewBox="0 0 360 54" fill="none">
                <defs>
                  <linearGradient id="za-sparkgrad" x1="0" y1="0" x2="360" y2="0">
                    <stop stopColor="#818cf8" />
                    <stop offset=".5" stopColor="#3b82f6" />
                    <stop offset="1" stopColor="#22d3ee" />
                  </linearGradient>
                </defs>
                <path className="za-spark-path" d="M0 38 L30 34 L60 40 L90 24 L120 30 L150 14 L180 26 L210 18 L240 32 L270 12 L300 22 L330 8 L360 20" />
              </svg>
              <div className="za-spark-rule" />
            </div>
          </div>

          <div className="za-brand-bottom">
            <span className="za-dot" />
            <span>Healthcare Operations Platform</span>
          </div>
        </section>

        {/* ===== Right form panel ===== */}
        <section className="za-form-side">
          <div className="za-card">
            <div className="za-mobile-lockup">
              <ZephyrusMark className="za-mark" gradId="za-mark-mobile" />
              <span className="za-wordmark">Zephyrus</span>
              <span className="za-m-tag">Real-time hospital demand &amp; capacity intelligence.</span>
            </div>
            {children}
          </div>
        </section>
      </main>
    </div>
  );
}
