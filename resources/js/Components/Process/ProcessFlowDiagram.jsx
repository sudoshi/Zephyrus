import React, { useCallback, useEffect, useState, useRef } from 'react';
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
  // Regular node types
  input: ({ data }) => (
    <div className="healthcare-card p-4 shadow-lg rounded-md bg-healthcare-surface dark:bg-healthcare-surface-dark border-2 border-healthcare-border dark:border-healthcare-border-dark hover:border-healthcare-primary dark:hover:border-healthcare-primary-dark transition-all duration-200" style={{ width: '400px' }}>
      <Handle type="target" position="left" id="target" />
      <Handle type="source" position="right" id="source" />
      <Handle type="source" position="bottom" id="source-bottom" />
      <Handle type="target" position="top" id="target-top" />
      
      {/* Header with input source name */}
      <div className="font-bold text-base text-healthcare-text-primary dark:text-healthcare-text-primary-dark border-b border-healthcare-border dark:border-healthcare-border-dark pb-2 mb-3">{data.label}</div>
      
      {/* Metrics section */}
      {data.metrics && (
        <div className="space-y-2">
          {/* Basic metrics */}
          <div className="flex justify-between items-center">
            <span className="text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Count</span>
            <span className="text-sm font-bold text-healthcare-primary dark:text-healthcare-primary-dark">{data.metrics.count || '-'}</span>
          </div>
          
          {data.metrics.frequency && (
            <div className="flex justify-between items-center">
              <span className="text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Frequency</span>
              <span className="text-sm font-bold text-healthcare-purple dark:text-healthcare-purple-dark">{data.metrics.frequency}</span>
            </div>
          )}
        </div>
      )}
    </div>
  ),
  
  // Specialized node components for Bed Placement workflow
  bed_placement_input: ({ data }) => {
    console.log('Rendering bed_placement_input with data:', data);
    return (
      <div className="healthcare-card p-4 shadow-lg rounded-md bg-healthcare-surface dark:bg-healthcare-surface-dark border-2 border-healthcare-border dark:border-healthcare-border-dark hover:border-healthcare-primary dark:hover:border-healthcare-primary-dark transition-all duration-200" style={{ width: '400px' }}>
        <Handle type="target" position="left" id="target" />
        <Handle type="source" position="right" id="source" />
        <Handle type="source" position="bottom" id="source-bottom" />
        <Handle type="target" position="top" id="target-top" />
        
        {/* Header with input source name */}
        <div className="flex items-center mb-3">
          <div className="w-8 h-8 rounded-full bg-healthcare-primary dark:bg-healthcare-primary-dark flex items-center justify-center mr-2">
            <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 9l3 3m0 0l-3 3m3-3H8m13 0a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </div>
          <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{data.label}</h3>
        </div>
        
        {/* Description */}
        {data.description && (
          <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-3">{data.description}</p>
        )}
        
        {/* Metrics section */}
        <div className="bg-gray-900 p-3 rounded-lg">
          {/* Basic metrics */}
          <div className="flex justify-between items-center">
            <span className="text-xs font-medium text-white opacity-70">Total:</span>
            <span className="text-sm font-bold text-white">{data.count || '-'} patients</span>
          </div>
          
          {/* Average Duration */}
          <div className="flex justify-between items-center mt-1">
            <span className="text-xs font-medium text-white opacity-70">Avg. Duration:</span>
            <span className="text-sm font-bold text-white">
              {data.avgDuration !== undefined ? `${data.avgDuration} min` : '-'}
            </span>
          </div>
          
          {/* Time-based metrics - only show if available */}
          {((data.metrics && data.metrics.last24h) || data.last24h) && (
            <div className="flex justify-between items-center mt-1">
              <span className="text-xs font-medium text-white opacity-70">Last 24h:</span>
              <span className="text-sm font-bold text-white">
                {(data.metrics && data.metrics.last24h) || data.last24h || '-'} patients
              </span>
            </div>
          )}
          
          {((data.metrics && data.metrics.last7d) || data.last7d) && (
            <div className="flex justify-between items-center mt-1">
              <span className="text-xs font-medium text-white opacity-70">Last 7d:</span>
              <span className="text-sm font-bold text-white">
                {(data.metrics && data.metrics.last7d) || data.last7d || '-'} patients
              </span>
            </div>
          )}
        </div>
      </div>
    );
  },
  
  bed_placement_process: ({ data }) => {
    console.log('Rendering bed_placement_process with data:', data);
    return (
      <div className="healthcare-card p-4 shadow-lg rounded-md bg-healthcare-surface dark:bg-healthcare-surface-dark border-2 border-healthcare-border dark:border-healthcare-border-dark hover:border-healthcare-primary dark:hover:border-healthcare-primary-dark transition-all duration-200" style={{ width: '400px' }}>
        <Handle type="target" position="left" id="target" />
        <Handle type="source" position="right" id="source" />
        <Handle type="source" position="bottom" id="source-bottom" />
        <Handle type="target" position="top" id="target-top" />
        
        {/* Header with process name */}
        <div className="flex items-center mb-3">
          <div className="w-8 h-8 rounded-full bg-healthcare-success dark:bg-healthcare-success-dark flex items-center justify-center mr-2">
            <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
            </svg>
          </div>
          <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{data.label}</h3>
        </div>
        
        {/* Description */}
        {data.description && (
          <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-3">{data.description}</p>
        )}
        
        {/* Metrics section */}
        <div className="bg-gray-900 p-3 rounded-lg">
          {/* Basic metrics */}
          <div className="flex justify-between items-center">
            <span className="text-xs font-medium text-white opacity-70">Cases:</span>
            <span className="text-sm font-bold text-white">{data.count || '-'}</span>
          </div>
          
          {/* Avg Time row */}
          <div className="flex justify-between items-center mt-1">
            <span className="text-xs font-medium text-white opacity-70">Avg. Time:</span>
            <span className="text-sm font-bold text-white">
              {data.avgDuration ? `${data.avgDuration} min` : ((data.metrics && data.metrics.avgTime) || data.avgTime || '10-30 min')}
            </span>
          </div>
          
          {/* Bottlenecks row */}
          <div className="flex justify-between items-center mt-1">
            <span className="text-xs font-medium text-white opacity-70">Bottlenecks:</span>
            <span className="text-sm font-bold text-white">
              {(data.metrics && data.metrics.bottlenecks) ? 
                (typeof data.metrics.bottlenecks === 'number' ? 
                  (data.metrics.bottlenecks * 100).toFixed(0) + '%' : 
                  data.metrics.bottlenecks) : 
                (data.bottlenecks || 'None')}
            </span>
          </div>
        </div>
      </div>
    );
  },
  
  bed_placement_result: ({ data }) => {
    console.log('Rendering bed_placement_result with data:', data);
    return (
      <div className="healthcare-card p-4 shadow-lg rounded-md bg-healthcare-surface dark:bg-healthcare-surface-dark border-2 border-healthcare-border dark:border-healthcare-border-dark hover:border-healthcare-primary dark:hover:border-healthcare-primary-dark transition-all duration-200" style={{ width: '400px' }}>
        <Handle type="target" position="left" id="target" />
        <Handle type="source" position="right" id="source" />
        <Handle type="source" position="bottom" id="source-bottom" />
        <Handle type="target" position="top" id="target-top" />
        
        {/* Header with result name - Patient Bedding */}
        <div className="flex items-center mb-3">
          <div className="w-8 h-8 rounded-full bg-healthcare-accent dark:bg-healthcare-accent-dark flex items-center justify-center mr-2">
            <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
            </svg>
          </div>
          <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{data.label}</h3>
        </div>
        
        {/* Description */}
        {data.description && (
          <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-3">{data.description}</p>
        )}
        
        {/* Metrics section */}
        <div className="bg-gray-900 p-3 rounded-lg">
          {/* Basic metrics */}
          <div className="flex justify-between items-center">
            <span className="text-xs font-medium text-white opacity-70">Total Cases:</span>
            <span className="text-sm font-bold text-white">{data.count || '-'}</span>
          </div>
          
          {/* Average Duration */}
          <div className="flex justify-between items-center mt-1">
            <span className="text-xs font-medium text-white opacity-70">Avg. Duration:</span>
            <span className="text-sm font-bold text-white">
              {data.avgDuration !== undefined ? `${data.avgDuration} min` : '-'}
            </span>
          </div>
          
          {/* Success Rate row - only show if available */}
          {((data.metrics && data.metrics.successRate) || data.successRate) && (
            <div className="flex justify-between items-center mt-1">
              <span className="text-xs font-medium text-white opacity-70">Success Rate:</span>
              <span className="text-sm font-bold text-white">
                {(data.metrics && data.metrics.successRate) ? 
                  (typeof data.metrics.successRate === 'number' ? 
                    (data.metrics.successRate * 100).toFixed(0) + '%' : 
                    data.metrics.successRate) : 
                  (data.successRate || '94%')}
              </span>
            </div>
          )}
          
          {/* Avg Total Time row - only show if available */}
          {((data.metrics && data.metrics.avgTotalTime) || data.avgTotalTime || (data.metrics && data.metrics.avgTime) || data.avgTime) && (
            <div className="flex justify-between items-center mt-1">
              <span className="text-xs font-medium text-white opacity-70">Avg. Total Time:</span>
              <span className="text-sm font-bold text-white">
                {(data.metrics && data.metrics.avgTotalTime) || data.avgTotalTime || 
                (data.metrics && data.metrics.avgTime) || data.avgTime}
              </span>
            </div>
          )}
        </div>
      </div>
    );
  },
  process: ({ data }) => (
    <div className="healthcare-card p-4 shadow-lg rounded-md bg-healthcare-surface dark:bg-healthcare-surface-dark border-2 border-healthcare-border dark:border-healthcare-border-dark hover:border-healthcare-primary dark:hover:border-healthcare-primary-dark transition-all duration-200" style={{ width: '400px' }}>
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
    <div className="healthcare-card p-4 shadow-lg rounded-md bg-healthcare-purple dark:bg-healthcare-purple-dark border-2 border-healthcare-border dark:border-healthcare-border-dark hover:border-healthcare-primary dark:hover:border-healthcare-primary-dark transition-all duration-200" style={{ width: '400px' }}>
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
            
            {/* Show last 24h metrics if available */}
            {data.metrics.last24h && (
              <div className="flex justify-between items-center">
                <span className="text-xs font-medium text-white/70">Last 24h</span>
                <span className="text-sm font-bold text-white">{data.metrics.last24h}</span>
              </div>
            )}
            
            {/* Show last 7d metrics if available */}
            {data.metrics.last7d && (
              <div className="flex justify-between items-center">
                <span className="text-xs font-medium text-white/70">Last 7d</span>
                <span className="text-sm font-bold text-white">{data.metrics.last7d}</span>
              </div>
            )}
            
            {/* Show standard paths if no custom metrics */}
            {!data.metrics.last24h && !data.metrics.last7d && (
              <div className="flex justify-between items-center">
                <span className="text-xs font-medium text-white/70">Paths</span>
                <span className="text-sm font-bold text-white">{data.metrics.pathCount || '-'}</span>
              </div>
            )}
          </div>
          
          {/* Right column */}
          <div className="space-y-2">
            {/* Show avg time if available */}
            <div className="flex justify-between items-center">
              <span className="text-xs font-medium text-white/70">Avg Time</span>
              <span className="text-sm font-bold text-white">{data.metrics.avgTime || (data.metrics.avgDuration ? data.metrics.avgDuration + 'm' : '-')}</span>
            </div>
            
            {/* Show bottlenecks if available */}
            {data.metrics.bottlenecks ? (
              <div className="flex justify-between items-center">
                <span className="text-xs font-medium text-white/70">Bottlenecks</span>
                <span className="text-sm font-bold text-white">{data.metrics.bottlenecks}</span>
              </div>
            ) : (
              <div className="flex justify-between items-center">
                <span className="text-xs font-medium text-white/70">Complexity</span>
                <span className="text-sm font-bold text-white">{data.metrics.complexity || 'Medium'}</span>
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  ),
  result: ({ data }) => (
    <div className="healthcare-card p-4 shadow-lg rounded-md bg-healthcare-teal dark:bg-healthcare-teal-dark border-2 border-healthcare-border dark:border-healthcare-border-dark hover:border-healthcare-primary dark:hover:border-healthcare-primary-dark transition-all duration-200" style={{ width: '400px' }}>
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
            
            {/* Show success rate if available */}
            {data.metrics.successRate ? (
              <div className="flex justify-between items-center">
                <span className="text-xs font-medium text-white/70">Success Rate</span>
                <span className="text-sm font-bold text-white">{data.metrics.successRate}</span>
              </div>
            ) : (
              <div className="flex justify-between items-center">
                <span className="text-xs font-medium text-white/70">Frequency</span>
                <span className="text-sm font-bold text-white">{data.metrics.frequency || '100%'}</span>
              </div>
            )}
          </div>
          
          {/* Right column */}
          <div className="space-y-2">
            {/* Show avg total time if available */}
            <div className="flex justify-between items-center">
              <span className="text-xs font-medium text-white/70">Avg Time</span>
              <span className="text-sm font-bold text-white">{data.metrics.avgTotalTime || data.metrics.avgTime || (data.metrics.avgDuration ? data.metrics.avgDuration + 'm' : '-')}</span>
            </div>
            
            {/* Show outcome or last30d if available */}
            {data.metrics.last30d ? (
              <div className="flex justify-between items-center">
                <span className="text-xs font-medium text-white/70">Last 30d</span>
                <span className="text-sm font-bold text-white">{data.metrics.last30d}</span>
              </div>
            ) : (
              <div className="flex justify-between items-center">
                <span className="text-xs font-medium text-white/70">Outcome</span>
                <div className="flex items-center">
                  <span className={`inline-block w-2 h-2 rounded-full mr-1 ${data.metrics.outcome === 'Positive' ? 'bg-healthcare-success' : data.metrics.outcome === 'Negative' ? 'bg-healthcare-danger' : 'bg-healthcare-warning'}`}></span>
                  <span className="text-sm font-bold text-white">{data.metrics.outcome || 'Neutral'}</span>
                </div>
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  ),
};

const NODE_WIDTH = 400;
const NODE_HEIGHT = 220;

const defaultEdgeOptions = {
  type: 'default',
  animated: true, // Make edges animated by default for better visibility
  markerEnd: {
    type: MarkerType.ArrowClosed,
    width: 30,
    height: 30,
    color: '#ea580c', // Use orange color for better visibility
  },
  style: {
    strokeWidth: 3, // Slightly thicker lines
    stroke: '#ea580c', // Match the arrow color
    strokeDasharray: '5,5', // Add dashed pattern for visual interest
  },
};

const getEdgeStyle = (data) => {
  const baseStyle = {
    strokeWidth: 3,
    strokeLinecap: 'round',
    strokeLinejoin: 'round',
  };

  // Get the count from data if available
  const count = data?.count || 0;
  
  // Calculate stroke width based on patient count - thicker for higher traffic
  const flowVolume = count > 0 ? Math.min(Math.max(Math.log10(count) * 1.2, 3), 6) : 4;

  if (data?.isMainFlow) {
    return {
      ...baseStyle,
      strokeWidth: flowVolume + 1,
      stroke: '#ea580c', // Orange for main flows
      animated: true,
    };
  }
  if (data?.isResultFlow) {
    return {
      ...baseStyle,
      strokeWidth: flowVolume,
      stroke: '#0e7490', // Teal for result flows
      strokeDasharray: '8,4',
      animated: true, // Animate result flows too
    };
  }
  
  // Enhanced default edge style for better visibility
  return {
    ...baseStyle,
    strokeWidth: flowVolume,
    stroke: '#0284c7', // Blue for standard flows
    animated: count > 100, // Animate high volume flows
  };
};

const getLayoutedElements = (nodes, edges, direction = 'vertical') => {
  if (!nodes || nodes.length === 0) return { nodes, edges };
  
  const dagreGraph = new dagre.graphlib.Graph();
  dagreGraph.setDefaultEdgeLabel(() => ({}));
  
  // Set graph direction and spacing based on direction parameter
  const isHorizontal = direction === 'horizontal';
  dagreGraph.setGraph({
    rankdir: isHorizontal ? 'LR' : 'TB', // LR for horizontal, TB for vertical
    nodesep: isHorizontal ? 200 : 150,  // More space between nodes horizontally
    ranksep: isHorizontal ? 150 : 200,  // Less space between ranks horizontally
    edgesep: 80,
    marginx: 50,    // Margin on x-axis
    marginy: 50,    // Margin on y-axis
    align: 'UL',    // Alignment for better visualization
    acyclicer: 'greedy',
    ranker: 'network-simplex',  // Better for hierarchical layouts
  });
  
  // Add nodes to the graph with their dimensions
  nodes.forEach((node) => {
    dagreGraph.setNode(node.id, { 
      width: NODE_WIDTH, 
      height: NODE_HEIGHT 
    });
  });
  
  // Add edges to the graph
  edges.forEach((edge) => {
    dagreGraph.setEdge(edge.source, edge.target);
  });
  
  // Calculate the layout
  dagre.layout(dagreGraph);
  
  // Create new array of nodes with updated positions
  const layoutedNodes = nodes.map((node) => {
    const nodeWithPosition = dagreGraph.node(node.id);
    return {
      ...node,
      // Set connection points based on direction
      targetPosition: isHorizontal ? 'left' : 'top',
      sourcePosition: isHorizontal ? 'right' : 'bottom',
      // Set the position from the layout
      position: {
        x: nodeWithPosition.x - NODE_WIDTH / 2,
        y: nodeWithPosition.y - NODE_HEIGHT / 2,
      },
    };
  });
  
  // Update edge handles based on direction
  const layoutedEdges = edges.map((edge) => ({
    ...edge,
    type: 'default',  // Use default with curvature for curved edges
    sourceHandle: isHorizontal ? 'source' : 'source-bottom',
    targetHandle: isHorizontal ? 'target' : 'target-top',
    style: { ...edge.style, stroke: '#fff', strokeWidth: 2 },
    animated: true,
    markerEnd: { type: 'arrowclosed' },
    curvature: 0.3,  // Add curvature for curved edges
  }));
  
  return { nodes: layoutedNodes, edges: layoutedEdges };
};

const ProcessFlowDiagram = React.forwardRef(({ data, savedLayout, onNodeClick, onEdgeClick, workflowName, processType, flowDirection = 'vertical' }, ref) => {
  const [nodes, setNodes, onNodesChange] = useNodesState([]);
  const [edges, setEdges, onEdgesChange] = useEdgesState([]);

  // State to store the fetched layout
  const [fetchedLayout, setFetchedLayout] = useState(null);
  
  // State to track viewport (zoom level, pan position)
  const [viewport, setViewport] = useState(null);
  
  // Ref to track the last saved viewport to prevent unnecessary saves
  const lastSavedViewport = useRef(null);
  
  // Function to fetch the saved layout from the server
  const fetchSavedLayout = useCallback(async () => {
    try {
      const urlParams = new URLSearchParams(window.location.search);
      const hospital = urlParams.get('hospital') || 'Virtua Marlton Hospital';
      const workflow = workflowName || urlParams.get('workflow') || 'Admissions';
      const timeRange = urlParams.get('timeRange') || '24 Hours';
      
      console.log('Fetching saved layout for:', { hospital, workflow, timeRange });
      
      const response = await axios.get('/improvement/process/layout', {
        params: {
          hospital,
          workflow,
          time_range: timeRange
        }
      });
      
      console.log('Fetched layout response:', response.data);
      
      if (response.data.layout) {
        // Store the fetched layout
        setFetchedLayout(response.data.layout);
        
        // If the layout contains viewport data, update the viewport state
        if (response.data.layout.viewport) {
          console.log('Found viewport data in fetched layout:', response.data.layout.viewport);
          setViewport(response.data.layout.viewport);
        }
        
        return response.data.layout;
      }
      
      return null;
    } catch (error) {
      console.error('Error fetching saved layout:', error);
      return null;
    }
  }, [workflowName]);
  
  useEffect(() => {
    const token = document.head.querySelector('meta[name="csrf-token"]');
    if (token) {
      axios.defaults.headers.common['X-CSRF-TOKEN'] = token.content;
    }
    
    // If no savedLayout is provided as a prop, fetch it from the server
    if (!savedLayout) {
      fetchSavedLayout();
    }
  }, [savedLayout, fetchSavedLayout]);

  // Function to save viewport (zoom and pan) state
  const saveViewport = useCallback(
    debounce(async (viewportData) => {
      try {
        if (!viewportData) return;
        
        // Extract just the viewport coordinates we need
        const simplifiedViewport = {
          x: viewportData.x,
          y: viewportData.y,
          zoom: viewportData.zoom
        };
        
        // Get parameters needed for the payload
        const urlParams = new URLSearchParams(window.location.search);
        const workflowToUse = workflowName || urlParams.get('workflow') || 'Admissions';
        let processTypeToUse = processType || 
                             urlParams.get('processType') || 
                             workflowToUse.toLowerCase().replace(/\s+/g, '_') || 
                             'admission';
        processTypeToUse = processTypeToUse.trim() || 'default_process';
        
        // Build the simplest possible payload - minimal properties, no nesting
        const payload = {
          process_type: processTypeToUse,
          layout_data: simplifiedViewport, // Just x, y and zoom - nothing else
          hospital: 'Virtua Marlton Hospital',
          workflow: workflowToUse,
          time_range: '24 Hours',
        };
        
        console.log('Saving viewport with exact payload:', payload);
        await axios.post('/improvement/process/viewport', payload);
      } catch (error) {
        console.error('Viewport save error - basic info:', error.message);
      }
    }, 1500), // Much longer debounce to reduce server load
    [workflowName, processType]
  );
  
  const saveLayout = useCallback(
    debounce(async (nodes) => {
      try {
        if (!nodes || nodes.length === 0) return;
        
        // Get basic parameters for the request
        const urlParams = new URLSearchParams(window.location.search);
        const workflowToUse = workflowName || urlParams.get('workflow') || 'Admissions';
        let processTypeToUse = processType || 
                              urlParams.get('processType') || 
                              workflowToUse.toLowerCase().replace(/\s+/g, '_') || 
                              'admission';
        processTypeToUse = processTypeToUse.trim() || 'default_process';
        
        // Create a minimal layout data object with just the node positions
        // No nested properties, keep it flat
        const layoutData = {};
        nodes.forEach(node => {
          if (node && node.id && node.position) {
            // Use string IDs as keys with simple x,y values
            layoutData[node.id] = {
              x: Math.round(node.position.x),
              y: Math.round(node.position.y)
            };
          }
        });
        
        // Only add essential data - skip flowDirection for now to simplify
        if (Object.keys(layoutData).length > 0) {
          const payload = {
            process_type: processTypeToUse,
            layout_data: layoutData,  // Just node positions
            hospital: 'Virtua Marlton Hospital', // hardcode to ensure consistency
            workflow: workflowToUse,
            time_range: '24 Hours',
          };
          
          console.log('Saving layout with payload:', payload);
          await axios.post('/improvement/process/layout', payload);
        }
      } catch (error) {
        console.error('Layout save error - basic info:', error.message);
      }
    }, 2000), // Much longer debounce to reduce frequency
    [workflowName, processType]
  );

  // Function to generate default positions for nodes based on their type and ID
  const generateDefaultPosition = (nodeId, nodeType) => {
    const centerX = 500; // Center position X
    const rowSpacing = 200; // Vertical spacing between rows
    
    // For the Bed Placement workflow specific to the provided image
    // First row - Input sources (blue boxes) at the top in a row
    if (nodeId === 'emergency_department' || nodeId === 'ed' || nodeId === 'input_ed') {
      return { x: 150, y: 100 };
    }
    if (nodeId === 'operating_room' || nodeId === 'or' || nodeId === 'input_or') {
      return { x: 350, y: 100 };
    }
    if (nodeId === 'direct_admissions' || nodeId === 'input_direct') {
      return { x: 550, y: 100 };
    }
    if (nodeId === 'transfers' || nodeId === 'input_transfers') {
      return { x: 750, y: 100 };
    }
    
    // Second row - First process node (green box) - Bed Request Initiated
    if (nodeId === 'bed_request' || nodeId === 'process_bed_request') {
      return { x: centerX, y: 300 };
    }
    
    // Third row - Second process node (green box) - Bed Assignment Decision
    if (nodeId === 'bed_assignment' || nodeId === 'process_bed_assignment') {
      return { x: centerX, y: 500 };
    }
    
    // Fourth row - Final destination (purple box) - Patient Bedding
    if (nodeId === 'patient_bedding' || nodeId === 'bedding' || nodeId === 'process_patient_bedding') {
      return { x: centerX, y: 700 };
    }
    
    // For other node IDs in the bed placement workflow that might be present
    if (nodeId === 'decision') {
      return { x: centerX, y: 400 };
    }
    if (nodeId === 'unit') {
      return { x: centerX, y: 600 };
    }
    
    // Default positions based on node type
    if (nodeType === 'input') {
      // Spread input nodes across the top
      const inputIndex = Math.floor(Math.random() * 4); // Random position for unlabeled inputs
      return { x: 150 + (inputIndex * 200), y: 100 };
    } else if (nodeType === 'process') {
      // Position process nodes in the middle vertical area
      return { x: centerX, y: 400 };
    } else if (nodeType === 'decision') {
      // Position decision nodes in the middle vertical area
      return { x: centerX, y: 500 };
    } else if (nodeType === 'result') {
      // Position result nodes at the bottom
      return { x: centerX, y: 700 };
    }
    
    // Default position if no specific rule applies
    return { x: centerX, y: 400 };
  };

  useEffect(() => {
    if (!data?.nodes || !data?.edges) {
      console.error('Missing nodes or edges data:', data);
      return;
    }
    
    console.log('Processing diagram data:', data);
    
    // Inspect the first node to determine the data format
    const firstNode = data.nodes[0];
    console.log('First node structure:', firstNode);
    
    // Get the current workflow from URL parameters
    const urlParams = new URLSearchParams(window.location.search);
    const workflowParam = urlParams.get('workflow');
    
    // Detect workflow type based on node IDs and metrics
    // Check for Bed Placement workflow - include both the original node IDs and the mock data node IDs
    const hasBedPlacementNodes = data.nodes.some(node => 
      ['input_ed', 'input_or', 'input_direct', 'input_transfers', 'process_bed_request', 'process_bed_assignment', 'process_patient_bedding', 
       'node_0', 'node_1', 'node_2', 'node_3', 'node_4', 'node_5', 'node_6', 'node_7', 'node_8', 'node_9'].includes(node.id)
    );
    
    // Check for Admissions workflow
    const hasAdmissionsNodes = data.nodes.some(node => 
      ['input_referral', 'input_self', 'input_transfer', 'process_screening', 'process_insurance', 'process_registration'].includes(node.id)
    );
    
    // Check for Discharges workflow
    const hasDischargesNodes = data.nodes.some(node => 
      ['input_inpatient', 'input_icu', 'input_observation', 'process_discharge_order', 'process_discharge_planning'].includes(node.id)
    );
    
    // Check for ED to Inpatient workflow
    const hasEDToInpatientNodes = data.nodes.some(node => 
      ['input_ed_arrival', 'process_triage', 'process_assessment', 'process_diagnostics', 'process_transfer'].includes(node.id)
    );
    
    // Check metrics for workflow type
    const workflowFromMetrics = data.metrics && data.metrics.cascade ? data.metrics.cascade.primaryProcess : null;
    
    // Determine the current workflow - prioritize the explicitly passed workflowName prop
    let currentWorkflow = workflowName || workflowParam;
    
    // If no explicit workflow name provided, try to detect from data
    if (!currentWorkflow) {
      if (workflowFromMetrics) {
        currentWorkflow = workflowFromMetrics;
      } else if (hasBedPlacementNodes) {
        currentWorkflow = 'Bed Placement';
      } else if (hasAdmissionsNodes) {
        currentWorkflow = 'Admissions';
      } else if (hasDischargesNodes) {
        currentWorkflow = 'Discharges';
      } else if (hasEDToInpatientNodes) {
        currentWorkflow = 'ED to Inpatient';
      }
    }
    
    // Determine if we're viewing each specific workflow
    const isBedPlacementWorkflow = 
      currentWorkflow === 'Bed Placement' || 
      hasBedPlacementNodes || 
      workflowFromMetrics === 'Bed Placement';
    
    const isAdmissionsWorkflow = 
      currentWorkflow === 'Admissions' || 
      hasAdmissionsNodes || 
      workflowFromMetrics === 'Admissions';
    
    const isDischargesWorkflow = 
      currentWorkflow === 'Discharges' || 
      hasDischargesNodes || 
      workflowFromMetrics === 'Discharges';
    
    const isEDToInpatientWorkflow = 
      currentWorkflow === 'ED to Inpatient' || 
      hasEDToInpatientNodes || 
      workflowFromMetrics === 'ED to Inpatient';
      
    console.log('Workflow detection:', {
      workflowParam,
      currentWorkflow,
      workflowFromMetrics,
      isBedPlacementWorkflow,
      isAdmissionsWorkflow,
      isDischargesWorkflow,
      isEDToInpatientWorkflow,
      nodeIds: data.nodes.map(node => node.id)
    });
    
    // Check the data structure to determine format
    const hasLabelProperty = data.nodes.length > 0 && 'label' in firstNode;
    const hasDataProperty = data.nodes.length > 0 && 'data' in firstNode;
    const hasTypeProperty = data.nodes.length > 0 && 'type' in firstNode;
    
    // Also check if data has our custom node structure (input_*, process_* IDs or start/end types)
    const hasCustomNodeStructure = data.nodes.some(node => 
      node.id?.startsWith('input_') || 
      node.id?.startsWith('process_') || 
      node.type === 'start' || 
      node.type === 'end' || 
      node.type === 'activity'
    );
    
    // Use our custom format for all workflows
    const useCustomFormat = true;
    
    // For development purposes, we can force workflow detection
    // Set to false in production
    const forceWorkflowDetection = false;
    
    // Override the detection results for testing if needed
    const isActuallyBedPlacementWorkflow = forceWorkflowDetection ? true : isBedPlacementWorkflow;
    const isActuallyAdmissionsWorkflow = forceWorkflowDetection ? false : isAdmissionsWorkflow;
    const isActuallyDischargesWorkflow = forceWorkflowDetection ? false : isDischargesWorkflow;
    const isActuallyEDToInpatientWorkflow = forceWorkflowDetection ? false : isEDToInpatientWorkflow;
    
    console.log('Workflow Detection Results:', {
      bedPlacement: isActuallyBedPlacementWorkflow,
      admissions: isActuallyAdmissionsWorkflow,
      discharges: isActuallyDischargesWorkflow,
      edToInpatient: isActuallyEDToInpatientWorkflow,
      currentWorkflow
    });
    
    // Force log the workflow name for debugging
    console.log(`Using custom format for workflow: ${currentWorkflow}`);
    
    // If we don't have any nodes, log an error
    if (!data.nodes || data.nodes.length === 0) {
      console.error('No nodes found in the data!', data);
      return;
    }
    
    console.log('Process flow data format:', {
      requestedWorkflow: workflowParam,
      isBedPlacementWorkflow,
      hasLabelProperty,
      hasDataProperty,
      hasTypeProperty,
      hasCustomNodeStructure,
      nodeCount: data.nodes.length,
      edgeCount: data.edges.length,
      usingFormat: 'ALWAYS using Custom Bed Placement format',
      firstNodeSample: firstNode
    });
    
    // Force log the entire data structure for debugging
    console.log('FULL DATA STRUCTURE:', JSON.stringify(data, null, 2));
    
    // If we have metrics in the data, log them for debugging
    if (data.metrics) {
      console.log('Process metrics:', data.metrics);
    }
    
    // Process the nodes based on the workflow
    let filteredNodes = data.nodes;
    
    // For Bed Placement workflow, remove specific nodes
    if (currentWorkflow === 'Bed Placement') {
      console.log('Processing Bed Placement workflow with', data.nodes.length, 'nodes');
      
      // Check if we're using the mock data format (node IDs like node_0, node_1, etc.)
      const hasMockDataFormat = data.nodes.some(node => node.id && node.id.match(/^node_\d+$/));
      
      if (hasMockDataFormat) {
        // For mock data, remove the three specific nodes (node_4, node_5, node_6)
        const nodesToRemove = ['node_4', 'node_5', 'node_6'];
        filteredNodes = data.nodes.filter(node => !nodesToRemove.includes(node.id));
        
        // Also filter out edges connected to these nodes
        if (data.edges) {
          // Create a new direct connection from node_3 to node_7 to replace the removed nodes
          const newEdge = {
            id: 'edge_new',
            source: 'node_3',  // Bed assignment decision
            target: 'node_7',  // Patient arrived at bed
            count: 50,
            label: '50 cases'
          };
          
          // Remove edges connected to the nodes we're removing
          const edgesToRemove = data.edges.filter(edge => 
            nodesToRemove.includes(edge.source) || nodesToRemove.includes(edge.target)
          ).map(edge => edge.id);
          
          // Filter out the removed edges and add our new edge
          data.edges = [
            ...data.edges.filter(edge => !edgesToRemove.includes(edge.id)),
            newEdge
          ];
          
          console.log('Filtered Bed Placement workflow:', {
            original: data.nodes.length,
            filtered: filteredNodes.length,
            removedNodes: nodesToRemove,
            removedEdges: edgesToRemove.length,
            addedEdge: newEdge
          });
        }
      } else {
        // For the original data format, keep all nodes
        filteredNodes = data.nodes;
      }
    }
    
    const mappedNodes = filteredNodes.map(node => {
      // ALWAYS use our custom format for ALL workflows
      // Map node types to component types
      let nodeType;
      
      console.log(`Processing node:`, node);
      
      // Handle both data formats - if node has a type property or node_type property, use it
      // otherwise try to infer from the id or other properties
      if (node.type || node.node_type) {
        const nodeTypeValue = node.type || node.node_type;
        console.log(`Mapping node ${node.id} (${node.label || ''}) with type ${nodeTypeValue} to component type`);
        
        // Direct mapping from node.type or node.node_type
        if (nodeTypeValue === 'start') {
          nodeType = 'input';
        } else if (nodeTypeValue === 'end') {
          nodeType = 'result';
        } else if (nodeTypeValue === 'decision') {
          nodeType = 'decision';
        } else if (nodeTypeValue === 'activity') {
          nodeType = 'process';
        } else {
          nodeType = 'process';
        }
      } else if (node.id) {
        // Try to infer from id
        if (node.id.includes('input_')) {
          nodeType = 'input';
        } else if (node.id.includes('process_')) {
          nodeType = 'process';
        } else if (node.id.includes('decision_')) {
          nodeType = 'decision';
        } else if (node.id.includes('result_')) {
          nodeType = 'result';
        } else {
          // For bed placement workflow, map node types based on the workflowName prop
          // This allows us to handle generic node IDs (node_0, node_1, etc.) in the JSON data
          if (currentWorkflow === 'Bed Placement' || isActuallyBedPlacementWorkflow) {
            // For Bed Placement workflow, use position in the flow to determine node type
            // First nodes are inputs, middle nodes are processes, last nodes are results
            const nodeIndex = parseInt(node.id.replace('node_', '')) || 0;
            const totalNodes = data.nodes.length;
            
            if (nodeIndex === 0 || nodeIndex < Math.floor(totalNodes * 0.3)) {
              // First ~30% of nodes are inputs
              nodeType = 'input';
            } else if (nodeIndex >= Math.floor(totalNodes * 0.7)) {
              // Last ~30% of nodes are results
              nodeType = 'result';
            } else {
              // Middle nodes are processes
              nodeType = 'process';
            }
          } else if (node.id === 'emergency_department' || node.id === 'operating_room' || 
              node.id === 'direct_admissions' || node.id === 'transfers' || 
              node.id === 'ed' || node.id === 'or') {
            nodeType = 'input';
          } else if (node.id === 'bed_request' || node.id === 'bed_assignment') {
            nodeType = 'process';
          } else if (node.id === 'decision' || node.id.includes('decision')) {
            nodeType = 'decision';
          } else if (node.id === 'patient_bedding' || node.id === 'bedding' || 
                    node.id === 'unit' || node.id.includes('destination')) {
            nodeType = 'result';
          } else if (node.id.startsWith('input_')) {
            nodeType = 'input';
          } else if (node.id.includes('_end') || node.id.endsWith('_discharge')) {
            nodeType = 'result';
          } else {
            nodeType = 'process';
          }
        }
      } else {
        // Default to process
        nodeType = 'process';
      }
        
      // Get the label from either node.label or node.data?.label
      const label = node.label || (node.data && node.data.label) || 'Unknown';
      
      // Get metrics from either direct properties or node.data
      const count = node.count !== undefined ? node.count : (node.data && node.data.caseCount) || 0;
      const avgDuration = node.avgDuration !== undefined ? node.avgDuration : (node.data && node.data.avgDuration) || 0;
      
      console.log(`Mapping node ${node.id} (${label}) to component type ${nodeType} with count=${count}, avgDuration=${avgDuration}`);
      
      // Handle custom metrics for Bed Placement format
      let metricsData = {
        caseCount: count,
        count: count, // Ensure count is directly available in the node data
        eventCount: count > 0 ? Math.round(count * 1.5) : 0,
        avgDuration: avgDuration, // Ensure avgDuration is directly available in the node data
        avgTime: avgDuration ? `${avgDuration} min` : null,
        frequency: count > 0 ? Math.round(count / 7) + '/day' : '0/day'
      };
      
      // If node has custom metrics object, use those values
      if (node.metrics) {
        console.log(`Node ${node.id} has custom metrics:`, node.metrics);
        
        // For input sources (ED, OR, Direct Admissions, Transfers)
        if (node.metrics.last24h !== undefined || node.metrics.last7d !== undefined || node.metrics.last30d !== undefined) {
          metricsData = {
            ...metricsData,
            last24h: node.metrics.last24h || 0,
            last7d: node.metrics.last7d || 0,
            last30d: node.metrics.last30d || 0
          };
        }
        
        // For process nodes with specific metrics
        if (node.metrics.avgTime !== undefined) {
          metricsData.avgTime = node.metrics.avgTime;
        }
        if (node.metrics.bottlenecks !== undefined) {
          metricsData.bottlenecks = node.metrics.bottlenecks;
        }
        if (node.metrics.successRate !== undefined) {
          metricsData.successRate = node.metrics.successRate;
        }
        if (node.metrics.avgTotalTime !== undefined) {
          metricsData.avgTotalTime = node.metrics.avgTotalTime;
        }
      }
      
      // Ensure metrics are properly passed to the node data
      if (node.metrics) {
        metricsData = {
          ...metricsData,
          ...node.metrics
        };
      }
      
      // Debug log to verify metrics data
      console.log(`Node ${node.id} metrics data:`, node.metrics);
      
      // Add additional debug logging for metrics
      console.log(`Node ${node.id} final metrics:`, metricsData);
      
      // Apply specialized node types based on workflow type
      let specialNodeType = nodeType;
      
      // Bed Placement workflow nodes - use the workflowName prop to determine if this is a Bed Placement workflow
      if (currentWorkflow === 'Bed Placement' || isActuallyBedPlacementWorkflow) {
        console.log(`Using specialized Bed Placement components for node ${node.id} with type ${nodeType}`);
        
        // For Bed Placement workflow with generic node IDs (node_0, node_1, etc.)
        // Map component types based on the node type we determined earlier
        if (nodeType === 'input') {
          specialNodeType = 'bed_placement_input';
        } else if (nodeType === 'process') {
          specialNodeType = 'bed_placement_process';
        } else if (nodeType === 'result') {
          specialNodeType = 'bed_placement_result';
        } else if (nodeType === 'decision') {
          specialNodeType = 'bed_placement_process'; // Map decision nodes to process for Bed Placement
        }
        
        // Handle specific node IDs from the JSON data
        if (node.id === 'input_ed' || node.id === 'input_or' || node.id === 'input_direct' || node.id === 'input_transfers' ||
            node.id === 'node_0' || node.id === 'node_1' || node.id === 'node_8' || node.id === 'node_9' || 
            node.label === 'Emergency Department' || node.label === 'Operating Room' || 
            node.label === 'Direct Admissions' || node.label === 'Transfers' ||
            node.label === 'Transfer' || node.label === 'Emergency Department (ED)' || 
            node.label === 'Operating Room (OR)' || node.label === 'Direct Admission') {
          specialNodeType = 'bed_placement_input';
        }
        // Process nodes
        else if (node.id === 'process_bed_request' || node.id === 'process_bed_assignment' ||
                 node.id === 'node_2' || node.id === 'node_3' || node.id === 'node_4' || 
                 node.id === 'node_5' || node.id === 'node_6' || 
                 node.label === 'Bed Request Initiated' || node.label === 'Bed Assignment Decision' || 
                 node.label === 'Bed request initiated' || node.label === 'Bed assignment decision' || 
                 node.label === 'Bed allocation' || node.label === 'Transportation ordered' || 
                 node.label === 'Patient in transit') {
          specialNodeType = 'bed_placement_process';
        }
        // Result node
        else if (node.id === 'process_patient_bedding' ||
                 node.id === 'node_7' || 
                 node.label === 'Patient Bedding' ||
                 node.label === 'Patient arrived at bed') {
          specialNodeType = 'bed_placement_result';
        }
      }
      // Admissions workflow nodes
      else if (isActuallyAdmissionsWorkflow) {
        // Input nodes
        if (node.id === 'input_referral' || node.id === 'input_self' || node.id === 'input_transfer') {
          specialNodeType = 'admissions_input';
        }
        // Process nodes
        else if (node.id === 'process_screening' || node.id === 'process_insurance' || 
                 node.id === 'process_registration' || node.id === 'process_bed_assignment') {
          specialNodeType = 'admissions_process';
        }
        // Result node
        else if (node.id === 'process_admission') {
          specialNodeType = 'admissions_result';
        }
      }
      // Discharges workflow nodes
      else if (isActuallyDischargesWorkflow) {
        // Input nodes
        if (node.id === 'input_inpatient' || node.id === 'input_icu' || node.id === 'input_observation') {
          specialNodeType = 'discharges_input';
        }
        // Process nodes
        else if (node.id === 'process_discharge_order' || node.id === 'process_discharge_planning' || 
                 node.id === 'process_medication' || node.id === 'process_education') {
          specialNodeType = 'discharges_process';
        }
        // Result node
        else if (node.id === 'process_final_discharge') {
          specialNodeType = 'discharges_result';
        }
      }
      // ED to Inpatient workflow nodes
      else if (isActuallyEDToInpatientWorkflow) {
        // Input nodes
        if (node.id === 'input_ed_arrival') {
          specialNodeType = 'ed_to_inpatient_input';
        }
        // Process nodes
        else if (node.id === 'process_triage' || node.id === 'process_registration' || 
                 node.id === 'process_assessment' || node.id === 'process_diagnostics' || 
                 node.id === 'process_decision' || node.id === 'process_bed_request' || 
                 node.id === 'process_bed_assignment') {
          specialNodeType = 'ed_to_inpatient_process';
        }
        // Result node
        else if (node.id === 'process_transfer') {
          specialNodeType = 'ed_to_inpatient_result';
        }
      }
      
      console.log(`Node ${node.id} - Workflow: ${currentWorkflow}, Special Node Type: ${specialNodeType}`);
      
      return {
        id: node.id,
        type: specialNodeType, // Use specialized component type for the specific workflow nodes
        // Generate default positions based on node type if not provided
        position: node.position || generateDefaultPosition(node.id, nodeType),
        data: {
          label: label,
          description: node.description || '',
          count: node.count,
          metrics: metricsData,
          // Pass all original node properties to ensure everything is available
          ...node,
          // Ensure metrics are directly accessible
          last24h: node.metrics?.last24h,
          last7d: node.metrics?.last7d,
          last30d: node.metrics?.last30d,
          avgTime: node.metrics?.avgTime,
          bottlenecks: node.metrics?.bottlenecks,
          successRate: node.metrics?.successRate,
          avgTotalTime: node.metrics?.avgTotalTime
        }
      };
    });

    const mappedEdges = data.edges.map(edge => {
      // Get edge properties from either direct properties or edge.data
      const source = edge.source || '';
      const target = edge.target || '';
      const count = parseInt(edge.count) || (edge.data && parseInt(edge.data.patientCount)) || 0;
      
      console.log(`Mapping edge ${edge.id} from ${source} to ${target} with count ${count}`);
      
      // Determine which workflow this edge belongs to - use the workflowName prop
      const isBedPlacementEdge = (currentWorkflow === 'Bed Placement' || isActuallyBedPlacementWorkflow) && (
        // Either based on specific node IDs if they exist
        source.includes('input_ed') || source.includes('input_or') || 
        source.includes('input_direct') || source.includes('input_transfers') || 
        source.includes('process_bed_request') || source.includes('process_bed_assignment') || 
        target.includes('process_bed_request') || target.includes('process_bed_assignment') || 
        target.includes('process_patient_bedding') ||
        // Or based on generic node IDs (node_0, node_1, etc.) for the JSON data format
        (source.includes('node_') && target.includes('node_')) ||
        // For any edge in the Bed Placement workflow (catch-all for any edge format)
        (currentWorkflow === 'Bed Placement')
      );
      
      const isAdmissionsEdge = isActuallyAdmissionsWorkflow && (
        source.includes('input_referral') || source.includes('input_self') || 
        source.includes('input_transfer') || source.includes('process_screening') || 
        source.includes('process_insurance') || source.includes('process_registration') || 
        target.includes('process_admission')
      );
      
      const isDischargesEdge = isActuallyDischargesWorkflow && (
        source.includes('input_inpatient') || source.includes('input_icu') || 
        source.includes('input_observation') || source.includes('process_discharge') || 
        target.includes('process_final_discharge')
      );
      
      const isEDToInpatientEdge = isActuallyEDToInpatientWorkflow && (
        source.includes('input_ed_arrival') || source.includes('process_triage') || 
        source.includes('process_assessment') || source.includes('process_diagnostics') || 
        target.includes('process_transfer')
      );
      
      console.log(`Edge ${edge.id}: ${source} → ${target} | Workflow detection:`, {
        isBedPlacementEdge,
        isAdmissionsEdge,
        isDischargesEdge,
        isEDToInpatientEdge
      });
      
      // Determine if this is a main flow based on source, target, or count
      const isMainFlow = count > 100 || 
        (source.includes('process_') && target.includes('process_')) || 
        source === target.replace('process_', '');
      
      // For success flow edges (typically the final step in a process)
      const isSuccessFlow = edge.id?.includes('success') || 
        (target.includes('bedding') || target.includes('discharge') || 
         target.includes('admission') || target.includes('transfer'));
      
      // Determine edge color based on workflow and flow type
      let edgeColor;
      
      if (isBedPlacementEdge) {
        // Bed Placement workflow colors - using healthcare theme colors
        edgeColor = isSuccessFlow ? 'var(--healthcare-accent)' : // Accent color for success flow
                    isMainFlow ? 'var(--healthcare-success)' : // Success color for main process flow
                    'var(--healthcare-primary)'; // Primary color for input flows
      } else if (isAdmissionsEdge) {
        // Admissions workflow colors
        edgeColor = isSuccessFlow ? '#8b5cf6' : // Purple for success flow
                    isMainFlow ? '#0891b2' : // Cyan for main process flow
                    '#2563eb'; // Blue for input flows
      } else if (isDischargesEdge) {
        // Discharges workflow colors
        edgeColor = isSuccessFlow ? '#8b5cf6' : // Purple for success flow
                    isMainFlow ? '#0d9488' : // Teal for main process flow
                    '#4f46e5'; // Indigo for input flows
      } else if (isEDToInpatientEdge) {
        // ED to Inpatient workflow colors
        edgeColor = isSuccessFlow ? '#8b5cf6' : // Purple for success flow
                    isMainFlow ? '#0369a1' : // Sky blue for main process flow
                    '#1d4ed8'; // Royal blue for input flows
      } else {
        // Default edge color for unidentified workflow
        edgeColor = '#ea580c'; // Orange
      }
      
      // Calculate edge thickness based on count (logarithmic scale)
      const thickness = Math.max(2, Math.min(6, 2 + (count > 0 ? Math.log10(count) * 0.8 : 0)));
      
      // Custom label based on the pattern in the image
      let edgeLabel = '';
      
      // For edges with metrics as shown in the image
      if (edge.label) {
        edgeLabel = edge.label;
      } else if (count > 0) {
        // Format similar to the '138 patients' pattern in the image
        edgeLabel = `${count} ${edge.label_unit || 'cases'}`;
        
        // Customize labels based on workflow type and edge position
        // Bed Placement workflow
        if (isBedPlacementEdge) {
          // Use the label from the JSON data if available
          if (edge.label) {
            edgeLabel = edge.label;
          } else {
            // For specific edge patterns in the Bed Placement workflow
            if ((source.includes('bed_request') && target.includes('bed_assignment')) ||
                (source.includes('process_bed_request') && target.includes('process_bed_assignment')) ||
                (source === 'node_2' && target === 'node_3')) {
              edgeLabel = `${count} total`;
            } else if ((source.includes('bed_assignment') && target.includes('patient_bedding')) ||
                       (source.includes('process_bed_assignment') && target.includes('process_patient_bedding')) ||
                       (source === 'node_3' && target === 'node_7')) {
              edgeLabel = `${count} successful`;
            }
          }
        }
        // Admissions workflow
        else if (isAdmissionsEdge) {
          if (source.includes('process_screening') && target.includes('process_insurance')) {
            edgeLabel = `${count} total`;
          } else if (source.includes('process_registration') && target.includes('process_admission')) {
            edgeLabel = `${count} successful`;
          }
        }
        // Discharges workflow
        else if (isDischargesEdge) {
          if (source.includes('process_discharge_order') && target.includes('process_discharge_planning')) {
            edgeLabel = `${count} total`;
          } else if (source.includes('process_education') && target.includes('process_final_discharge')) {
            edgeLabel = `${count} successful`;
          }
        }
        // ED to Inpatient workflow
        else if (isEDToInpatientEdge) {
          if (source.includes('process_triage') && target.includes('process_registration')) {
            edgeLabel = `${count} total`;
          } else if (source.includes('process_bed_assignment') && target.includes('process_transfer')) {
            edgeLabel = `${count} successful`;
          }
        }
      }
      
      if (edge.format === 'ocel') {
        return {
          id: edge.id,
          source: source,
          target: target,
          type: 'smoothstep',  // Use smoothstep for better vertical edge routing
          markerEnd: {
            type: MarkerType.ArrowClosed,
            color: edgeColor,
          },
          sourceHandle: 'source',
          targetHandle: 'target',
          label: edgeLabel,
          data: {
            count: count,
            isMainFlow: isMainFlow,
            isSuccessFlow: isSuccessFlow
          },
          labelStyle: { 
            fill: 'white', 
            fontWeight: '600', 
            fontSize: '14px',
            fontFamily: 'var(--font-sans)',
            textAlign: 'center',
            letterSpacing: '0.02em',
            lineHeight: '1.3',
            backgroundColor: '#1f2937',
            padding: '6px 10px',
            borderRadius: '6px'
          },
          labelBgStyle: { 
            fill: '#1f2937',
            opacity: 0.95,
            rx: 6,
            ry: 6,
          },
          labelBgPadding: [2, 4],
          labelShowBg: count > 0, // Only show label for edges with count
          style: {
            strokeWidth: thickness,
            stroke: edgeColor,
            animated: isMainFlow || isSuccessFlow // Animate main and success flows
          }
        };
      } else {
        // Handle other formats (existing code)
        return {
          ...edge,
          type: 'smoothstep',  // Use smoothstep for better vertical edge routing
          markerEnd: {
            type: MarkerType.ArrowClosed
          },
          sourceHandle: 'source-bottom',  // Always use bottom source for vertical flow
          targetHandle: 'target-top',     // Always use top target for vertical flow
          label: edge.data ? `${edge.data.patientCount} patients\n${edge.data.avgTime}` : '',
          labelStyle: { 
            fill: 'white', 
            fontWeight: '600', 
            fontSize: '14px',
            fontFamily: 'var(--font-sans)',
            textAlign: 'center',
            letterSpacing: '0.02em',
            lineHeight: '1.5',
            backgroundColor: '#1f2937',
            padding: '8px 12px',
            borderRadius: '6px',
            boxShadow: '0 2px 4px 0 rgba(0, 0, 0, 0.2)'
          },
          labelBgStyle: { 
            fill: '#1f2937',
            opacity: 0.95,
            rx: 6,
            ry: 6
          },
          labelBgPadding: [0, 0],
          labelShowBg: false,
          style: getEdgeStyle(edge.data),
        };
      }
    });

    console.log('Mapped nodes:', mappedNodes);
    console.log('Mapped edges:', mappedEdges);
    
    // Use either the savedLayout prop or the fetchedLayout state
    const layoutToUse = savedLayout || fetchedLayout;
    
    if (!layoutToUse) {
      console.log('No saved layout available, generating layout...');
      const { nodes: layoutedNodes, edges: layoutedEdges } = getLayoutedElements(mappedNodes, mappedEdges, flowDirection);
      console.log('Layout generated:', { nodes: layoutedNodes.length, edges: layoutedEdges.length, direction: flowDirection });
      setNodes(layoutedNodes);
      setEdges(layoutedEdges);
    } else {
      console.log('Using saved layout...', layoutToUse);
      
      // Check if we have viewport data in the saved layout
      if (layoutToUse.viewport) {
        console.log('Found saved viewport settings:', layoutToUse.viewport);
        setViewport(layoutToUse.viewport);
      } else {
        console.log('No saved viewport settings found');
      }
      
      // Check if we have a saved flowDirection and use it
      if (layoutToUse.flowDirection) {
        console.log('Found saved flow direction:', layoutToUse.flowDirection);
        // Don't update the flowDirection state here as it would cause a re-render loop
        // We'll just use the saved value for layout calculations
      }
      
      // Apply saved layout but ensure correct orientation properties are set based on flowDirection
      const isHorizontal = flowDirection === 'horizontal';
      const nodesWithDirectionProps = mappedNodes.map(node => {
        // Get the saved position for this node if it exists
        const savedPosition = layoutToUse[node.id];
        
        // Debug log to check if we have a saved position for this node
        if (savedPosition) {
          console.log(`Found saved position for node ${node.id}:`, savedPosition);
        } else {
          console.log(`No saved position for node ${node.id}, using default position`);
        }
        
        return {
          ...node,
          // Set connection points based on flow direction
          targetPosition: isHorizontal ? 'left' : 'top',
          sourcePosition: isHorizontal ? 'right' : 'bottom',
          // Apply saved position if available, otherwise keep the current position
          position: savedPosition ? {
            x: parseFloat(savedPosition.x),
            y: parseFloat(savedPosition.y)
          } : node.position
        };
      });
      
      console.log('Nodes with saved positions:', nodesWithDirectionProps);
      // Apply the saved layout
      setNodes(nodesWithDirectionProps);
      
      // Ensure edges use the correct type and handles for the selected flow direction
      const edgesWithDirectionProps = mappedEdges.map(edge => ({
        ...edge,
        type: 'default',  // Use default with curvature for curved edges
        sourceHandle: edge.sourceHandle || (isHorizontal ? 'source' : 'source-bottom'),
        targetHandle: edge.targetHandle || (isHorizontal ? 'target' : 'target-top'),
        style: { ...edge.style, stroke: '#fff', strokeWidth: 2 },
        animated: true,
        markerEnd: { type: 'arrowclosed' },
        curvature: 0.3,  // Add curvature for curved edges
      }));
      
      setEdges(edgesWithDirectionProps);
    }
  }, [data, savedLayout, fetchedLayout, flowDirection]);

  // Add a dedicated effect to handle flowDirection changes
  useEffect(() => {
    // Only regenerate layout if we already have nodes
    if (nodes.length > 0 && edges.length > 0) {
      console.log('Flow direction changed to:', flowDirection, '- regenerating layout');
      const { nodes: layoutedNodes, edges: layoutedEdges } = getLayoutedElements(nodes, edges, flowDirection);
      setNodes([...layoutedNodes]);
      setEdges([...layoutedEdges]);
    }
  }, [flowDirection]);

  const onConnect = useCallback(
    (params) => {
      const isHorizontal = flowDirection === 'horizontal';
      return setEdges((els) => addEdge({ 
        ...params, 
        type: 'default',  // Use default with curvature for curved edges
        sourceHandle: params.sourceHandle || (isHorizontal ? 'source' : 'source-bottom'),
        targetHandle: params.targetHandle || (isHorizontal ? 'target' : 'target-top'),
        style: { ...getEdgeStyle({ isMainFlow: true }), stroke: '#fff', strokeWidth: 2 },
        animated: true,
        markerEnd: { type: 'arrowclosed' },
        curvature: 0.3,  // Add curvature for curved edges
      }, els));
    },
    [flowDirection]
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
  }, [setNodes, saveLayout, viewport]);

  React.useImperativeHandle(ref, () => ({
    resetLayout: () => {
      const { nodes: layoutedNodes, edges: layoutedEdges } = getLayoutedElements(nodes, edges, flowDirection);
      setNodes([...layoutedNodes]);
      setEdges([...layoutedEdges]);
    }
  }));

  // No error notification needed anymore since we've reverted to the original working format

  return (
    <div style={{ width: '100%', height: '90vh', position: 'relative' }} className="process-flow-container">
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
        defaultEdgeOptions={{
          ...defaultEdgeOptions,
          type: 'default',  // Use default with curvature for curved edges
          style: { stroke: '#fff', strokeWidth: 2 },
          animated: true,
          markerEnd: { type: 'arrowclosed' },
          curvature: 0.3,  // Add curvature for curved edges
        }}
        onMove={(_, viewport) => {
          // Track viewport changes (zoom and pan)
          setViewport(viewport);
          
          // Only trigger save on major viewport changes
          if (!lastSavedViewport.current || 
              Math.abs(lastSavedViewport.current.x - viewport.x) > 50 || 
              Math.abs(lastSavedViewport.current.y - viewport.y) > 50 || 
              Math.abs(lastSavedViewport.current.zoom - viewport.zoom) > 0.2) {
            
            // Call the already-debounced saveViewport function
            saveViewport(viewport);
            
            // Update the last saved viewport reference
            lastSavedViewport.current = { ...viewport };
          }
        }}
        defaultViewport={viewport || { x: 0, y: 0, zoom: 0.9 }}
        fitView={!viewport} // Only fit view if we don't have a saved viewport
        fitViewOptions={{ 
          padding: 0.2,  // Reduced padding for tighter fit
          includeHiddenNodes: true,
          duration: 800,
          zoom: 0.9,     // Adjusted zoom to see the entire diagram
          maxZoom: 2.0   // Allow more zooming in if needed
        }}
        className="bg-healthcare-background dark:bg-healthcare-background-dark"
        minZoom={0.3}
        maxZoom={2.0}
        zoomOnScroll={true}
        zoomOnPinch={true}
        panOnScroll={false}
        panOnScrollMode={undefined}
        panOnDrag={true}
        proOptions={{
          hideAttribution: true
        }}
        style={{
          cursor: 'default',
          '--xy-theme-selected': 'var(--healthcare-primary)',
          '--xy-theme-hover': 'var(--healthcare-border)',
          '--xy-theme-edge-hover': 'var(--healthcare-primary-dark)',
          backgroundColor: 'var(--healthcare-background)'
        }}
      >
        <Background
          gap={20}
          size={1.5}
          color="#1a365d"
          style={{ opacity: 0.02 }}
        />
        <Controls 
          showZoom={true}
          showFitView={true}
          showInteractive={true}
          position="bottom-right"
          style={{ 
            marginRight: '10px',
            marginBottom: '10px',
            backgroundColor: 'rgba(255, 255, 255, 0.8)',
            borderRadius: '8px',
            padding: '4px'
          }}
        />
        <MiniMap 
          position="bottom-left"
          style={{ 
            backgroundColor: 'var(--healthcare-surface)',
            marginLeft: '10px',
            marginBottom: '10px',
            borderRadius: '8px',
            border: '1px solid rgba(255, 255, 255, 0.2)'
          }} 
          maskColor="rgba(0, 0, 0, 0.4)"
          nodeColor="#0284c7"
        />
      </ReactFlow>
    </div>
  );
});

export default ProcessFlowDiagram;
