import { useEffect, useState, type ReactNode } from 'react';
import { AUTH_BACKGROUND_FALLBACK, AUTH_BACKGROUND_SLIDES } from '@/Components/Auth/authBackgrounds';

interface AuthLayoutProps {
  children: ReactNode;
}

const SLIDE_MS = 9500;

/** Categorized capability pills — the product "spec sheet" descriptiveness. */
const PILL_GROUPS: { label: string; tone: string; items: string[] }[] = [
  { label: 'Modules', tone: 'za-pill-indigo',
    items: ['Command Center', 'RTDC', 'Perioperative', 'Patient Flow', 'Care Progression'] },
  { label: 'Capabilities', tone: 'za-pill-cyan',
    items: ['Live Census', 'Bed Management', 'Surge Forecasting', 'Block Utilization'] },
  { label: 'Standards & Security', tone: 'za-pill-sky',
    items: ['HIPAA', 'RBAC', 'OIDC SSO', 'Audit Logging', 'PHI Isolation'] },
];

function prefersReducedMotion(): boolean {
  return typeof window !== 'undefined'
    && typeof window.matchMedia === 'function'
    && window.matchMedia('(prefers-reduced-motion: reduce)').matches === true;
}

/**
 * Zephyrus guest/auth shell — "split-elegant" cinematic sign-in.
 * Full-bleed wind/wave slideshow → left brand panel (aurora + headline +
 * domain map + capability pills) → right glass card (per-page `children`).
 * Dark-only; visuals come from the scoped `.zauth` stylesheet in
 * resources/css/auth.css.
 */
export default function AuthLayout({ children }: AuthLayoutProps) {
  const [slide, setSlide] = useState(0);

  useEffect(() => {
    document.documentElement.classList.add('dark');
  }, []);

  useEffect(() => {
    if (prefersReducedMotion() || AUTH_BACKGROUND_SLIDES.length <= 1) return;
    const t = setInterval(() => setSlide((i) => (i + 1) % AUTH_BACKGROUND_SLIDES.length), SLIDE_MS);
    return () => clearInterval(t);
  }, []);

  return (
    <div className="zauth">
      {/* ===== Full-bleed cinematic background slideshow ===== */}
      <div className="za-bg" data-active-index={slide} aria-hidden="true">
        <div className="za-bg-fallback" style={{ background: AUTH_BACKGROUND_FALLBACK }} />
        {AUTH_BACKGROUND_SLIDES.map(({ src, position }, i) => (
          <div
            key={src}
            className={`za-bg-slide${i === slide ? ' is-active' : ''}`}
            style={{ backgroundImage: `url(${src})`, backgroundPosition: position }}
          />
        ))}
        <div className="za-bg-scrim" />
      </div>

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
              <span className="za-wordmark">Zephyrus</span>
              <span className="za-badge">v1.0</span>
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

            <div className="za-spark-rule" aria-hidden="true" />

            <div className="za-pillstack" aria-label="Platform capabilities and standards">
              {PILL_GROUPS.map((group) => (
                <div key={group.label} className="za-pills-section">
                  <p className="za-pills-label">{group.label}</p>
                  <div className="za-pills">
                    {group.items.map((item) => (
                      <span key={item} className={`za-pill ${group.tone}`}>{item}</span>
                    ))}
                  </div>
                </div>
              ))}
            </div>
          </div>

          <div className="za-brand-bottom">
            <span className="za-dot" />
            <span>Acumenus Data Sciences</span>
          </div>
        </section>

        {/* ===== Right form panel ===== */}
        <section className="za-form-side">
          <div className="za-card">
            <div className="za-mobile-lockup">
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
