// resources/js/Components/cockpit/PatientLens.tsx
//
// Zephyrus 2.0 P8 WS-3 — the patient lens (A2P) render surface. This is the
// altitude BELOW a bed/board row: the operational context for one patient —
// current/target location, the cross-domain status spine, the flow timeline,
// the open dependencies (bed / transport / EVS), any recommendations, and the
// persona's allowed actions. It is PHI-minimized server-side; the client shows
// the operational picture, never raw identity.
//
// Data: GET /api/cockpit/patient/{ptok} via useCockpitPatient, parsed
// defensively by patientLensSchema. Two denial paths render distinct honest
// states rather than a crash: a 403 (persona/authorization) → "Access limited"
// (no retry — it is a deliberate boundary, the CMIO A2P matrix); a transient
// failure or contract break → a retryable error card. Every operational status
// is paired with a SHAPE glyph via StatusChip (never color alone).
import { useMemo } from 'react';
import { isAxiosError } from 'axios';
import type { StatusLevel } from '@/types/commandCenter';
import type {
  PatientLens as PatientLensPayload,
  PatientLensDependency,
  PatientLensSpineItem,
  PatientLensTimelineItem,
} from '@/types/patientLens';
import { operationalStatusLevel, safeParsePatientLens } from '@/types/patientLens';
import { useCockpitPatient } from '@/features/cockpit/hooks';
import { statusStyle } from './statusStyle';
import { StatusChip } from './StatusChip';

// Worst-of accent ranking over the canon StatusLevel — the header bar earns its
// hue from the most urgent OPEN dependency, so a calm context reads grey.
const LEVEL_SEVERITY: Record<StatusLevel, number> = {
  critical: 4,
  warning: 3,
  info: 2,
  success: 1,
  neutral: 0,
};

function worstDependencyLevel(dependencies: PatientLensDependency[]): StatusLevel {
  return dependencies.reduce<StatusLevel>((worst, dep) => {
    const level = operationalStatusLevel(dep.status);
    return LEVEL_SEVERITY[level] > LEVEL_SEVERITY[worst] ? level : worst;
  }, 'neutral');
}

function shortTime(iso: string | null | undefined): string {
  if (!iso) return '—';
  const ms = Date.parse(iso);
  return Number.isNaN(ms) ? '—' : new Date(ms).toLocaleTimeString([], { hour12: false });
}

type ParsedLens =
  | { state: 'loading' }
  | { state: 'unauthorized'; detail: string }
  | { state: 'error'; detail: string }
  | { state: 'ready'; lens: PatientLensPayload };

/** Pull the friendly server message off a 403 envelope; else the raw error. */
function detailFromError(error: unknown): { unauthorized: boolean; detail: string } {
  if (isAxiosError(error)) {
    const status = error.response?.status;
    const body = error.response?.data as { error?: { message?: string } } | undefined;
    const message = body?.error?.message ?? error.message;
    return { unauthorized: status === 403, detail: message };
  }
  return { unauthorized: false, detail: error instanceof Error ? error.message : 'Request failed' };
}

export interface PatientLensProps {
  /** The A2P context ref (ptok_…) to open. */
  contextRef: string;
}

export function PatientLens({ contextRef }: PatientLensProps) {
  const query = useCockpitPatient(contextRef);

  const parsed = useMemo<ParsedLens>(() => {
    if (query.isError) {
      const { unauthorized, detail } = detailFromError(query.error);
      return unauthorized ? { state: 'unauthorized', detail } : { state: 'error', detail };
    }
    if (query.data === undefined) return { state: 'loading' };
    const result = safeParsePatientLens(query.data);
    if (!result.ok) return { state: 'error', detail: result.error };
    return { state: 'ready', lens: result.data };
  }, [query.data, query.isError, query.error]);

  return (
    <div className="flex flex-col gap-3" data-testid="cockpit-patient-lens" data-context={contextRef}>
      {parsed.state === 'loading' && (
        <p role="status" className="py-10 text-center text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          Loading patient context…
        </p>
      )}

      {parsed.state === 'unauthorized' && (
        <div role="alert" className="flex flex-col items-start gap-2 rounded-md border border-healthcare-border dark:border-healthcare-border-dark bg-healthcare-surface dark:bg-healthcare-surface-dark p-4 shadow-sm">
          <p className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            Access limited for this patient context.
          </p>
          <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            {parsed.detail}
          </p>
        </div>
      )}

      {parsed.state === 'error' && (
        <div role="alert" className="flex flex-col items-start gap-2 rounded-md border border-healthcare-warning/40 bg-healthcare-warning/10 p-4">
          <p className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            Could not load this patient context.
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

      {parsed.state === 'ready' && <ReadyLens lens={parsed.lens} />}
    </div>
  );
}

function ReadyLens({ lens }: { lens: PatientLensPayload }) {
  const accent = worstDependencyLevel(lens.dependencies);
  const accentStyle = statusStyle(accent);
  const { header, persona } = lens;

  return (
    <>
      <header className="flex items-start gap-3" data-accent={accent}>
        <span
          aria-hidden="true"
          className="mt-0.5 h-[34px] w-[9px] shrink-0 rounded-sm"
          style={{ backgroundColor: accentStyle.color }}
        />
        <div className="min-w-0 flex-1">
          <nav className="flex flex-wrap items-center gap-1.5 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            <span className="rounded border border-healthcare-border dark:border-healthcare-border-dark px-1 py-px font-medium uppercase tracking-wide">
              A2P · Patient
            </span>
            <span aria-hidden="true">›</span>
            <span>{persona.title}</span>
          </nav>
          <h2 className="mt-0.5 truncate text-xl font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            {header.current_location ?? 'Patient operational context'}
          </h2>
          <p className="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            {header.service && <span>{header.service}</span>}
            {header.target_location && (
              <span>
                <span aria-hidden="true">→ </span>
                {header.target_location}
              </span>
            )}
            {header.responsible_team && <span>· {header.responsible_team}</span>}
            {header.isolation_required && (
              <span className="inline-flex items-center gap-1 text-healthcare-info dark:text-healthcare-info-dark">
                <StatusChip status="info" label="Isolation" />
              </span>
            )}
          </p>
        </div>
        <span className="shrink-0 text-xs tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          As of {shortTime(header.as_of)}
        </span>
      </header>

      {lens.status_spine.length > 0 && (
        <div className="flex flex-wrap gap-2" data-testid="patient-lens-spine">
          {lens.status_spine.map((item) => (
            <SpineChip key={`${item.domain}-${item.label}`} item={item} />
          ))}
        </div>
      )}

      <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
        <DependenciesCard dependencies={lens.dependencies} />
        <TimelineCard timeline={lens.timeline} />
      </div>

      {lens.recommendations.length > 0 && (
        <section
          className="rounded-md border border-healthcare-border dark:border-healthcare-border-dark bg-healthcare-surface dark:bg-healthcare-surface-dark p-3 shadow-sm"
          data-testid="patient-lens-recommendations"
        >
          <h3 className="mb-1.5 text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            Recommendations
          </h3>
          <ul className="flex flex-col gap-1.5">
            {lens.recommendations.map((rec) => (
              <li key={rec.recommendation_uuid} className="text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                <span className="font-medium">{rec.title}</span>
                {rec.rationale && (
                  <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"> — {rec.rationale}</span>
                )}
              </li>
            ))}
          </ul>
        </section>
      )}

      {lens.actions.length > 0 && (
        <section className="flex flex-wrap items-center gap-2" data-testid="patient-lens-actions">
          <span className="text-xs uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            Actions
          </span>
          {lens.actions.map((action) => (
            <span
              key={action.kind}
              className="rounded-full border border-healthcare-border dark:border-healthcare-border-dark px-2.5 py-0.5 text-xs font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark"
            >
              {action.label}
            </span>
          ))}
        </section>
      )}
    </>
  );
}

function SpineChip({ item }: { item: PatientLensSpineItem }) {
  return (
    <span className="inline-flex items-center gap-1.5 rounded-md border border-healthcare-border dark:border-healthcare-border-dark bg-healthcare-surface dark:bg-healthcare-surface-dark px-2 py-1 text-xs shadow-sm">
      <span className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{item.label}</span>
      <StatusChip status={operationalStatusLevel(item.status)} label={item.status} />
    </span>
  );
}

function DependenciesCard({ dependencies }: { dependencies: PatientLensDependency[] }) {
  return (
    <section
      className="rounded-md border border-healthcare-border dark:border-healthcare-border-dark bg-healthcare-surface dark:bg-healthcare-surface-dark p-3 shadow-sm"
      data-testid="patient-lens-dependencies"
    >
      <h3 className="mb-1.5 text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
        Open dependencies
      </h3>
      {dependencies.length === 0 ? (
        <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          No open operational dependencies.
        </p>
      ) : (
        <ul className="flex flex-col gap-1.5">
          {dependencies.map((dep) => (
            <li
              key={`${dep.dependency_type}-${dep.entity_ref ?? dep.label}`}
              className="flex items-center justify-between gap-2 text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark"
            >
              <span className="min-w-0 truncate">{dep.label}</span>
              <StatusChip status={operationalStatusLevel(dep.status)} label={dep.status} />
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

function TimelineCard({ timeline }: { timeline: PatientLensTimelineItem[] }) {
  return (
    <section
      className="rounded-md border border-healthcare-border dark:border-healthcare-border-dark bg-healthcare-surface dark:bg-healthcare-surface-dark p-3 shadow-sm"
      data-testid="patient-lens-timeline"
    >
      <h3 className="mb-1.5 text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
        Flow timeline
      </h3>
      {timeline.length === 0 ? (
        <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          No flow events yet.
        </p>
      ) : (
        <ol className="flex flex-col gap-1.5">
          {timeline.map((event, index) => (
            <li
              key={`${event.event_type}-${event.occurred_at ?? index}`}
              className="flex items-baseline justify-between gap-2 text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark"
            >
              <span className="min-w-0 truncate">
                {event.event_type}
                {event.status_after && (
                  <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"> · {event.status_after}</span>
                )}
              </span>
              <span className="shrink-0 text-xs tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                {shortTime(event.occurred_at)}
              </span>
            </li>
          ))}
        </ol>
      )}
    </section>
  );
}
