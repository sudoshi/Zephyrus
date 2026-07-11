import {
  formatDurationHours,
  formatDurationMinutes,
  formatDurationSeconds,
} from '@/lib/duration';

const UNIT_PATTERN = 'days?|d|hours?|hrs?|hr|h|minutes?|mins?|min|m|seconds?|secs?|sec|s';

const formatNumericDuration = (value, unit) => {
  if (unit === 'hours') return formatDurationHours(value);
  if (unit === 'seconds') return formatDurationSeconds(value);
  if (unit === 'days') return formatDurationHours(value * 24);

  return formatDurationMinutes(value);
};

const normalizeUnit = (unit) => {
  const normalized = unit.toLowerCase();

  if (normalized.startsWith('d')) return 'days';
  if (normalized.startsWith('h')) return 'hours';
  if (normalized.startsWith('s')) return 'seconds';

  return 'minutes';
};

/**
 * Process analytics currently receive a mix of numeric minutes and legacy
 * strings with units. Normalize both without attempting to reinterpret
 * timestamps or non-duration status text.
 */
export const formatProcessDuration = (
  value,
  assumedUnit = 'minutes',
  unavailable = 'N/A',
) => {
  if (typeof value === 'number' && Number.isFinite(value)) {
    return formatNumericDuration(value, assumedUnit);
  }

  if (typeof value !== 'string' || value.trim() === '') return unavailable;

  const duration = value.trim();
  const numeric = Number(duration);
  if (Number.isFinite(numeric)) return formatNumericDuration(numeric, assumedUnit);

  const rangeMatch = duration.match(
    new RegExp(`^(-?\\d+(?:\\.\\d+)?)\\s*(?:-|to)\\s*(-?\\d+(?:\\.\\d+)?)\\s*(${UNIT_PATTERN})$`, 'i'),
  );
  if (rangeMatch) {
    const unit = normalizeUnit(rangeMatch[3]);

    return `${formatNumericDuration(Number(rangeMatch[1]), unit)} - ${formatNumericDuration(Number(rangeMatch[2]), unit)}`;
  }

  const tokenPattern = new RegExp(`(-?\\d+(?:\\.\\d+)?)\\s*(${UNIT_PATTERN})\\b`, 'gi');
  const tokens = [...duration.matchAll(tokenPattern)];
  const remainder = duration.replace(tokenPattern, '').trim();

  if (tokens.length > 1 && remainder === '') {
    const totalSeconds = tokens.reduce((sum, match) => {
      const amount = Number(match[1]);
      const unit = normalizeUnit(match[2]);
      const multiplier = unit === 'days'
        ? 86_400
        : unit === 'hours'
          ? 3_600
          : unit === 'minutes'
            ? 60
            : 1;

      return sum + amount * multiplier;
    }, 0);

    return formatDurationSeconds(totalSeconds);
  }

  const singleMatch = duration.match(
    new RegExp(`^(-?\\d+(?:\\.\\d+)?)\\s*(${UNIT_PATTERN})\\b(.*)$`, 'i'),
  );
  if (singleMatch) {
    const formatted = formatNumericDuration(
      Number(singleMatch[1]),
      normalizeUnit(singleMatch[2]),
    );
    const suffix = singleMatch[3].trim();

    return suffix ? `${formatted} ${suffix}` : formatted;
  }

  return duration;
};
