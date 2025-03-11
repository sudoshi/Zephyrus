import React, { useCallback, useEffect, useState } from 'react';
import ReactFlow, {
  MiniMap,
  Controls,
  Background,
  useNodesState,
  useEdgesState,
  MarkerType,
} from 'reactflow';
import 'reactflow/dist/style.css';
import ProcessNode from './ProcessNode';
import { useTheme } from '@/hooks/useTheme.js';;
import StatusTooltip from '../ResourceAnalysis/StatusTooltip';

const nodeTypes = {
  process: ProcessNode,
};

const NetworkGraph = ({ 
  data, 
  onNodeClick, 
  onNodeHover,
  width = 800,
  height = 600,
  className = ''
}) => {
  const { isDarkMode } = useTheme();
  const [nodes, setNodes, onNodesChange] = useNodesState([]);
  const [edges, setEdges, onEdgesChange] = useEdgesState([]);
  const [selectedNode, setSelectedNode] = useState(null);

  // Transform data into reactflow format
  useEffect(() => {
    const transformedNodes = data.nodes.map((node) => ({
      id: node.id,
      type: 'process',
      position: { x: 0, y: 0 }, // Initial position, will be arranged by layout
      data: {
        ...node,
        label: node.label,
        type: node.type,
        severity: node.severity,
      },
    }));

    const transformedEdges = data.edges.map((edge, index) => ({
      id: `e${index}`,
      source: edge.source,
      target: edge.target,
      type: 'smoothstep',
      animated: true,
      style: { stroke: getEdgeColor(edge.value) },
      markerEnd: {
        type: MarkerType.ArrowClosed,
        color: getEdgeColor(edge.value),
      },
    }));

    // Arrange nodes in a circular layout
    const radius = Math.min(width, height) * 0.35;
    const centerX = width / 2;
    const centerY = height / 2;
    const angleStep = (2 * Math.PI) / (transformedNodes.length - 1);

    // Position primary node in center
    const primaryNode = transformedNodes.find(n => n.data.type === 'primary');
    if (primaryNode) {
      primaryNode.position = { x: centerX, y: centerY };
    }

    // Position other nodes in a circle around primary
    const otherNodes = transformedNodes.filter(n => n.data.type !== 'primary');
    otherNodes.forEach((node, index) => {
      const angle = angleStep * index;
      node.position = {
        x: centerX + radius * Math.cos(angle),
        y: centerY + radius * Math.sin(angle),
      };
    });

    setNodes(transformedNodes);
    setEdges(transformedEdges);
  }, [data, width, height]);

  const getEdgeColor = (value) => {
    if (value > 0.8) return 'var(--healthcare-critical)';
    if (value > 0.6) return 'var(--healthcare-warning)';
    if (value > 0.4) return 'var(--healthcare-info)';
    return 'var(--healthcare-success)';
  };

  const handleNodeClick = useCallback((_, node) => {
    setSelectedNode(node);
    onNodeClick?.(node);
  }, [onNodeClick]);

  const handleNodeMouseEnter = useCallback((_, node) => {
    onNodeHover?.(node);
  }, [onNodeHover]);

  const handleNodeMouseLeave = useCallback(() => {
    onNodeHover?.(null);
  }, [onNodeHover]);

  return (
    <div style={{ width, height }} className={className}>
      <ReactFlow
        nodes={nodes}
        edges={edges}
        onNodesChange={onNodesChange}
        onEdgesChange={onEdgesChange}
        onNodeClick={handleNodeClick}
        onNodeMouseEnter={handleNodeMouseEnter}
        onNodeMouseLeave={handleNodeMouseLeave}
        nodeTypes={nodeTypes}
        fitView
        attributionPosition="bottom-right"
        minZoom={0.5}
        maxZoom={2}
        defaultViewport={{ zoom: 1 }}
      >
        <Background
          color={isDarkMode ? '#2D3748' : '#E2E8F0'}
          gap={16}
          size={1}
        />
        <Controls
          className="healthcare-card"
          showInteractive={false}
        />
        <MiniMap
          className="healthcare-card !right-14"
          nodeColor={(node) => {
            if (node.data.type === 'primary') return 'var(--healthcare-primary)';
            const severity = node.data.severity || 0;
            if (severity > 0.8) return 'var(--healthcare-critical)';
            if (severity > 0.6) return 'var(--healthcare-warning)';
            return 'var(--healthcare-info)';
          }}
          maskColor={isDarkMode ? 'rgba(0, 0, 0, 0.2)' : 'rgba(255, 255, 255, 0.2)'}
          style={{
            backgroundColor: isDarkMode ? 'var(--healthcare-background-dark)' : 'var(--healthcare-background)',
          }}
        />
      </ReactFlow>

      {/* Node Details Tooltip */}
      {selectedNode && (
        <StatusTooltip
          content={
            <div className="space-y-2">
              <div className="font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                {selectedNode.data.label}
              </div>
              {selectedNode.data.type !== 'primary' && (
                <>
                  <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    Impact Severity: {Math.round((selectedNode.data.severity || 0) * 100)}%
                  </div>
                  {selectedNode.data.metrics && (
                    <div className="pt-2 border-t border-healthcare-border dark:border-healthcare-border-dark">
                      {Object.entries(selectedNode.data.metrics).map(([key, value]) => (
                        <div key={key} className="flex justify-between text-sm">
                          <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                            {key}:
                          </span>
                          <span className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                            {value}
                          </span>
                        </div>
                      ))}
                    </div>
                  )}
                </>
              )}
            </div>
          }
          position="right"
        >
          <div className="absolute top-4 right-4 z-10">
            <div className="healthcare-card p-4">
              <div className="w-1 h-1" />
            </div>
          </div>
        </StatusTooltip>
      )}
    </div>
  );
};

export default NetworkGraph;
