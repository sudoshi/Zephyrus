import React, { useState, useEffect, useRef } from 'react';
import { Head } from '@inertiajs/react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import ProcessFlowDiagram from '@/Components/Process/ProcessFlowDiagram';
import ProcessMetricsModal from '@/Components/Process/ProcessMetricsModal';
import ProcessSelector, { hospitals, workflows, timeRanges } from '@/Components/Process/ProcessSelector';

const Process = ({ auth, savedLayout }) => {
  const [processData, setProcessData] = useState(null);
  const [selectedNode, setSelectedNode] = useState(null);
  const [selectedEdge, setSelectedEdge] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [isMetricsModalOpen, setIsMetricsModalOpen] = useState(false);
  const [selectedHospital, setSelectedHospital] = useState(hospitals[0]);
  const [selectedWorkflow, setSelectedWorkflow] = useState(workflows[0]);
  const [selectedTimeRange, setSelectedTimeRange] = useState(timeRanges[0]);
  const diagramRef = useRef(null);

  useEffect(() => {
    const fetchData = async () => {
      try {
        const response = await fetch(`/improvement/api/nursing-operations?hospital=${encodeURIComponent(selectedHospital)}&workflow=${encodeURIComponent(selectedWorkflow)}&timeRange=${encodeURIComponent(selectedTimeRange)}`);
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
  }, [selectedHospital, selectedWorkflow, selectedTimeRange]);

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
        headerContent={
          <ProcessSelector
            selectedHospital={selectedHospital}
            selectedWorkflow={selectedWorkflow}
            selectedTimeRange={selectedTimeRange}
            onHospitalChange={setSelectedHospital}
            onWorkflowChange={setSelectedWorkflow}
            onTimeRangeChange={setSelectedTimeRange}
            onShowMetrics={handleShowOverallMetrics}
            onResetLayout={handleResetLayout}
          />
        }
      >
        <div className="relative">
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
