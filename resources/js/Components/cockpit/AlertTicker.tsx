// resources/js/Components/cockpit/AlertTicker.tsx
//
// Cockpit grammar row 2 (Zephyrus 2.0 P2): the crit-first alert ticker. The
// server already rations entry (only warn/crit metrics whose kpi_definition
// carries an alert_template — the Earned-Red discipline), so this component
// renders every alert it is given, crit-first order preserved.
//
// Motion policy: the strip marquees ONLY when it overflows its row (measured,
// not guessed), and the CSS animation is disabled wholesale under
// prefers-reduced-motion (app.css .cockpit-marquee-track). The crit blip is
// motion-safe gated the same way. The row keeps a stable height when empty so
// an alert appearing never reflows the wall.
import { useEffect, useRef, useState } from 'react';
import type { CSSProperties } from 'react';
import type { CockpitAlert } from '@/types/cockpit';
import { Surface } from '@/Components/ui/Surface';
import { cockpitStatusStyle } from './statusStyle';
import { ProvenanceBadge } from './ProvenanceBadge';

function AlertItem({ alert }: { alert: CockpitAlert }) {
  const s = cockpitStatusStyle(alert.status);

  return (
    <span className="inline-flex shrink-0 items-center gap-1.5" data-testid={`cockpit-alert-${alert.key}`}>
      <span
        role="img"
        aria-label={s.label}
        className={`text-xs leading-none ${alert.status === 'crit' ? 'motion-safe:animate-pulse' : ''}`}
        style={{ color: s.color }}
      >
        {s.glyph}
      </span>
      <span className="whitespace-nowrap text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
        {alert.text}
      </span>
      {alert.provenance === 'demo' && <ProvenanceBadge />}
    </span>
  );
}

export function AlertTicker({ alerts }: { alerts: CockpitAlert[] }) {
  const viewportRef = useRef<HTMLDivElement>(null);
  const [overflowing, setOverflowing] = useState(false);

  // Re-measure when the alert set changes or the window resizes; marquee only
  // when the strip genuinely cannot fit (scrolling content that fits is worse
  // than a static row).
  useEffect(() => {
    const measure = () => {
      const el = viewportRef.current;
      setOverflowing(el != null && el.scrollWidth > el.clientWidth + 1);
    };
    measure();
    window.addEventListener('resize', measure);
    return () => window.removeEventListener('resize', measure);
  }, [alerts]);

  if (alerts.length === 0) {
    return (
      <Surface className="flex items-center gap-2 px-4 py-2" data-testid="cockpit-alert-ticker">
        <span aria-hidden="true" className="text-xs leading-none text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          {'–'}
        </span>
        <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          No active alerts — all monitored metrics within bands
        </span>
      </Surface>
    );
  }

  const strip = (
    <span className="inline-flex items-center gap-6 pr-6">
      {alerts.map((alert) => <AlertItem key={alert.key} alert={alert} />)}
    </span>
  );

  // ~12s of travel per alert keeps the read speed roughly constant as the
  // ticker grows.
  const marqueeStyle: CSSProperties = { '--cockpit-marquee-duration': `${alerts.length * 12}s` } as CSSProperties;

  return (
    <Surface className="px-4 py-2" data-testid="cockpit-alert-ticker" aria-label="Active alerts">
      <div ref={viewportRef} className={overflowing ? 'overflow-hidden' : 'overflow-x-auto'}>
        {overflowing ? (
          <div className="cockpit-marquee-track inline-flex w-max items-center" style={marqueeStyle}>
            {strip}
            {/* Duplicate strip = seamless loop; hidden from the a11y tree. */}
            <span aria-hidden="true">{strip}</span>
          </div>
        ) : (
          <div className="inline-flex w-max items-center">{strip}</div>
        )}
      </div>
    </Surface>
  );
}
