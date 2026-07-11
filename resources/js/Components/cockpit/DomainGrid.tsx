// resources/js/Components/cockpit/DomainGrid.tsx
//
// Cockpit grammar rows 4–5 (Zephyrus 2.0 P2): the 8-domain panel grid. Each
// panel header is a drill-down entry point (cockpit/Panel onDrill → D2 URL
// state; P3 opens the DrillModal). Density is A0: the domain gauge where one
// exists (RTDC occupancy / ED NEDOCS / Periop prime-time), then slim
// MetricRows — the full tile set lives in the drill.
import type { CockpitDomain, CockpitDrillDomain, CockpitMetricValue } from '@/types/cockpit';
import type { StatusLevel } from '@/types/commandCenter';
import { COCKPIT_STATE_TO_LEVEL } from './statusStyle';
import { Panel } from './Panel';
import { RadialGauge, type RadialGaugeBand } from './RadialGauge';
import { MetricRow } from './Tile';
import { ProvenanceBadge } from './ProvenanceBadge';
import { formatMetricTarget } from './metricFormatting';

// Fixed wall order — operational domains first, ledger domains last. Keys are
// the server domain registry (SnapshotBuilder providers / DrillBuilder).
const DOMAIN_ORDER = ['rtdc', 'ed', 'periop', 'staffing', 'flow', 'quality', 'service', 'financial'] as const;

const DOMAIN_TITLES: Record<(typeof DOMAIN_ORDER)[number], string> = {
  rtdc: 'Demand & Capacity',
  ed: 'Emergency',
  periop: 'Perioperative',
  staffing: 'Staffing',
  flow: 'Flow & Transport',
  quality: 'Quality & Safety',
  service: 'Service Lines',
  financial: 'Financial',
};

// NEDOCS context arc. The reference's five bands included a green "not busy"
// zone; canon rations green, so the calm end renders neutral and the arc keeps
// four reconciled bands (≤60 calm, ≤100 watch, ≤140 overcrowded, ≤200 severe+).
const GAUGE_BANDS: Record<string, RadialGaugeBand[]> = {
  'ed.nedocs': [
    { edge: 60, level: 'neutral' },
    { edge: 100, level: 'info' },
    { edge: 140, level: 'warning' },
    { edge: 200, level: 'critical' },
  ],
};

const SEVERITY: Record<CockpitMetricValue['status'], number> = {
  crit: 4, warn: 3, watch: 2, ok: 1, normal: 0,
};

/** Panel accent is EARNED: only a warn/crit tile puts a glyph on the header. */
function panelAccent(tiles: CockpitMetricValue[]): StatusLevel | undefined {
  const worst = tiles.reduce(
    (acc: CockpitMetricValue['status'] | null, tile) =>
      acc === null || SEVERITY[tile.status] > SEVERITY[acc] ? tile.status : acc,
    null,
  );
  if (worst === 'crit' || worst === 'warn') return COCKPIT_STATE_TO_LEVEL[worst];
  return undefined;
}

function gaugeScale(metric: CockpitMetricValue): number {
  const scale = metric.metadata?.scale;
  return typeof scale === 'number' && scale > 0 ? scale : 100;
}

function DomainGauge({ metric }: { metric: CockpitMetricValue }) {
  const level = COCKPIT_STATE_TO_LEVEL[metric.status];

  return (
    <div className="flex items-center gap-3" data-testid={`cockpit-gauge-${metric.key}`}>
      <RadialGauge
        value={metric.value}
        scale={gaugeScale(metric)}
        status={level}
        bands={GAUGE_BANDS[metric.key]}
        target={metric.target}
        size={84}
        strokeWidth={9}
        big={metric.display}
        bigClass="text-base"
      />
      <div className="flex min-w-0 flex-col gap-0.5">
        <span className="flex items-center gap-1.5">
          <span className="min-w-0 truncate text-xs font-medium uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            {metric.label}
          </span>
          {metric.metadata?.provenance === 'demo' && <ProvenanceBadge />}
        </span>
        {metric.sub && (
          <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            {metric.sub}
          </span>
        )}
        {metric.target != null && (
          <span className="text-xs tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            {formatMetricTarget(metric.target, metric.unit)}
          </span>
        )}
      </div>
    </div>
  );
}

interface DomainGridProps {
  domains: Record<string, CockpitDomain>;
  /** Omit for static wall mode so panel headers are semantic text, not controls. */
  onDrill?: (domain: CockpitDrillDomain) => void;
}

export function DomainGrid({ domains, onDrill }: DomainGridProps) {
  const present = DOMAIN_ORDER.filter((key) => domains[key] !== undefined);

  return (
    <div className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4" data-testid="cockpit-domain-grid">
      {present.map((key) => {
        const domain = domains[key];
        const gauge = domain.gaugeKey
          ? domain.tiles.find((tile) => tile.key === domain.gaugeKey) ?? null
          : null;
        const rows = domain.tiles.filter((tile) => tile.key !== gauge?.key);
        const visible = rows.slice(0, gauge ? 5 : 6);
        const hidden = rows.length - visible.length;

        return (
          <Panel
            key={key}
            title={DOMAIN_TITLES[key]}
            accent={panelAccent(domain.tiles)}
            meta={domain.provenance !== 'live' ? <ProvenanceBadge label={domain.provenance === 'demo' ? 'demo' : 'partial'} /> : undefined}
            onDrill={onDrill ? () => onDrill(key) : undefined}
            className="min-w-0"
          >
            {gauge && <DomainGauge metric={gauge} />}
            <div className="flex flex-col gap-1.5">
              {visible.map((tile) => <MetricRow key={tile.key} metric={tile} />)}
            </div>
            {hidden > 0 && (
              <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                +{hidden} more in drill
              </span>
            )}
          </Panel>
        );
      })}
    </div>
  );
}
