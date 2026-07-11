// resources/js/Components/arena/review/ReviewFlowMap.tsx
//
// The flow view for the Review — the window's discovered OCDFG. Reuses the
// Study's read-only OcdfgMap verbatim; selecting a barrier focuses its first
// activity. The StatusEngine performance overlay (edge colour by band) lands
// with buildReviewGraph in the next slice — today arcs are object-type coloured.
import { useMemo } from 'react';
import type { ArenaOcdfg } from '@/features/arena/schema';
import { OcdfgMap } from '@/Components/arena/OcdfgMap';

interface Props {
  map: ArenaOcdfg;
  selectedNodeId: string | null;
  onSelectNode: (id: string | null) => void;
}

export function ReviewFlowMap({ map, selectedNodeId, onSelectNode }: Props) {
  const orderedTypes = useMemo(() => [...map.object_types].sort(), [map.object_types]);

  return (
    <div className="space-y-2">
      <h3 className="text-xs font-semibold uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        Flow · discovered map
      </h3>
      {map.nodes.length > 0 ? (
        <OcdfgMap ocdfg={map} orderedTypes={orderedTypes} selectedNodeId={selectedNodeId} onSelectNode={onSelectNode} />
      ) : (
        <div style={{ height: 620 }} className="flex items-center justify-center rounded-md border border-healthcare-border bg-healthcare-surface text-sm text-healthcare-text-secondary shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-secondary-dark">
          No activity in this window.
        </div>
      )}
      <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        Arcs coloured by object type; the performance overlay (colour by StatusEngine band) is the next slice. Select a barrier to focus its activities.
      </p>
    </div>
  );
}
