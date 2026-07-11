import { useEffect, useMemo, useState } from 'react';
import { ChevronLeft, ChevronRight, Database, FileCheck2, Layers3, Search } from 'lucide-react';

import {
  arenaProcessLandscapeIndexSchema,
  arenaProcessModelDetailSchema,
  type ArenaProcessModelSummary,
} from '@/features/arena/schema';
import { useArenaProcessModel, useArenaProcessModels } from '@/features/arena/hooks';
import { ReferenceProcessMap } from './ReferenceProcessMap';

const READINESS_LABEL: Record<ArenaProcessModelSummary['current_readiness'], string> = {
  partial_projection: 'Partial OCEL projection',
  source_present_not_projected: 'Source present, not projected',
  reference_only: 'Reference only',
};

const READINESS_STYLE: Record<ArenaProcessModelSummary['current_readiness'], string> = {
  partial_projection: 'border-teal-500/40 bg-teal-500/10 text-teal-700 dark:text-teal-300',
  source_present_not_projected: 'border-healthcare-warning/40 bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark',
  reference_only: 'border-healthcare-border bg-healthcare-background text-healthcare-text-secondary dark:border-healthcare-border-dark dark:bg-healthcare-background-dark dark:text-healthcare-text-secondary-dark',
};

function Metric({ label, value, detail }: { label: string; value: string | number; detail?: string }) {
  return (
    <div className="min-w-[9rem] rounded-md border border-healthcare-border bg-healthcare-surface p-3 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
      <div className="text-xs font-semibold uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        {label}
      </div>
      <div className="mt-1 text-xl font-semibold tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
        {value}
      </div>
      {detail ? (
        <div className="mt-0.5 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{detail}</div>
      ) : null}
    </div>
  );
}

function LoadingCard({ message }: { message: string }) {
  return (
    <div className="flex min-h-40 items-center justify-center rounded-md border border-healthcare-border bg-healthcare-surface p-6 text-sm text-healthcare-text-secondary dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-secondary-dark">
      {message}
    </div>
  );
}

export function ProcessModelLandscape() {
  const [selectedId, setSelectedId] = useState('A8');
  const [search, setSearch] = useState('');
  const [domain, setDomain] = useState('all');
  const [priority, setPriority] = useState('all');
  const [readiness, setReadiness] = useState('all');

  const indexQuery = useArenaProcessModels();
  const index = useMemo(() => {
    if (indexQuery.data === undefined) return null;
    const parsed = arenaProcessLandscapeIndexSchema.safeParse(indexQuery.data);
    return parsed.success ? parsed.data : null;
  }, [indexQuery.data]);

  const filteredModels = useMemo(() => {
    if (!index) return [];
    const needle = search.trim().toLowerCase();
    return index.models.filter((model) => {
      if (domain !== 'all' && model.domain_code !== domain) return false;
      if (priority !== 'all' && model.priority !== priority) return false;
      if (readiness !== 'all' && model.current_readiness !== readiness) return false;
      if (needle === '') return true;
      return `${model.process_id} ${model.name} ${model.core_interaction} ${model.improvement_question}`
        .toLowerCase()
        .includes(needle);
    });
  }, [domain, index, priority, readiness, search]);

  useEffect(() => {
    if (filteredModels.length > 0 && !filteredModels.some((model) => model.process_id === selectedId)) {
      setSelectedId(filteredModels[0].process_id);
    }
  }, [filteredModels, selectedId]);

  const detailQuery = useArenaProcessModel(index ? selectedId : null);
  const detail = useMemo(() => {
    if (detailQuery.data === undefined) return null;
    const parsed = arenaProcessModelDetailSchema.safeParse(detailQuery.data);
    return parsed.success ? parsed.data : null;
  }, [detailQuery.data]);

  const selectedIndex = filteredModels.findIndex((model) => model.process_id === selectedId);
  const selectedSummary = index?.models.find((model) => model.process_id === selectedId) ?? null;
  const emittedPercent = index
    ? ((index.projection.emitted_object_types / Math.max(1, index.projection.target_object_types)) * 100).toFixed(1)
    : '0.0';

  const moveSelection = (offset: number) => {
    if (filteredModels.length === 0 || selectedIndex < 0) return;
    const next = (selectedIndex + offset + filteredModels.length) % filteredModels.length;
    setSelectedId(filteredModels[next].process_id);
  };

  if (indexQuery.isLoading) {
    return <LoadingCard message="Loading the seeded hospital OCEL model registry…" />;
  }

  if (indexQuery.isError || !index) {
    return (
      <LoadingCard message="The OCEL reference-model registry is unavailable. Run the landscape migration and OcelProcessLandscapeSeeder." />
    );
  }

  return (
    <section className="space-y-4" aria-labelledby="ocel-model-landscape-title">
      <div className="rounded-md border border-healthcare-border bg-healthcare-surface p-5 shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
        <div className="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
          <div className="max-w-4xl">
            <div className="flex flex-wrap items-center gap-2">
              <h2 id="ocel-model-landscape-title" className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                Hospital OCEL model landscape
              </h2>
              <span className="rounded-full border border-healthcare-border bg-healthcare-background px-2 py-0.5 text-xs font-semibold uppercase tracking-wide text-healthcare-text-secondary dark:border-healthcare-border-dark dark:bg-healthcare-background-dark dark:text-healthcare-text-secondary-dark">
                Seeded reference designs
              </span>
            </div>
            <p className="mt-2 text-sm leading-6 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              Every bounded model in {index.document.id} is selectable below and rendered as a React Flow map. These flows describe the target operational semantics; they are not proof that the live OCEL log observed the steps. The discovered evidence surface remains separate below.
            </p>
            <div className="mt-3 flex items-start gap-2 rounded-md border border-healthcare-warning/30 bg-healthcare-warning/5 px-3 py-2 text-xs text-healthcare-warning dark:text-healthcare-warning-dark">
              <FileCheck2 className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
              <span>{index.document.requested_count_note} All {index.document.catalog_count} are included here.</span>
            </div>
          </div>

          <div className="flex flex-wrap gap-2">
            <Metric label="Reference models" value={index.counts.models} detail={`${index.counts.domains} operational domains`} />
            <Metric label="Projected evidence" value={index.projection.projected_events.toLocaleString()} detail={`${index.projection.projected_objects.toLocaleString()} OCEL objects`} />
            <Metric label="Emitted ontology" value={`${emittedPercent}%`} detail={`${index.projection.emitted_object_types}/${index.projection.target_object_types} target types`} />
          </div>
        </div>
      </div>

      <div className="rounded-md border border-healthcare-border bg-healthcare-surface p-4 shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
        <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-[minmax(16rem,1fr),12rem,10rem,15rem]">
          <label className="relative block">
            <span className="sr-only">Search process models</span>
            <Search className="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-healthcare-text-secondary" aria-hidden="true" />
            <input
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              placeholder="Search ID, model, object, or question"
              className="w-full rounded-md border border-healthcare-border bg-healthcare-background py-2 pl-9 pr-3 text-sm text-healthcare-text-primary focus:border-healthcare-primary focus:outline-none focus:ring-1 focus:ring-healthcare-primary dark:border-healthcare-border-dark dark:bg-healthcare-background-dark dark:text-healthcare-text-primary-dark"
            />
          </label>

          <label>
            <span className="sr-only">Filter by domain</span>
            <select
              value={domain}
              onChange={(event) => setDomain(event.target.value)}
              className="w-full rounded-md border border-healthcare-border bg-healthcare-background px-3 py-2 text-sm text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-healthcare-background-dark dark:text-healthcare-text-primary-dark"
            >
              <option value="all">All domains</option>
              {index.domains.map((item) => (
                <option key={item.code} value={item.code}>{item.code} · {item.name} ({item.count})</option>
              ))}
            </select>
          </label>

          <label>
            <span className="sr-only">Filter by priority</span>
            <select
              value={priority}
              onChange={(event) => setPriority(event.target.value)}
              className="w-full rounded-md border border-healthcare-border bg-healthcare-background px-3 py-2 text-sm text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-healthcare-background-dark dark:text-healthcare-text-primary-dark"
            >
              <option value="all">All priorities</option>
              {['P0', 'P1', 'P2', 'P3'].map((item) => <option key={item} value={item}>{item}</option>)}
            </select>
          </label>

          <label>
            <span className="sr-only">Filter by readiness</span>
            <select
              value={readiness}
              onChange={(event) => setReadiness(event.target.value)}
              className="w-full rounded-md border border-healthcare-border bg-healthcare-background px-3 py-2 text-sm text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-healthcare-background-dark dark:text-healthcare-text-primary-dark"
            >
              <option value="all">All readiness tiers</option>
              {Object.entries(READINESS_LABEL).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
            </select>
          </label>
        </div>

        <div className="mt-3 flex flex-col gap-3 lg:flex-row lg:items-center">
          <label className="min-w-0 flex-1">
            <span className="sr-only">Select an OCEL process model</span>
            <select
              value={filteredModels.some((model) => model.process_id === selectedId) ? selectedId : ''}
              onChange={(event) => setSelectedId(event.target.value)}
              disabled={filteredModels.length === 0}
              className="w-full rounded-md border border-healthcare-border bg-healthcare-surface px-3 py-2.5 text-sm font-medium text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-primary-dark"
            >
              {filteredModels.length === 0 ? <option value="">No models match these filters</option> : null}
              {filteredModels.map((model) => (
                <option key={model.process_id} value={model.process_id}>
                  {model.process_id} · {model.name} · {model.priority}
                </option>
              ))}
            </select>
          </label>

          <div className="flex items-center gap-2">
            <button
              type="button"
              onClick={() => moveSelection(-1)}
              disabled={filteredModels.length < 2}
              aria-label="Previous process model"
              className="rounded-md border border-healthcare-border p-2 text-healthcare-text-primary hover:bg-healthcare-hover disabled:opacity-40 dark:border-healthcare-border-dark dark:text-healthcare-text-primary-dark dark:hover:bg-healthcare-hover-dark"
            >
              <ChevronLeft className="h-4 w-4" />
            </button>
            <span className="min-w-24 text-center text-xs tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              {selectedIndex >= 0 ? selectedIndex + 1 : 0} of {filteredModels.length}
            </span>
            <button
              type="button"
              onClick={() => moveSelection(1)}
              disabled={filteredModels.length < 2}
              aria-label="Next process model"
              className="rounded-md border border-healthcare-border p-2 text-healthcare-text-primary hover:bg-healthcare-hover disabled:opacity-40 dark:border-healthcare-border-dark dark:text-healthcare-text-primary-dark dark:hover:bg-healthcare-hover-dark"
            >
              <ChevronRight className="h-4 w-4" />
            </button>
          </div>
        </div>
      </div>

      {selectedSummary ? (
        <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr),20rem]">
          <div className="space-y-3">
            <div className="rounded-md border border-healthcare-border bg-healthcare-surface p-4 shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="text-sm font-semibold tabular-nums text-healthcare-primary dark:text-healthcare-primary-dark">{selectedSummary.process_id}</span>
                    <h3 className="text-base font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{selectedSummary.name}</h3>
                  </div>
                  <p className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    {selectedSummary.domain_code} · {selectedSummary.domain_name}
                  </p>
                </div>
                <div className="flex flex-wrap gap-1.5">
                  <span className="rounded-full border border-healthcare-border px-2 py-0.5 text-xs font-semibold text-healthcare-text-primary dark:border-healthcare-border-dark dark:text-healthcare-text-primary-dark">{selectedSummary.priority}</span>
                  <span className="rounded-full border border-healthcare-border px-2 py-0.5 text-xs font-semibold text-healthcare-text-primary dark:border-healthcare-border-dark dark:text-healthcare-text-primary-dark">Evidence {selectedSummary.evidence_grade}</span>
                  <span className={`rounded-full border px-2 py-0.5 text-xs font-semibold ${READINESS_STYLE[selectedSummary.current_readiness]}`}>
                    {READINESS_LABEL[selectedSummary.current_readiness]}
                  </span>
                </div>
              </div>
              <p className="mt-3 text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                {selectedSummary.improvement_question}
              </p>
            </div>

            {detailQuery.isLoading ? (
              <LoadingCard message={`Loading ${selectedId} reference flow…`} />
            ) : detail ? (
              <ReferenceProcessMap detail={detail} />
            ) : (
              <LoadingCard message="This process flow could not be loaded from the seeded registry." />
            )}
          </div>

          <aside className="space-y-3">
            <div className="rounded-md border border-healthcare-border bg-healthcare-surface p-4 shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
              <div className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                <Layers3 className="h-4 w-4" aria-hidden="true" /> Core object collision
              </div>
              <div className="mt-3 flex flex-wrap gap-1.5">
                {detail?.model.core_objects.map((object) => (
                  <span key={object} className="rounded-full border border-healthcare-border px-2 py-1 text-xs font-medium text-healthcare-text-primary dark:border-healthcare-border-dark dark:text-healthcare-text-primary-dark">
                    {object}
                  </span>
                )) ?? <span className="text-xs text-healthcare-text-secondary">Loading objects…</span>}
              </div>
              <dl className="mt-4 space-y-3 text-xs">
                <div>
                  <dt className="font-semibold uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Interaction pattern</dt>
                  <dd className="mt-1 text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{selectedSummary.interaction_pattern.replaceAll('-', ' ')}</dd>
                </div>
                <div>
                  <dt className="font-semibold uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Delivery wave</dt>
                  <dd className="mt-1 text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{selectedSummary.implementation_wave.replace('_', ' ')}</dd>
                </div>
              </dl>
            </div>

            <div className="rounded-md border border-healthcare-border bg-healthcare-surface p-4 shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
              <div className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                <Database className="h-4 w-4" aria-hidden="true" /> Current readiness
              </div>
              <p className="mt-3 text-sm leading-5 text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                {selectedSummary.readiness_note}
              </p>
              <p className="mt-3 border-t border-healthcare-border pt-3 text-xs leading-4 text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark">
                Seeded nodes are target semantics from {index.document.id}. An exact activity count appears only when the current OCEL activity vocabulary already matches a target event name.
              </p>
            </div>

            <div className="rounded-md border border-healthcare-border bg-healthcare-surface p-4 text-xs text-healthcare-text-secondary shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-secondary-dark">
              <div className="font-semibold uppercase tracking-wide">Node legend</div>
              <div className="mt-2 grid grid-cols-2 gap-2">
                <span><span className="mr-1 inline-block h-2 w-2 rounded-full bg-healthcare-info dark:bg-healthcare-info-dark" />Trigger</span>
                <span><span className="mr-1 inline-block h-2 w-2 rounded-full bg-violet-500" />Decision</span>
                <span><span className="mr-1 inline-block h-2 w-2 rounded-full bg-healthcare-critical" />Exception</span>
                <span><span className="mr-1 inline-block h-2 w-2 rounded-full bg-teal-500" />Outcome</span>
              </div>
            </div>
          </aside>
        </div>
      ) : (
        <LoadingCard message="No OCEL models match the current filters." />
      )}
    </section>
  );
}
