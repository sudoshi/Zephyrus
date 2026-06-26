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
    <div className={`rounded-lg p-5 ${isTop ? 'bg-healthcare-surface dark:bg-healthcare-surface-dark ring-1 ring-healthcare-primary dark:ring-healthcare-primary-dark' : 'bg-healthcare-background dark:bg-healthcare-background-dark'}`}>
      <div className="flex items-center justify-between">
        <div>
          <span className="text-2xl font-semibold tabular-nums">{rec.bed_label}</span>
          <span className="text-caption ml-2">{rec.unit_name}</span>
        </div>
        <span className="text-label">Score {rec.score}{isTop && runnerUpDelta !== null ? ` · +${runnerUpDelta} vs next` : ''}</span>
      </div>

      <div className="mt-3 flex flex-wrap gap-2">
        {rec.chips.map((c) => (
          <span key={c.label} className={`text-caption rounded-md px-2 py-[2px] ${c.ok ? 'bg-[var(--success-bg)] text-[var(--success)]' : 'bg-[var(--critical-bg)] text-[var(--critical)]'}`}>
            {c.label}
          </span>
        ))}
      </div>

      <div className="mt-3 flex flex-wrap gap-2">
        {rec.breakdown.map((b) => (
          <span key={b.term} className="text-caption text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            {b.term}: <span className={b.value < 0 ? 'text-[var(--critical)]' : 'text-[var(--success)]'}>{b.value > 0 ? `+${b.value}` : b.value}</span>
          </span>
        ))}
      </div>

      <div className="mt-4 flex items-center justify-between">
        <span className="text-caption italic">Recommendation for placement decision — not an automated assignment.</span>
        <button onClick={() => onAccept(rec.bed_id)} className="rounded-md bg-healthcare-primary dark:bg-healthcare-primary-dark px-4 py-2 text-white">
          Accept
        </button>
      </div>
    </div>
  );
}
