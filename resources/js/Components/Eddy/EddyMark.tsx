interface EddyMarkProps {
  size?: number;
  className?: string;
}

/**
 * Eddy's mark — a small "operations bridge" glyph in currentColor (no external
 * asset). Operational chrome: tint with healthcare-* text/fill where used.
 */
export function EddyMark({ size = 22, className = '' }: EddyMarkProps) {
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={1.75}
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      className={className}
    >
      <circle cx="12" cy="12" r="9" opacity="0.35" />
      <path d="M5 14c2.5-4 4-5 7-5s4.5 1 7 5" />
      <path d="M12 5.5v3M12 15.5v3" opacity="0.7" />
      <circle cx="12" cy="12" r="1.6" fill="currentColor" stroke="none" />
    </svg>
  );
}
