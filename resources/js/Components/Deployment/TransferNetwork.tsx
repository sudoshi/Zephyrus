import type { TransferEdge } from '@/features/deployment/types';
import { EmptyState } from '@/Components/system';
import { useMemo, useState } from 'react';
import ReactFlow, {
  Background,
  BackgroundVariant,
  Controls,
  Handle,
  MarkerType,
  Position,
  type Edge,
  type Node,
} from 'reactflow';
import dagre from 'dagre';
import 'reactflow/dist/style.css';
import { humanize } from './format';

const NODE_W = 168;
const NODE_H = 46;

type NodeData = { label: string; sub?: string; focus?: boolean; external?: boolean };

function FacilityNode({ data }: { data: NodeData }) {
  const ring = data.focus
    ? 'border-healthcare-primary dark:border-healthcare-primary-dark ring-1 ring-healthcare-primary/40'
    : data.external
      ? 'border-dashed border-healthcare-border dark:border-healthcare-border-dark'
      : 'border-healthcare-border dark:border-healthcare-border-dark';
  return (
    <div
      className={`rounded-md border bg-healthcare-surface px-3 py-2 shadow-sm dark:bg-healthcare-surface-dark ${ring}`}
      style={{ width: NODE_W }}
    >
      <Handle type="target" position={Position.Left} className="!h-1.5 !w-1.5 !border-0 !bg-healthcare-border dark:!bg-healthcare-border-dark" />
      <div className="truncate text-xs font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
        {data.label}
      </div>
      {data.sub && (
        <div className="truncate text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          {data.sub}
        </div>
      )}
      <Handle type="source" position={Position.Right} className="!h-1.5 !w-1.5 !border-0 !bg-healthcare-border dark:!bg-healthcare-border-dark" />
    </div>
  );
}

const nodeTypes = { facility: FacilityNode };

interface AggEdge {
  source: string;
  target: string;
  serviceLines: Set<string>;
  minMinutes: number | null;
  external: boolean;
}

function buildGraph(edges: TransferEdge[], focusKey: string): { nodes: Node[]; edges: Edge[] } {
  const nodeMeta = new Map<string, NodeData>();
  const agg = new Map<string, AggEdge>();

  const ensureNode = (id: string, data: NodeData) => {
    if (!nodeMeta.has(id)) nodeMeta.set(id, data);
  };
  ensureNode(focusKey, { label: humanize(focusKey), focus: true, sub: 'This facility' });

  for (const e of edges) {
    const targetId = e.is_external_partner
      ? `ext:${e.destination_external_name ?? e.destination_facility_key ?? 'external'}`
      : (e.destination_facility_key ?? `ext:${e.destination_external_name ?? 'external'}`);
    const sourceId = e.source_facility_key;

    ensureNode(sourceId, { label: humanize(sourceId), focus: sourceId === focusKey });
    ensureNode(targetId, {
      label: e.is_external_partner ? (e.destination_external_name ?? 'External') : humanize(e.destination_facility_key ?? targetId),
      focus: targetId === focusKey,
      external: e.is_external_partner,
      sub: e.is_external_partner ? 'External partner' : undefined,
    });

    const key = `${sourceId}__${targetId}`;
    const existing = agg.get(key);
    if (existing) {
      if (e.service_line_code) existing.serviceLines.add(e.service_line_code);
      if (e.typical_minutes !== null) {
        existing.minMinutes = existing.minMinutes === null ? e.typical_minutes : Math.min(existing.minMinutes, e.typical_minutes);
      }
    } else {
      agg.set(key, {
        source: sourceId,
        target: targetId,
        serviceLines: new Set(e.service_line_code ? [e.service_line_code] : []),
        minMinutes: e.typical_minutes,
        external: e.is_external_partner,
      });
    }
  }

  const g = new dagre.graphlib.Graph();
  g.setDefaultEdgeLabel(() => ({}));
  g.setGraph({ rankdir: 'LR', nodesep: 20, ranksep: 90, marginx: 12, marginy: 12 });
  for (const id of nodeMeta.keys()) g.setNode(id, { width: NODE_W, height: NODE_H });
  for (const e of agg.values()) g.setEdge(e.source, e.target);
  dagre.layout(g);

  const nodes: Node[] = Array.from(nodeMeta.entries()).map(([id, data]) => {
    const pos = g.node(id);
    return {
      id,
      type: 'facility',
      data,
      position: { x: (pos?.x ?? 0) - NODE_W / 2, y: (pos?.y ?? 0) - NODE_H / 2 },
      draggable: true,
    };
  });

  const rfEdges: Edge[] = Array.from(agg.values()).map((e) => ({
    id: `${e.source}__${e.target}`,
    source: e.source,
    target: e.target,
    label: e.minMinutes !== null ? `${e.minMinutes}m` : `${e.serviceLines.size} line${e.serviceLines.size === 1 ? '' : 's'}`,
    markerEnd: { type: MarkerType.ArrowClosed, width: 16, height: 16 },
    style: { stroke: 'var(--border-strong, #64748B)', strokeWidth: 1.5, ...(e.external ? { strokeDasharray: '5 4' } : {}) },
    labelStyle: { fill: 'var(--text-secondary, #94A3B8)', fontSize: 10, fontVariantNumeric: 'tabular-nums' },
    labelBgStyle: { fill: 'var(--surface-base, #1E293B)', fillOpacity: 0.9 },
    labelBgPadding: [4, 2],
    labelBgBorderRadius: 4,
  }));

  return { nodes, edges: rfEdges };
}

export function TransferNetwork({ edges, focusKey }: { edges: TransferEdge[]; focusKey: string }) {
  const [serviceLine, setServiceLine] = useState<string>('');

  const serviceLines = useMemo(
    () => Array.from(new Set(edges.map((e) => e.service_line_code).filter((c): c is string => !!c))).sort(),
    [edges],
  );

  const filtered = useMemo(
    () => (serviceLine ? edges.filter((e) => e.service_line_code === serviceLine) : edges),
    [edges, serviceLine],
  );

  const graph = useMemo(() => buildGraph(filtered, focusKey), [filtered, focusKey]);

  if (edges.length === 0) {
    return <EmptyState message="No interfacility transfer relationships for this facility." icon="heroicons:arrows-right-left" />;
  }

  return (
    <div className="space-y-2">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex items-center gap-3 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          <span className="tabular-nums">{graph.edges.length} routes</span>
          <span className="inline-flex items-center gap-1">
            <span className="h-0.5 w-4 bg-[var(--border-strong,#64748B)]" /> Internal
          </span>
          <span className="inline-flex items-center gap-1">
            <span className="h-0.5 w-4 border-t border-dashed border-[var(--border-strong,#64748B)]" /> External partner
          </span>
        </div>
        <label className="flex items-center gap-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          Service line
          <select
            value={serviceLine}
            onChange={(e) => setServiceLine(e.target.value)}
            className="rounded-md border border-healthcare-border bg-healthcare-surface px-2 py-1 text-xs text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-primary-dark"
          >
            <option value="">All ({serviceLines.length})</option>
            {serviceLines.map((sl) => (
              <option key={sl} value={sl}>
                {humanize(sl)}
              </option>
            ))}
          </select>
        </label>
      </div>
      <div className="h-[460px] overflow-hidden rounded-lg border border-healthcare-border bg-healthcare-background dark:border-healthcare-border-dark dark:bg-healthcare-background-dark">
        <ReactFlow
          nodes={graph.nodes}
          edges={graph.edges}
          nodeTypes={nodeTypes}
          fitView
          proOptions={{ hideAttribution: true }}
          minZoom={0.2}
          nodesConnectable={false}
          edgesFocusable={false}
        >
          <Background variant={BackgroundVariant.Dots} gap={20} size={1} className="!bg-transparent" color="var(--border-subtle, #33415580)" />
          <Controls showInteractive={false} className="!border-healthcare-border" />
        </ReactFlow>
      </div>
    </div>
  );
}
