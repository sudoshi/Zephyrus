interface ZephyrusMarkProps {
  className?: string;
  /** Unique gradient id — required when the mark is rendered more than once per page. */
  gradId?: string;
}

/* Concentric rising-pulse arcs — indigo→blue→cyan brand mark. */
export function ZephyrusMark({ className, gradId = 'za-mark-grad' }: ZephyrusMarkProps) {
  return (
    <svg className={className} viewBox="0 0 80 80" fill="none" aria-hidden="true">
      <defs>
        <linearGradient id={gradId} x1="0" y1="0" x2="80" y2="80" gradientUnits="userSpaceOnUse">
          <stop stopColor="#818cf8" />
          <stop offset=".5" stopColor="#3b82f6" />
          <stop offset="1" stopColor="#22d3ee" />
        </linearGradient>
      </defs>
      <path d="M12 58 A30 30 0 0 1 68 58" stroke={`url(#${gradId})`} strokeWidth="2.5" strokeLinecap="round" opacity=".35" />
      <path d="M22 54 A20 20 0 0 1 58 54" stroke={`url(#${gradId})`} strokeWidth="2.5" strokeLinecap="round" opacity=".65" />
      <path d="M30 50 A12 12 0 0 1 50 50" stroke={`url(#${gradId})`} strokeWidth="3" strokeLinecap="round" />
      <circle cx="40" cy="38" r="3.6" fill={`url(#${gradId})`} />
    </svg>
  );
}
