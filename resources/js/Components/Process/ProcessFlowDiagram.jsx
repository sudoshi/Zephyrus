import React, { useCallback, useEffect } from 'react';
import ReactFlow, { 
  Background,
  Controls,
  MiniMap,
  useNodesState,
  useEdgesState,
  MarkerType,
  addEdge,
  Handle,
} from 'reactflow';
import dagre from 'dagre';
import axios from 'axios';
import { debounce } from 'lodash';
import 'reactflow/dist/style.css';

// Node type components and helper functions remain the same...
const nodeTypes = {
  process: ({ data }) => (
    <div className="healthcare-card p-4 shadow-lg rounded-md bg-healthcare-surface dark:bg-healthcare-surface-dark border-2 border-healthcare-border dark:border-healthcare-border-dark hover:border-healthcare-primary dark:hover:border-healthcare-primary-dark transition-all duration-200" style={{ width: '320px' }}>
      <Handle type="target" position="left" id="target" />
      <Handle type="source" position="right" id="source" />
      <Handle type="source" position="bottom" id="source-bottom" />
      <Handle type="target" position="top" id="target-top" />
      
      {/* Header with activity name */}
      <div className="font-bold text-base text-healthcare-text-primary dark:text-healthcare-text-primary-dark border-b border-healthcare-border dark:border-healthcare-border-dark pb-2 mb-3">
        {data.label}
      </div>
      
      {/* Metrics section */}
      {data.metrics && (
        <div className="grid grid-cols-2 gap-3">
          {/* Left column - frequency metrics */}
          <div className="space-y-2">
            <div className="flex justify-between items-center">
              <span className="text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Cases</span>
              <span className="text-sm font-bold text-healthcare-primary dark:text-healthcare-primary-dark">{data.metrics.caseCount || data.metrics.count}</span>
            </div>
            
            <div className="flex justify-between items-center">
              <span className="text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Events</span>
              <span className="text-sm font-bold text-healthcare-info dark:text-healthcare-info-dark">{data.metrics.eventCount || '-'}</span>
            </div>
            
            <div className="flex justify-between items-center">
              <span className="text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Frequency</span>
              <span className="text-sm font-bold text-healthcare-purple dark:text-healthcare-purple-dark">{data.metrics.frequency || '100%'}</span>
            </div>
          </div>
          
          {/* Right column - time metrics */}
          <div className="space-y-2">
            <div className="flex justify-between items-center">
              <span className="text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Avg Time</span>
              <span className="text-sm font-bold text-healthcare-success dark:text-healthcare-success-dark">{data.metrics.avgTime}</span>
            </div>
            
            <div className="flex justify-between items-center">
              <span className="text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Min Time</span>
              <span className="text-sm font-bold text-healthcare-success-light dark:text-healthcare-success-light-dark">{data.metrics.minTime || '-'}</span>
            </div>
            
            <div className="flex justify-between items-center">
              <span className="text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Max Time</span>
              <span className="text-sm font-bold text-healthcare-warning dark:text-healthcare-warning-dark">{data.metrics.maxTime || '-'}</span>
            </div>
          </div>
          
          {/* Bottom progress bar for performance indicator */}
          <div className="col-span-2 mt-1">
            <div className="flex justify-between items-center mb-1">
              <span className="text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Performance</span>
              <span className="text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{data.metrics.performance || '100%'}</span>
            </div>
            <div className="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2.5">
              <div 
                className="bg-healthcare-primary dark:bg-healthcare-primary-dark h-2.5 rounded-full" 
                style={{ width: data.metrics.performance || '100%' }}
              ></div>
            </div>
          </div>
        </div>
      )}
    </div>
  ),
  decision: ({ data }) => (
    <div className="healthcare-card p-4 shadow-lg rounded-md bg-healthcare-purple dark:bg-healthcare-purple-dark border-2 border-healthcare-border dark:border-healthcare-border-dark hover:border-healthcare-primary dark:hover:border-healthcare-primary-dark transition-all duration-200" style={{ width: '320px' }}>
      <Handle type="target" position="left" id="target" />
      <Handle type="source" position="right" id="source" />
      <Handle type="source" position="bottom" id="source-bottom" />
      <Handle type="target" position="top" id="target-top" />
      
      {/* Header with decision point name */}
      <div className="font-bold text-base text-white border-b border-white/20 pb-2 mb-3">{data.label}</div>
      
      {/* Metrics section */}
      {data.metrics && (
        <div className="grid grid-cols-2 gap-3">
          {/* Left column */}
          <div className="space-y-2">
            <div className="flex justify-between items-center">
              <span className="text-xs font-medium text-white/70">Cases</span>
              <span className="text-sm font-bold text-white">{data.metrics.caseCount || data.metrics.count}</span>
            </div>
            
            <div className="flex justify-between items-center">
              <span className="text-xs font-medium text-white/70">Paths</span>
              <span className="text-sm font-bold text-white">{data.metrics.pathCount || '-'}</span>
            </div>
          </div>
          
          {/* Right column */}
          <div className="space-y-2">
            <div className="flex justify-between items-center">
              <span className="text-xs font-medium text-white/70">Avg Time</span>
              <span className="text-sm font-bold text-white">{data.metrics.avgTime}</span>
            </div>
            
            <div className="flex justify-between items-center">
              <span className="text-xs font-medium text-white/70">Complexity</span>
              <span className="text-sm font-bold text-white">{data.metrics.complexity || 'Medium'}</span>
            </div>
          </div>
        </div>
      )}
    </div>
  ),
  result: ({ data }) => (
    <div className="healthcare-card p-4 shadow-lg rounded-md bg-healthcare-teal dark:bg-healthcare-teal-dark border-2 border-healthcare-border dark:border-healthcare-border-dark hover:border-healthcare-primary dark:hover:border-healthcare-primary-dark transition-all duration-200" style={{ width: '320px' }}>
      <Handle type="target" position="left" id="target" />
      <Handle type="source" position="right" id="source" />
      <Handle type="source" position="bottom" id="source-bottom" />
      <Handle type="target" position="top" id="target-top" />
      
      {/* Header with result name */}
      <div className="font-bold text-base text-white border-b border-white/20 pb-2 mb-3">{data.label}</div>
      
      {/* Metrics section */}
      {data.metrics && (
        <div className="grid grid-cols-2 gap-3">
          {/* Left column */}
          <div className="space-y-2">
            <div className="flex justify-between items-center">
              <span className="text-xs font-medium text-white/70">Cases</span>
              <span className="text-sm font-bold text-white">{data.metrics.caseCount || data.metrics.count}</span>
            </div>
            
            <div className="flex justify-between items-center">
              <span className="text-xs font-medium text-white/70">Frequency</span>
              <span className="text-sm font-bold text-white">{data.metrics.frequency || '100%'}</span>
            </div>
          </div>
          
          {/* Right column */}
          <div className="space-y-2">
            <div className="flex justify-between items-center">
              <span className="text-xs font-medium text-white/70">Avg Time</span>
              <span className="text-sm font-bold text-white">{data.metrics.avgTime}</span>
            </div>
            
            <div className="flex justify-between items-center">
              <span className="text-xs font-medium text-white/70">Outcome</span>
              <div className="flex items-center">
                <span className={`inline-block w-2 h-2 rounded-full mr-1 ${data.metrics.outcome === 'Positive' ? 'bg-healthcare-success' : data.metrics.outcome === 'Negative' ? 'bg-healthcare-danger' : 'bg-healthcare-warning'}`}></span>
                <span className="text-sm font-bold text-white">{data.metrics.outcome || 'Neutral'}</span>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  ),
};

const NODE_WIDTH = 320;
const NODE_HEIGHT = 180;

const defaultEdgeOptions = {
  type: 'default',
  animated: false,
  markerEnd: {
    type: MarkerType.ArrowClosed,
    width: 30,
    height: 30,
    color: 'var(--healthcare-primary)',
  },
  style: {
    strokeWidth: 2,
    stroke: 'var(--healthcare-primary)',
  },
};

const getEdgeStyle = (data) => {
  const baseStyle = {
    strokeWidth: 2,
    strokeLinecap: 'round',
    strokeLinejoin: 'round',
  };

  if (data?.isMainFlow) {
    return {
      ...baseStyle,
      strokeWidth: 4,
      stroke: 'var(--healthcare-primary)',
      animated: true,
    };
  }
  if (data?.isResultFlow) {
    return {
      ...baseStyle,
      strokeWidth: 3,
      stroke: 'var(--healthcare-teal)',
      strokeDasharray: '8,4',
      animated: false,
    };
  }
  return {
    ...baseStyle,
    strokeWidth: 3,
    stroke: 'var(--healthcare-info)',
    animated: false,
  };
};

const getLayoutedElements = (nodes, edges, direction = 'LR') => {
  const dagreGraph = new dagre.graphlib.Graph();
  dagreGraph.setDefaultEdgeLabel(() => ({}));

  const isHorizontal = direction === 'LR';
  dagreGraph.setGraph({
    rankdir: direction,
    nodesep: 250,
    ranksep: 350,
    marginx: 200,
    marginy: 200,
    align: 'DL',
    acyclicer: 'greedy',
    ranker: 'tight-tree',
  });

  nodes.forEach((node) => {
    dagreGraph.setNode(node.id, { width: NODE_WIDTH, height: NODE_HEIGHT });
  });

  edges.forEach((edge) => {
    dagreGraph.setEdge(edge.source, edge.target);
  });

  dagre.layout(dagreGraph);

  nodes.forEach((node) => {
    const nodeWithPosition = dagreGraph.node(node.id);
    node.targetPosition = isHorizontal ? 'left' : 'top';
    node.sourcePosition = isHorizontal ? 'right' : 'bottom';
    node.position = {
      x: nodeWithPosition.x - NODE_WIDTH / 2,
      y: nodeWithPosition.y - NODE_HEIGHT / 2,
    };
  });

  return { nodes, edges };
};

const ProcessFlowDiagram = React.forwardRef(({ data, savedLayout, onNodeClick, onEdgeClick }, ref) => {
  const [nodes, setNodes, onNodesChange] = useNodesState([]);
  const [edges, setEdges, onEdgesChange] = useEdgesState([]);

  useEffect(() => {
    const token = document.head.querySelector('meta[name="csrf-token"]');
    if (token) {
      axios.defaults.headers.common['X-CSRF-TOKEN'] = token.content;
    }
  }, []);

  const saveLayout = useCallback(
    debounce(async (nodes) => {
      try {
        const urlParams = new URLSearchParams(window.location.search);
        await axios.post('/improvement/process/layout', {
          process_type: 'admission',
          layout_data: nodes.reduce((acc, node) => ({
            ...acc,
            [node.id]: node.position,
          }), {}),
          hospital: urlParams.get('hospital') || 'Virtua Marlton Hospital',
          workflow: urlParams.get('workflow') || 'Admissions',
          time_range: urlParams.get('timeRange') || '24 Hours',
        });
      } catch (error) {
        console.error('Error saving layout:', error);
      }
    }, 1000),
    []
  );

  useEffect(() => {
    if (!data?.nodes || !data?.edges) return;

    const mappedNodes = data.nodes.map(node => ({
      ...node,
      type: node.id.endsWith('_result') || node.id.includes('results') ? 'result' : 
            node.id.includes('decision') || node.id.includes('triage') ? 'decision' : 'process',
      draggable: true,
      data: {
        ...node.data,
        label: node.data.label.replace(/_/g, ' '),
      },
      ...(savedLayout && savedLayout[node.id] ? {
        position: savedLayout[node.id]
      } : {}),
    }));

    const mappedEdges = data.edges.map(edge => ({
      ...edge,
      type: edge.source.includes('results') ? 'step' : 'default',
      markerEnd: {
        type: MarkerType.ArrowClosed,
      },
      sourceHandle: edge.source.includes('results') ? 'source-bottom' : 'source',
      targetHandle: edge.target.includes('results') ? 'target-top' : 'target',
      label: edge.data ? `${edge.data.patientCount} patients\n${edge.data.avgTime}` : '',
      labelStyle: { 
        fill: 'white', 
        fontWeight: 600, 
        fontSize: '15px',
        fontFamily: 'var(--font-sans)',
        textAlign: 'center',
        letterSpacing: '0.02em',
        lineHeight: '1.5',
        backgroundColor: '#ea580c',
        padding: '10px 16px',
        borderRadius: '8px',
        boxShadow: '0 2px 4px 0 rgba(0, 0, 0, 0.1)',
      },
      labelBgStyle: { 
        fill: '#ea580c',
        opacity: 0.95,
        rx: 8,
        ry: 8,
      },
      labelBgPadding: [0, 0],
      labelShowBg: false,
      style: getEdgeStyle(edge.data),
    }));

    if (!savedLayout) {
      const { nodes: layoutedNodes, edges: layoutedEdges } = getLayoutedElements(mappedNodes, mappedEdges);
      setNodes(layoutedNodes);
      setEdges(layoutedEdges);
    } else {
      setNodes(mappedNodes);
      setEdges(mappedEdges);
    }
  }, [data, savedLayout]);

  const onConnect = useCallback(
    (params) => setEdges((els) => addEdge({ 
      ...params, 
      type: 'default',
      style: getEdgeStyle({ isMainFlow: true }),
    }, els)),
    []
  );

  const handleNodeClick = useCallback((event, node) => {
    onNodeClick?.(node);
  }, [onNodeClick]);

  const handleEdgeClick = useCallback((event, edge) => {
    onEdgeClick?.(edge);
  }, [onEdgeClick]);

  const onNodeDragStart = useCallback((event, node) => {
    document.body.style.cursor = 'grabbing';
  }, []);

  const onNodeDrag = useCallback((event, node) => {
    setEdges((eds) => 
      eds.map((e) => ({
        ...e,
        style: {
          ...e.style,
          opacity: e.source === node.id || e.target === node.id ? 0.5 : 1,
        },
      }))
    );
  }, [setEdges]);

  const onNodeDragStop = useCallback((event, node) => {
    document.body.style.cursor = '';
    setEdges((eds) => 
      eds.map((e) => ({
        ...e,
        style: getEdgeStyle(e.data),
      }))
    );
    
    setNodes((nds) => {
      const updatedNodes = nds.map((n) => {
        if (n.id === node.id) {
          return {
            ...n,
            position: node.position,
          };
        }
        return n;
      });
      
      // Always save layout
      saveLayout(updatedNodes);
      
      return updatedNodes;
    });
  }, [setNodes, saveLayout]);

  React.useImperativeHandle(ref, () => ({
    resetLayout: () => {
      const { nodes: layoutedNodes, edges: layoutedEdges } = getLayoutedElements(nodes, edges);
      setNodes([...layoutedNodes]);
      setEdges([...layoutedEdges]);
    }
  }));

  return (
    <div style={{ width: '100%', height: '90vh' }}>
      <ReactFlow
        onNodeDragStart={onNodeDragStart}
        onNodeDrag={onNodeDrag}
        onNodeDragStop={onNodeDragStop}
        snapToGrid={true}
        snapGrid={[20, 20]}
        nodes={nodes}
        edges={edges}
        onNodesChange={onNodesChange}
        onEdgesChange={onEdgesChange}
        onConnect={onConnect}
        onNodeClick={handleNodeClick}
        onEdgeClick={handleEdgeClick}
        nodeTypes={nodeTypes}
        defaultEdgeOptions={defaultEdgeOptions}
        fitView
        fitViewOptions={{ 
          padding: 0.3,
          includeHiddenNodes: true,
          duration: 800,
          zoom: 1.3,
        }}
        className="bg-healthcare-background dark:bg-healthcare-background-dark"
        minZoom={0.15}
        maxZoom={1.2}
        proOptions={{
          hideAttribution: true,
        }}
        style={{
          cursor: 'default',
          '--xy-theme-selected': 'var(--healthcare-primary)',
          '--xy-theme-hover': 'var(--healthcare-border)',
          '--xy-theme-edge-hover': 'var(--healthcare-primary-dark)',
          backgroundColor: 'var(--healthcare-background)',
        }}
      >
        <Background
          gap={35}
          size={2}
          color="#1a365d"
          style={{ opacity: 0.012 }}
        />
        <Controls />
        <MiniMap style={{ backgroundColor: 'var(--healthcare-surface)' }} />
      </ReactFlow>
    </div>
  );
});

export default ProcessFlowDiagram;
