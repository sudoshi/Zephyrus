// resources/js/Components/system/metric.ts
//
// The gold-standard metric factory. Every metric on every page is a KpiMetric
// (the Command Center contract): a value with a status, a definition, an
// optional target, and an optional recent series that renders as a sparkline.
// This factory builds that rich shape from minimal input so any page can emit
// it without verbose object literals — which is what makes the metric WALL look
// identical everywhere AND stay dense (sparkline + target + trend for free).
import type { KpiMetric, StatusLevel, Trajectory } from '@/types/commandCenter';

export type { KpiMetric, StatusLevel } from '@/types/commandCenter';

export interface MetricInput {
  key: string;
  label: string;
  value: number;
  /** Pre-formatted display (e.g. "94%", "1,240"). Defaults from value + unit. */
  display?: string;
  /** "%" turns the tile into a radial gauge; "" / count renders a big number. */
  unit?: string;
  status?: StatusLevel;
  target?: number | null;
  targetDisplay?: string | null;
  /** Recent series (oldest→newest). >=2 points renders the sparkline. */
  trajectory?: number[] | null;
  /** True when a falling series is good (wait time, LOS, bottlenecks). */
  goodWhenDown?: boolean;
  /** One-line "what is this number" — shown on the ⓘ tooltip; defensible at depth. */
  definition?: string;
  drillHref?: string | null;
}

function toTrajectory(points: number[] | null | undefined, goodWhenDown: boolean): Trajectory | null {
  if (!points || points.length < 2) return null;
  const prev = points[points.length - 2];
  const last = points[points.length - 1];
  const direction = last > prev ? 'up' : last < prev ? 'down' : 'flat';
  return { points, direction, goodWhenDown };
}

const fmt = (n: number): string => (Number.isInteger(n) ? n.toLocaleString('en-US') : String(n));

export function metric(input: MetricInput): KpiMetric {
  const isPct = input.unit === '%';
  const display = input.display ?? (isPct ? `${input.value}%` : fmt(input.value));
  const targetDisplay =
    input.targetDisplay ??
    (input.target != null ? (isPct ? `${input.target}%` : fmt(input.target)) : null);

  return {
    key: input.key,
    label: input.label,
    value: input.value,
    unit: input.unit ?? '',
    display,
    target: input.target ?? null,
    targetDisplay,
    status: input.status ?? 'neutral',
    trajectory: toTrajectory(input.trajectory, input.goodWhenDown ?? false),
    drillHref: input.drillHref ?? null,
    definition: input.definition ?? input.label,
  };
}
