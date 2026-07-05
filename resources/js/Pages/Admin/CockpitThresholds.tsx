// resources/js/Pages/Admin/CockpitThresholds.tsx
//
// P8 WS-6b — the admin THRESHOLD EDITOR page. A CMIO retunes status band edges
// (OK / Warn / Crit) per KPI without a deploy; every save is audited server-side.
// Route (registered elsewhere): /admin/cockpit/thresholds → Inertia::render(
// 'Admin/CockpitThresholds'), inside the AdminMiddleware group. The page takes NO
// required Inertia props — it self-fetches via useKpiDefinitions and parses the
// payload defensively with safeParseKpiDefinitions (canon error card on any miss,
// never a white screen). Inertia pages are default-exported.
import { useMemo, useState } from 'react';
import { Head } from '@inertiajs/react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { safeParseKpiDefinitions, type KpiDefinition } from '@/types/kpiDefinitions';
import { useKpiDefinitions, useUpdateKpiDefinition } from '@/features/cockpit/useKpiDefinitions';

// Per-row draft: the three edge inputs as raw strings (an empty string = null).
interface EdgeDraft {
  ok: string;
  warn: string;
  crit: string;
}

// A number|null edge value → its input string form. null / undefined → ''.
function edgeToInput(value: number | null | undefined): string {
  return value === null || value === undefined ? '' : String(value);
}

// An input string → the number|null we PUT. Empty → null; NaN → null (skip).
function inputToEdge(value: string): number | null {
  const trimmed = value.trim();
  if (trimmed === '') return null;
  const parsed = Number(trimmed);
  return Number.isNaN(parsed) ? null : parsed;
}

function seedDraft(def: KpiDefinition): EdgeDraft {
  return {
    ok: edgeToInput(def.edges.ok),
    warn: edgeToInput(def.edges.warn),
    crit: edgeToInput(def.edges.crit),
  };
}

function isDirty(def: KpiDefinition, draft: EdgeDraft): boolean {
  return (
    draft.ok !== edgeToInput(def.edges.ok) ||
    draft.warn !== edgeToInput(def.edges.warn) ||
    draft.crit !== edgeToInput(def.edges.crit)
  );
}

const inputClass =
  'rounded-md border border-healthcare-border dark:border-healthcare-border-dark ' +
  'bg-healthcare-surface dark:bg-healthcare-surface-dark px-2 py-1 text-sm tabular-nums ' +
  'text-healthcare-text-primary dark:text-healthcare-text-primary-dark w-24';

const headerCellClass =
  'px-4 py-2 text-left text-xs font-medium uppercase tracking-wider ' +
  'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark';

const bodyCellClass =
  'px-4 py-2.5 whitespace-nowrap text-sm ' +
  'text-healthcare-text-primary dark:text-healthcare-text-primary-dark';

function CockpitThresholdsBody() {
  const query = useKpiDefinitions();
  const mutation = useUpdateKpiDefinition();

  // Draft edits keyed by definition key. Seeded lazily from the parsed payload
  // and only for rows the admin actually touches (untouched rows fall back to
  // the fetched edges), so a background refetch never clobbers an active edit.
  const [drafts, setDrafts] = useState<Record<string, EdgeDraft>>({});
  const [announce, setAnnounce] = useState<string>('');

  const parsed = useMemo(
    () => (query.data === undefined ? null : safeParseKpiDefinitions(query.data)),
    [query.data],
  );

  if (query.isLoading) {
    return (
      <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        Loading thresholds…
      </p>
    );
  }

  if (query.isError || (parsed !== null && !parsed.ok)) {
    return (
      <div className="rounded-md border border-healthcare-border dark:border-healthcare-border-dark bg-healthcare-surface dark:bg-healthcare-surface-dark p-4 shadow-sm">
        <p className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
          Could not load KPI definitions.
        </p>
        {parsed !== null && !parsed.ok ? (
          <p className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            {parsed.error}
          </p>
        ) : null}
        <button
          type="button"
          onClick={() => void query.refetch()}
          className="mt-3 rounded-md bg-healthcare-primary px-3 py-1 text-sm font-medium text-white disabled:opacity-50"
        >
          Retry
        </button>
      </div>
    );
  }

  if (parsed === null || !parsed.ok) {
    // Not loading, not errored, but no parse yet — treat as empty (defensive).
    return (
      <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        Loading thresholds…
      </p>
    );
  }

  const definitions = parsed.data.definitions;

  const draftFor = (def: KpiDefinition): EdgeDraft => drafts[def.key] ?? seedDraft(def);

  const setEdge = (def: KpiDefinition, field: keyof EdgeDraft, value: string) => {
    setDrafts((prev) => {
      const current = prev[def.key] ?? seedDraft(def);
      return { ...prev, [def.key]: { ...current, [field]: value } };
    });
  };

  const save = (def: KpiDefinition) => {
    const draft = draftFor(def);
    setAnnounce('');
    mutation.mutate(
      {
        metricKey: def.key,
        body: {
          ok_edge: inputToEdge(draft.ok),
          warn_edge: inputToEdge(draft.warn),
          crit_edge: inputToEdge(draft.crit),
        },
      },
      {
        onSuccess: () => {
          // Drop the local draft so the row re-derives from the refetched edges.
          setDrafts((prev) => {
            const next = { ...prev };
            delete next[def.key];
            return next;
          });
          setAnnounce('Saved');
        },
        onError: () => setAnnounce('Save failed'),
      },
    );
  };

  return (
    <>
      <div aria-live="polite" className="sr-only">
        {announce}
      </div>
      <div className="overflow-x-auto rounded-md border border-healthcare-border dark:border-healthcare-border-dark bg-healthcare-surface dark:bg-healthcare-surface-dark shadow-sm">
        <table className="min-w-full divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
          <thead>
            <tr>
              <th className={headerCellClass}>Key</th>
              <th className={headerCellClass}>Label</th>
              <th className={headerCellClass}>Domain</th>
              <th className={headerCellClass}>Direction</th>
              <th className={headerCellClass}>OK edge</th>
              <th className={headerCellClass}>Warn edge</th>
              <th className={headerCellClass}>Crit edge</th>
              <th className={headerCellClass}>Active</th>
              <th className={`${headerCellClass} text-right`}>Save</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
            {definitions.map((def) => {
              const draft = draftFor(def);
              const dirty = isDirty(def, draft);
              const rowSaving = mutation.isPending && mutation.variables?.metricKey === def.key;
              return (
                <tr key={def.key}>
                  <td className={`${bodyCellClass} tabular-nums`}>{def.key}</td>
                  <td className={bodyCellClass}>{def.label}</td>
                  <td className={bodyCellClass}>{def.domain ?? '—'}</td>
                  <td className={bodyCellClass}>{def.direction ?? def.edges.direction}</td>
                  <td className={bodyCellClass}>
                    <input
                      type="number"
                      className={inputClass}
                      value={draft.ok}
                      onChange={(e) => setEdge(def, 'ok', e.target.value)}
                      aria-label={`OK edge for ${def.label}`}
                    />
                  </td>
                  <td className={bodyCellClass}>
                    <input
                      type="number"
                      className={inputClass}
                      value={draft.warn}
                      onChange={(e) => setEdge(def, 'warn', e.target.value)}
                      aria-label={`Warn edge for ${def.label}`}
                    />
                  </td>
                  <td className={bodyCellClass}>
                    <input
                      type="number"
                      className={inputClass}
                      value={draft.crit}
                      onChange={(e) => setEdge(def, 'crit', e.target.value)}
                      aria-label={`Crit edge for ${def.label}`}
                    />
                  </td>
                  <td className={bodyCellClass}>{def.isActive ? 'Yes' : 'No'}</td>
                  <td className={`${bodyCellClass} text-right`}>
                    <button
                      type="button"
                      onClick={() => save(def)}
                      disabled={rowSaving || !dirty}
                      className="rounded-md bg-healthcare-primary px-3 py-1 text-sm font-medium text-white disabled:opacity-50"
                    >
                      {rowSaving ? 'Saving…' : 'Save'}
                    </button>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </>
  );
}

export default function CockpitThresholds() {
  return (
    <DashboardLayout>
      <Head title="Cockpit Thresholds · Zephyrus" />
      <PageContentLayout
        title="Cockpit Thresholds"
        subtitle="Tune status band edges without a deploy (audited)"
        headerContent={null}
      >
        <CockpitThresholdsBody />
      </PageContentLayout>
    </DashboardLayout>
  );
}
