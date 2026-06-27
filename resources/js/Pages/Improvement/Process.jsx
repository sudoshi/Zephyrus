import React, { useState, useEffect, useRef } from 'react';
import { Head, Link } from '@inertiajs/react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import ProcessFlowDiagram from '@/Components/Process/ProcessFlowDiagram';
import ProcessMetricsModal from '@/Components/Process/ProcessMetricsModal';
import ProcessIntelligenceModal from '@/Components/Process/ProcessIntelligenceModal';
import { Section, Panel } from '@/Components/system';
import { Icon } from '@iconify/react';
import { motion } from 'framer-motion';
import { AnalyticsProvider } from '@/Contexts/AnalyticsContext';
import { useDarkMode } from '@/Layouts/AuthenticatedLayout';
import ProcessFilters from '@/Components/Process/ProcessFilters';
import TimelineSlider from '@/Components/Process/TimelineSlider';
import { workflows } from '@/Components/Process/ProcessSelector';
import VariantsViewPanel from '@/Components/Process/VariantsViewPanel';


const Process = ({ auth, savedLayout }) => {
  const [processData, setProcessData] = useState(null);
  const [selectedNode, setSelectedNode] = useState(null);
  const [selectedEdge, setSelectedEdge] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [isMetricsModalOpen, setIsMetricsModalOpen] = useState(false);
  const [isIntelligenceModalOpen, setIsIntelligenceModalOpen] = useState(false);
  const [activeTab, setActiveTab] = useState('process-map');
  const [selectedMap, setSelectedMap] = useState('Bed Placement');
  const [flowDirection, setFlowDirection] = useState('Vertical');
  const [timelineRange, setTimelineRange] = useState([new Date(2024, 0, 1), new Date()]);

  // Switch the active process map; the [selectedMap] effect loads its data.
  const handleMapChange = (mapName) => {
    if (mapName === selectedMap) return;
    setSelectedMap(mapName);
    setSelectedNode(null);
    setSelectedEdge(null);
    setIsMetricsModalOpen(false);
  };
  const [filters, setFilters] = useState({
    selectedHospital: '',
    selectedUnit: '',
    selectedSpecialty: '',
    startDate: new Date(2024, 9, 1), // Oct 1, 2024
    endDate: new Date(2024, 11, 31), // Dec 31, 2024
    showComparison: false,
    compStartDate: new Date(2024, 0, 1), // Jan 1, 2024
    compEndDate: new Date(2024, 5, 30), // Jun 30, 2024
  });
  
  const diagramRef = useRef(null);

  // Define side navigation items
  const sideNavItems = [
    { id: 'process-map', label: 'Process Maps', icon: 'lucide:map' },
    { id: 'variants', label: 'Process Variants', icon: 'lucide:git-branch' },
    { id: 'statistics', label: 'Statistics', icon: 'lucide:bar-chart' },
    { id: 'optimization', label: 'Optimization', icon: 'lucide:settings' },
  ];
  
  const { isDarkMode } = useDarkMode();

  // Handle tab change
  const handleTabChange = (tabId) => {
    setActiveTab(tabId);
    
    // Update URL with active tab parameter
    const url = new URL(window.location);
    url.searchParams.set('tab', tabId);
    window.history.pushState({}, '', url);
  };

  // Initialize tab from URL parameter
  useEffect(() => {
    const url = new URL(window.location);
    const tabParam = url.searchParams.get('tab');
    if (tabParam && sideNavItems.some(item => item.id === tabParam)) {
      setActiveTab(tabParam);
    } else {
      // Default to process-map if no valid tab is specified
      setActiveTab('process-map');
      // Update URL with default tab parameter
      url.searchParams.set('tab', 'process-map');
      window.history.replaceState({}, '', url);
    }
  }, []);

  // Create patient flow filters object with default values
  const getPatientFlowFilters = () => {
    return {
      selectedHospital: 'Virtua Mullica Hospital',
      selectedLocation: 'Virtua Mullica Hospital',
      selectedDepartment: 'All Departments',
      selectedUnit: '',
      selectedPatientType: 'All Patients',
      dateRange: {
        startDate: getDefaultStartDate(),
        endDate: new Date()
      },
      showComparison: false,
      comparisonDateRange: {
        startDate: getPreviousPeriodStartDate(),
        endDate: getDefaultStartDate()
      }
    };
  };

  // Helper function to get default start date (7 days ago)
  const getDefaultStartDate = () => {
    const today = new Date();
    return new Date(today.setDate(today.getDate() - 7));
  };

  // Helper function to get previous period start date (14 days ago)
  const getPreviousPeriodStartDate = () => {
    const today = new Date();
    return new Date(today.setDate(today.getDate() - 14));
  };

  // Define fetchData outside useEffect so it can be called from handleMapChange
  const fetchData = async (workflowName = null) => {
      try {
        // Use the provided workflow name or the selected map from state
        const workflow = workflowName || selectedMap;
        setLoading(true);
        setError(null); // Reset any previous errors

        // Ensure we're not trying to fetch data for an empty workflow
        if (!workflow) {
          setError('No workflow specified');
          setLoading(false);
          return;
        }
        
        // Map workflow names to their corresponding sample process-map files.
        const workflowDataFiles = {
          'Bed Placement': 'bed_placement_process_map.json',
          'Admissions': 'admissions_process_map.json',
          'Discharges': 'discharges_process_map.json',
          'ED to Inpatient': 'ed_to_inpatient_process_map.json',
        };

        const dataBasePath = '/mock-data';

        // Load the workflow's sample process-map file directly when available.
        if (workflowDataFiles[workflow]) {
          try {
            const response = await fetch(`${dataBasePath}/${workflowDataFiles[workflow]}`);

            if (!response.ok) {
              throw new Error(`Failed to load sample data: ${response.status}`);
            }

            const data = await response.json();
            setProcessData(data);
            setLoading(false);
            return;
          } catch (error) {
            // Fall back to the API if the sample file fails to load.
          }
        }

        // Fall back to the process-mining API, honoring the requested workflow.
        const url = new URL('/improvement/api/nursing-operations', window.location.origin);
        url.searchParams.append('hospital', 'Virtua Mullica Hospital');
        url.searchParams.append('workflow', workflow);
        url.searchParams.append('timeRange', '7 Days');
        url.searchParams.append('format', 'mock_data');
        url.searchParams.append('nodeCount', 100);
        url.searchParams.append('arcCount', 10);
        url.searchParams.append('parallelismFactor', 40);
        url.searchParams.append('frequencyMetric', 'case');
        url.searchParams.append('durationMetric', 'average');

        const response = await fetch(url, {
          headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'Cache-Control': 'no-cache',
          },
        });

        if (!response.ok) {
          throw new Error(`HTTP error! status: ${response.status}`);
        }

        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
          throw new Error(`Expected JSON response but got ${contentType}`);
        }

        const data = await response.json();

        if (!data || !data.nodes || !data.edges) {
          throw new Error('Invalid data structure received from API');
        }

        setProcessData(data);
      } catch (error) {
        const workflow = workflowName || selectedMap;
        setError(`Failed to load process data for ${workflow}: ${error.message}`);
        setProcessData(null); // Clear any previous data
      } finally {
        setLoading(false);
      }
    };

  // Load the selected workflow's process map on mount and whenever it changes.
  useEffect(() => {
    if (selectedMap) {
      fetchData(selectedMap);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedMap]);

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
  
  // Handle timeline range changes
  const handleTimelineChange = (range) => {
    setTimelineRange(range);
    // Timeline range is captured in state for future server-side filtering.
  };

  const handleCloseModal = () => {
    setIsMetricsModalOpen(false);
    setSelectedNode(null);
    setSelectedEdge(null);
  };

  // Render appropriate content based on active tab
  const renderContent = () => {
    if (loading) {
      return (
        <div className="flex items-center justify-center h-64">
          <motion.div 
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            className="animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-healthcare-primary dark:border-healthcare-primary-dark"
          ></motion.div>
          <div className="ml-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            Loading {selectedMap} process map...
          </div>
        </div>
      );
    }

    if (error) {
      return (
        <div className="flex items-center justify-center h-64">
          <motion.div
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
            className="bg-healthcare-critical/10 dark:bg-healthcare-critical-dark/20 border border-healthcare-critical dark:border-healthcare-critical-dark text-healthcare-critical dark:text-healthcare-critical-dark px-4 py-3 rounded relative max-w-xl"
            role="alert"
          >
            <div className="flex items-start">
              <div className="flex-shrink-0">
                <Icon icon="lucide:alert-triangle" className="h-5 w-5 text-healthcare-critical dark:text-healthcare-critical-dark" />
              </div>
              <div className="ml-3">
                <p className="text-sm font-medium mb-2">Error loading {selectedMap} process map</p>
                <p className="text-sm">{error}</p>
                <button 
                  onClick={() => fetchData(selectedMap)}
                  className="mt-3 px-4 py-2 bg-healthcare-primary dark:bg-healthcare-primary-dark text-white rounded hover:bg-healthcare-primary-dark dark:hover:bg-healthcare-primary transition-colors"
                >
                  Retry
                </button>
                <button 
                  className="mt-2 px-3 py-1 text-sm bg-healthcare-critical/10 dark:bg-healthcare-critical-dark/20 rounded"
                  onClick={() => window.location.reload()}
                >
                  Retry
                </button>
              </div>
            </div>
          </motion.div>
        </div>
      );
    }

    // Render content based on active tab
    switch (activeTab) {
      case 'process-map':
        return (
          <Section
            title="Process Maps"
            icon="lucide:map"
            summary={`${selectedMap} workflow`}
            actions={
              <div className="flex items-center space-x-4">
                <div className="flex items-center">
                  <label htmlFor="map-selector" className="mr-2 text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    Map:
                  </label>
                  <select
                    id="map-selector"
                    value={selectedMap}
                    onChange={(e) => handleMapChange(e.target.value)}
                    className="px-3 py-1.5 bg-healthcare-surface dark:bg-healthcare-surface-dark border border-healthcare-border dark:border-healthcare-border-dark rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-healthcare-primary focus:border-transparent text-sm"
                  >
                    {workflows.map((workflow) => (
                      <option key={workflow} value={workflow}>
                        {workflow}
                      </option>
                    ))}
                  </select>
                </div>
                <div className="flex items-center">
                  <label htmlFor="direction-selector" className="mr-2 text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    Direction:
                  </label>
                  <select
                    id="direction-selector"
                    value={flowDirection}
                    onChange={(e) => setFlowDirection(e.target.value)}
                    className="px-3 py-1.5 bg-healthcare-surface dark:bg-healthcare-surface-dark border border-healthcare-border dark:border-healthcare-border-dark rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-healthcare-primary focus:border-transparent text-sm"
                  >
                    <option value="Vertical">Vertical</option>
                    <option value="Horizontal">Horizontal</option>
                  </select>
                </div>
              </div>
            }
          >
            <Panel className="p-4">
              <div className="mb-4 flex justify-center w-full">
                <TimelineSlider
                  onChange={handleTimelineChange}
                />
              </div>
              <div className="relative">
                <ProcessFlowDiagram
                  ref={diagramRef}
                  data={processData}
                  savedLayout={savedLayout}
                  onNodeClick={handleNodeClick}
                  onEdgeClick={handleEdgeClick}
                  workflowName={selectedMap}
                  processType={selectedMap.toLowerCase().replace(/\s+/g, '_')}
                  flowDirection={flowDirection.toLowerCase()}
                />
                {/* Floating action button for resetting layout */}
                <button
                  onClick={() => diagramRef.current?.resetLayout()}
                  className="absolute bottom-4 right-4 p-3 rounded-full bg-healthcare-primary dark:bg-healthcare-primary-dark text-white shadow-lg hover:bg-healthcare-primary-hover dark:hover:bg-healthcare-primary-dark-hover focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-healthcare-primary dark:focus:ring-healthcare-primary-dark transition-colors duration-200"
                  title="Reset Layout"
                >
                  <Icon icon="lucide:refresh-cw" className="h-5 w-5" />
                </button>
              </div>
              <ProcessMetricsModal
                isOpen={isMetricsModalOpen}
                onClose={handleCloseModal}
                selectedNode={selectedNode}
                selectedEdge={selectedEdge}
                overallMetrics={processData?.metrics}
              />
              <ProcessIntelligenceModal
                isOpen={isIntelligenceModalOpen}
                onClose={() => setIsIntelligenceModalOpen(false)}
                metrics={processData?.metrics}
              />
            </Panel>
          </Section>
        );

      case 'variants':
        return (
          <Section title="Process Variants" icon="lucide:git-branch"
                   summary="Variant frequency and pathway comparison">
            <VariantsViewPanel />
          </Section>
        );

      case 'statistics':
        return (
          <Section title="Process Statistics" icon="lucide:bar-chart"
                   summary="Detailed statistics about your processes">
            <Panel className="p-4">
              <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark p-6 rounded-lg">
                <div className="text-center py-12">
                  <Icon icon="lucide:bar-chart" className="h-16 w-16 mx-auto text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark opacity-50" />
                  <h4 className="mt-4 text-xl font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    Process Statistics Coming Soon
                  </h4>
                  <p className="mt-2 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    This feature is currently under development.
                  </p>
                </div>
              </div>
            </Panel>
          </Section>
        );

      case 'optimization':
        return (
          <Section title="Process Optimization" icon="lucide:settings"
                   summary="Identify optimization opportunities in your processes">
            <Panel className="p-4">
              <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark p-6 rounded-lg">
                <div className="text-center py-12">
                  <Icon icon="lucide:settings" className="h-16 w-16 mx-auto text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark opacity-50" />
                  <h4 className="mt-4 text-xl font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    Process Optimization Coming Soon
                  </h4>
                  <p className="mt-2 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    This feature is currently under development.
                  </p>
                </div>
              </div>
            </Panel>
          </Section>
        );
      
      default:
        return (
          <div className="flex items-center justify-center h-64">
            <div className="text-center">
              <Icon icon="lucide:alert-circle" className="h-12 w-12 mx-auto text-healthcare-warning dark:text-healthcare-warning-dark mb-4" />
              <h3 className="text-lg font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                Tab Not Found
              </h3>
              <p className="mt-2 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                The requested tab does not exist.
              </p>
              <button 
                className="mt-4 px-4 py-2 bg-healthcare-primary dark:bg-healthcare-primary-dark text-white rounded-md hover:bg-healthcare-primary-hover dark:hover:bg-healthcare-primary-dark-hover"
                onClick={() => handleTabChange('process-map')}
              >
                Go to Process Maps
              </button>
            </div>
          </div>
        );
    }
  };

  return (
    <DashboardLayout>
      <Head title="Process Analysis - Improvement" />
      
      <div className="flex h-full gap-4 p-4">
        {/* Side Navigation */}
        <div className="w-64 flex flex-col gap-4">
          {/* Process Analysis Navigation */}
          <Panel className="p-2">
            <h2 className="px-2 pt-1 pb-2 text-sm font-semibold uppercase tracking-wide text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
              Process Analysis
            </h2>
            <ul className="space-y-2">
              {sideNavItems.map(item => (
                <li key={item.id}>
                  <button
                    onClick={() => handleTabChange(item.id)}
                    className={`flex items-center w-full px-4 py-3 text-sm font-medium rounded-md transition-all duration-300 ${
                      activeTab === item.id
                        ? 'bg-healthcare-primary/10 dark:bg-healthcare-primary-dark/20 text-healthcare-primary dark:text-healthcare-primary-dark border border-healthcare-primary/30 dark:border-healthcare-primary-dark/30'
                        : 'text-healthcare-text-primary dark:text-healthcare-text-primary-dark hover:bg-healthcare-hover dark:hover:bg-healthcare-hover-dark border border-transparent'
                    }`}
                  >
                    <Icon icon={item.icon} className="w-5 h-5 mr-3" />
                    {item.label}
                  </button>
                </li>
              ))}
            </ul>
          </Panel>
          
          {/* Process Filters Panel */}
          <ProcessFilters
            hospitals={[
              { id: 'marh', name: 'Virtua Marlton Hospital' },
              { id: 'memh', name: 'Virtua Mount Holly Hospital' },
              { id: 'ollh', name: 'Virtua Our Lady of Lourdes Hospital' },
              { id: 'vorh', name: 'Virtua Voorhees Hospital' }
            ]}
            units={[
              { id: 'unit1', name: 'Medical/Surgical', hospitalId: 'marh' },
              { id: 'unit2', name: 'ICU', hospitalId: 'marh' },
              { id: 'unit3', name: 'Emergency', hospitalId: 'memh' },
              { id: 'unit4', name: 'Medical/Surgical', hospitalId: 'ollh' },
              { id: 'unit5', name: 'ICU', hospitalId: 'vorh' }
            ]}
            specialties={[
              { id: 'spec1', name: 'Orthopedics', unitId: 'unit1' },
              { id: 'spec2', name: 'Cardiology', unitId: 'unit2' },
              { id: 'spec3', name: 'General Surgery', unitId: 'unit3' },
              { id: 'spec4', name: 'Neurology', unitId: 'unit4' },
              { id: 'spec5', name: 'Pulmonology', unitId: 'unit5' }
            ]}
            initialFilters={filters}
            onFilterChange={(newFilters) => {
              setFilters(newFilters);
              // You can add additional logic here to fetch data based on filters
            }}
            className="px-2"
          />
        </div>
        
        {/* Main Content */}
        <div className="flex-1 overflow-auto">
          <AnalyticsProvider>
            <motion.div
              initial={{ opacity: 0 }}
              animate={{ opacity: 1 }}
              transition={{ duration: 0.5 }}
              className="h-full"
            >
              {renderContent()}
            </motion.div>
          </AnalyticsProvider>
        </div>
      </div>
    </DashboardLayout>
  );
};

export default Process;
