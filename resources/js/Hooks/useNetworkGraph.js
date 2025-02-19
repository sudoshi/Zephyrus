import { useState, useCallback, useRef, useEffect } from 'react';

const useNetworkGraph = (initialData) => {
  const [data, setData] = useState(initialData);
  const [selectedNode, setSelectedNode] = useState(null);
  const [hoveredNode, setHoveredNode] = useState(null);
  const [highlightedNodes, setHighlightedNodes] = useState(new Set());
  const [highlightedLinks, setHighlightedLinks] = useState(new Set());
  const graphRef = useRef(null);

  // Reset graph state when data changes
  useEffect(() => {
    setSelectedNode(null);
    setHoveredNode(null);
    setHighlightedNodes(new Set());
    setHighlightedLinks(new Set());
  }, [initialData]);

  const updateHighlights = useCallback((node) => {
    const newHighlightedNodes = new Set();
    const newHighlightedLinks = new Set();

    if (node) {
      // Add the hovered node
      newHighlightedNodes.add(node.id);

      // Find all connected links and nodes
      data.links.forEach(link => {
        const sourceId = typeof link.source === 'object' ? link.source.id : link.source;
        const targetId = typeof link.target === 'object' ? link.target.id : link.target;

        if (sourceId === node.id) {
          newHighlightedLinks.add(link);
          newHighlightedNodes.add(targetId);
        } else if (targetId === node.id) {
          newHighlightedLinks.add(link);
          newHighlightedNodes.add(sourceId);
        }
      });
    }

    setHighlightedNodes(newHighlightedNodes);
    setHighlightedLinks(newHighlightedLinks);
  }, [data]);

  const handleNodeHover = useCallback((node) => {
    setHoveredNode(node);
    updateHighlights(node);
  }, [updateHighlights]);

  const handleNodeClick = useCallback((node) => {
    setSelectedNode(prev => prev === node ? null : node);
  }, []);

  const zoomToFit = useCallback((duration = 1000) => {
    graphRef.current?.zoomToFit(duration);
  }, []);

  const centerOnNode = useCallback((nodeId, duration = 1000) => {
    const node = data.nodes.find(n => n.id === nodeId);
    if (node && graphRef.current) {
      graphRef.current.centerAt(node.x, node.y, duration);
      graphRef.current.zoom(2, duration);
    }
  }, [data]);

  const isNodeHighlighted = useCallback((nodeId) => {
    return highlightedNodes.has(nodeId);
  }, [highlightedNodes]);

  const isLinkHighlighted = useCallback((link) => {
    return highlightedLinks.has(link);
  }, [highlightedLinks]);

  const getNodeColor = useCallback((node) => {
    if (!node) return 'var(--healthcare-text-secondary)';

    // Dim nodes that aren't highlighted when there's a highlight active
    if (highlightedNodes.size > 0 && !isNodeHighlighted(node.id)) {
      return 'var(--healthcare-text-secondary)';
    }

    if (node.type === 'primary') {
      return 'var(--healthcare-primary)';
    }

    const severity = node.severity || 0;
    if (severity > 0.8) return 'var(--healthcare-critical)';
    if (severity > 0.6) return 'var(--healthcare-warning)';
    if (severity > 0.4) return 'var(--healthcare-info)';
    return 'var(--healthcare-success)';
  }, [highlightedNodes, isNodeHighlighted]);

  const getLinkColor = useCallback((link) => {
    // Dim links that aren't highlighted when there's a highlight active
    if (highlightedLinks.size > 0 && !isLinkHighlighted(link)) {
      return 'var(--healthcare-text-secondary)';
    }

    const value = link.value || 0;
    if (value > 0.8) return 'var(--healthcare-critical)';
    if (value > 0.6) return 'var(--healthcare-warning)';
    if (value > 0.4) return 'var(--healthcare-info)';
    return 'var(--healthcare-success)';
  }, [highlightedLinks, isLinkHighlighted]);

  const getLinkWidth = useCallback((link) => {
    return isLinkHighlighted(link) ? 2 : 1;
  }, [isLinkHighlighted]);

  const getNodeSize = useCallback((node) => {
    if (node.type === 'primary') return 8;
    return isNodeHighlighted(node.id) ? 7 : 5;
  }, [isNodeHighlighted]);

  return {
    graphRef,
    selectedNode,
    hoveredNode,
    handleNodeHover,
    handleNodeClick,
    zoomToFit,
    centerOnNode,
    getNodeColor,
    getLinkColor,
    getLinkWidth,
    getNodeSize,
    isNodeHighlighted,
    isLinkHighlighted
  };
};

export default useNetworkGraph;
