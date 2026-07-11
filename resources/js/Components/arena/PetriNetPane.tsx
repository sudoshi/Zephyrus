import type { ArenaPetriNet } from '@/features/arena/schema';

/**
 * Phase XO.2 MVP: per-object-type Petri-net structural summary. Full node-link
 * layout is an additive follow-up; the counts here already tell an operator
 * whether a process is linear or branchy.
 */
export function PetriNetPane({ data }: { data: ArenaPetriNet }) {
  if (!data.nets.length) {
    return (
      <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        No object-centric Petri net available for the current selection.
      </p>
    );
  }
  return (
    <div className="grid gap-3 sm:grid-cols-2">
      {data.nets.map((net) => {
        const silent = net.transitions.filter((t) => t.label === null).length;
        const variable = net.arcs.filter((a) => a.variable).length;
        return (
          <div
            key={net.object_type}
            className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 shadow-sm dark:bg-healthcare-surface-dark"
          >
            <h3 className="text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
              {net.object_type}
            </h3>
            <dl className="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              <dt>Places</dt><dd className="tabular-nums text-right">{net.places.length}</dd>
              <dt>Transitions</dt><dd className="tabular-nums text-right">{net.transitions.length}</dd>
              <dt>Silent (τ)</dt><dd className="tabular-nums text-right">{silent}</dd>
              <dt>Variable arcs</dt><dd className="tabular-nums text-right">{variable}</dd>
            </dl>
          </div>
        );
      })}
    </div>
  );
}
