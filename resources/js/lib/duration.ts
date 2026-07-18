export interface RelativeDurationLabels {
  future?: string;
  past?: string;
  unavailable?: string;
}

function finiteNumber(value: number | null | undefined): number | null {
  return typeof value === 'number' && Number.isFinite(value) ? value : null;
}

/**
 * Render elapsed time without decimal units. Values are rounded once, at the
 * whole-second boundary, then decomposed into hours, minutes, and seconds.
 */
export function formatDurationSeconds(
  value: number | null | undefined,
  unavailable = 'N/A',
): string {
  const numeric = finiteNumber(value);
  if (numeric === null) return unavailable;

  const totalSeconds = Math.round(Math.abs(numeric));
  const negative = numeric < 0 && totalSeconds > 0;
  const hours = Math.floor(totalSeconds / 3_600);
  const minutes = Math.floor((totalSeconds % 3_600) / 60);
  const seconds = totalSeconds % 60;
  const parts: string[] = [];

  if (hours > 0) parts.push(`${hours} hr`);
  if (hours > 0 || minutes > 0) parts.push(`${minutes} min`);
  parts.push(`${seconds} sec`);

  return `${negative ? '-' : ''}${parts.join(' ')}`;
}

/**
 * Coarse operational age for alert/handoff surfaces: at most two units, never
 * seconds past a minute, never minutes past a day — "12 days 8 hr" reads at a
 * glance where "308 hr 22 min 15 sec" does not.
 */
export function formatCoarseDurationSeconds(
  value: number | null | undefined,
  unavailable = 'N/A',
): string {
  const numeric = finiteNumber(value);
  if (numeric === null) return unavailable;

  const totalSeconds = Math.round(Math.abs(numeric));
  const sign = numeric < 0 && totalSeconds > 0 ? '-' : '';

  if (totalSeconds < 60) return `${sign}${totalSeconds} sec`;

  const days = Math.floor(totalSeconds / 86_400);
  const hours = Math.floor((totalSeconds % 86_400) / 3_600);
  const minutes = Math.floor((totalSeconds % 3_600) / 60);

  if (days > 0) return `${sign}${days} ${days === 1 ? 'day' : 'days'}${hours > 0 ? ` ${hours} hr` : ''}`;
  if (hours > 0) return `${sign}${hours} hr${minutes > 0 ? ` ${minutes} min` : ''}`;
  return `${sign}${minutes} min`;
}

export function formatDurationMinutes(
  value: number | null | undefined,
  unavailable = 'N/A',
): string {
  const numeric = finiteNumber(value);

  return numeric === null ? unavailable : formatDurationSeconds(numeric * 60, unavailable);
}

export function formatDurationHours(
  value: number | null | undefined,
  unavailable = 'N/A',
): string {
  const numeric = finiteNumber(value);

  return numeric === null ? unavailable : formatDurationSeconds(numeric * 3_600, unavailable);
}

/** Return null when a unit is not an elapsed-time unit. */
export function formatDurationForUnit(
  value: number | null | undefined,
  unit: string | null | undefined,
  unavailable = 'N/A',
): string | null {
  const normalized = unit?.trim().toLowerCase();

  if (['s', 'sec', 'secs', 'second', 'seconds'].includes(normalized ?? '')) {
    return formatDurationSeconds(value, unavailable);
  }
  if (['m', 'min', 'mins', 'minute', 'minutes'].includes(normalized ?? '')) {
    return formatDurationMinutes(value, unavailable);
  }
  if (['h', 'hr', 'hrs', 'hour', 'hours'].includes(normalized ?? '')) {
    return formatDurationHours(value, unavailable);
  }
  return null;
}

export function formatRelativeDurationMinutes(
  value: number | null | undefined,
  labels: RelativeDurationLabels = {},
): string {
  const numeric = finiteNumber(value);
  if (numeric === null) return labels.unavailable ?? 'No target';

  const duration = formatDurationMinutes(Math.abs(numeric));
  const suffix = numeric < 0 ? labels.past ?? 'overdue' : labels.future ?? 'remaining';

  return `${duration} ${suffix}`;
}
