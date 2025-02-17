import React, { useState, useEffect, useRef } from 'react';
import { Head } from '@inertiajs/react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import ProcessFlowDiagram from '@/Components/Process/ProcessFlowDiagram';
import ProcessMetricsModal from '@/Components/Process/ProcessMetricsModal';

const Process = ({ auth, savedLayout }) => {
  const [processData, setProcessData] = useState(null);
  const [selectedNode, setSelectedNode] = useState(null);
  const [selectedEdge, setSelectedEdge] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [isMetricsModalOpen, setIsMetricsModalOpen] = useState(false);
  const diagramRef = useRef(null);

  useEffect(() => {
    const fetchData = async () => {
      try {
        const response = await fetch('/improvement/api/nursing-operations');
        if (!response.ok) {
          throw new Error(`HTTP error! status: ${response.status}`);
        }
        const data = await response.json();
        setProcessData(data);
      } catch (error) {
        console.error('Error fetching data:', error);
        setError('Failed to load process data');
      } finally {
        setLoading(false);
      }
    };

    fetchData();
  }, []);

  const handleNodeClick = (node) => {
    setSelectedNode(node);
    setSelectedEdge(null);
    setIsMetricsModalOpen(true);
  };

  const handleEdgeClick = (edge) => {
    setSelectedEdge(edge);
    setSelectedNode(null);
    setIsMetricsModalOpen(true);
  };

  const handleCloseModal = () => {
    setIsMetricsModalOpen(false);
    setSelectedNode(null);
    setSelectedEdge(null);
  };

  const handleShowOverallMetrics = () => {
    setSelectedNode(null);
    setSelectedEdge(null);
    setIsMetricsModalOpen(true);
  };

  const handleResetLayout = () => {
    diagramRef.current?.resetLayout();
  };

  if (loading) {
    return (
      <DashboardLayout>
        <Head title="Process Analysis - Improvement" />
        <PageContentLayout
          title="Process Analysis"
          subtitle="Analyze and optimize healthcare processes"
        >
          <div className="flex items-center justify-center h-64">
            <p className="text-lg">Loading process data...</p>
          </div>
        </PageContentLayout>
      </DashboardLayout>
    );
  }

  if (error) {
    return (
      <DashboardLayout>
        <Head title="Process Analysis - Improvement" />
        <PageContentLayout
          title="Process Analysis"
          subtitle="Analyze and optimize healthcare processes"
        >
          <div className="flex items-center justify-center h-64">
            <p className="text-red-500">{error}</p>
          </div>
        </PageContentLayout>
      </DashboardLayout>
    );
  }

  return (
    <DashboardLayout>
      <Head title="Process Analysis - Improvement" />
      
      <PageContentLayout
        title="Process Analysis"
        subtitle="Analyze and optimize healthcare processes"
      >
        <div className="relative">
          <div className="absolute top-4 right-4 z-10 flex gap-4">
            <button
              onClick={handleShowOverallMetrics}
              className="px-4 py-2 bg-healthcare-surface dark:bg-healthcare-surface-dark border border-healthcare-border dark:border-healthcare-border-dark rounded-md shadow-sm hover:bg-healthcare-surface-hover dark:hover:bg-healthcare-surface-hover-dark transition-colors"
            >
              View Metrics
            </button>
            <button
              onClick={handleResetLayout}
              className="px-4 py-2 bg-healthcare-surface dark:bg-healthcare-surface-dark border border-healthcare-border dark:border-healthcare-border-dark rounded-md shadow-sm hover:bg-healthcare-surface-hover dark:hover:bg-healthcare-surface-hover-dark transition-colors"
            >
              Reset Layout
            </button>
          </div>
          <ProcessFlowDiagram 
            ref={diagramRef}
            data={processData} 
            savedLayout={savedLayout}
            onNodeClick={handleNodeClick}
            onEdgeClick={handleEdgeClick}
          />
          <ProcessMetricsModal
            isOpen={isMetricsModalOpen}
            onClose={handleCloseModal}
            selectedNode={selectedNode}
            selectedEdge={selectedEdge}
            overallMetrics={processData?.metrics}
          />
        </div>
      </PageContentLayout>
    </DashboardLayout>
  );
};

export default Process;
