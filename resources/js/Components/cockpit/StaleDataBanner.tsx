import { Icon } from '@iconify/react';

export interface StaleDataBannerProps {
  /** Live updates have stopped advancing — render the loud, solid amber banner. */
  stale: boolean;
  /** Data is getting old but not yet stale — render the quiet inline cue. Ignored when `stale`. */
  aging?: boolean;
  /** Human-readable label for when the data was last known good (e.g. "2 min ago"). */
  updatedLabel: string;
  /** Desk-only retry affordance. Omit on a wall; background polling continues. */
  onRetry?: () => void;
  /** Optional extra classes merged onto the outer container. */
  className?: string;
}

/**
 * App-chrome-level data-freshness banner for the cockpit.
 *
 * Escalates from a quiet inline "aging" cue to a loud, solid amber "stale"
 * banner — never a silent stale screen. Extracted from the inline block that
 * previously lived in both CockpitOverview and the classic overview.
 */
export function StaleDataBanner({
  stale,
  aging = false,
  updatedLabel,
  onRetry,
  className,
}: StaleDataBannerProps) {
  if (stale) {
    return (
      // A data-freshness FAILURE the operator must act on — role="alert" is
      // assertive by default (interrupts the screen reader), unlike the polite
      // aging cue below. The message text is the accessible name.
      <div
        role="alert"
        aria-atomic="true"
        className={[
          'flex items-center gap-2 rounded-md px-3 py-2 text-sm text-white',
          'bg-healthcare-warning dark:bg-healthcare-warning-dark',
          className ?? '',
        ]
          .filter(Boolean)
          .join(' ')}
      >
        <Icon
          icon="heroicons:exclamation-triangle"
          aria-hidden="true"
          className="h-4 w-4 shrink-0 text-white"
        />
        <span className="min-w-0">
          Live updates interrupted — showing last good data from {updatedLabel}.
          {onRetry && (
            <>
              {' '}
              <button
                type="button"
                onClick={onRetry}
                className="font-medium text-white underline underline-offset-2 hover:no-underline"
              >
                Retry now
              </button>
            </>
          )}
        </span>
      </div>
    );
  }

  if (aging) {
    return (
      <div
        role="status"
        className={[
          'flex items-center gap-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark',
          className ?? '',
        ]
          .filter(Boolean)
          .join(' ')}
      >
        <span
          aria-hidden="true"
          className="h-1.5 w-1.5 shrink-0 rounded-full bg-healthcare-warning dark:bg-healthcare-warning-dark"
        />
        <span className="min-w-0">Data aging — last updated {updatedLabel}.</span>
      </div>
    );
  }

  return null;
}
