// resources/js/Components/arena/review/ReviewFlowMap.tsx
//
// The flow view for the Review — the window's discovered OCDFG under the
// performance overlay. Unlike the Study's OcdfgMap (arcs coloured by object
// type), arcs here are coloured by *barrier severity* and labelled with their
// median wait, so the map reads as a bottleneck heat-map. Selecting a barrier
// lights its map_focus and dims the rest; clicking a lit node/arc selects it
// back. The band is the engine's classification — only real barriers glow, the
// rest of the flow rests neutral (earned urgency, CLAUDE.md).
import { useMemo } from 'react';
import ReactFlow, {
  Background,
  Controls,
  Handle,
  Position,
  type Edge,
  type Node,
  type NodeProps,
  type NodeTypes,
} from 'reactflow';
import 'reactflow/dist/style.css';

import type { ArenaHandoff, ArenaOcdfg } from '@/features/arena/schema';
import type { RankedBarrier } from '@/features/arena/reviewSchema';
import { objectTypeColor } from '@/Components/arena/objectTypePalette';
import { SEVERITY_STRIPE } from './format';
import {
  BAND_COLOR,
  PERF_BANDS,
  barrierForEdge,
  barrierForNode,
  buildReviewGraph,
  type ReviewEdgeData,
  type ReviewNodeData,
} from './reviewGraph';

interface Props {
  map: ArenaOcdfg;
  performanceIndex: ArenaHandoff[];
  barriers: RankedBarrier[];
  selectedBarrier: RankedBarrier | null;
  onSelect: (barrierId: string | null) => void;
}

function makeReviewNode(orderedTypes: string[], selectedNodeIds: Set<string>) {
  return function ReviewActivityNode({ id, data }: NodeProps<ReviewNodeData>) {
    const selected = selectedNodeIds.has(id);
    return (
      <div
        style={{ width: 184, opacity: data.dimmed ? 0.35 : 1 }}
        className={`relative overflow-hidden rounded-md border bg-healthcare-surface px-3 py-2 shadow-sm transition-opacity dark:bg-healthcare-surface-dark ${
          selected
            ? 'border-healthcare-primary dark:border-healthcare-primary-dark'
            : 'border-healthcare-border dark:border-healthcare-border-dark'
        }`}
      >
        {/* Severity stripe — same left-edge language as the rail row, so a barrier
            reads identically in the list and on the map. */}
        {data.severity && (
          <span aria-hidden className={`absolute inset-y-0 left-0 w-1 ${SEVERITY_STRIPE[data.severity]}`} />
        )}
        <Handle type="target" position={Position.Left} className="!h-1.5 !w-1.5 !border-0 !bg-healthcare-border" />
        <Handle type="source" position={Position.Right} className="!h-1.5 !w-1.5 !border-0 !bg-healthcare-border" />
        <div className="flex items-center justify-between gap-2 pl-1">
          <span className="truncate text-xs font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            {data.activity}
          </span>
          <span className="tabular-nums text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            {data.frequency}
          </span>
        </div>
        <div className="mt-1.5 flex flex-wrap gap-1 pl-1">
          {data.objectTypes.map((type) => (
            <span
              key={type}
              title={type}
              className="inline-block h-1.5 w-1.5 rounded-full"
              style={{ backgroundColor: objectTypeColor(type, orderedTypes) }}
            />
          ))}
        </div>
      </div>
    );
  };
}

export function ReviewFlowMap({ map, performanceIndex, barriers, selectedBarrier, onSelect }: Props) {
  const orderedTypes = useMemo(() => [...map.object_types].sort(), [map.object_types]);

  const built = useMemo(
    () => buildReviewGraph(map, performanceIndex, barriers, selectedBarrier),
    [map, performanceIndex, barriers, selectedBarrier],
  );

  // The selected barrier's nodes get the primary-border "selected" treatment.
  const selectedNodeIds = useMemo(
    () => new Set(selectedBarrier?.map_focus.node_ids ?? []),
    [selectedBarrier],
  );

  const nodes = useMemo<Node<ReviewNodeData>[]>(() => built.nodes, [built.nodes]);
  const edges = useMemo<Edge<ReviewEdgeData>[]>(() => built.edges, [built.edges]);

  const nodeTypes = useMemo<NodeTypes>(
    () => ({ reviewActivity: makeReviewNode(orderedTypes, selectedNodeIds) }),
    [orderedTypes, selectedNodeIds],
  );

  return (
    <div className="space-y-2">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h3 className="text-xs font-semibold uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          Flow · bottleneck overlay
        </h3>
        <div className="flex flex-wrap items-center gap-3">
          {PERF_BANDS.map((band) => (
            <span key={band.severity} className="inline-flex items-center gap-1.5 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              <span aria-hidden className="inline-block h-0.5 w-4 rounded-full" style={{ backgroundColor: BAND_COLOR[band.severity] }} />
              {band.label}
            </span>
          ))}
          <span className="inline-flex items-center gap-1.5 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            <span aria-hidden className="inline-block h-0.5 w-4 rounded-full" style={{ backgroundColor: '#94A3B8' }} />
            Within band
          </span>
        </div>
      </div>
      {map.nodes.length > 0 ? (
        <div
          style={{ height: 620 }}
          className="overflow-hidden rounded-md border border-healthcare-border bg-healthcare-surface dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark"
        >
          <ReactFlow
            nodes={nodes}
            edges={edges}
            nodeTypes={nodeTypes}
            fitView
            minZoom={0.15}
            nodesConnectable={false}
            elementsSelectable
            proOptions={{ hideAttribution: true }}
            onNodeClick={(_event, node) => {
              const id = barrierForNode(barriers, node.id);
              if (id) onSelect(id);
            }}
            onEdgeClick={(_event, edge) => {
              const id = barrierForEdge(barriers, edge.id);
              if (id) onSelect(id);
            }}
            onPaneClick={() => onSelect(null)}
          >
            <Background gap={20} size={1} className="!bg-transparent" color="var(--healthcare-border)" />
            <Controls showInteractive={false} />
          </ReactFlow>
        </div>
      ) : (
        <div style={{ height: 620 }} className="flex items-center justify-center rounded-md border border-healthcare-border bg-healthcare-surface text-sm text-healthcare-text-secondary shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-secondary-dark">
          No activity in this window.
        </div>
      )}
      <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        Arc colour = barrier severity · width ∝ volume · label = median wait. Only transitions that are barriers glow; select one to focus its path.
      </p>
    </div>
  );
}
