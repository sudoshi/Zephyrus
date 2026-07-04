// resources/js/Components/cockpit/CommandBar.tsx
//
// Cockpit grammar row 1 (spec §0.3 / §8.1, Zephyrus 2.0 P2): facility chip,
// capacity-status pill, freshness controls, LIVE badge, 1Hz clock. Earned
// urgency applies to the pill automatically — capacityStatus.status is the
// logical state ('normal' renders grey; only a real surge earns amber/coral).
import { useEffect, useState } from 'react';
import type { CockpitSnapshotSections } from '@/types/cockpit';
import { Surface } from '@/Components/ui/Surface';
import { cockpitStatusStyle } from './statusStyle';

interface CommandBarProps {
  facility: CockpitSnapshotSections['facility'];
  capacityStatus: CockpitSnapshotSections['capacityStatus'];
  updatedLabel: string;
  refreshing: boolean;
  aging: boolean;
  stale: boolean;
  onRefresh: () => void;
}

function LiveBadge({ aging, stale }: { aging: boolean; stale: boolean }) {
  // Rationed teal: the LIVE dot is a confirmed-good system-status slot; it
  // downgrades to amber the moment freshness is merely suspect.
  const style = stale
    ? cockpitStatusStyle('warn')
    : aging
      ? cockpitStatusStyle('watch')
      : cockpitStatusStyle('ok');
  const text = stale ? 'STALE' : aging ? 'AGING' : 'LIVE';

  return (
    <span className="inline-flex items-center gap-1.5" aria-label={`Data feed: ${text.toLowerCase()}`}>
      <span
        aria-hidden="true"
        className={`h-1.5 w-1.5 shrink-0 rounded-full ${stale || aging ? '' : 'motion-safe:animate-pulse'}`}
        style={{ background: style.color }}
      />
      <span className="text-xs font-semibold tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        {text}
      </span>
    </span>
  );
}

function Clock() {
  const [now, setNow] = useState(() => new Date());

  useEffect(() => {
    const id = setInterval(() => setNow(new Date()), 1_000);
    return () => clearInterval(id);
  }, []);

  return (
    <span className="flex flex-col items-end leading-none">
      <span className="text-lg font-semibold tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
        {now.toLocaleTimeString([], { hour12: false })}
      </span>
      <span className="mt-0.5 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        {now.toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric' })}
      </span>
    </span>
  );
}

export function CommandBar({
  facility,
  capacityStatus,
  updatedLabel,
  refreshing,
  aging,
  stale,
  onRefresh,
}: CommandBarProps) {
  const pill = cockpitStatusStyle(capacityStatus.status);
  const facilitySub = [
    facility.licensedBeds != null ? `${facility.licensedBeds} licensed beds` : null,
    facility.level,
  ].filter(Boolean).join(' · ');

  return (
    <Surface className="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3" data-testid="cockpit-command-bar">
      <span className="flex min-w-0 flex-col leading-tight">
        <span className="truncate text-base font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
          {facility.name}
        </span>
        {facilitySub && (
          <span className="truncate text-xs tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            {facilitySub}
          </span>
        )}
      </span>

      <span
        className="inline-flex shrink-0 items-center gap-1.5 rounded-full border border-healthcare-border dark:border-healthcare-border-dark px-2.5 py-1"
        data-testid="capacity-status-pill"
      >
        <span role="img" aria-label={pill.label} className="text-xs leading-none" style={{ color: pill.color }}>
          {pill.glyph}
        </span>
        <span className="text-xs font-semibold uppercase tracking-wide text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
          {capacityStatus.level}
        </span>
      </span>

      <span className="ml-auto flex shrink-0 items-center gap-3">
        <LiveBadge aging={aging} stale={stale} />
        <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          Updated {updatedLabel}
        </span>
        <button
          type="button"
          onClick={onRefresh}
          disabled={refreshing}
          aria-label="Refresh data"
          className="inline-flex items-center gap-1 rounded-md border border-healthcare-border dark:border-healthcare-border-dark
                     bg-healthcare-surface dark:bg-healthcare-surface-dark
                     px-2 py-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark
                     shadow-sm transition-colors duration-300 hover:bg-healthcare-hover dark:hover:bg-healthcare-hover-dark
                     disabled:cursor-not-allowed disabled:opacity-60"
        >
          <span className={refreshing ? 'inline-block motion-safe:animate-spin' : 'inline-block'} aria-hidden="true">{'⟳'}</span>
          {refreshing ? 'Refreshing…' : 'Refresh'}
        </button>
        <Clock />
      </span>
    </Surface>
  );
}
