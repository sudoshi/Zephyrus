// resources/js/Components/cockpit/DrillModal.tsx
//
// The A2 Drill surface (Zephyrus 2.0 P3): one full-width modal per cockpit
// domain, opened from any panel header or the OKR scorecard and auto-opened
// from ?drill={domain} (D2 — P4a's redirects land here). Built on the Radix
// ui/dialog primitives, which ship ESC + backdrop-click + focus-trap +
// focus-restore-to-trigger + aria-modal, over the solid scrim — the cockpit
// never introduces glassmorphism.
//
// Data: /api/cockpit/drill/{domain} via useCockpitDrill, parsed defensively by
// drillPayloadSchema. KPI tiles are the SAME cached snapshot numbers the wall
// shows (DrillBuilder discipline); only the detail tables read live boards.
// A failed fetch or contract break renders an in-modal error card with retry —
// the cockpit behind the scrim is never at risk.
import { useMemo } from 'react';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogTitle,
} from '@/Components/ui/dialog';
import {
  drillPayloadSchema,
  type CockpitDrillDomain,
  type CockpitMetricValue,
  type DrillPayload,
} from '@/types/cockpit';
import { useCockpitDrill } from '@/features/cockpit/hooks';
import { COCKPIT_STATE_TO_LEVEL, statusStyle } from './statusStyle';
import { Tile } from './Tile';
import { DataTable } from './DataTable';

// Same worst-of ordering the DomainGrid panels use for their earned accents.
const SEVERITY: Record<CockpitMetricValue['status'], number> = {
  crit: 4,
  warn: 3,
  ok: 2,
  watch: 1,
  normal: 0,
};

function worstStatus(kpis: CockpitMetricValue[]): CockpitMetricValue['status'] {
  return kpis.reduce<CockpitMetricValue['status']>(
    (worst, kpi) => (SEVERITY[kpi.status] > SEVERITY[worst] ? kpi.status : worst),
    'normal',
  );
}

type ParsedDrill =
  | { state: 'loading' }
  | { state: 'error'; detail: string }
  | { state: 'ready'; payload: DrillPayload };

export interface DrillModalProps {
  domain: CockpitDrillDomain | null;
  onClose: () => void;
}

export function DrillModal({ domain, onClose }: DrillModalProps) {
  const query = useCockpitDrill(domain);

  const parsed = useMemo<ParsedDrill>(() => {
    if (query.isError) {
      return { state: 'error', detail: query.error instanceof Error ? query.error.message : 'Request failed' };
    }
    if (query.data === undefined) return { state: 'loading' };
    const result = drillPayloadSchema.safeParse(query.data);
    if (!result.success) {
      const first = result.error.issues[0];
      const where = first?.path?.length ? ` (at ${first.path.join('.')})` : '';
      return { state: 'error', detail: `${first?.message ?? 'Invalid drill payload'}${where}` };
    }
    return { state: 'ready', payload: result.data };
  }, [query.data, query.isError, query.error]);

  const accent =
    parsed.state === 'ready'
      ? COCKPIT_STATE_TO_LEVEL[worstStatus(parsed.payload.kpis)]
      : COCKPIT_STATE_TO_LEVEL.normal;
  const accentStyle = statusStyle(accent);

  return (
    <Dialog open={domain !== null} onOpenChange={(open: boolean) => { if (!open) onClose(); }}>
      <DialogContent
        className="flex max-h-[88vh] w-[calc(100vw-2rem)] max-w-[1280px] flex-col gap-0 overflow-hidden p-0"
        data-testid="cockpit-drill-modal"
        data-domain={domain ?? undefined}
        data-accent={accent}
      >
        <header className="flex items-start gap-3 border-b border-healthcare-border dark:border-healthcare-border-dark p-4 pr-12">
          <span
            aria-hidden="true"
            className="mt-0.5 h-[30px] w-[9px] shrink-0 rounded-sm"
            style={{ backgroundColor: accentStyle.color }}
          />
          <div className="min-w-0 flex-1">
            <DialogTitle>
              {parsed.state === 'ready' ? parsed.payload.title : 'Drill-down'}
            </DialogTitle>
            <DialogDescription className="mt-1">
              {parsed.state === 'ready'
                ? parsed.payload.sub ?? 'Domain detail — same snapshot the cockpit shows'
                : parsed.state === 'loading'
                  ? 'Loading drill detail…'
                  : 'Drill detail unavailable'}
            </DialogDescription>
          </div>
          {parsed.state === 'ready' && (
            <span className="shrink-0 text-xs tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              As of {new Date(parsed.payload.asOf).toLocaleTimeString([], { hour12: false })}
            </span>
          )}
        </header>

        <div className="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-4">
          {parsed.state === 'loading' && (
            <p role="status" className="py-8 text-center text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              Loading {domain} drill…
            </p>
          )}

          {parsed.state === 'error' && (
            <div role="alert" className="flex flex-col items-start gap-2 rounded-md border border-healthcare-warning/40 bg-healthcare-warning/10 p-4">
              <p className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                Could not load this drill-down.
              </p>
              <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                {parsed.detail}
              </p>
              <button
                type="button"
                onClick={() => void query.refetch()}
                className="rounded-md border border-healthcare-border dark:border-healthcare-border-dark px-3 py-1.5 text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark hover:bg-healthcare-background dark:hover:bg-healthcare-background-dark"
              >
                Retry
              </button>
            </div>
          )}

          {parsed.state === 'ready' && (
            <>
              {parsed.payload.kpis.length > 0 && (
                <div
                  className="grid grid-cols-2 gap-2 md:grid-cols-3 xl:grid-cols-6"
                  data-testid="cockpit-drill-kpis"
                >
                  {parsed.payload.kpis.map((kpi) => (
                    <Tile key={kpi.key} metric={kpi} />
                  ))}
                </div>
              )}

              {parsed.payload.tables.map((table) => (
                <section key={table.caption} data-testid="cockpit-drill-table">
                  <h3 className="mb-1.5 text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    {table.caption}
                  </h3>
                  <DataTable caption={table.caption} columns={table.columns} rows={table.rows} />
                </section>
              ))}
            </>
          )}
        </div>
      </DialogContent>
    </Dialog>
  );
}
