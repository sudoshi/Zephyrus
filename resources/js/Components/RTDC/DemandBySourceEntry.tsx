interface DemandSources { ed: number; or: number; transfer: number; direct: number }
interface DemandBySourceEntryProps extends DemandSources { onChange: (d: DemandSources) => void }

export function DemandBySourceEntry({ ed, or, transfer, direct, onChange }: DemandBySourceEntryProps) {
  const current = { ed, or, transfer, direct };
  const field = (key: keyof DemandSources, value: number) => onChange({ ...current, [key]: value });
  const labels: Record<keyof DemandSources, string> = { ed: 'ED', or: 'OR', transfer: 'Transfer', direct: 'Direct' };

  return (
    <div className="grid grid-cols-4 gap-3">
      {(Object.keys(labels) as (keyof DemandSources)[]).map((k) => (
        <label key={k} className="flex flex-col gap-1">
          <span className="text-label">{labels[k]}</span>
          <input
            type="number" min={0} aria-label={labels[k]} value={current[k]}
            onChange={(e) => field(k, Number(e.target.value))}
            className="rounded-md bg-healthcare-background dark:bg-healthcare-background-dark p-2 text-healthcare-text-primary dark:text-healthcare-text-primary-dark"
          />
        </label>
      ))}
    </div>
  );
}
