import React, { useState, useEffect, useRef } from 'react';
import { Head, Link } from '@inertiajs/react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import ProcessFlowDiagram from '@/Components/Process/ProcessFlowDiagram';
import ProcessMetricsModal from '@/Components/Process/ProcessMetricsModal';
import ProcessIntelligenceModal from '@/Components/Process/ProcessIntelligenceModal';
import PatientFlowDashboard from '@/Components/Analytics/PatientFlow/PatientFlowDashboard';
import Panel from '@/Components/ui/Panel';
import { Icon } from '@iconify/react';
import { motion } from 'framer-motion';
import { AnalyticsProvider } from '@/Contexts/AnalyticsContext';
import { useDarkMode } from '@/Layouts/AuthenticatedLayout';
import ProcessFilters from '@/Components/Process/ProcessFilters';
import TimelineSlider from '@/Components/Process/TimelineSlider';
import { workflows } from '@/Components/Process/ProcessSelector';
import VariantsViewPanel from '@/Components/Process/VariantsViewPanel';

// Log the imported workflows for debugging
console.log('Imported workflows in Process.jsx:', workflows);

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
  
  // Reference to track if the component has mounted
  const componentMounted = useRef(false);

  // Initialize with Bed Placement workflow on mount
  useEffect(() => {
    console.log('Component mounting - initializing with Bed Placement workflow');
    
    // Set initial state
    setSelectedMap('Bed Placement');
    setLoading(true);
    
    // Directly load the bed placement data file without using fetchData
    const loadBedPlacementData = async () => {
      try {
        console.log('Directly loading bed_placement_process_map.json');
        const response = await fetch('/mock-data/bed_placement_process_map.json');
        
        if (!response.ok) {
          throw new Error(`Failed to load Bed Placement data: ${response.status}`);
        }
        
        const data = await response.json();
        console.log('Successfully loaded Bed Placement data:', data);
        
        // Validate data structure
        if (!data || !data.nodes || !data.edges) {
          throw new Error('Invalid Bed Placement data structure');
        }
        
        // Set the data and mark component as mounted
        setProcessData(data);
        componentMounted.current = true;
      } catch (error) {
        console.error('Error loading Bed Placement data:', error);
        setError(`Failed to load Bed Placement data: ${error.message}`);
      } finally {
        setLoading(false);
      }
    };
    
    // Execute the data loading
    loadBedPlacementData();
    
    // Cleanup function
    return () => {
      console.log('Process component unmounting');
    };
  }, []);
  
  // Function to handle map selection changes
  const handleMapChange = (mapName) => {
    console.log(`Changing map to: ${mapName}`);
    
    // Only update if the map is actually changing
    if (mapName !== selectedMap) {
      setSelectedMap(mapName);
      // Reset selected elements
      setSelectedNode(null);
      setSelectedEdge(null);
      setIsMetricsModalOpen(false);
      
      // Special handling for Bed Placement to ensure it always loads correctly
      if (mapName === 'Bed Placement') {
        console.log('Special handling for Bed Placement map');
        setLoading(true);
        
        // Direct fetch of the Bed Placement data file
        fetch('/mock-data/bed_placement_process_map.json')
          .then(response => {
            if (!response.ok) {
              throw new Error(`Failed to load Bed Placement data: ${response.status}`);
            }
            return response.json();
          })
          .then(data => {
            console.log('Successfully loaded Bed Placement data directly');
            setProcessData(data);
            setError(null);
          })
          .catch(err => {
            console.error('Error loading Bed Placement data:', err);
            setError(`Failed to load Bed Placement data: ${err.message}`);
            // Fall back to the regular fetchData as a backup
            fetchData('Bed Placement');
          })
          .finally(() => setLoading(false));
      } else {
        // For other maps, use the regular fetchData function
        fetchData(mapName);
      }
    }
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
      // Skip fetch if component is still mounting and we're trying to load Bed Placement
      if (!componentMounted.current && (workflowName === 'Bed Placement' || (!workflowName && selectedMap === 'Bed Placement'))) {
        console.log('Skipping fetchData call during initial mount for Bed Placement');
        return;
      }
      
      try {
        // Use the provided workflow name or the selected map from state
        const workflow = workflowName || selectedMap;
        console.log(`Fetching process map data for workflow: ${workflow}`);
        setLoading(true);
        setError(null); // Reset any previous errors
        
        // Debug log to track fetch calls
        console.log(`Process map fetch initiated for: ${workflow} at ${new Date().toISOString()}`);
        
        // Ensure we're not trying to fetch data for an empty workflow
        if (!workflow) {
          console.error('No workflow specified for fetchData');
          setError('No workflow specified');
          setLoading(false);
          return;
        }
        
        // Map workflow names to their corresponding sample data files
        const workflowDataFiles = {
          'Bed Placement': 'bed_placement_process_map.json',
          'Admissions': 'admissions_process_map.json',
          'Discharges': 'discharges_process_map.json',
          'ED to Inpatient': 'ed_to_inpatient_process_map.json'
        };
        
        // Define the base path for process map data files
        const dataBasePath = '/mock-data';
        
        // Check if we have a sample data file for this workflow
        if (workflowDataFiles[workflow]) {
          console.log(`Loading sample data for ${workflow} workflow`);
          
          try {
            // Load the sample data file directly
            const response = await fetch(`${dataBasePath}/${workflowDataFiles[workflow]}`);
            
            if (!response.ok) {
              throw new Error(`Failed to load sample data: ${response.status}`);
            }
            
            const data = await response.json();
            console.log(`Loaded sample ${workflow} data:`, data);
            setProcessData(data);
            setLoading(false);
            return;
          } catch (error) {
            console.error(`Error loading sample ${workflow} data:`, error);
            // Fall back to API if sample data fails to load
          }
        }
        
        // For other workflows or if sample data failed to load, use the API
        // Include process mining parameters in the API request
        const url = new URL('/improvement/api/nursing-operations', window.location.origin);
        
        // Add default parameters
        url.searchParams.append('hospital', 'Virtua Mullica Hospital');
        url.searchParams.append('workflow', workflow);
        url.searchParams.append('timeRange', '7 Days');
        
        // Force all workflows to use the mock data format for consistency
        url.searchParams.append('format', 'mock_data');
        console.log(`Using mock data format for all workflows including ${workflow}`);
        
        // Add default process mining parameters
        url.searchParams.append('nodeCount', 100);
        url.searchParams.append('arcCount', 10);
        url.searchParams.append('parallelismFactor', 40);
        url.searchParams.append('frequencyMetric', 'case');
        url.searchParams.append('durationMetric', 'average');
        
        console.log(`API request URL: ${url.toString()}`);
        
        const response = await fetch(url, {
          headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'Cache-Control': 'no-cache'
          }
        });
        
        if (!response.ok) {
          console.error(`API response not OK: ${response.status} ${response.statusText}`);
          throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
          console.error(`Unexpected content type: ${contentType}`);
          throw new Error(`Expected JSON response but got ${contentType}`);
        }
        
        const data = await response.json();
        console.log(`Received data for ${selectedMap}:`, data);
        
        if (!data || !data.nodes || !data.edges) {
          console.error('Invalid data structure received:', data);
          throw new Error('Invalid data structure received from API');
        }
        
        // Validate the data structure for all process maps
        console.log(`Validating data structure for ${selectedMap}...`);
        
        // Check if the first node has the expected structure
        const firstNode = data.nodes[0];
        if (firstNode) {
          console.log('First node structure:', firstNode);
          
          // Log the data format for debugging
          const hasLabel = 'label' in firstNode || (firstNode.data && 'label' in firstNode.data);
          const hasType = 'type' in firstNode;
          const hasPosition = 'position' in firstNode;
          
          console.log('Data format validation:', {
            hasLabel,
            hasType,
            hasPosition,
            nodeCount: data.nodes.length,
            edgeCount: data.edges.length
          });
        }
        
        setProcessData(data);
        console.log(`Successfully loaded ${selectedMap} process map with ${data.nodes.length} nodes and ${data.edges.length} edges`);
      } catch (error) {
        const workflow = workflowName || selectedMap;
        console.error(`Error fetching data for ${workflow}:`, error);
        setError(`Failed to load process data for ${workflow}: ${error.message}`);
        setProcessData(null); // Clear any previous data
        
        // Log additional debugging information
        console.error('Error details:', {
          workflow: workflow,
          url: url?.toString(),
          error: error.stack || error.message
        });
      } finally {
        setLoading(false);
      }
    };

  // Handle workflow changes after initial mount
  useEffect(() => {
    // Only respond to changes after component has mounted and initial data is loaded
    if (componentMounted.current && selectedMap) {
      console.log(`Workflow changed to: ${selectedMap}, fetching data`);
      fetchData(selectedMap);
    }
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
    // Here you would typically filter your process data based on the selected time range
    console.log(`Timeline range updated: ${range[0].toLocaleTimeString()} - ${range[1].toLocaleTimeString()}`);
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
            className="bg-red-100 dark:bg-red-900 border border-red-400 dark:border-red-700 text-red-700 dark:text-red-200 px-4 py-3 rounded relative max-w-xl"
            role="alert"
          >
            <div className="flex items-start">
              <div className="flex-shrink-0">
                <Icon icon="lucide:alert-triangle" className="h-5 w-5 text-red-400" />
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
                  className="mt-2 px-3 py-1 text-sm bg-red-200 dark:bg-red-800 rounded"
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
          <Panel 
            dropLightIntensity="medium"
            title="Process Maps"
            headerRight={
              <div className="flex items-center space-x-4">
                <div className="flex items-center">
                  <label htmlFor="map-selector" className="mr-2 text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    Map:
                  </label>
                  <select
                    id="map-selector"
                    value={selectedMap}
                    onChange={(e) => {
                      console.log(`Changing selected map from ${selectedMap} to ${e.target.value}`);
                      handleMapChange(e.target.value);
                    }}
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
            headerContent={
              <div className="flex justify-center w-full">
                <TimelineSlider
                  onChange={handleTimelineChange}
                />
              </div>
            }
          >

            

            

            

            
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
        );
      
      case 'variants':
        return <VariantsViewPanel />;
      
      case 'statistics':
        return (
          <Panel title="Process Statistics" dropLightIntensity="medium">
            <div className="p-4">
              <h3 className="text-lg font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-4">
                Process Statistics
              </h3>
              <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-6">
                View detailed statistics about your processes.
              </p>
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
            </div>
          </Panel>
        );
      
      case 'optimization':
        return (
          <Panel title="Process Optimization" dropLightIntensity="medium">
            <div className="p-4">
              <h3 className="text-lg font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-4">
                Process Optimization
              </h3>
              <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-6">
                Identify optimization opportunities in your processes.
              </p>
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
            </div>
          </Panel>
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
          <Panel title="Process Analysis" className="rounded-lg overflow-hidden">
            <ul className="space-y-2 p-2">
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
