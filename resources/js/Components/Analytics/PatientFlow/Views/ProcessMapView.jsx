import React, { useState, useCallback, useEffect } from 'react';
import Panel from '@/Components/ui/Panel';
import ReactFlow, {
  Background,
  Controls,
  MiniMap,
  useNodesState,
  useEdgesState,
} from 'reactflow';
import dagre from 'dagre';
import { Icon } from '@iconify/react';

// Custom node components
import ActivityNode from '../Nodes/ActivityNode';
import StartNode from '../Nodes/StartNode';
import EndNode from '../Nodes/EndNode';

// Import styles
import 'reactflow/dist/style.css';

const ProcessMapView = ({ data }) => {
  const [nodes, setNodes, onNodesChange] = useNodesState([]);
  const [edges, setEdges, onEdgesChange] = useEdgesState([]);
  const [layoutSettings, setLayoutSettings] = useState({
    nodeSpacing: 50,
    rankSpacing: 200,
    direction: 'LR', // LR = left to right, TB = top to bottom
    edgeCurvature: 0.5,
    minNodeWidth: 150,
    abstractionLevel: 100, // 0-100, higher means more abstraction (fewer nodes)
  });

  // Register custom node types
  const nodeTypes = {
    activity: ActivityNode,
    start: StartNode,
    end: EndNode,
  };

  // Process the data into nodes and edges
  useEffect(() => {
    if (!data || !data.processMap) return;

    // Transform the data into ReactFlow format
    const processMapData = data.processMap;
    
    // Filter nodes based on abstractionLevel
    const threshold = (100 - layoutSettings.abstractionLevel) / 100;
    const filteredNodes = processMapData.nodes.filter(node => 
      node.type === 'start' || 
      node.type === 'end' || 
      (node.frequency / processMapData.maxFrequency) >= threshold
    );
    
    // Get node IDs for filtered nodes
    const filteredNodeIds = new Set(filteredNodes.map(node => node.id));
    
    // Filter edges to only include connections between filtered nodes
    const filteredEdges = processMapData.edges.filter(edge => 
      filteredNodeIds.has(edge.source) && 
      filteredNodeIds.has(edge.target) &&
      (edge.frequency / processMapData.maxFrequency) >= threshold
    );

    // Transform nodes to ReactFlow format
    const rfNodes = filteredNodes.map(node => ({
      id: node.id,
      type: node.type || 'activity',
      data: {
        label: node.label,
        count: node.count,
        frequency: node.frequency,
        avgDuration: node.avgDuration,
        type: node.type,
      },
      position: { x: 0, y: 0 }, // Initial position, will be set by layout
    }));

    // Transform edges to ReactFlow format
    const rfEdges = filteredEdges.map(edge => ({
      id: edge.id,
      source: edge.source,
      target: edge.target,
      animated: false,
      label: `${edge.count}`,
      data: {
        count: edge.count,
        frequency: edge.frequency,
        avgDuration: edge.avgDuration,
      },
      style: {
        strokeWidth: Math.max(1, Math.log(edge.count) * 0.5),
      },
    }));

    // Apply layout
    const { nodes: layoutedNodes, edges: layoutedEdges } = getLayoutedElements(
      rfNodes,
      rfEdges,
      layoutSettings
    );

    setNodes(layoutedNodes);
    setEdges(layoutedEdges);
  }, [data, layoutSettings, setNodes, setEdges]);

  // Layout the graph using dagre
  const getLayoutedElements = (nodes, edges, settings) => {
    const dagreGraph = new dagre.graphlib.Graph();
    dagreGraph.setDefaultEdgeLabel(() => ({}));
    
    // Set graph direction and spacing
    dagreGraph.setGraph({
      rankdir: settings.direction,
      nodesep: settings.nodeSpacing,
      ranksep: settings.rankSpacing,
      edgesep: settings.nodeSpacing / 2,
    });

    // Add nodes to dagre graph
    nodes.forEach(node => {
      dagreGraph.setNode(node.id, {
        width: settings.minNodeWidth,
        height: 60,
      });
    });

    // Add edges to dagre graph
    edges.forEach(edge => {
      dagreGraph.setEdge(edge.source, edge.target);
    });

    // Calculate layout
    dagre.layout(dagreGraph);

    // Apply layout to nodes
    const layoutedNodes = nodes.map(node => {
      const nodeWithPosition = dagreGraph.node(node.id);
      return {
        ...node,
        position: {
          x: nodeWithPosition.x - settings.minNodeWidth / 2,
          y: nodeWithPosition.y - 30,
        },
      };
    });

    return { nodes: layoutedNodes, edges };
  };

  // Handle layout settings changes
  const handleLayoutChange = (setting, value) => {
    setLayoutSettings(prev => ({
      ...prev,
      [setting]: value,
    }));
  };

  // Handle direction change
  const handleDirectionChange = (direction) => {
    setLayoutSettings(prev => ({
      ...prev,
      direction,
    }));
  };

  return (
    <div className="space-y-6">
      <Panel className="h-[calc(100vh-300px)] min-h-[600px]">
        <div className="flex h-full">
          <div className="w-64 p-4 border-r border-gray-200 dark:border-gray-700 overflow-y-auto">
            <div className="space-y-6">
              <div>
                <h3 className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Layout Direction</h3>
                <div className="flex space-x-2">
                  <button
                    onClick={() => handleDirectionChange('LR')}
                    className={`p-2 rounded ${
                      layoutSettings.direction === 'LR'
                        ? 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300'
                        : 'bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300'
                    }`}
                    title="Left to Right"
                  >
                    <Icon icon="carbon:arrow-right" className="w-5 h-5" />
                  </button>
                  <button
                    onClick={() => handleDirectionChange('TB')}
                    className={`p-2 rounded ${
                      layoutSettings.direction === 'TB'
                        ? 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300'
                        : 'bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300'
                    }`}
                    title="Top to Bottom"
                  >
                    <Icon icon="carbon:arrow-down" className="w-5 h-5" />
                  </button>
                  <button
                    onClick={() => handleDirectionChange('RL')}
                    className={`p-2 rounded ${
                      layoutSettings.direction === 'RL'
                        ? 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300'
                        : 'bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300'
                    }`}
                    title="Right to Left"
                  >
                    <Icon icon="carbon:arrow-left" className="w-5 h-5" />
                  </button>
                  <button
                    onClick={() => handleDirectionChange('BT')}
                    className={`p-2 rounded ${
                      layoutSettings.direction === 'BT'
                        ? 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300'
                        : 'bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300'
                    }`}
                    title="Bottom to Top"
                  >
                    <Icon icon="carbon:arrow-up" className="w-5 h-5" />
                  </button>
                </div>
              </div>

              <div>
                <h3 className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Abstraction Level</h3>
                <div className="space-y-1">
                  <div className="flex justify-between text-xs text-gray-500 dark:text-gray-400">
                    <span>Detailed</span>
                    <span>Abstract</span>
                  </div>
                  <input
                    type="range"
                    min="0"
                    max="100"
                    value={layoutSettings.abstractionLevel}
                    onChange={(e) => handleLayoutChange('abstractionLevel', parseInt(e.target.value))}
                    className="w-full"
                  />
                </div>
              </div>

              <div>
                <h3 className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Node Spacing</h3>
                <input
                  type="range"
                  min="20"
                  max="100"
                  value={layoutSettings.nodeSpacing}
                  onChange={(e) => handleLayoutChange('nodeSpacing', parseInt(e.target.value))}
                  className="w-full"
                />
              </div>

              <div>
                <h3 className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Rank Spacing</h3>
                <input
                  type="range"
                  min="100"
                  max="300"
                  value={layoutSettings.rankSpacing}
                  onChange={(e) => handleLayoutChange('rankSpacing', parseInt(e.target.value))}
                  className="w-full"
                />
              </div>
            </div>
          </div>

          <div className="flex-1 h-full">
            <ReactFlow
              nodes={nodes}
              edges={edges}
              onNodesChange={onNodesChange}
              onEdgesChange={onEdgesChange}
              nodeTypes={nodeTypes}
              fitView
              attributionPosition="bottom-right"
              minZoom={0.2}
              maxZoom={2}
            >
              <Background color="#f8f8f8" gap={16} />
              <Controls />
              <MiniMap
                nodeColor={(node) => {
                  switch (node.type) {
                    case 'start':
                      return '#4682B4'; // Steel blue
                    case 'end':
                      return '#20B2AA'; // Light sea green
                    default:
                      return '#5F9EA0'; // Cadet blue
                  }
                }}
                maskColor="rgba(240, 240, 240, 0.6)"
              />
            </ReactFlow>
          </div>
        </div>
      </Panel>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
        <Panel title="Process Statistics" isSubpanel={true} dropLightIntensity="medium">
          <div className="grid grid-cols-2 gap-4">
            <div className="bg-blue-50 dark:bg-blue-900/30 p-4 rounded-lg">
              <h3 className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Cases</h3>
              <div className="text-2xl font-bold text-blue-600 dark:text-blue-400">{data?.stats?.cases?.count || 0}</div>
              <div className="text-sm text-gray-500 dark:text-gray-400">Total patient cases</div>
            </div>
            
            <div className="bg-green-50 dark:bg-green-900/30 p-4 rounded-lg">
              <h3 className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Completion Rate</h3>
              <div className="text-2xl font-bold text-green-600 dark:text-green-400">{data?.stats?.cases?.completionRate || 0}%</div>
              <div className="text-sm text-gray-500 dark:text-gray-400">Cases completed</div>
            </div>
            
            <div className="bg-purple-50 dark:bg-purple-900/30 p-4 rounded-lg">
              <h3 className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Avg. Process Time</h3>
              <div className="text-2xl font-bold text-purple-600 dark:text-purple-400">{data?.stats?.time?.avgProcessTime || '0 hrs'}</div>
              <div className="text-sm text-gray-500 dark:text-gray-400">Per patient case</div>
            </div>
            
            <div className="bg-yellow-50 dark:bg-yellow-900/30 p-4 rounded-lg">
              <h3 className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Avg. Wait Time</h3>
              <div className="text-2xl font-bold text-yellow-600 dark:text-yellow-400">{data?.stats?.time?.avgWaitTime || '0 hrs'}</div>
              <div className="text-sm text-gray-500 dark:text-gray-400">Between activities</div>
            </div>
          </div>
        </Panel>
        
        <Panel title="Top Bottlenecks" isSubpanel={true} dropLightIntensity="medium">
          <div className="space-y-4">
            {(data?.bottlenecks || []).slice(0, 3).map((bottleneck, index) => (
              <div key={index} className="flex items-start p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                <div className={`flex-shrink-0 p-2 rounded-full mr-3 ${
                  index === 0 
                    ? 'bg-red-100 dark:bg-red-900 text-red-600 dark:text-red-300' 
                    : index === 1 
                      ? 'bg-orange-100 dark:bg-orange-900 text-orange-600 dark:text-orange-300' 
                      : 'bg-yellow-100 dark:bg-yellow-900 text-yellow-600 dark:text-yellow-300'
                }`}>
                  <Icon icon="carbon:warning-alt" className="w-5 h-5" />
                </div>
                <div className="flex-1">
                  <h4 className="text-sm font-medium text-gray-900 dark:text-gray-100">{bottleneck.activity}</h4>
                  <div className="mt-1 flex items-center text-xs text-gray-500 dark:text-gray-400">
                    <span className="mr-2">Wait: {bottleneck.waitTime}</span>
                    <span>Impact: {bottleneck.impact}%</span>
                  </div>
                </div>
              </div>
            ))}
            {(!data?.bottlenecks || data.bottlenecks.length === 0) && (
              <div className="text-center py-6 text-gray-500 dark:text-gray-400">
                No bottleneck data available
              </div>
            )}
          </div>
        </Panel>
      </div>
    </div>
  );
};

export default ProcessMapView;
