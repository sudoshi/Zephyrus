// resources/js/Components/cockpit/ProvenanceBadge.tsx
//
// D5: every mocked number is labeled at the point of display. One tiny badge
// shared by tiles, domain panels, and the alert ticker so "demo" always looks
// the same and can be found (and removed, per P7 live swaps) in one place.
export function ProvenanceBadge({ label = 'demo' }: { label?: 'demo' | 'partial' }) {
  return (
    <span
      data-testid="provenance-badge"
      className="shrink-0 rounded border border-healthcare-border dark:border-healthcare-border-dark
                 px-1 py-px text-xs font-medium uppercase tracking-wide
                 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"
    >
      {label}
    </span>
  );
}
