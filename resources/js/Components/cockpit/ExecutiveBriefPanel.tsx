// resources/js/Components/cockpit/ExecutiveBriefPanel.tsx
//
// P6 WS-5 — the ExecutiveBrief silo page reconciled into the cockpit as an
// executive-role panel. Same governed agent (executive_briefing_agent, the
// executive_brief.compose tool), deliberately slower cadence: the brief
// composes ON DEMAND, never on a poll — a narrative synthesis is minutes-
// scale truth, not a 45-second gauge. The standalone /ops/executive-brief
// page remains as the deep-link for the full lineage/governance trace.
import { Link } from '@inertiajs/react';
import { Surface } from '@/Components/ui/Surface';
import { useRunAgent } from '@/features/ops/hooks';
import { cockpitStatusStyle } from './statusStyle';

// Brief statuses arrive in the legacy 3-state vocabulary. Unknown stays
// grey — green is earned, never a default.
function briefState(status: string | undefined): 'normal' | 'ok' | 'warn' | 'crit' {
  if (status === 'critical') return 'crit';
  if (status === 'warning') return 'warn';
  if (status === 'success') return 'ok';
  return 'normal';
}

export function ExecutiveBriefPanel() {
  const run = useRunAgent();
  const output = run.data?.output;
  const composed = run.data?.agentKey === 'executive_briefing_agent' && output?.headline;

  return (
    <Surface className="p-4" data-testid="cockpit-executive-brief">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 className="text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
          Executive brief
        </h2>
        <div className="flex items-center gap-3">
          <Link
            href="/ops/executive-brief"
            className="text-xs font-medium text-healthcare-primary transition-colors duration-200 hover:text-healthcare-primary-hover dark:text-healthcare-primary-dark"
          >
            Full brief →
          </Link>
          <button
            type="button"
            onClick={() => run.mutate('executive_briefing_agent')}
            disabled={run.isPending}
            className="rounded-md bg-healthcare-primary px-2.5 py-1 text-xs font-medium text-white transition-colors duration-200 hover:bg-healthcare-primary-hover disabled:opacity-50 dark:bg-healthcare-primary-dark"
          >
            {run.isPending ? 'Composing…' : composed ? 'Recompose' : 'Compose brief'}
          </button>
        </div>
      </div>

      {!composed ? (
        <p className="mt-2 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          {run.isPending
            ? 'The briefing agent is synthesizing the current situation…'
            : 'Compose a governed, read-only synthesis of situation, plan and measured impact.'}
        </p>
      ) : (
        <div className="mt-2 space-y-2">
          <p className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            {output?.headline}
          </p>
          {(output?.situation?.length ?? 0) > 0 && (
            <ul className="flex flex-wrap gap-x-4 gap-y-1">
              {(output?.situation ?? []).map((item) => {
                const s = cockpitStatusStyle(briefState(item.status));
                return (
                  <li key={item.domain} className="inline-flex items-center gap-1.5 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    <span role="img" aria-label={s.label} style={{ color: s.color }}>{s.glyph}</span>
                    {item.domain}
                  </li>
                );
              })}
            </ul>
          )}
        </div>
      )}
    </Surface>
  );
}
