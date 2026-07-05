// resources/js/Pages/Analytics/Arena.tsx
//
// Zephyrus 2.0 Part X (X1) — the Patient-Flow Arena Study surface. Renders the
// object-centric process map discovered from the OCEL log (server-cached in
// arena.maps, mined by the PHI-free OCPM sidecar). Data stays `unknown` until
// parsed with the Zod schema at this boundary; every failure degrades to an
// in-place card, never a white screen.
import { useMemo, useState } from 'react';
import { Head } from '@inertiajs/react';
import AnalyticsLayout from '@/Layouts/AnalyticsLayout';
import { ConformancePane } from '@/Components/arena/ConformancePane';
import { OcdfgMap } from '@/Components/arena/OcdfgMap';
import { MIXED_EDGE_COLOR, objectTypeColor } from '@/Components/arena/objectTypePalette';
import { useArenaConformance, useArenaMap, useArenaSummary } from '@/features/arena/hooks';
import {
  arenaConformanceResponseSchema,
  arenaMapResponseSchema,
  arenaSummarySchema,
  type ArenaOcdfg,
  type ArenaPathwayConformance,
  type ArenaSummary,
} from '@/features/arena/schema';

function StatBlock({ label, value }: { label: string; value: string | number }) {
  return (
    <div className="flex flex-col">
      <span className="text-xs font-medium uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        {label}
      </span>
      <span className="tabular-nums text-2xl font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
        {value}
      </span>
    </div>
  );
}

function InfoCard({ title, body }: { title: string; body: string }) {
  return (
    <div className="rounded-md border border-healthcare-border bg-healthcare-surface p-6 shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
      <h2 className="text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{title}</h2>
      <p className="mt-2 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{body}</p>
    </div>
  );
}

export default function Arena() {
  const [selectedTypes, setSelectedTypes] = useState<string[]>([]);
  const [minFreq, setMinFreq] = useState<number>(3);
  const [selectedNodeId, setSelectedNodeId] = useState<string | null>(null);

  const summaryQuery = useArenaSummary();
  const mapQuery = useArenaMap({ types: selectedTypes, minFreq, scope: 'house' });

  const summary = useMemo<ArenaSummary | null>(() => {
    if (summaryQuery.data === undefined) return null;
    const parsed = arenaSummarySchema.safeParse(summaryQuery.data);
    return parsed.success ? parsed.data : null;
  }, [summaryQuery.data]);

  const mapResult = useMemo(() => {
    if (mapQuery.data === undefined) return null;
    const parsed = arenaMapResponseSchema.safeParse(mapQuery.data);
    return parsed.success ? parsed.data : null;
  }, [mapQuery.data]);

  const ocdfg: ArenaOcdfg | null = mapResult && mapResult.available ? mapResult.map : null;

  const conformanceQuery = useArenaConformance();
  const conformancePathways = useMemo<ArenaPathwayConformance[] | null>(() => {
    if (conformanceQuery.data === undefined) return null;
    const parsed = arenaConformanceResponseSchema.safeParse(conformanceQuery.data);
    if (!parsed.success || !parsed.data.available) return null;
    return parsed.data.pathways;
  }, [conformanceQuery.data]);

  // Stable, full object-type ordering (from the summary) so a colour never
  // shifts when the user filters the map to a subset of types.
  const orderedTypes = useMemo<string[]>(() => {
    if (summary) return Object.keys(summary.object_types).sort();
    return ocdfg ? [...ocdfg.object_types].sort() : [];
  }, [summary, ocdfg]);

  const selectedNode = useMemo(
    () => ocdfg?.nodes.find((node) => node.id === selectedNodeId) ?? null,
    [ocdfg, selectedNodeId],
  );

  const toggleType = (type: string) => {
    setSelectedTypes((current) =>
      current.includes(type) ? current.filter((t) => t !== type) : [...current, type],
    );
  };

  return (
    <AnalyticsLayout title="Patient-Flow Arena" headerButtons={null}>
      <Head title="Patient-Flow Arena" />

      <div className="space-y-4">
        <InfoCard
          title="Object-centric process map — discovered, not drawn"
          body="This map is mined from the OCEL 2.0 log the platform projects from its own event data (RTDC, ED, perioperative, clinical pathways). Each node is an activity; each arc is a directly-follows relation coloured by the object type that governs it. Filter to a single object type to read that lifecycle in isolation."
        />

        {/* Summary strip */}
        <div className="rounded-md border border-healthcare-border bg-healthcare-surface p-6 shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
          {summary ? (
            <div className="flex flex-wrap gap-x-10 gap-y-4">
              <StatBlock label="Events" value={summary.events.toLocaleString()} />
              <StatBlock label="Objects" value={summary.objects.toLocaleString()} />
              <StatBlock label="Object types" value={Object.keys(summary.object_types).length} />
              <StatBlock label="Activities" value={Object.keys(summary.activities).length} />
            </div>
          ) : (
            <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              {summaryQuery.isError ? 'Log summary unavailable.' : 'Loading log summary…'}
            </p>
          )}
        </div>

        {/* Controls */}
        <div className="flex flex-wrap items-center gap-4 rounded-md border border-healthcare-border bg-healthcare-surface p-4 shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              Object types
            </span>
            {orderedTypes.map((type) => {
              const active = selectedTypes.length === 0 || selectedTypes.includes(type);
              return (
                <button
                  key={type}
                  type="button"
                  onClick={() => toggleType(type)}
                  className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-medium transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-healthcare-gold ${
                    active
                      ? 'border-healthcare-border bg-healthcare-hover text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-healthcare-hover-dark dark:text-healthcare-text-primary-dark'
                      : 'border-healthcare-border text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark'
                  }`}
                >
                  <span className="h-2 w-2 rounded-full" style={{ backgroundColor: objectTypeColor(type, orderedTypes) }} />
                  {type}
                </button>
              );
            })}
            {selectedTypes.length > 0 && (
              <button
                type="button"
                onClick={() => setSelectedTypes([])}
                className="text-xs font-medium text-healthcare-primary hover:underline dark:text-healthcare-primary-dark"
              >
                Reset
              </button>
            )}
          </div>

          <label className="flex items-center gap-2 text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            Min activity freq
            <input
              type="number"
              min={1}
              max={200}
              value={minFreq}
              onChange={(event) => setMinFreq(Math.max(1, Number(event.target.value) || 1))}
              className="w-20 rounded-md border border-healthcare-border bg-healthcare-surface px-2 py-1 text-xs tabular-nums text-healthcare-text-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-healthcare-gold dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-primary-dark"
            />
          </label>

          <button
            type="button"
            onClick={() => mapQuery.refetch()}
            className="rounded-md border border-healthcare-border px-3 py-1.5 text-xs font-medium text-healthcare-text-primary hover:bg-healthcare-hover focus-visible:outline focus-visible:outline-2 focus-visible:outline-healthcare-gold dark:border-healthcare-border-dark dark:text-healthcare-text-primary-dark dark:hover:bg-healthcare-hover-dark"
          >
            Refresh
          </button>

          {mapResult && mapResult.available && (
            <span className="ml-auto text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              {mapResult.stale ? 'Serving last-good (sidecar unreachable)' : mapResult.cached ? 'Cached' : 'Freshly mined'}
            </span>
          )}
        </div>

        {/* Map + detail */}
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-[1fr,20rem]">
          <div>
            {mapQuery.isError ? (
              <InfoCard
                title="Arena unavailable"
                body="The Arena map could not load. Confirm ARENA_ENABLED is on and the OCPM sidecar is reachable, then Refresh."
              />
            ) : mapResult && !mapResult.available ? (
              <InfoCard
                title="OCPM sidecar unavailable"
                body="The discovery engine is not reachable right now. The map will return once the sidecar is back."
              />
            ) : ocdfg && ocdfg.nodes.length > 0 ? (
              <OcdfgMap
                ocdfg={ocdfg}
                orderedTypes={orderedTypes}
                selectedNodeId={selectedNodeId}
                onSelectNode={setSelectedNodeId}
              />
            ) : ocdfg ? (
              <InfoCard title="No activities at this threshold" body="Lower the minimum activity frequency or clear the object-type filter." />
            ) : (
              <div
                style={{ height: 620 }}
                className="flex items-center justify-center rounded-md border border-healthcare-border bg-healthcare-surface text-sm text-healthcare-text-secondary shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-secondary-dark"
              >
                Mining the process map…
              </div>
            )}
          </div>

          {/* Detail / legend panel */}
          <div className="space-y-4">
            <div className="rounded-md border border-healthcare-border bg-healthcare-surface p-4 shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
              <h3 className="text-xs font-semibold uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                {selectedNode ? 'Activity' : 'Object types'}
              </h3>
              {selectedNode ? (
                <div className="mt-3 space-y-3">
                  <div className="text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    {selectedNode.activity}
                  </div>
                  <div className="flex items-center justify-between text-sm">
                    <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Occurrences</span>
                    <span className="tabular-nums font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                      {selectedNode.frequency.toLocaleString()}
                    </span>
                  </div>
                  <div>
                    <div className="mb-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Touched by</div>
                    <div className="flex flex-wrap gap-1.5">
                      {selectedNode.object_types.map((type) => (
                        <span
                          key={type}
                          className="inline-flex items-center gap-1.5 rounded-full border border-healthcare-border px-2 py-0.5 text-xs font-medium text-healthcare-text-primary dark:border-healthcare-border-dark dark:text-healthcare-text-primary-dark"
                        >
                          <span className="h-2 w-2 rounded-full" style={{ backgroundColor: objectTypeColor(type, orderedTypes) }} />
                          {type}
                        </span>
                      ))}
                    </div>
                  </div>
                </div>
              ) : (
                <ul className="mt-3 space-y-2">
                  {orderedTypes.map((type) => (
                    <li key={type} className="flex items-center gap-2 text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                      <span className="h-2.5 w-2.5 rounded-full" style={{ backgroundColor: objectTypeColor(type, orderedTypes) }} />
                      <span className="flex-1">{type}</span>
                      {summary && (
                        <span className="tabular-nums text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                          {summary.object_types[type]?.toLocaleString() ?? '—'}
                        </span>
                      )}
                    </li>
                  ))}
                  <li className="flex items-center gap-2 pt-1 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    <span className="h-2.5 w-2.5 rounded-full" style={{ backgroundColor: MIXED_EDGE_COLOR }} />
                    <span>Multi-type transition</span>
                  </li>
                </ul>
              )}
            </div>

            {ocdfg && (
              <div className="rounded-md border border-healthcare-border bg-healthcare-surface p-4 text-xs text-healthcare-text-secondary shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-secondary-dark">
                <span className="tabular-nums font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                  {ocdfg.nodes.length}
                </span>{' '}
                activities ·{' '}
                <span className="tabular-nums font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                  {ocdfg.edges.length}
                </span>{' '}
                transitions across{' '}
                <span className="tabular-nums font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                  {ocdfg.object_types.length}
                </span>{' '}
                object types.
              </div>
            )}
          </div>
        </div>

        {/* Conformance pane (X3) — patient safety as conformance */}
        <div className="space-y-3 pt-2">
          <div>
            <h2 className="text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
              Patient-safety conformance
            </h2>
            <p className="mt-1 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              Live adherence of the OCEL log to the reference care pathways. Every deviation is observed — derived from the
              event sequence and timing — never predicted.
            </p>
          </div>
          {conformancePathways ? (
            <ConformancePane pathways={conformancePathways} />
          ) : (
            <div className="rounded-md border border-healthcare-border bg-healthcare-surface p-6 text-sm text-healthcare-text-secondary shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-secondary-dark">
              {conformanceQuery.isError ? 'Conformance unavailable — is the OCPM sidecar reachable?' : 'Checking pathway conformance…'}
            </div>
          )}
        </div>
      </div>
    </AnalyticsLayout>
  );
}
