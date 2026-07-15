export interface FilterSummaryItem {
  key: string;
  label: string;
  value: string;
}

export function FilterSummary({ items, resultCount, onClear }: { items: FilterSummaryItem[]; resultCount: number; onClear?: () => void }) {
  return (
    <section aria-label="Applied filter summary" className="flex flex-wrap items-center gap-2 rounded-lg border border-healthcare-border bg-healthcare-surface p-3 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
      <span className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
        <span className="tabular-nums">{resultCount}</span> results
      </span>
      {items.length === 0 ? (
        <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">All orders · No filters applied</span>
      ) : items.map((item) => (
        <span key={item.key} className="rounded-md border border-healthcare-border px-2 py-1 text-xs text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark">
          {item.label}: <span className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{item.value}</span>
        </span>
      ))}
      {onClear && items.length > 0 && <button type="button" className="ml-auto rounded-md px-2 py-1 text-xs text-healthcare-info focus:outline-none focus:ring-2 focus:ring-healthcare-info dark:text-healthcare-info-dark" onClick={onClear}>Clear filters</button>}
    </section>
  );
}
