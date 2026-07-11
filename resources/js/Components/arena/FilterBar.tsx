// resources/js/Components/arena/FilterBar.tsx
//
// Phase XO.1 filter surface: a time window applied across the Arena views. Emits a
// single TimeFrameFilter (or none). Object-type restriction already lives in the
// map controls; event-type / event-attribute filters are additive follow-ups that
// push more ArenaFilter items through the same pipeline.
import type { ArenaFilter } from '@/features/arena/schema';

type Props = {
  value: ArenaFilter[];
  onChange: (filters: ArenaFilter[]) => void;
};

export function FilterBar({ value, onChange }: Props) {
  const timeFrame = value.find((f) => f.kind === 'time_frame');

  // The `end` boundary is stored as end-of-day (T23:59:59) so the sidecar's
  // inclusive `ts <= end` covers the whole chosen day; a bare `YYYY-MM-DD` would
  // parse to midnight and silently drop everything after 00:00 on that date.
  const update = (patch: { start?: string; end?: string }) => {
    const start = patch.start !== undefined ? patch.start : timeFrame?.start;
    const end = patch.end !== undefined ? patch.end : timeFrame?.end;
    const next: ArenaFilter = { kind: 'time_frame', start: start || undefined, end: end || undefined };
    onChange(next.start || next.end ? [next] : []);
  };

  const inputClass =
    'rounded-md border border-healthcare-border bg-healthcare-surface px-2 py-1 text-xs text-healthcare-text-primary ' +
    'dark:bg-healthcare-surface-dark dark:text-healthcare-text-primary-dark';

  return (
    <div className="flex flex-wrap items-center gap-2" role="group" aria-label="Time window filter">
      <span className="text-xs font-medium uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        Time window
      </span>
      <label className="flex items-center gap-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        From
        <input
          type="date"
          value={timeFrame?.start ?? ''}
          onChange={(e) => update({ start: e.target.value })}
          className={inputClass}
        />
      </label>
      <label className="flex items-center gap-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        To
        <input
          type="date"
          value={timeFrame?.end?.slice(0, 10) ?? ''}
          onChange={(e) => update({ end: e.target.value ? `${e.target.value}T23:59:59` : '' })}
          className={inputClass}
        />
      </label>
      {timeFrame && (
        <button
          type="button"
          onClick={() => onChange([])}
          className="text-xs font-medium text-healthcare-primary underline"
        >
          Clear
        </button>
      )}
    </div>
  );
}
