import { useMemo } from 'react';
import ReactFlow, {
  Background,
  Controls,
  Handle,
  Position,
  type NodeProps,
  type NodeTypes,
} from 'reactflow';
import 'reactflow/dist/style.css';

import type { ArenaProcessModelDetail } from '@/features/arena/schema';
import { objectTypeColor } from './objectTypePalette';
import {
  buildReferenceProcessGraph,
  type ReferenceProcessNodeData,
} from './referenceProcessLayout';

const KIND_STYLE: Record<ReferenceProcessNodeData['kind'], string> = {
  trigger: 'border-healthcare-info/70 bg-healthcare-info/10 dark:border-healthcare-info-dark/70 dark:bg-healthcare-info-dark/15',
  event: 'border-healthcare-border bg-healthcare-surface dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark',
  decision: 'border-violet-500/70 bg-violet-50 dark:border-violet-400/70 dark:bg-violet-950/30',
  exception: 'border-healthcare-critical/70 bg-healthcare-critical/5 dark:border-healthcare-critical-dark/70 dark:bg-healthcare-critical-dark/10',
  outcome: 'border-teal-500/70 bg-teal-50 dark:border-teal-400/70 dark:bg-teal-950/30',
};

function ReferenceActivityNode({ data, selected }: NodeProps<ReferenceProcessNodeData>) {
  return (
    <div
      style={{ width: 216, minHeight: 92 }}
      className={`rounded-md border px-3 py-2.5 shadow-sm ${KIND_STYLE[data.kind]} ${
        selected ? 'ring-2 ring-healthcare-gold ring-offset-2 dark:ring-offset-healthcare-background-dark' : ''
      }`}
    >
      <Handle type="target" position={Position.Left} className="!h-2 !w-2 !border-0 !bg-healthcare-border-dark" />
      <Handle type="source" position={Position.Right} className="!h-2 !w-2 !border-0 !bg-healthcare-border-dark" />

      <div className="flex items-start gap-2">
        <span className="mt-0.5 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-healthcare-background px-1 text-xs font-semibold tabular-nums text-healthcare-text-secondary dark:bg-healthcare-background-dark dark:text-healthcare-text-secondary-dark">
          {data.ordinal}
        </span>
        <div className="min-w-0 flex-1">
          <div className="text-xs font-semibold leading-4 text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            {data.label}
          </div>
          <div className="mt-1 text-xs font-medium uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            {data.kind}
            {data.observedCount > 0 ? ` · ${data.observedCount} exact activity matches` : ''}
          </div>
        </div>
      </div>

      <div className="mt-2 flex flex-wrap gap-1">
        {data.objectTypes.map((type) => (
          <span
            key={type}
            title={type}
            className="inline-flex max-w-[9rem] items-center gap-1 rounded-full border border-healthcare-border/70 bg-healthcare-surface/80 px-1.5 py-0.5 text-xs font-medium text-healthcare-text-secondary dark:border-healthcare-border-dark/70 dark:bg-healthcare-surface-dark/80 dark:text-healthcare-text-secondary-dark"
          >
            <span
              className="h-1.5 w-1.5 shrink-0 rounded-full"
              style={{ backgroundColor: objectTypeColor(type, data.orderedTypes) }}
            />
            <span className="truncate">{type}</span>
          </span>
        ))}
      </div>
    </div>
  );
}

interface ReferenceProcessMapProps {
  detail: ArenaProcessModelDetail;
}

export function ReferenceProcessMap({ detail }: ReferenceProcessMapProps) {
  const graph = useMemo(() => buildReferenceProcessGraph(detail), [detail]);
  const nodeTypes = useMemo<NodeTypes>(() => ({ referenceActivity: ReferenceActivityNode }), []);

  return (
    <div
      style={{ height: 540 }}
      className="overflow-hidden rounded-md border border-healthcare-border bg-healthcare-background/50 dark:border-healthcare-border-dark dark:bg-healthcare-background-dark/50"
      aria-label={`${detail.model.process_id} ${detail.model.name} reference process flow`}
    >
      <ReactFlow
        nodes={graph.nodes}
        edges={graph.edges}
        nodeTypes={nodeTypes}
        fitView
        fitViewOptions={{ padding: 0.2 }}
        minZoom={0.25}
        maxZoom={1.7}
        nodesConnectable={false}
        nodesDraggable={false}
        elementsSelectable
        proOptions={{ hideAttribution: true }}
      >
        <Background gap={20} size={1} className="!bg-transparent" color="var(--healthcare-border)" />
        <Controls showInteractive={false} />
      </ReactFlow>
    </div>
  );
}
