import type { Barrier } from '@/schemas/rtdc';

const CATEGORY_TONE: Record<Barrier['category'], string> = {
  medical: 'var(--critical)',
  logistical: 'var(--warning)',
  placement: 'var(--info)',
  social: 'var(--accent)',
};

interface BarrierBoardProps { barriers: Barrier[]; onResolve: (id: number) => void }

export function BarrierBoard({ barriers, onResolve }: BarrierBoardProps) {
  if (barriers.length === 0) {
    return <div className="text-caption">No open barriers.</div>;
  }
  return (
    <ul className="flex flex-col gap-[var(--space-2)]">
      {barriers.map((b) => (
        <li key={b.barrier_id} className="flex items-center justify-between rounded-[var(--radius-sm)] bg-[var(--surface-overlay)] p-[var(--space-3)]">
          <span className="flex items-center gap-[var(--space-2)]">
            <span className="inline-block h-2 w-2 rounded-full" style={{ background: CATEGORY_TONE[b.category] }} />
            <span className="text-label capitalize">{b.category}</span>
            <span className="text-[var(--text-secondary)]">{b.description}</span>
          </span>
          <button onClick={() => onResolve(b.barrier_id)} className="text-caption underline">Resolve</button>
        </li>
      ))}
    </ul>
  );
}
