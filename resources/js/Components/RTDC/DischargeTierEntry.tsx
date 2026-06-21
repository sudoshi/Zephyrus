interface DischargeTiers { definite: number; probable: number; possible: number }
interface DischargeTierEntryProps extends DischargeTiers { onChange: (tiers: DischargeTiers) => void }

export function DischargeTierEntry({ definite, probable, possible, onChange }: DischargeTierEntryProps) {
  const field = (key: keyof DischargeTiers, value: number) =>
    onChange({ definite, probable, possible, [key]: value });

  return (
    <div className="grid grid-cols-3 gap-[var(--space-3)]">
      {(['definite', 'probable', 'possible'] as const).map((tier) => (
        <label key={tier} className="flex flex-col gap-[var(--space-1)]">
          <span className="text-label capitalize">{tier}</span>
          <input
            type="number"
            min={0}
            aria-label={tier}
            value={{ definite, probable, possible }[tier]}
            onChange={(e) => field(tier, Number(e.target.value))}
            className="rounded-[var(--radius-sm)] bg-[var(--surface-overlay)] p-[var(--space-2)] text-[var(--text-primary)]"
          />
        </label>
      ))}
    </div>
  );
}
