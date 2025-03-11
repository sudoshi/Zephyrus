import React, { useState } from 'react';
import { Icon } from '@iconify/react';
import Panel from '@/Components/ui/Panel';
import TabNavigation from '@/Components/ui/TabNavigation';
import { useDarkMode } from '@/Layouts/AuthenticatedLayout';
import { getChartTheme } from '@/utils/chartTheme';

const VariantsViewPanel = () => {
  const { isDarkMode } = useDarkMode();
  const [activeTab, setActiveTab] = useState('summary');
  const [filterOpen, setFilterOpen] = useState(false);
  const [sortBy, setSortBy] = useState('frequency');
  const [hospitalFilter, setHospitalFilter] = useState('all');
  const [selectedMap, setSelectedMap] = useState('bed-assignment');
  
  // Mock data for Virtua Health system bed assignment process
  const bedAssignmentStats = {
    totalCases: 287,
    totalVariants: 4,
    meanTime: "3.2 hours",
    medianTime: "2.4 hours",
    modeTime: "1.8 hours",
    stdDev: "2.7 hours",
    successRate: "94%",
    mostCommonVariant: {
      pattern: "ED → Bed Request → Bed Assignment → Bedding",
      cases: 52,
      percentage: 18.1
    }
  };
  
  // Process variants data for visualization - updated to include all 4 input sources + critical pathway
  const processVariants = [
    {
      traces: 118,
      percentage: 41.1,
      steps: [
        { name: "ED", color: "#3b82f6" }, 
        { name: "Bed Request", color: "#93c5fd", timing: "38 min" }, 
        { name: "Bed Assignment", color: "#93c5fd", timing: "42 min" }, 
        { name: "Bedding", color: "#86efac" }
      ],
      timing: "80 min avg",
      isOutlier: false
    },
    {
      traces: 68,
      percentage: 23.7,
      steps: [
        { name: "Direct Admit", color: "#93c5fd" }, 
        { name: "Bed Request", color: "#93c5fd", timing: "42 min" }, 
        { name: "Bed Assignment", color: "#93c5fd", timing: "35 min" }, 
        { name: "Bedding", color: "#86efac" }
      ],
      timing: "77 min avg",
      isOutlier: false
    },
    {
      traces: 45,
      percentage: 15.7,
      steps: [
        { name: "OR", color: "#93c5fd" }, 
        { name: "Bed Request", color: "#93c5fd", timing: "25 min" }, 
        { name: "Bed Assignment", color: "#93c5fd", timing: "30 min" }, 
        { name: "Bedding", color: "#86efac" }
      ],
      timing: "55 min avg",
      isOutlier: false
    },
    {
      traces: 39,
      percentage: 13.6,
      steps: [
        { name: "Transfer", color: "#fbbf24" }, 
        { name: "Bed Request", color: "#fbbf24", timing: "55 min" }, 
        { name: "Bed Assignment", color: "#fbbf24", timing: "90 min" }, 
        { name: "Bedding", color: "#fbbf24" }
      ],
      timing: "145 min avg",
      isOutlier: true
    },
    {
      traces: 17,
      percentage: 5.9,
      steps: [
        { name: "ED (ICU)", color: "#f87171" }, 
        { name: "Bed Request", color: "#f87171", timing: "85 min" }, 
        { name: "Bed Assignment", color: "#f87171", timing: "132 min" }, 
        { name: "Bedding", color: "#f87171" }
      ],
      timing: "217 min avg",
      isOutlier: true,
      highlight: true
    }
  ];
  
  // Hospital-specific data
  const hospitals = [
    {
      name: "Virtua Memorial Hospital",
      bedCount: 320,
      avgTime: "3.7 hours",
      orPercentage: 38,
      caseCount: 68,
      topSource: "OR"
    },
    {
      name: "Virtua Voorhees Hospital",
      bedCount: 398,
      avgTime: "3.2 hours",
      edCount: 1245,
      caseCount: 89,
      topSource: "ED"
    },
    {
      name: "Virtua Marlton Hospital",
      bedCount: 188,
      avgTime: "1.4 hours",
      directAdmissionPercentage: 22,
      caseCount: 56,
      topSource: "Direct Admissions"
    },
    {
      name: "Virtua Willingboro Hospital",
      bedCount: 143,
      avgTime: "3.4 hours",
      directAdmissionPercentage: 24,
      caseCount: 38,
      topSource: "Direct Admissions"
    },
    {
      name: "Virtua Camden Hospital",
      bedCount: 125,
      avgTime: "3.9 hours",
      transferPercentage: 8,
      caseCount: 36,
      topSource: "Transfers"
    }
  ];
  
  // Source pathways data
  const sourcePathways = [
    {
      id: 1,
      source: "Emergency Department",
      pathway: "ED → Bed Request → Bed Assignment → Bedding",
      cases24h: 52,
      percentage: 38,
      avgDuration: "4.3 hours",
      medianDuration: "3.8 hours",
      outlierPercentage: 18
    },
    {
      id: 2,
      source: "Operating Room",
      pathway: "OR → Bed Request → Bed Assignment → Bedding",
      cases24h: 38,
      percentage: 45,
      avgDuration: "2.1 hours",
      medianDuration: "1.9 hours",
      outlierPercentage: 6
    },
    {
      id: 3,
      source: "Direct Admissions",
      pathway: "Direct Admission → Bed Request → Bed Assignment → Bedding",
      cases24h: 32,
      percentage: 62,
      avgDuration: "1.8 hours",
      medianDuration: "1.5 hours",
      outlierPercentage: 4
    },
    {
      id: 4,
      source: "Transfers",
      pathway: "Transfer → Bed Request → Bed Assignment → Bedding",
      cases24h: 9,
      percentage: 68,
      avgDuration: "3.7 hours",
      medianDuration: "3.4 hours",
      outlierPercentage: 22
    }
  ];
  
  // Less common pathway variants
  const otherPathways = [
    {
      id: 5,
      source: "Emergency Department",
      pathway: "ED → Bed Request → Bed Request Cancelled → Bed Request → Bed Assignment → Bedding",
      cases24h: 12,
      percentage: 9,
      avgDuration: "6.8 hours",
      medianDuration: "6.2 hours",
      outlierPercentage: 89
    },
    {
      id: 6,
      source: "Operating Room",
      pathway: "OR → Bed Request → Bed Assignment → Bed Reassigned → Bed Assignment → Bedding",
      cases24h: 8,
      percentage: 10,
      avgDuration: "4.5 hours",
      medianDuration: "4.1 hours",
      outlierPercentage: 75
    },
    {
      id: 7,
      source: "Direct Admissions",
      pathway: "Direct Admission → Patient Delayed → Bed Request → Bed Assignment → Bedding",
      cases24h: 4,
      percentage: 8,
      avgDuration: "5.2 hours",
      medianDuration: "4.9 hours",
      outlierPercentage: 62
    },
    {
      id: 8,
      source: "Transfers",
      pathway: "Transfer → Administrative Review → Bed Request → Bed Assignment → Bedding",
      cases24h: 2,
      percentage: 15,
      avgDuration: "8.3 hours",
      medianDuration: "7.9 hours",
      outlierPercentage: 94
    }
  ];
  
  // Time-based trends
  const timePatterns = {
    dayOfWeek: [
      { day: "Monday", avgTime: "4.1 hours", volume: "High" },
      { day: "Tuesday", avgTime: "3.5 hours", volume: "Medium" },
      { day: "Wednesday", avgTime: "2.6 hours", volume: "Medium" },
      { day: "Thursday", avgTime: "3.1 hours", volume: "Medium" },
      { day: "Friday", avgTime: "3.8 hours", volume: "High" },
      { day: "Saturday", avgTime: "2.9 hours", volume: "Low" },
      { day: "Sunday", avgTime: "2.7 hours", volume: "Low" }
    ],
    timeOfDay: [
      { period: "6am-10am", avgTime: "2.8 hours", volume: "Medium" },
      { period: "10am-2pm", avgTime: "3.4 hours", volume: "High" },
      { period: "2pm-4pm", avgTime: "4.8 hours", volume: "Very High" },
      { period: "4pm-8pm", avgTime: "3.6 hours", volume: "High" },
      { period: "8pm-10pm", avgTime: "2.4 hours", volume: "Medium" },
      { period: "10pm-6am", avgTime: "1.7 hours", volume: "Low" }
    ]
  };
  
  // Outlier characteristics
  const outlierCharacteristics = [
    { factor: "ICU bed requests", percentage: 65 },
    { factor: "Evening shift changes (2-4pm)", percentage: 48 },
    { factor: "Specialty bed requirements", percentage: 37 },
    { factor: "Complex patient conditions", percentage: 29 },
    { factor: "High hospital occupancy (>90%)", percentage: 23 },
    { factor: "Staffing shortages", percentage: 18 }
  ];
  
  // Monthly trend metrics
  const monthlyTrends = {
    overallImprovement: "32%",
    bySource: [
      { source: "ED", improvement: "28%", current: "4.3 hours", previous: "6.0 hours" },
      { source: "OR", improvement: "35%", current: "2.1 hours", previous: "3.2 hours" },
      { source: "Direct Admissions", improvement: "38.59%", current: "1.8 hours", previous: "2.9 hours" },
      { source: "Transfers", improvement: "19%", current: "3.7 hours", previous: "4.6 hours" }
    ]
  };
  
  // Combined pathways for display
  const allPathways = [...sourcePathways, ...(activeTab === 'outliers' ? otherPathways : [])];

  // Handler for changing sort option
  const handleSortChange = (option) => {
    setSortBy(option);
  };

  // Handler for applying filters
  const handleApplyFilters = () => {
    setFilterOpen(false);
    // Implementation would go here to filter data based on selected filters
  };

  // Handler for tab change
  const handleTabChange = (tabId) => {
    setActiveTab(tabId);
  };

  // Define tab menu groups for TabNavigation component
  const menuGroups = [
    {
      title: 'Process Variants',
      items: [
        { id: 'summary', label: 'Summary', icon: 'carbon:summary' },
        { id: 'outliers', label: 'Outliers', icon: 'carbon:warning' },
        { id: 'statistics', label: 'Statistics', icon: 'carbon:chart-line' }
      ]
    }
  ];

  // Map options for dropdown
  const mapOptions = [
    { id: 'bed-assignment', label: 'Bed Assignment' },
    { id: 'or-turnover', label: 'OR Turnover' },
    { id: 'discharge', label: 'Discharge Process' },
    { id: 'lab-results', label: 'Lab Results' }
  ];

  const renderSummaryTab = () => (
    <div className="p-4">
      <div className="grid grid-cols-2 gap-4 mb-6">
        <Panel isSubpanel={true} dropLightIntensity="medium" title="Process Overview" className="bg-blue-50 dark:bg-blue-900/20">
          <div className="grid grid-cols-2 gap-y-2">
            <div className="text-sm text-gray-600 dark:text-gray-300">Total Cases (24h):</div>
            <div className="text-sm font-semibold dark:text-white">{bedAssignmentStats.totalCases}</div>
            <div className="text-sm text-gray-600 dark:text-gray-300">Process Variants:</div>
            <div className="text-sm font-semibold dark:text-white">{bedAssignmentStats.totalVariants}</div>
            <div className="text-sm text-gray-600 dark:text-gray-300">Success Rate:</div>
            <div className="text-sm font-semibold dark:text-white">{bedAssignmentStats.successRate}</div>
            <div className="text-sm text-gray-600 dark:text-gray-300">Most Common Pathway:</div>
            <div className="text-sm font-semibold dark:text-white">ED → Bed (38%)</div>
          </div>
        </Panel>
        
        <Panel isSubpanel={true} dropLightIntensity="medium" title="Time Statistics" className="bg-green-50 dark:bg-green-900/20">
          <div className="grid grid-cols-2 gap-y-2">
            <div className="text-sm text-gray-600 dark:text-gray-300">Mean Duration:</div>
            <div className="text-sm font-semibold dark:text-white">{bedAssignmentStats.meanTime}</div>
            <div className="text-sm text-gray-600 dark:text-gray-300">Median Duration:</div>
            <div className="text-sm font-semibold dark:text-white">{bedAssignmentStats.medianTime}</div>
            <div className="text-sm text-gray-600 dark:text-gray-300">Mode Duration:</div>
            <div className="text-sm font-semibold dark:text-white">{bedAssignmentStats.modeTime}</div>
            <div className="text-sm text-gray-600 dark:text-gray-300">Standard Deviation:</div>
            <div className="text-sm font-semibold dark:text-white">{bedAssignmentStats.stdDev}</div>
          </div>
        </Panel>
      </div>
      
      <Panel isSubpanel={true} dropLightIntensity="medium" title="Bed Assignment Process Variants" className="mb-6 bg-gray-50 dark:bg-gray-800/30">
        <div className="p-4">
          <div className="text-sm text-gray-600 dark:text-gray-300 mb-4 text-center">Common bed assignment pathways by frequency</div>
          <div className="overflow-x-auto">
            <div className="min-w-max">
              <div className="flex justify-between mb-4 ml-2 mr-2">
                <div className="text-xs text-gray-500 dark:text-gray-400 font-medium">Total cases: 287</div>
                <div className="flex space-x-3">
                  <div className="flex items-center">
                    <div className="w-3 h-3 bg-green-500 rounded-full mr-1"></div>
                    <span className="text-xs text-gray-600 dark:text-gray-300">Normal</span>
                  </div>
                  <div className="flex items-center">
                    <div className="w-3 h-3 bg-amber-500 rounded-full mr-1"></div>
                    <span className="text-xs text-gray-600 dark:text-gray-300">Outlier</span>
                  </div>
                  <div className="flex items-center">
                    <div className="w-3 h-3 bg-red-500 rounded-full mr-1"></div>
                    <span className="text-xs text-gray-600 dark:text-gray-300">Critical</span>
                  </div>
                </div>
              </div>
              
              {processVariants.map((variant, index) => (
                <div key={index} className="mb-14">
                  {/* Trace information above the arrows - now larger */}
                  <div className="ml-2 mb-2">
                    <div className="text-base font-semibold text-gray-700 dark:text-gray-100 flex items-center">
                      {variant.traces.toLocaleString()} traces
                      {variant.isOutlier && (
                        <span className="ml-2 px-2 py-0.5 bg-amber-100 dark:bg-amber-900/30 text-amber-800 dark:text-amber-200 rounded text-xs font-bold">
                          OUTLIER
                        </span>
                      )}
                      {variant.highlight && (
                        <span className="ml-2 px-2 py-0.5 bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-200 rounded text-xs font-bold">
                          CRITICAL
                        </span>
                      )}
                    </div>
                    <div className="flex items-center mt-0.5">
                      <div className="text-sm text-gray-600 dark:text-gray-300 font-medium">{variant.percentage.toFixed(1)}% of total</div>
                      {variant.timing && (
                        <div className="text-sm font-bold text-blue-600 dark:text-blue-400 ml-3">{variant.timing}</div>
                      )}
                    </div>
                  </div>
                  <div className="flex items-center">
                    {variant.steps.map((step, stepIndex) => {
                      // Determine colors based on status
                      let bgColor;
                      if (variant.highlight) {
                        // Critical pathway
                        bgColor = step.color; // Already red from the data
                      } else if (variant.isOutlier) {
                        // Outlier pathway
                        if (stepIndex === 0) {
                          bgColor = "#f59e0b"; // Amber for outlier source
                        } else if (stepIndex === variant.steps.length - 1) {
                          bgColor = "#f59e0b"; // Amber for outlier destination
                        } else {
                          bgColor = "#fcd34d"; // Lighter amber for middle steps
                        }
                      } else {
                        // Normal pathway
                        if (stepIndex === 0) {
                          // First step in each variant gets a distinct color by variant type
                          if (step.name.includes("ED")) bgColor = "#3b82f6";
                          else if (step.name.includes("Direct Admit")) bgColor = "#8b5cf6";
                          else if (step.name.includes("Transfer")) bgColor = "#ec4899";
                          else bgColor = step.color;
                        } else if (stepIndex === variant.steps.length - 1) {
                          // Last step in green to indicate completion
                          bgColor = "#10b981";
                        } else {
                          // Middle steps in light blue
                          bgColor = "#93c5fd";
                        }
                      }
                      
                      return (
                        <div key={stepIndex} className="flex items-center">
                          <div 
                            className="h-16 flex flex-col items-center justify-center px-4 relative"
                            style={{
                              backgroundColor: bgColor,
                              clipPath: 'polygon(0% 0%, 90% 0%, 100% 50%, 90% 100%, 0% 100%, 10% 50%)',
                              width: '140px',
                              marginLeft: stepIndex === 0 ? '0' : '-10px',
                              zIndex: variant.steps.length - stepIndex
                            }}
                          >
                            <span className="text-white text-xs font-medium">{step.name}</span>
                            {step.timing && (
                              <span className="text-white text-[10px] mt-1 opacity-90">{step.timing}</span>
                            )}
                            {stepIndex === variant.steps.length - 1 && (
                              <span className="text-white text-xs mt-1 font-bold">{variant.percentage.toFixed(1)}%</span>
                            )}
                          </div>
                        </div>
                      );
                    })}
                  </div>
                </div>
              ))}
            </div>
          </div>
          <div className="text-xs text-gray-500 dark:text-gray-400 mt-4 text-center">
            Figure: Visualization of common bed assignment pathways across Virtua Health System. 
            The most common pathway is direct ED to Bed assignment (38%), followed by Direct Admit pathways (26.1%).
          </div>
          
          <div className="flex justify-end mt-4 mr-2">
            <a href="#" className="text-xs flex items-center text-blue-600 dark:text-blue-400 hover:underline">
              <span>View detailed timing metrics</span>
              <svg className="w-3 h-3 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 5l7 7-7 7"></path>
              </svg>
            </a>
          </div>
        </div>
      </Panel>
      
      <Panel isSubpanel={true} dropLightIntensity="strong" title="Key Process Insights" className="mb-6 bg-purple-50 dark:bg-purple-900/20">
        <ul className="text-sm space-y-2">
          <li className="flex items-start">
            <span className="text-purple-600 dark:text-purple-400 mr-2">•</span>
            <span className="dark:text-white">ED admissions take the longest time on average (4.3 hours), while direct admissions are the most efficient (1.8 hours).</span>
          </li>
          <li className="flex items-start">
            <span className="text-purple-600 dark:text-purple-400 mr-2">•</span>
            <span className="dark:text-white">Peak congestion occurs during the 2-4pm shift change period (4.8 hours average).</span>
          </li>
          <li className="flex items-start">
            <span className="text-purple-600 dark:text-purple-400 mr-2">•</span>
            <span className="dark:text-white">Virtua Marlton Hospital has the fastest average bed assignment time (1.4 hours), 56% better than the system average.</span>
          </li>
          <li className="flex items-start">
            <span className="text-purple-600 dark:text-purple-400 mr-2">•</span>
            <span className="dark:text-white">12% of all cases are considered outliers ({'>'}6 hours), with ICU bed requests being the most common factor (65%).</span>
          </li>
          <li className="flex items-start">
            <span className="text-purple-600 dark:text-purple-400 mr-2">•</span>
            <span className="dark:text-white">Overall system improvement of 32% in assignment times over the past 12 months.</span>
          </li>
        </ul>
      </Panel>
      
      <div className="flex justify-between items-center mb-3">
        <h3 className="text-lg font-semibold dark:text-white">Main Process Pathways</h3>
        <div className="flex space-x-2">
          <button 
            onClick={() => setFilterOpen(!filterOpen)}
            className="flex items-center px-3 py-1 text-sm bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 dark:text-white rounded-md"
          >
            <Icon icon="carbon:filter" className="mr-1" />
            Filter
          </button>
          <div className="relative">
            <button 
              className="flex items-center px-3 py-1 text-sm bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 dark:text-white rounded-md"
              onClick={() => {
                const nextSort = sortBy === 'frequency' ? 'duration' : 
                                 sortBy === 'duration' ? 'source' : 'frequency';
                handleSortChange(nextSort);
              }}
            >
              <Icon icon="carbon:arrows-vertical" className="mr-1" />
              Sort by: {sortBy === 'frequency' ? 'Frequency' : sortBy === 'duration' ? 'Duration' : 'Source'}
            </button>
          </div>
        </div>
      </div>
      
      {filterOpen && (
        <Panel isSubpanel={true} dropLightIntensity="subtle" className="mb-3">
          <div className="grid grid-cols-3 gap-3">
            <div>
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Hospital</label>
              <select 
                className="w-full p-1 border rounded text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                value={hospitalFilter}
                onChange={(e) => setHospitalFilter(e.target.value)}
              >
                <option value="all">All Hospitals</option>
                <option value="memorial">Virtua Memorial</option>
                <option value="voorhees">Virtua Voorhees</option>
                <option value="marlton">Virtua Marlton</option>
                <option value="willingboro">Virtua Willingboro</option>
                <option value="camden">Virtua Camden</option>
              </select>
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Source</label>
              <select className="w-full p-1 border rounded text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                <option>All Sources</option>
                <option>Emergency Department</option>
                <option>Operating Room</option>
                <option>Direct Admissions</option>
                <option>Transfers</option>
              </select>
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Duration</label>
              <select className="w-full p-1 border rounded text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                <option>Any Duration</option>
                <option>{'<'} 2 hours</option>
                <option>2-4 hours</option>
                <option>4-6 hours</option>
                <option>{'>'} 6 hours (Outliers)</option>
              </select>
            </div>
          </div>
          <div className="flex justify-end mt-2">
            <button 
              className="px-3 py-1 bg-blue-600 text-white text-sm rounded-md hover:bg-blue-700"
              onClick={handleApplyFilters}
            >
              Apply Filters
            </button>
          </div>
        </Panel>
      )}
      
      <Panel isSubpanel={true} dropLightIntensity="medium">
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead className="bg-gray-50 dark:bg-gray-800">
              <tr>
                <th scope="col" className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Source & Pathway</th>
                <th scope="col" className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Cases (24h)</th>
                <th scope="col" className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">% of Source</th>
                <th scope="col" className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Avg. Duration</th>
                <th scope="col" className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Median</th>
                <th scope="col" className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">% Outliers</th>
              </tr>
            </thead>
            <tbody className="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
              {sourcePathways.map((pathway) => (
                <tr key={pathway.id} className="hover:bg-gray-50 dark:hover:bg-gray-700">
                  <td className="px-4 py-3 text-sm text-gray-900 dark:text-white">
                    <div className="font-medium">{pathway.source}</div>
                    <div className="text-xs text-gray-500 dark:text-gray-400">{pathway.pathway}</div>
                  </td>
                  <td className="px-4 py-3 text-sm text-gray-900 dark:text-white">{pathway.cases24h}</td>
                  <td className="px-4 py-3 text-sm text-gray-900 dark:text-white">{pathway.percentage}%</td>
                  <td className="px-4 py-3 text-sm text-gray-900 dark:text-white">{pathway.avgDuration}</td>
                  <td className="px-4 py-3 text-sm text-gray-900 dark:text-white">{pathway.medianDuration}</td>
                  <td className="px-4 py-3 text-sm">
                    <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${
                      pathway.outlierPercentage < 10 ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' :
                      pathway.outlierPercentage < 20 ? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200' :
                      'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'
                    }`}>
                      {pathway.outlierPercentage}%
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Panel>
    </div>
  );
  
  const renderOutliersTab = () => (
    <div className="p-4">
      <Panel isSubpanel={true} dropLightIntensity="medium" title="Outlier Analysis" className="mb-6 bg-amber-50 dark:bg-amber-900/20">
        <p className="text-sm mb-3 dark:text-white">Outliers are defined as bed assignments taking {'>'}6 hours (approximately 2 standard deviations above mean). These cases represent 12% of total bed assignments.</p>
        <div className="grid grid-cols-2 gap-4">
          <div>
            <h4 className="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Source Distribution</h4>
            <ul className="text-sm space-y-1">
              <li className="flex justify-between">
                <span className="text-gray-600 dark:text-gray-400">ED Admissions:</span>
                <span className="dark:text-white">18% are outliers</span>
              </li>
              <li className="flex justify-between">
                <span className="text-gray-600 dark:text-gray-400">OR Admissions:</span>
                <span className="dark:text-white">6% are outliers</span>
              </li>
              <li className="flex justify-between">
                <span className="text-gray-600 dark:text-gray-400">Direct Admissions:</span>
                <span className="dark:text-white">4% are outliers</span>
              </li>
              <li className="flex justify-between">
                <span className="text-gray-600 dark:text-gray-400">Transfers:</span>
                <span className="dark:text-white">22% are outliers</span>
              </li>
            </ul>
          </div>
          <div>
            <h4 className="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Contributing Factors</h4>
            <ul className="text-sm space-y-1">
              {outlierCharacteristics.slice(0, 4).map((factor, index) => (
                <li key={index} className="flex justify-between">
                  <span className="text-gray-600 dark:text-gray-400">{factor.factor}:</span>
                  <span className="dark:text-white">{factor.percentage}% of outliers</span>
                </li>
              ))}
            </ul>
          </div>
        </div>
      </Panel>
      
      <Panel isSubpanel={true} dropLightIntensity="medium" title="Hospital-Specific Outlier Rates" className="mb-6 bg-blue-50 dark:bg-blue-900/20">
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-blue-200 dark:divide-blue-800">
            <thead>
              <tr>
                <th className="px-3 py-2 text-left text-xs font-medium text-blue-800 dark:text-blue-300 uppercase tracking-wider">Hospital</th>
                <th className="px-3 py-2 text-left text-xs font-medium text-blue-800 dark:text-blue-300 uppercase tracking-wider">Overall Outlier %</th>
                <th className="px-3 py-2 text-left text-xs font-medium text-blue-800 dark:text-blue-300 uppercase tracking-wider">Top Source</th>
                <th className="px-3 py-2 text-left text-xs font-medium text-blue-800 dark:text-blue-300 uppercase tracking-wider">Avg. Outlier Time</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-blue-100 dark:divide-blue-800">
              <tr>
                <td className="px-3 py-2 text-sm dark:text-white">Virtua Memorial</td>
                <td className="px-3 py-2 text-sm dark:text-white">14%</td>
                <td className="px-3 py-2 text-sm dark:text-white">ED (76%)</td>
                <td className="px-3 py-2 text-sm dark:text-white">8.2 hours</td>
              </tr>
              <tr>
                <td className="px-3 py-2 text-sm dark:text-white">Virtua Voorhees</td>
                <td className="px-3 py-2 text-sm dark:text-white">11%</td>
                <td className="px-3 py-2 text-sm dark:text-white">ED (82%)</td>
                <td className="px-3 py-2 text-sm dark:text-white">7.6 hours</td>
              </tr>
              <tr>
                <td className="px-3 py-2 text-sm dark:text-white">Virtua Marlton</td>
                <td className="px-3 py-2 text-sm dark:text-white">6%</td>
                <td className="px-3 py-2 text-sm dark:text-white">Transfers (54%)</td>
                <td className="px-3 py-2 text-sm dark:text-white">6.8 hours</td>
              </tr>
              <tr>
                <td className="px-3 py-2 text-sm dark:text-white">Virtua Willingboro</td>
                <td className="px-3 py-2 text-sm dark:text-white">15%</td>
                <td className="px-3 py-2 text-sm dark:text-white">Transfers (68%)</td>
                <td className="px-3 py-2 text-sm dark:text-white">9.1 hours</td>
              </tr>
              <tr>
                <td className="px-3 py-2 text-sm dark:text-white">Virtua Camden</td>
                <td className="px-3 py-2 text-sm dark:text-white">18%</td>
                <td className="px-3 py-2 text-sm dark:text-white">Transfers (72%)</td>
                <td className="px-3 py-2 text-sm dark:text-white">9.7 hours</td>
              </tr>
            </tbody>
          </table>
        </div>
      </Panel>
      
      <div className="flex justify-between items-center mb-3">
        <h3 className="text-lg font-semibold dark:text-white">Problematic Pathways</h3>
        <div className="flex space-x-2">
          <button 
            onClick={() => setFilterOpen(!filterOpen)}
            className="flex items-center px-3 py-1 text-sm bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 dark:text-white rounded-md"
          >
            <Icon icon="carbon:filter" className="mr-1" />
            Filter
          </button>
        </div>
      </div>
      
      <Panel isSubpanel={true} dropLightIntensity="medium">
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead className="bg-gray-50 dark:bg-gray-800">
              <tr>
                <th scope="col" className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Source & Complex Pathway</th>
                <th scope="col" className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Cases (24h)</th>
                <th scope="col" className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">% of Source</th>
                <th scope="col" className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Avg. Duration</th>
                <th scope="col" className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Median</th>
                <th scope="col" className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">% Outliers</th>
              </tr>
            </thead>
            <tbody className="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
              {otherPathways.map((pathway) => (
                <tr key={pathway.id} className="hover:bg-gray-50 dark:hover:bg-gray-700">
                  <td className="px-4 py-3 text-sm text-gray-900 dark:text-white">
                    <div className="font-medium">{pathway.source}</div>
                    <div className="text-xs text-gray-500 dark:text-gray-400">{pathway.pathway}</div>
                  </td>
                  <td className="px-4 py-3 text-sm text-gray-900 dark:text-white">{pathway.cases24h}</td>
                  <td className="px-4 py-3 text-sm text-gray-900 dark:text-white">{pathway.percentage}%</td>
                  <td className="px-4 py-3 text-sm text-gray-900 dark:text-white">{pathway.avgDuration}</td>
                  <td className="px-4 py-3 text-sm text-gray-900 dark:text-white">{pathway.medianDuration}</td>
                  <td className="px-4 py-3 text-sm">
                    <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${
                      pathway.outlierPercentage < 10 ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' :
                      pathway.outlierPercentage < 50 ? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200' :
                      'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'
                    }`}>
                      {pathway.outlierPercentage}%
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Panel>
    </div>
  );
  
  const renderStatisticsTab = () => (
    <div className="p-4">
      <div className="grid grid-cols-2 gap-4 mb-6">
        <Panel isSubpanel={true} dropLightIntensity="medium" title="Time of Day Impact" className="bg-indigo-50 dark:bg-indigo-900/20">
          {/* Chart container with proper styling according to standards */}
          <div className="mt-2 p-4 bg-gray-900 rounded-lg">
            {/* This is where a time of day chart would be rendered using the chart theme */}
            <div className="text-center text-white text-sm mb-2">Time of Day Impact Chart</div>
            <div className="text-xs text-gray-300 text-center">Using chartTheme for consistent styling</div>
            {/* Chart would be rendered here with chartTheme applied */}
          </div>
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-indigo-200 dark:divide-indigo-800">
              <thead>
                <tr>
                  <th className="px-3 py-2 text-left text-xs font-medium text-indigo-800 dark:text-indigo-300 uppercase tracking-wider">Time Period</th>
                  <th className="px-3 py-2 text-left text-xs font-medium text-indigo-800 dark:text-indigo-300 uppercase tracking-wider">Avg. Time</th>
                  <th className="px-3 py-2 text-left text-xs font-medium text-indigo-800 dark:text-indigo-300 uppercase tracking-wider">Volume</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-indigo-100 dark:divide-indigo-800">
                {timePatterns.timeOfDay.map((period, index) => (
                  <tr key={index} className={period.volume === 'Very High' ? 'bg-red-50 dark:bg-red-900/20' : ''}>
                    <td className="px-3 py-2 text-sm dark:text-white">{period.period}</td>
                    <td className="px-3 py-2 text-sm dark:text-white">{period.avgTime}</td>
                    <td className="px-3 py-2 text-sm">
                      <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${
                        period.volume === 'Low' ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' :
                        period.volume === 'Medium' ? 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200' :
                        period.volume === 'High' ? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200' :
                        'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'
                      }`}>
                        {period.volume}
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Panel>
        
        <Panel isSubpanel={true} dropLightIntensity="medium" title="Day of Week Impact" className="bg-violet-50 dark:bg-violet-900/20">
          {/* Chart container with proper styling according to standards */}
          <div className="mt-2 p-4 bg-gray-900 rounded-lg">
            {/* This is where a day of week chart would be rendered using the chart theme */}
            <div className="text-center text-white text-sm mb-2">Day of Week Impact Chart</div>
            <div className="text-xs text-gray-300 text-center">Using chartTheme for consistent styling</div>
            {/* Chart would be rendered here with chartTheme applied */}
          </div>
          <div className="overflow-x-auto mt-3">
            <table className="min-w-full divide-y divide-violet-200 dark:divide-violet-800">
              <thead>
                <tr>
                  <th className="px-3 py-2 text-left text-xs font-medium text-violet-800 dark:text-violet-300 uppercase tracking-wider">Day</th>
                  <th className="px-3 py-2 text-left text-xs font-medium text-violet-800 dark:text-violet-300 uppercase tracking-wider">Avg. Time</th>
                  <th className="px-3 py-2 text-left text-xs font-medium text-violet-800 dark:text-violet-300 uppercase tracking-wider">Volume</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-violet-100 dark:divide-violet-800">
                {timePatterns.dayOfWeek.map((day, index) => (
                  <tr key={index}>
                    <td className="px-3 py-2 text-sm dark:text-white">{day.day}</td>
                    <td className="px-3 py-2 text-sm dark:text-white">{day.avgTime}</td>
                    <td className="px-3 py-2 text-sm">
                      <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${
                        day.volume === 'Low' ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' :
                        day.volume === 'Medium' ? 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200' :
                        'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200'
                      }`}>
                        {day.volume}
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Panel>
      </div>
      
      <Panel isSubpanel={true} dropLightIntensity="strong" title="Hospital Performance Comparison" className="mb-6 bg-emerald-50 dark:bg-emerald-900/20">
        {/* Chart container with proper styling according to standards */}
        <div className="mt-2 p-4 bg-gray-900 rounded-lg mb-3">
          {/* This is where a hospital comparison chart would be rendered using the chart theme */}
          <div className="text-center text-white text-sm mb-2">Hospital Performance Comparison Chart</div>
          <div className="text-xs text-gray-300 text-center">Using chartTheme for consistent styling</div>
          {/* Chart would be rendered here with chartTheme applied */}
        </div>
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-emerald-200 dark:divide-emerald-800">
            <thead>
              <tr>
                <th className="px-3 py-2 text-left text-xs font-medium text-emerald-800 dark:text-emerald-300 uppercase tracking-wider">Hospital</th>
                <th className="px-3 py-2 text-left text-xs font-medium text-emerald-800 dark:text-emerald-300 uppercase tracking-wider">Bed Count</th>
                <th className="px-3 py-2 text-left text-xs font-medium text-emerald-800 dark:text-emerald-300 uppercase tracking-wider">Avg. Time</th>
                <th className="px-3 py-2 text-left text-xs font-medium text-emerald-800 dark:text-emerald-300 uppercase tracking-wider">Cases (24h)</th>
                <th className="px-3 py-2 text-left text-xs font-medium text-emerald-800 dark:text-emerald-300 uppercase tracking-wider">Top Source</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-emerald-100 dark:divide-emerald-800">
              {hospitals.map((hospital, index) => (
                <tr key={index}>
                  <td className="px-3 py-2 text-sm font-medium dark:text-white">{hospital.name}</td>
                  <td className="px-3 py-2 text-sm dark:text-white">{hospital.bedCount}</td>
                  <td className="px-3 py-2 text-sm dark:text-white">{hospital.avgTime}</td>
                  <td className="px-3 py-2 text-sm dark:text-white">{hospital.caseCount}</td>
                  <td className="px-3 py-2 text-sm dark:text-white">{hospital.topSource}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Panel>
      
      <Panel isSubpanel={true} dropLightIntensity="medium" title="Monthly Improvement Trends" className="bg-teal-50 dark:bg-teal-900/20">
        <p className="text-sm mb-3 dark:text-white">Overall system improvement: <span className="font-semibold text-teal-600 dark:text-teal-400">{monthlyTrends.overallImprovement}</span> in bed assignment times over the past 12 months.</p>
        
        {/* Chart container with proper styling according to standards */}
        <div className="mt-2 p-4 bg-gray-900 rounded-lg mb-3">
          {/* This is where a monthly trends chart would be rendered using the chart theme */}
          <div className="text-center text-white text-sm mb-2">Monthly Improvement Trends Chart</div>
          <div className="text-xs text-gray-300 text-center">Using chartTheme for consistent styling</div>
          {/* Chart would be rendered here with chartTheme applied */}
        </div>
        
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-teal-200 dark:divide-teal-800">
            <thead>
              <tr>
                <th className="px-3 py-2 text-left text-xs font-medium text-teal-800 dark:text-teal-300 uppercase tracking-wider">Source</th>
                <th className="px-3 py-2 text-left text-xs font-medium text-teal-800 dark:text-teal-300 uppercase tracking-wider">Improvement</th>
                <th className="px-3 py-2 text-left text-xs font-medium text-teal-800 dark:text-teal-300 uppercase tracking-wider">Current Avg</th>
                <th className="px-3 py-2 text-left text-xs font-medium text-teal-800 dark:text-teal-300 uppercase tracking-wider">Previous Avg</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-teal-100 dark:divide-teal-800">
              {monthlyTrends.bySource.map((source, index) => (
                <tr key={index}>
                  <td className="px-3 py-2 text-sm font-medium dark:text-white">{source.source}</td>
                  <td className="px-3 py-2 text-sm font-semibold text-teal-600 dark:text-teal-400">{source.improvement}</td>
                  <td className="px-3 py-2 text-sm dark:text-white">{source.current}</td>
                  <td className="px-3 py-2 text-sm text-gray-500 dark:text-gray-400">{source.previous}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Panel>
    </div>
  );
  
  // Create the Map dropdown for the header right section
  const mapDropdown = (
    <div className="flex items-center">
      <span className="text-sm font-medium text-gray-600 dark:text-gray-300 mr-2">Map:</span>
      <select
        className="text-sm border border-gray-300 rounded-md dark:border-gray-600 dark:bg-gray-700 dark:text-white py-1 px-2"
        value={selectedMap}
        onChange={(e) => setSelectedMap(e.target.value)}
      >
        <option value="bed-assignment">Bed Assignment</option>
        <option value="or-turnover">OR Turnover</option>
        <option value="discharge">Discharge Process</option>
        <option value="lab-results">Lab Results</option>
      </select>
    </div>
  );

  return (
    <Panel title="Process Variants" headerRight={mapDropdown}>
      <TabNavigation
        menuGroups={menuGroups}
        activeTab={activeTab}
        onTabChange={handleTabChange}
      />
      
      <div className="mt-4">
        {activeTab === 'summary' && renderSummaryTab()}
        {activeTab === 'outliers' && renderOutliersTab()}
        {activeTab === 'statistics' && renderStatisticsTab()}
      </div>
    </Panel>
  );
};

export default VariantsViewPanel;
