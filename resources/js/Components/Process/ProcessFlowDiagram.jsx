import React, { useCallback, useEffect } from 'react';
import ReactFlow, { 
  Background,
  Controls,
  MiniMap,
  useNodesState,
  useEdgesState,
  MarkerType,
} from 'reactflow';
import 'reactflow/dist/style.css';

const nodeTypes = {
  process: ({ data }) => (
    <div className="healthcare-card px-6 py-3 shadow-lg rounded-md bg-healthcare-surface dark:bg-healthcare-surface-dark border-2 border-healthcare-border dark:border-healthcare-border-dark hover:border-healthcare-primary dark:hover:border-healthcare-primary-dark transition-all duration-200" style={{ minWidth: '180px' }}>
      <div className="font-bold text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{data.label}</div>
      {data.metrics && (
        <div className="mt-2 text-xs">
          <div className="text-healthcare-info dark:text-healthcare-info-dark">Count: {data.metrics.count}</div>
          <div className="text-healthcare-success dark:text-healthcare-success-dark">Avg Time: {data.metrics.avgTime}</div>
        </div>
      )}
    </div>
  ),
  decision: ({ data }) => (
    <div className="healthcare-card px-6 py-3 shadow-lg rounded-md bg-healthcare-purple dark:bg-healthcare-purple-dark border-2 border-healthcare-border dark:border-healthcare-border-dark hover:border-healthcare-primary dark:hover:border-healthcare-primary-dark transition-all duration-200 rotate-45" style={{ minWidth: '180px', minHeight: '80px' }}>
      <div className="font-bold text-sm text-white -rotate-45">{data.label}</div>
    </div>
  ),
  result: ({ data }) => (
    <div className="healthcare-card px-6 py-3 shadow-lg rounded-md bg-healthcare-teal dark:bg-healthcare-teal-dark border-2 border-healthcare-border dark:border-healthcare-border-dark hover:border-healthcare-primary dark:hover:border-healthcare-primary-dark transition-all duration-200" style={{ minWidth: '180px' }}>
      <div className="font-bold text-sm text-white">{data.label}</div>
      {data.metrics && (
        <div className="mt-2 text-xs text-white/80">
          <div>Count: {data.metrics.count}</div>
          <div>Avg Time: {data.metrics.avgTime}</div>
        </div>
      )}
    </div>
  ),
};

const defaultEdgeOptions = {
  type: 'smoothstep',
  markerEnd: {
    type: MarkerType.ArrowClosed,
  },
  style: {
    strokeWidth: 2,
  },
  className: 'text-healthcare-primary dark:text-healthcare-primary-dark',
};

const getEdgeStyle = (data) => {
  if (data?.isMainFlow) {
    return {
      strokeWidth: 3,
      stroke: 'var(--healthcare-primary-dark)',
    };
  }
  if (data?.isResultFlow) {
    return {
      strokeWidth: 2,
      stroke: 'var(--healthcare-teal-dark)',
      strokeDasharray: '5,5',
    };
  }
  return {
    strokeWidth: 2,
    stroke: 'var(--healthcare-info-dark)',
  };
};

const ProcessFlowDiagram = ({ data, onNodeClick, onEdgeClick }) => {
  const [nodes, setNodes, onNodesChange] = useNodesState([]);
  const [edges, setEdges, onEdgesChange] = useEdgesState([]);

  useEffect(() => {
    if (!data?.nodes || !data?.edges) return;

    const mappedNodes = data.nodes.map(node => ({
      ...node,
      position: node.position || { x: 0, y: 0 },
      type: node.id.endsWith('_result') ? 'result' : 
            node.id.includes('decision') || node.id.includes('assortment') ? 'decision' : 'process',
      draggable: false,
    }));

    const mappedEdges = data.edges.map(edge => ({
      ...edge,
      type: 'smoothstep',
      markerEnd: {
        type: MarkerType.ArrowClosed,
      },
      label: edge.data ? `${edge.data.patientCount} patients\n${edge.data.avgTime}` : '',
      labelStyle: { fill: 'var(--healthcare-text-primary-dark)', fontWeight: 500 },
      style: getEdgeStyle(edge.data),
      animated: edge.data?.isMainFlow,
    }));
    
    setNodes(mappedNodes);
    setEdges(mappedEdges);
  }, [data]);

  const handleNodeClick = useCallback((event, node) => {
    onNodeClick?.(node);
  }, [onNodeClick]);

  const handleEdgeClick = useCallback((event, edge) => {
    onEdgeClick?.(edge);
  }, [onEdgeClick]);

  return (
    <div style={{ width: '100%', height: '80vh' }}>
      <ReactFlow
        nodes={nodes}
        edges={edges}
        onNodesChange={onNodesChange}
        onEdgesChange={onEdgesChange}
        onNodeClick={handleNodeClick}
        onEdgeClick={handleEdgeClick}
        nodeTypes={nodeTypes}
        defaultEdgeOptions={defaultEdgeOptions}
        edgeOptions={{
          style: { stroke: 'var(--healthcare-primary-dark)' },
        }}
        fitView
        fitViewOptions={{ padding: 0.2 }}
        className="bg-healthcare-background dark:bg-healthcare-background-dark"
        minZoom={0.4}
        maxZoom={2}
      >
        <Background />
        <Controls />
        <MiniMap />
      </ReactFlow>
    </div>
  );
};

export default ProcessFlowDiagram;
