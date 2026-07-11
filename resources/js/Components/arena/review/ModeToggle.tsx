// resources/js/Components/arena/review/ModeToggle.tsx
//
// Segmented control in the Arena page header. Review is the default mode (the
// 48-Hour Flow Review); Explore is today's free object-centric Study, preserved
// verbatim. Deep-linkable via ?mode=explore.
export type ReviewMode = 'review' | 'explore';

const TABS: { value: ReviewMode; label: string }[] = [
  { value: 'review', label: '48-Hour Review' },
  { value: 'explore', label: 'Explore' },
];

export function ModeToggle({ mode, onChange }: { mode: ReviewMode; onChange: (mode: ReviewMode) => void }) {
  return (
    <div
      role="tablist"
      aria-label="Arena mode"
      className="inline-flex rounded-md border border-healthcare-border p-0.5 dark:border-healthcare-border-dark"
    >
      {TABS.map((tab) => {
        const active = tab.value === mode;
        return (
          <button
            key={tab.value}
            type="button"
            role="tab"
            aria-selected={active}
            onClick={() => onChange(tab.value)}
            className={`rounded px-3 py-1 text-xs font-medium transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-healthcare-gold ${
              active
                ? 'bg-healthcare-primary text-white dark:bg-healthcare-primary-dark'
                : 'text-healthcare-text-secondary hover:text-healthcare-text-primary dark:text-healthcare-text-secondary-dark dark:hover:text-healthcare-text-primary-dark'
            }`}
          >
            {tab.label}
          </button>
        );
      })}
    </div>
  );
}
