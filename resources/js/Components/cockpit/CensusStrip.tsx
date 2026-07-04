// resources/js/Components/cockpit/CensusStrip.tsx
//
// Cockpit grammar row 3 (Zephyrus 2.0 P2): the 8-chip house census strip.
// Order is fixed server-side (SnapshotBuilder::CENSUS_STRIP) so the wall reads
// identically every day; a key missing from the snapshot simply leaves its
// chip out rather than shifting placeholders in.
import type { CockpitMetricValue } from '@/types/cockpit';
import { COCKPIT_STATE_TO_LEVEL } from './statusStyle';
import { CensusChip } from './CensusChip';

export function CensusStrip({ census }: { census: CockpitMetricValue[] }) {
  if (census.length === 0) return null;

  return (
    <div
      aria-label="House census strip"
      data-testid="cockpit-census-strip"
      className="grid gap-2"
      style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(128px, 1fr))' }}
    >
      {census.map((metric) => (
        <CensusChip
          key={metric.key}
          label={metric.label}
          value={metric.display}
          sub={metric.sub ?? undefined}
          status={COCKPIT_STATE_TO_LEVEL[metric.status]}
        />
      ))}
    </div>
  );
}
