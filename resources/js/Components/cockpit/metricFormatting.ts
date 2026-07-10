import { formatDurationForUnit } from '@/lib/duration';

export function formatMetricTarget(
  target: number | null,
  unit: string | null,
): string | null {
  if (target === null) return null;

  const duration = formatDurationForUnit(target, unit);
  if (duration !== null) return `Target ${duration}`;

  if (unit === '%') return `Target ${target}%`;

  return `Target ${target}${unit ? ` ${unit}` : ''}`;
}
