// resources/js/Components/cockpit/Tile.tsx
//
// The §3.1 MetricValue tile (Zephyrus 2.0 P2 — the Tile promotion deferred
// from P0). This is where the ISA-101 grey-baseline rule is APPLIED to metric
// values: statusStyle().valuePrimary is the single policy source — normal and
// watch render near-white; only earned ok (rationed), warn, and crit color the
// number itself. Two densities share one enforcement point:
//   <Tile>      card — the P3 drill KPI strip and gauge-side summaries
//   <MetricRow> slim single-line row — the A0 domain panels (one screen)
import type { CockpitMetricValue } from '@/types/cockpit';
import { Surface } from '@/Components/ui/Surface';
import { COCKPIT_STATE_TO_LEVEL, statusStyle, type StatusStyle } from './statusStyle';
import { Sparkline } from './Sparkline';
import { ProvenanceBadge } from './ProvenanceBadge';
import { formatMetricTarget } from './metricFormatting';

function valueColoring(s: StatusStyle): { className: string; style?: { color: string } } {
  return s.valuePrimary
    ? { className: 'text-healthcare-text-primary dark:text-healthcare-text-primary-dark' }
    : { className: '', style: { color: s.color } };
}

function isDemo(metric: CockpitMetricValue): boolean {
  return metric.metadata?.provenance === 'demo';
}

export interface TileProps {
  metric: CockpitMetricValue;
  /** Render the trend sparkline when ≥2 points exist (default true). */
  sparkline?: boolean;
}

export function Tile({ metric, sparkline = true }: TileProps) {
  const s = statusStyle(COCKPIT_STATE_TO_LEVEL[metric.status]);
  const value = valueColoring(s);
  const target = formatMetricTarget(metric.target, metric.unit);

  return (
    <Surface className="flex h-full flex-col gap-1 p-3" data-testid={`cockpit-tile-${metric.key}`}>
      <div className="flex items-start justify-between gap-2">
        <span className="min-w-0 truncate text-xs font-medium uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          {metric.label}
        </span>
        <span className="flex shrink-0 items-center gap-1.5">
          {isDemo(metric) && <ProvenanceBadge />}
          <span role="img" aria-label={s.label} className="text-xs leading-none" style={{ color: s.color }}>
            {s.glyph}
          </span>
        </span>
      </div>

      <span className={`text-2xl font-semibold tabular-nums leading-none ${value.className}`} style={value.style}>
        {metric.display}
      </span>

      {metric.sub && (
        <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          {metric.sub}
        </span>
      )}

      {sparkline && metric.trend.length >= 2 && (
        <Sparkline
          data={metric.trend}
          status={COCKPIT_STATE_TO_LEVEL[metric.status]}
          target={metric.target}
          id={metric.key}
        />
      )}

      {target && (
        <span className="mt-auto text-xs tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          {target}
        </span>
      )}
    </Surface>
  );
}

export interface MetricRowProps {
  metric: CockpitMetricValue;
}

/**
 * A0 density: one metric, one line — glyph, label, value. The domain panels
 * stack six of these where a Tile grid would blow the no-scroll budget.
 */
export function MetricRow({ metric }: MetricRowProps) {
  const s = statusStyle(COCKPIT_STATE_TO_LEVEL[metric.status]);
  const value = valueColoring(s);

  return (
    <div className="flex items-center gap-2" data-testid={`cockpit-row-${metric.key}`}>
      <span role="img" aria-label={s.label} className="w-3 shrink-0 text-xs leading-none" style={{ color: s.color }}>
        {s.glyph}
      </span>
      <span
        className="min-w-0 flex-1 truncate text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"
        title={metric.sub ?? metric.label}
      >
        {metric.label}
      </span>
      {isDemo(metric) && <ProvenanceBadge />}
      <span className={`shrink-0 text-sm font-semibold tabular-nums ${value.className}`} style={value.style}>
        {metric.display}
      </span>
    </div>
  );
}
