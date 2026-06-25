import { useEffect, type ReactNode } from 'react';

interface AuthLayoutProps {
  children: ReactNode;
}

const ZEPHYRUS_ICON_SRC = '/images/zephyrus-icon.png';

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
              <img className="za-lockup-icon" src={ZEPHYRUS_ICON_SRC} alt="" aria-hidden="true" />
              <span className="za-wordmark">Zephyrus</span>
            </div>
          </div>

          <div className="za-brand-mid">
            <span className="za-eyebrow">Operations Command Center</span>
            <h2 className="za-headline">
              Coordinate <span className="za-accent">ED, RTDC, perioperative &amp; improvement</span> decisions in one live view.
            </h2>
            <p className="za-sub">
              Zephyrus connects hospital-wide strain to unit-level action: demand signals,
              capacity plans, operating room flow, and process improvement work.
            </p>
            <div className="za-domain-map" aria-label="Zephyrus workflow coverage">
              <div className="za-domain za-domain-ed">
                <span>Emergency Department</span>
                <p>Arrivals, acuity, wait times, boarding, treatment flow.</p>
              </div>
              <div className="za-domain za-domain-rtdc">
                <span>RTDC</span>
                <p>Bed capacity, unit census, discharges, placement decisions.</p>
              </div>
              <div className="za-domain za-domain-or">
                <span>Perioperative</span>
                <p>Block use, room status, case timing, turnover performance.</p>
              </div>
              <div className="za-domain za-domain-pi">
                <span>Process Improvement</span>
                <p>Process mining, bottlenecks, root cause work, PDSA cycles.</p>
              </div>
            </div>
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
              <img className="za-mobile-icon" src={ZEPHYRUS_ICON_SRC} alt="" aria-hidden="true" />
              <span className="za-wordmark">Zephyrus</span>
              <span className="za-m-tag">ED, RTDC, perioperative, and improvement intelligence for hospital operations.</span>
            </div>
            {children}
          </div>
        </section>
      </main>
    </div>
  );
}
