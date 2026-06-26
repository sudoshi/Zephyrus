import type { RankedRecommendations } from '@/schemas/rtdc';

type Rec = RankedRecommendations['recommendations'][number];

interface RecommendationCardProps {
  rec: Rec;
  isTop: boolean;
  runnerUpDelta: number | null;
  onAccept: (bedId: number) => void;
}

export function RecommendationCard({ rec, isTop, runnerUpDelta, onAccept }: RecommendationCardProps) {
  return (
    <div className={`rounded-[var(--radius-lg)] p-[var(--space-5)] ${isTop ? 'bg-[var(--surface-raised)] ring-1 ring-[var(--accent)]' : 'bg-[var(--surface-overlay)]'}`}>
      <div className="flex items-center justify-between">
        <div>
          <span className="text-2xl font-semibold tabular-nums">{rec.bed_label}</span>
          <span className="text-caption ml-[var(--space-2)]">{rec.unit_name}</span>
        </div>
        <span className="text-label">Score {rec.score}{isTop && runnerUpDelta !== null ? ` · +${runnerUpDelta} vs next` : ''}</span>
      </div>

      <div className="mt-[var(--space-3)] flex flex-wrap gap-[var(--space-2)]">
        {rec.chips.map((c) => (
          <span key={c.label} className={`text-caption rounded-[var(--radius-sm)] px-[var(--space-2)] py-[2px] ${c.ok ? 'bg-[var(--success-bg)] text-[var(--success)]' : 'bg-[var(--critical-bg)] text-[var(--critical)]'}`}>
            {c.label}
          </span>
        ))}
      </div>

      <div className="mt-[var(--space-3)] flex flex-wrap gap-[var(--space-2)]">
        {rec.breakdown.map((b) => (
          <span key={b.term} className="text-caption text-[var(--text-muted)]">
            {b.term}: <span className={b.value < 0 ? 'text-[var(--critical)]' : 'text-[var(--success)]'}>{b.value > 0 ? `+${b.value}` : b.value}</span>
          </span>
        ))}
      </div>

      <div className="mt-[var(--space-4)] flex items-center justify-between">
        <span className="text-caption italic">Recommendation for placement decision — not an automated assignment.</span>
        <button onClick={() => onAccept(rec.bed_id)} className="rounded-[var(--radius-md)] bg-[var(--primary)] px-[var(--space-4)] py-[var(--space-2)] text-white">
          Accept
        </button>
      </div>
    </div>
  );
}
