// Minimal ambient types for `dagre` (no @types/dagre is installed, and dagre
// ships none). Covers only the surface the OC-DFG layout uses. Kept local so the
// Arena's .ts layout can stay strict-typed without an `any` or a new dependency.
declare module 'dagre' {
  interface GraphLabel {
    rankdir?: 'TB' | 'BT' | 'LR' | 'RL';
    nodesep?: number;
    ranksep?: number;
    marginx?: number;
    marginy?: number;
    [key: string]: unknown;
  }

  interface NodeConfig {
    width: number;
    height: number;
    x?: number;
    y?: number;
  }

  interface DagreGraph {
    setGraph(options: GraphLabel): DagreGraph;
    setDefaultEdgeLabel(callback: () => Record<string, unknown>): DagreGraph;
    setNode(id: string, node: NodeConfig): DagreGraph;
    setEdge(source: string, target: string, label?: Record<string, unknown>): DagreGraph;
    hasNode(id: string): boolean;
    node(id: string): NodeConfig | undefined;
  }

  interface GraphlibNamespace {
    Graph: new (options?: { directed?: boolean; multigraph?: boolean; compound?: boolean }) => DagreGraph;
  }

  interface Dagre {
    graphlib: GraphlibNamespace;
    layout: (graph: DagreGraph) => void;
  }

  const dagre: Dagre;
  export default dagre;
}
