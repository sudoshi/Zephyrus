interface BedNeedReadoutProps {
  bedNeed: number;
  capacityNow: number;
  demandExpected: number;
}

export function BedNeedReadout({ bedNeed, capacityNow, demandExpected }: BedNeedReadoutProps) {
  const deficit = bedNeed > 0;
  const surplus = bedNeed < 0;
  const tone = deficit ? 'text-[var(--critical)]' : surplus ? 'text-[var(--success)]' : 'text-[var(--text-secondary)]';

  return (
    <div className="rounded-[var(--radius-lg)] bg-[var(--surface-raised)] p-[var(--space-5)]">
      <div className="text-label">Bed Need</div>
      <div className={`text-2xl font-semibold tabular-nums ${tone}`}>{bedNeed > 0 ? `+${bedNeed}` : bedNeed}</div>
      <div className="text-caption">
        {deficit && `Short ${bedNeed} beds`}
        {surplus && `${Math.abs(bedNeed)} beds surplus`}
        {bedNeed === 0 && 'Balanced'}
      </div>
      <div className="text-caption mt-[var(--space-2)]">
        Demand {demandExpected} · Effective capacity {capacityNow}
      </div>
      {/* S2 safety note (spec §10): bed-need surfaces capacity vs demand for the huddle to decide;
          it never recommends a discharge. */}
      <div className="text-caption mt-[var(--space-2)] italic">For huddle decision — not an automated action.</div>
    </div>
  );
}
