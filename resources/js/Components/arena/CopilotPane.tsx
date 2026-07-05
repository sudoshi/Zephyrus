// resources/js/Components/arena/CopilotPane.tsx
//
// Part X (X4) — the governed AI copilot pane. Four capabilities, each with the
// same discipline the doc mandates: a provenance-pinned narrative (numbers shown
// beside the prose), an allow-listed NL query (the box can only run one of the
// named queries — never free-form SQL), a map-authoring action that surfaces its
// conformance FITNESS and withholds below the floor, and draft buttons that land a
// PENDING action on the Eddy approval queue (the copilot proposes; a human approves).
//
// This component only mounts when props.arena.ai_enabled is true, so a disabled
// copilot never calls the (404-gated) routes.
import { useMemo, useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import {
  postArenaAuthorMap,
  postArenaDraftCorrection,
  postArenaDraftPdsa,
  postArenaQuery,
} from '@/features/arena/api';
import { useArenaNarrative } from '@/features/arena/hooks';
import {
  arenaAuthorMapResponseSchema,
  arenaDraftResponseSchema,
  arenaNarrativeResponseSchema,
  arenaQueryResponseSchema,
} from '@/features/arena/schema';

function AiBadge() {
  return (
    <span className="inline-flex items-center rounded-full border border-healthcare-border px-2 py-0.5 text-xs font-medium text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark">
      AI-generated · human-approved
    </span>
  );
}

function Card({ title, hint, children }: { title: string; hint?: string; children: React.ReactNode }) {
  return (
    <div className="rounded-md border border-healthcare-border bg-healthcare-surface p-5 shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
      <h3 className="text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{title}</h3>
      {hint && <p className="mt-0.5 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{hint}</p>}
      <div className="mt-3">{children}</div>
    </div>
  );
}

const btn =
  'rounded-md border border-healthcare-border px-3 py-1.5 text-xs font-medium text-healthcare-text-primary hover:bg-healthcare-hover focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-healthcare-gold disabled:opacity-50 dark:border-healthcare-border-dark dark:text-healthcare-text-primary-dark dark:hover:bg-healthcare-hover-dark';

function NarrativeCard() {
  const query = useArenaNarrative(true);
  const parsed = useMemo(() => {
    if (query.data === undefined) return null;
    const r = arenaNarrativeResponseSchema.safeParse(query.data);
    return r.success ? r.data : null;
  }, [query.data]);

  return (
    <Card title="Process narrative" hint="Every claim is pinned to a live metric — the numbers are shown beside the prose.">
      {parsed && parsed.available ? (
        <div className="space-y-3">
          <p className="text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{parsed.narrative}</p>
          <ul className="space-y-1">
            {parsed.provenance.map((f) => (
              <li key={f.claim} className="flex items-baseline justify-between gap-3 text-xs">
                <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{f.claim}</span>
                <span className="tabular-nums font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{f.value}</span>
              </li>
            ))}
          </ul>
          <div className="flex items-center gap-2 pt-1">
            <AiBadge />
            <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{parsed.generated_label}</span>
          </div>
        </div>
      ) : (
        <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          {query.isError ? 'Narrative unavailable.' : 'Assembling the process narrative…'}
        </p>
      )}
    </Card>
  );
}

function AskCard() {
  const [question, setQuestion] = useState('');
  const mutation = useMutation({ mutationFn: (q: string) => postArenaQuery(q) });
  const result = useMemo(() => {
    if (mutation.data === undefined) return null;
    const r = arenaQueryResponseSchema.safeParse(mutation.data);
    return r.success ? r.data : null;
  }, [mutation.data]);

  const submit = () => {
    const q = question.trim();
    if (q) mutation.mutate(q);
  };

  return (
    <Card title="Ask the log" hint="Answered by an allow-listed, parameterized query — never free-form SQL.">
      <div className="flex gap-2">
        <input
          type="text"
          value={question}
          onChange={(e) => setQuestion(e.target.value)}
          onKeyDown={(e) => e.key === 'Enter' && submit()}
          placeholder="e.g. busiest activities, top 5"
          className="flex-1 rounded-md border border-healthcare-border bg-healthcare-surface px-3 py-1.5 text-sm text-healthcare-text-primary placeholder:text-healthcare-text-secondary focus-visible:outline focus-visible:outline-2 focus-visible:outline-healthcare-gold dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-primary-dark dark:placeholder:text-healthcare-text-secondary-dark"
        />
        <button type="button" onClick={submit} disabled={mutation.isPending} className={btn}>
          {mutation.isPending ? 'Asking…' : 'Ask'}
        </button>
      </div>

      {result && result.available && result.matched && result.columns && result.rows ? (
        <div className="mt-3 space-y-2">
          <div className="text-xs font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            {result.label}
            <span className="ml-2 font-normal text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              via “{result.query_id}” ({result.routed_by})
            </span>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-healthcare-border text-left text-xs text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark">
                  {result.columns.map((c) => (
                    <th key={c} className="pb-1.5 font-medium">{c}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {result.rows.map((row, i) => (
                  <tr key={i} className="border-b border-healthcare-border/60 last:border-0 dark:border-healthcare-border-dark/60">
                    {result.columns!.map((c) => (
                      <td key={c} className="py-1.5 tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                        {String(row[c] ?? '—')}
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {result.provenance && (
            <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{result.provenance}</p>
          )}
        </div>
      ) : result && result.available && !result.matched ? (
        <div className="mt-3 space-y-2">
          <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{result.message}</p>
          {result.suggestions && (
            <div className="flex flex-wrap gap-1.5">
              {result.suggestions.map((s) => (
                <button
                  key={s.id}
                  type="button"
                  onClick={() => { setQuestion(s.label); mutation.mutate(s.label); }}
                  className="rounded-full border border-healthcare-border px-2.5 py-1 text-xs font-medium text-healthcare-text-secondary hover:bg-healthcare-hover dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark dark:hover:bg-healthcare-hover-dark"
                >
                  {s.label}
                </button>
              ))}
            </div>
          )}
        </div>
      ) : mutation.isError ? (
        <p className="mt-3 text-sm text-healthcare-critical dark:text-healthcare-critical-dark">The copilot could not answer that right now.</p>
      ) : null}
    </Card>
  );
}

function AuthorMapCard() {
  const mutation = useMutation({ mutationFn: () => postArenaAuthorMap() });
  const result = useMemo(() => {
    if (mutation.data === undefined) return null;
    const r = arenaAuthorMapResponseSchema.safeParse(mutation.data);
    return r.success ? r.data : null;
  }, [mutation.data]);

  return (
    <Card title="Author a map" hint="The copilot proposes a map; the data adjudicates its fitness. A model below the floor is withheld.">
      <button type="button" onClick={() => mutation.mutate()} disabled={mutation.isPending} className={btn}>
        {mutation.isPending ? 'Validating…' : 'Propose & validate'}
      </button>

      {result && result.available ? (
        <div className="mt-3 space-y-2">
          <div className="flex flex-wrap items-center gap-2">
            <span
              className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${
                result.published
                  ? 'bg-healthcare-success/10 text-healthcare-success dark:bg-healthcare-success/20 dark:text-healthcare-success-dark'
                  : 'bg-healthcare-warning/10 text-healthcare-warning dark:bg-healthcare-warning/20 dark:text-healthcare-warning-dark'
              }`}
            >
              {result.published ? 'Conformance-validated' : 'Withheld (below fitness floor)'}
            </span>
            <span className="text-xs tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              fitness {(result.fitness * 100).toFixed(1)}% · precision {(result.precision * 100).toFixed(1)}% · floor {(result.fitness_floor * 100).toFixed(0)}%
            </span>
          </div>
          <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{result.generated_label}</p>
          {/* The gate's evidence — the busy real paths a model missed and the arcs it invented. */}
          {((result.invented_edges?.length ?? 0) > 0 || (result.missing_edges?.length ?? 0) > 0) && (
            <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              {(result.invented_edges?.length ?? 0)} invented {(result.invented_edges?.length ?? 0) === 1 ? 'arc' : 'arcs'} ·{' '}
              {(result.missing_edges?.length ?? 0)} busy {(result.missing_edges?.length ?? 0) === 1 ? 'path' : 'paths'} missed
            </p>
          )}
        </div>
      ) : mutation.isError ? (
        <p className="mt-3 text-sm text-healthcare-critical dark:text-healthcare-critical-dark">Map validation is unavailable (the OCPM sidecar may be down).</p>
      ) : null}
    </Card>
  );
}

function DraftResult({ data, isError }: { data: unknown; isError?: boolean }) {
  const parsed = useMemo(() => {
    if (data === undefined) return null;
    const r = arenaDraftResponseSchema.safeParse(data);
    return r.success ? r.data : null;
  }, [data]);
  if (isError) {
    return <p className="mt-2 text-xs text-healthcare-critical dark:text-healthcare-critical-dark">Draft could not be created.</p>;
  }
  if (!parsed) return null;
  if (parsed.drafted && parsed.action) {
    return (
      <p className="mt-2 text-xs text-healthcare-success dark:text-healthcare-success-dark">
        Drafted → pending approval · {parsed.action.action_type} (#{parsed.action.approval_uuid?.slice(0, 8)})
      </p>
    );
  }
  return (
    <p className="mt-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
      Nothing to draft ({parsed.reason ?? 'no signal'}).
    </p>
  );
}

function DraftCard() {
  const pdsa = useMutation({ mutationFn: (focus: string) => postArenaDraftPdsa(focus) });
  const correction = useMutation({ mutationFn: (pathway: string) => postArenaDraftCorrection(pathway) });

  return (
    <Card title="Draft a governed action" hint="Every draft lands PENDING on the approval queue — the copilot holds ops:draft, never ops:approve.">
      <div className="space-y-3">
        <div>
          <div className="mb-1.5 text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">PDSA cycle</div>
          <div className="flex flex-wrap gap-2">
            {(['bottleneck', 'sepsis', 'surgical_safety'] as const).map((f) => (
              <button key={f} type="button" onClick={() => pdsa.mutate(f)} disabled={pdsa.isPending} className={btn}>
                {f}
              </button>
            ))}
          </div>
          <DraftResult data={pdsa.data} isError={pdsa.isError} />
        </div>
        <div>
          <div className="mb-1.5 text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Pathway correction</div>
          <div className="flex flex-wrap gap-2">
            {(['sepsis', 'surgical_safety'] as const).map((p) => (
              <button key={p} type="button" onClick={() => correction.mutate(p)} disabled={correction.isPending} className={btn}>
                {p}
              </button>
            ))}
          </div>
          <DraftResult data={correction.data} isError={correction.isError} />
        </div>
      </div>
    </Card>
  );
}

export function CopilotPane() {
  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
      <NarrativeCard />
      <AskCard />
      <AuthorMapCard />
      <DraftCard />
    </div>
  );
}
