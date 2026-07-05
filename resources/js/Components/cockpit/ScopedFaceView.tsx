// resources/js/Components/cockpit/ScopedFaceView.tsx
//
// Zephyrus 2.0 P8 WS-2b — the mounted altitude face. When /dashboard is mounted
// at a non-house scope (?scope=unit:MICU | service_line:* | department:*), the
// page renders THIS instead of the 8-domain house grid: the scope's own KPIs +
// §6.4 Cell tables, in the same drill grammar so the existing Tile / DataTable
// primitives render every altitude. Reuse-first — a unit/department mount shows
// altitude-APPROPRIATE tiles, never the house grid shrunk (the WS-2 decision).
//
// Data: GET /api/cockpit/face?scope={token} via useCockpitFace, parsed
// defensively by cockpitFaceSchema. A resolver that bounced a junk token to
// house comes back render:'grid' — handled here as a graceful "open the house
// cockpit" card, never a crash. The default /dashboard (no scope) never mounts
// this component and stays byte-for-byte unchanged.
import { useMemo } from 'react';
import type { CockpitDetailFace, CockpitFace, CockpitMetricValue, CockpitScopeLevel } from '@/types/cockpit';
import { safeParseCockpitFace } from '@/types/cockpit';
import { useCockpitFace } from '@/features/cockpit/hooks';
import { COCKPIT_STATE_TO_LEVEL, statusStyle } from './statusStyle';
import { Tile } from './Tile';
import { DataTable } from './DataTable';

// Same worst-of ordering the DomainGrid panels and DrillModal use for their
// earned header accent.
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

const LEVEL_LABEL: Record<CockpitScopeLevel, string> = {
  house: 'House',
  service_line: 'Service line',
  department: 'Department',
  unit: 'Unit',
};

type ParsedFace =
  | { state: 'loading' }
  | { state: 'error'; detail: string }
  | { state: 'ready'; face: CockpitFace };

export interface ScopedFaceViewProps {
  /** A non-house mount token ('unit:MICU' | 'service_line:*' | 'department:*'). */
  scopeToken: string;
}

export function ScopedFaceView({ scopeToken }: ScopedFaceViewProps) {
  const query = useCockpitFace(scopeToken);

  const parsed = useMemo<ParsedFace>(() => {
    if (query.isError) {
      return { state: 'error', detail: query.error instanceof Error ? query.error.message : 'Request failed' };
    }
    if (query.data === undefined) return { state: 'loading' };
    const result = safeParseCockpitFace(query.data);
    if (!result.ok) return { state: 'error', detail: result.error };
    return { state: 'ready', face: result.data };
  }, [query.data, query.isError, query.error]);

  return (
    <div className="flex flex-col gap-3" data-testid="cockpit-scoped-face" data-scope={scopeToken}>
      {parsed.state === 'loading' && (
        <p role="status" className="py-10 text-center text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          Loading mount…
        </p>
      )}

      {parsed.state === 'error' && (
        <div role="alert" className="flex flex-col items-start gap-2 rounded-md border border-healthcare-warning/40 bg-healthcare-warning/10 p-4">
          <p className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            Could not load this mount.
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

      {parsed.state === 'ready' && parsed.face.render === 'grid' && (
        <HouseBounce label={parsed.face.scope.label} />
      )}

      {parsed.state === 'ready' && parsed.face.render === 'face' && (
        <DetailFace face={parsed.face} />
      )}
    </div>
  );
}

/**
 * A junk/house-resolving token lands here — the resolver already degraded it to
 * house, so send the operator to the full house cockpit rather than showing an
 * empty scoped shell. A full navigation (not client-side) clears the ?scope=
 * read-once state cleanly.
 */
function HouseBounce({ label }: { label: string }) {
  return (
    <div className="flex flex-col items-start gap-2 rounded-md border border-healthcare-border dark:border-healthcare-border-dark bg-healthcare-surface dark:bg-healthcare-surface-dark p-4 shadow-sm">
      <p className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
        Mounted at {label}.
      </p>
      <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        This scope resolved to the house cockpit.
      </p>
      <a
        href="/dashboard"
        className="rounded-md border border-healthcare-border dark:border-healthcare-border-dark px-3 py-1.5 text-sm font-medium text-healthcare-primary dark:text-healthcare-primary-dark hover:bg-healthcare-background dark:hover:bg-healthcare-background-dark"
      >
        Open the house cockpit
      </a>
    </div>
  );
}

function DetailFace({ face }: { face: CockpitDetailFace }) {
  const accent = COCKPIT_STATE_TO_LEVEL[worstStatus(face.kpis)];
  const accentStyle = statusStyle(accent);
  const empty = face.kpis.length === 0 && face.tables.length === 0;

  return (
    <>
      <header className="flex items-start gap-3" data-accent={accent}>
        <span
          aria-hidden="true"
          className="mt-0.5 h-[34px] w-[9px] shrink-0 rounded-sm"
          style={{ backgroundColor: accentStyle.color }}
        />
        <div className="min-w-0 flex-1">
          <nav className="flex items-center gap-1.5 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            <a href="/dashboard" className="hover:text-healthcare-primary dark:hover:text-healthcare-primary-dark hover:underline underline-offset-2">
              House
            </a>
            <span aria-hidden="true">›</span>
            <span className="rounded border border-healthcare-border dark:border-healthcare-border-dark px-1 py-px font-medium uppercase tracking-wide">
              {LEVEL_LABEL[face.scope.level]}
            </span>
          </nav>
          <h2 className="mt-0.5 truncate text-xl font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            {face.title}
          </h2>
          {face.sub && (
            <p className="mt-0.5 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              {face.sub}
            </p>
          )}
        </div>
        <span className="shrink-0 text-xs tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          As of {new Date(face.asOf).toLocaleTimeString([], { hour12: false })}
        </span>
      </header>

      {empty ? (
        <div className="rounded-md border border-healthcare-border dark:border-healthcare-border-dark bg-healthcare-surface dark:bg-healthcare-surface-dark p-6 text-center text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark shadow-sm">
          No live census for this mount yet.
        </div>
      ) : (
        <>
          {face.kpis.length > 0 && (
            <div className="grid grid-cols-2 gap-2 md:grid-cols-3 xl:grid-cols-6" data-testid="cockpit-face-kpis">
              {face.kpis.map((kpi) => (
                <Tile key={kpi.key} metric={kpi} />
              ))}
            </div>
          )}

          {face.tables.map((table) => (
            <section key={table.caption} data-testid="cockpit-face-table">
              <h3 className="mb-1.5 text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                {table.caption}
              </h3>
              <DataTable caption={table.caption} columns={table.columns} rows={table.rows} />
            </section>
          ))}
        </>
      )}
    </>
  );
}
