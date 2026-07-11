// resources/js/Components/arena/review/BarrierRail.tsx
//
// The ranked, unified barrier list — flow / care / human interleaved by severity
// then delta. Severity is the ONLY status colour (the left stripe); kind is a
// neutral outline chip + glyph so it never competes with urgency. Selecting a
// row drives the one selection atom the movement owns.
import type { RankedBarrier } from '@/features/arena/reviewSchema';
import { DeltaBadge } from './DeltaBadge';
import { KIND_LABEL, SEVERITY_STRIPE } from './format';

function KindGlyph({ kind }: { kind: RankedBarrier['kind'] }) {
  if (kind === 'flow') {
    return (
      <svg viewBox="0 0 10 10" className="h-2.5 w-2.5" aria-hidden="true">
        <path d="M1 5h8M6 2l3 3-3 3" stroke="currentColor" fill="none" strokeWidth="1.4" />
      </svg>
    );
  }
  if (kind === 'care') {
    return (
      <svg viewBox="0 0 10 10" className="h-2.5 w-2.5" aria-hidden="true">
        <path d="M5 1v8M1 5h8" stroke="currentColor" fill="none" strokeWidth="1.4" />
      </svg>
    );
  }
  return (
    <svg viewBox="0 0 10 10" className="h-2.5 w-2.5" aria-hidden="true">
      <circle cx="5" cy="3" r="2" stroke="currentColor" fill="none" strokeWidth="1.2" />
      <path d="M2 9c0-2 6-2 6 0" stroke="currentColor" fill="none" strokeWidth="1.2" />
    </svg>
  );
}

function KindChip({ kind }: { kind: RankedBarrier['kind'] }) {
  return (
    <span className="mr-1.5 inline-flex items-center gap-1 rounded border border-healthcare-border px-1.5 py-0.5 align-middle text-xs font-semibold text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark">
      <KindGlyph kind={kind} />
      {KIND_LABEL[kind]}
    </span>
  );
}

function BarrierRow({ barrier, selected, onSelect }: { barrier: RankedBarrier; selected: boolean; onSelect: (id: string) => void }) {
  const { metric } = barrier;
  return (
    <button
      type="button"
      onClick={() => onSelect(barrier.id)}
      aria-pressed={selected}
      className={`grid w-full grid-cols-[4px,1fr,auto] items-stretch gap-3 overflow-hidden rounded-md border bg-healthcare-surface text-left shadow-sm transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-healthcare-gold dark:bg-healthcare-surface-dark ${
        selected ? 'border-healthcare-primary dark:border-healthcare-primary-dark' : 'border-healthcare-border hover:bg-healthcare-hover dark:border-healthcare-border-dark dark:hover:bg-healthcare-hover-dark'
      }`}
    >
      <span className={`w-1 ${SEVERITY_STRIPE[barrier.severity]}`} aria-hidden="true" />
      <span className="min-w-0 py-2.5 pr-1">
        <span className="block text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
          <KindChip kind={barrier.kind} />
          {barrier.title}
        </span>
        <span className="mt-0.5 block text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{barrier.subtitle}</span>
        <span className="mt-1 block tabular-nums text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          {barrier.provenance.source} · {barrier.provenance.note}
        </span>
      </span>
      <span className="flex flex-col items-end justify-center py-2.5 pr-3 text-right">
        <span className="tabular-nums text-base font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{metric.value_label}</span>
        {metric.delta_pct !== null && (
          <DeltaBadge direction={metric.direction} label={`${metric.delta_pct > 0 ? '+' : ''}${Math.round(metric.delta_pct)}%`} />
        )}
      </span>
    </button>
  );
}

interface Props {
  barriers: RankedBarrier[];
  selectedId: string | null;
  onSelect: (id: string) => void;
}

export function BarrierRail({ barriers, selectedId, onSelect }: Props) {
  return (
    <div className="space-y-3">
      <h3 className="text-xs font-semibold uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        Barriers this window · {barriers.length}
      </h3>
      {barriers.length === 0 ? (
        <div className="rounded-md border border-healthcare-border bg-healthcare-surface p-6 text-sm text-healthcare-text-secondary shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-secondary-dark">
          No barriers in this window.
        </div>
      ) : (
        <div className="space-y-2">
          {barriers.map((barrier) => (
            <BarrierRow key={barrier.id} barrier={barrier} selected={barrier.id === selectedId} onSelect={onSelect} />
          ))}
        </div>
      )}
    </div>
  );
}
