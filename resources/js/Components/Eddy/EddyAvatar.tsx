interface EddyAvatarProps {
  size?: number;
  className?: string;
}

/**
 * Eddy's avatar — the assistant's identity portrait (a brand content asset served
 * from public/, not UI chrome). Rendered as a round image with a subtle token
 * border so it sits cleanly on any surface, light or dark.
 */
export function EddyAvatar({ size = 28, className = '' }: EddyAvatarProps) {
  return (
    <img
      src="/images/eddy/eddy-avatar.png"
      alt="Eddy"
      width={size}
      height={size}
      loading="lazy"
      style={{ width: size, height: size }}
      className={`shrink-0 rounded-full border border-healthcare-border object-cover dark:border-healthcare-border-dark ${className}`}
    />
  );
}
