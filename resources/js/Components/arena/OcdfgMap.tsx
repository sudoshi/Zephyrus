// resources/js/Components/arena/OcdfgMap.tsx
//
// Renders the object-centric DFG with reactflow + dagre, in the canon: activity
// nodes are Surface-treated cards (Figtree, tabular-nums), arcs are coloured by
// object type (a sanctioned categorical data-viz palette), width ∝ frequency.
// Read-only — the Study observes the discovered process, it does not edit it.
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

import type { ArenaOcdfg } from '@/features/arena/schema';
import { buildOcdfgGraph, type ActivityNodeData, type OcdfgEdgeData } from './ocdfgLayout';
import { objectTypeColor } from './objectTypePalette';

interface OcdfgMapProps {
  ocdfg: ArenaOcdfg;
  orderedTypes: string[];
  selectedNodeId: string | null;
  onSelectNode: (id: string | null) => void;
}

function makeActivityNode(orderedTypes: string[]) {
  return function ActivityNode({ data, selected }: NodeProps<ActivityNodeData>) {
    return (
      <div
        style={{ width: 184 }}
        className={`rounded-md border bg-healthcare-surface px-3 py-2 shadow-sm dark:bg-healthcare-surface-dark ${
          selected
            ? 'border-healthcare-primary dark:border-healthcare-primary-dark'
            : 'border-healthcare-border dark:border-healthcare-border-dark'
        }`}
      >
        <Handle type="target" position={Position.Left} className="!h-1.5 !w-1.5 !border-0 !bg-healthcare-border" />
        <Handle type="source" position={Position.Right} className="!h-1.5 !w-1.5 !border-0 !bg-healthcare-border" />
        <div className="flex items-center justify-between gap-2">
          <span className="truncate text-xs font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            {data.activity}
          </span>
          <span className="tabular-nums text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            {data.frequency}
          </span>
        </div>
        <div className="mt-1.5 flex flex-wrap gap-1">
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

export function OcdfgMap({ ocdfg, orderedTypes, selectedNodeId, onSelectNode }: OcdfgMapProps) {
  const built = useMemo(() => buildOcdfgGraph(ocdfg, orderedTypes), [ocdfg, orderedTypes]);

  const nodes = useMemo<Node<ActivityNodeData>[]>(
    () => built.nodes.map((node) => ({ ...node, selected: node.id === selectedNodeId })),
    [built.nodes, selectedNodeId],
  );

  const edges = useMemo<Edge<OcdfgEdgeData>[]>(() => built.edges, [built.edges]);

  const nodeTypes = useMemo<NodeTypes>(() => ({ activity: makeActivityNode(orderedTypes) }), [orderedTypes]);

  return (
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
        onNodeClick={(_event, node) => onSelectNode(node.id)}
        onPaneClick={() => onSelectNode(null)}
      >
        <Background gap={20} size={1} className="!bg-transparent" color="var(--healthcare-border)" />
        <Controls showInteractive={false} />
      </ReactFlow>
    </div>
  );
}
