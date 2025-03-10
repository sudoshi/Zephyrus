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
import { workflows } from '@/Components/Process/ProcessSelector';

const Process = ({ auth, savedLayout }) => {
  const [processData, setProcessData] = useState(null);
  const [selectedNode, setSelectedNode] = useState(null);
  const [selectedEdge, setSelectedEdge] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [isMetricsModalOpen, setIsMetricsModalOpen] = useState(false);
  const [isIntelligenceModalOpen, setIsIntelligenceModalOpen] = useState(false);
  const [activeTab, setActiveTab] = useState('process-map');
  const [selectedMap, setSelectedMap] = useState('Admissions');
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

  useEffect(() => {
    const fetchData = async () => {
      try {
        // Include process mining parameters in the API request
        const url = new URL('/improvement/api/nursing-operations', window.location.origin);
        
        // Add default parameters
        url.searchParams.append('hospital', 'Virtua Mullica Hospital');
        url.searchParams.append('workflow', selectedMap);
        url.searchParams.append('timeRange', '7 Days');
        
        // Add default process mining parameters
        url.searchParams.append('nodeCount', 100);
        url.searchParams.append('arcCount', 10);
        url.searchParams.append('parallelismFactor', 40);
        url.searchParams.append('frequencyMetric', 'case');
        url.searchParams.append('durationMetric', 'average');
        
        const response = await fetch(url);
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
        </div>
      );
    }

    if (error) {
      return (
        <div className="flex items-center justify-center h-64">
          <motion.div
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
            className="bg-red-100 dark:bg-red-900 border border-red-400 dark:border-red-700 text-red-700 dark:text-red-200 px-4 py-3 rounded relative"
            role="alert"
          >
            <div className="flex items-start">
              <div className="flex-shrink-0">
                <Icon icon="lucide:alert-triangle" className="h-5 w-5 text-red-400" />
              </div>
              <div className="ml-3">
                <p className="text-sm">{error}</p>
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
            title="Process Maps" 
            dropLightIntensity="medium"
            headerRight={
              <div className="flex items-center">
                <label htmlFor="map-selector" className="mr-2 text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                  Map:
                </label>
                <select
                  id="map-selector"
                  value={selectedMap}
                  onChange={(e) => setSelectedMap(e.target.value)}
                  className="px-3 py-1.5 bg-healthcare-surface dark:bg-healthcare-surface-dark border border-healthcare-border dark:border-healthcare-border-dark rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-healthcare-primary focus:border-transparent text-sm"
                >
                  {workflows.map((workflow) => (
                    <option key={workflow} value={workflow}>
                      {workflow}
                    </option>
                  ))}
                </select>
              </div>
            }
          >
            <div className="grid grid-cols-1 lg:grid-cols-4 gap-4 mb-4">
              {/* Process Summary Panel */}
              <Panel title="Process Summary" className="lg:col-span-2" isSubpanel={true} dropLightIntensity="subtle">
                <div className="p-4 grid grid-cols-2 md:grid-cols-4 gap-4">
                  <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark p-3 rounded-lg">
                    <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-1">Cases</div>
                    <div className="text-xl font-bold text-healthcare-primary dark:text-healthcare-primary-dark">{processData?.metrics?.totalCases || 0}</div>
                  </div>
                  <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark p-3 rounded-lg">
                    <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-1">Activities</div>
                    <div className="text-xl font-bold text-healthcare-info dark:text-healthcare-info-dark">{processData?.nodes?.length || 0}</div>
                  </div>
                  <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark p-3 rounded-lg">
                    <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-1">Avg Duration</div>
                    <div className="text-xl font-bold text-healthcare-success dark:text-healthcare-success-dark">{processData?.metrics?.avgDuration || '0h'}</div>
                  </div>
                  <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark p-3 rounded-lg">
                    <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-1">Variants</div>
                    <div className="text-xl font-bold text-healthcare-purple dark:text-healthcare-purple-dark">{processData?.metrics?.variantCount || 0}</div>
                  </div>
                </div>
              </Panel>
              
              {/* Performance Panel */}
              <Panel title="Performance Metrics" className="lg:col-span-2" isSubpanel={true} dropLightIntensity="subtle">
                <div className="p-4 grid grid-cols-2 md:grid-cols-4 gap-4">
                  <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark p-3 rounded-lg">
                    <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-1">Bottlenecks</div>
                    <div className="text-xl font-bold text-healthcare-warning dark:text-healthcare-warning-dark">{processData?.metrics?.bottleneckCount || 0}</div>
                  </div>
                  <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark p-3 rounded-lg">
                    <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-1">Rework</div>
                    <div className="text-xl font-bold text-healthcare-danger dark:text-healthcare-danger-dark">{processData?.metrics?.reworkPercentage || '0%'}</div>
                  </div>
                  <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark p-3 rounded-lg">
                    <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-1">Throughput</div>
                    <div className="text-xl font-bold text-healthcare-success dark:text-healthcare-success-dark">{processData?.metrics?.throughput || '0/day'}</div>
                  </div>
                  <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark p-3 rounded-lg">
                    <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-1">Compliance</div>
                    <div className="text-xl font-bold text-healthcare-info dark:text-healthcare-info-dark">{processData?.metrics?.complianceRate || '0%'}</div>
                  </div>
                </div>
              </Panel>
            </div>
            
            <div className="relative mt-4">
              <ProcessFlowDiagram 
                ref={diagramRef}
                data={processData} 
                savedLayout={savedLayout}
                onNodeClick={handleNodeClick}
                onEdgeClick={handleEdgeClick}
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
        return (
          <Panel title="Process Variants" dropLightIntensity="medium">
            <div className="p-4">
              <h3 className="text-lg font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-4">
                Process Variants Analysis
              </h3>
              <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-6">
                Analyze different process variants and their frequencies.
              </p>
              <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark p-6 rounded-lg">
                <div className="text-center py-12">
                  <Icon icon="lucide:git-branch" className="h-16 w-16 mx-auto text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark opacity-50" />
                  <h4 className="mt-4 text-xl font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    Process Variants Coming Soon
                  </h4>
                  <p className="mt-2 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    This feature is currently under development.
                  </p>
                </div>
              </div>
            </div>
          </Panel>
        );
      
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
