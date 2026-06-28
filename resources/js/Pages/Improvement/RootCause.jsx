import React, { useState, useRef, useEffect } from 'react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { Head } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { Button } from '@/Components/ui/button';
import { Section, Panel } from '@/Components/system';
import Input from '@/Components/ui/input';
import { DatePicker } from '@/Components/ui/flowbite/DatePicker';
import Textarea from '@/Components/ui/textarea';
import { NETWORK_FACILITY_NAMES } from '@/constants/summitHospital';

// Process types with their respective colors
const PROCESS_TYPES = {
  'Reported Barriers': {
    color: 'bg-red-500',
    textColor: 'text-red-500',
    borderColor: 'border-red-500',
    darkColor: 'dark:bg-red-600',
    darkTextColor: 'dark:text-red-400',
    darkBorderColor: 'dark:border-red-600',
    examples: [
      'Medication Administration Delays',
      'Nurse Staffing Shortages',
      'Equipment Availability Issues',
      'Communication Breakdowns',
      'Documentation Burden'
    ]
  },
  'Admission Process': {
    color: 'bg-blue-500',
    textColor: 'text-blue-500',
    borderColor: 'border-blue-500',
    darkColor: 'dark:bg-blue-600',
    darkTextColor: 'dark:text-blue-400',
    darkBorderColor: 'dark:border-blue-600',
    examples: [
      'ED to Inpatient Handoff',
      'Bed Assignment Delays',
      'Insurance Verification',
      'Initial Assessment Workflow',
      'Admission Order Set Usage'
    ]
  },
  'Discharge Process': {
    color: 'bg-green-500',
    textColor: 'text-green-500',
    borderColor: 'border-green-500',
    darkColor: 'dark:bg-green-600',
    darkTextColor: 'dark:text-green-400',
    darkBorderColor: 'dark:border-green-600',
    examples: [
      'Discharge Medication Reconciliation',
      'Transportation Coordination',
      'Follow-up Appointment Scheduling',
      'Discharge Summary Completion',
      'Patient Education Process'
    ]
  },
  'Perioperative Process': {
    color: 'bg-purple-500',
    textColor: 'text-purple-500',
    borderColor: 'border-purple-500',
    darkColor: 'dark:bg-purple-600',
    darkTextColor: 'dark:text-purple-400',
    darkBorderColor: 'dark:border-purple-600',
    examples: [
      'OR Turnover Time',
      'Surgical Case Scheduling',
      'Pre-op to OR Handoff',
      'Anesthesia Workflow',
      'PACU Transfer Delays'
    ]
  },
  'Patient Flow': {
    color: 'bg-amber-500',
    textColor: 'text-amber-500',
    borderColor: 'border-amber-500',
    darkColor: 'dark:bg-amber-600',
    darkTextColor: 'dark:text-amber-400',
    darkBorderColor: 'dark:border-amber-600',
    examples: [
      'ED Throughput Bottlenecks',
      'Inpatient Unit Transfers',
      'Diagnostic Testing Delays',
      'Consultation Wait Times',
      'Bed Turnover Efficiency'
    ]
  },
  'Medication Process': {
    color: 'bg-teal-500',
    textColor: 'text-teal-500',
    borderColor: 'border-teal-500',
    darkColor: 'dark:bg-teal-600',
    darkTextColor: 'dark:text-teal-400',
    darkBorderColor: 'dark:border-teal-600',
    examples: [
      'Medication Order to Administration',
      'High-Alert Medication Handling',
      'Pharmacy Turnaround Time',
      'Medication Reconciliation',
      'Automated Dispensing Issues'
    ]
  }
};

// Hospital locations
const HOSPITAL_LOCATIONS = NETWORK_FACILITY_NAMES;

// OCEL 2.0 Object Types for Healthcare
const OBJECT_TYPES = [
  'Patient',
  'Nurse',
  'Physician',
  'Bed',
  'Medication',
  'Location',
  'Equipment',
  'Test',
  'Document'
];

// Status categories
const STATUS_CATEGORIES = ['New', 'In-Progress', 'Completed'];

// Helper function to get icon for object type
const getObjectTypeIcon = (type) => {
  switch (type) {
    case 'Patient':
      return 'healthicons:patient-boy';
    case 'Nurse':
      return 'healthicons:nurse';
    case 'Physician':
      return 'healthicons:doctor-male';
    case 'Bed':
      return 'healthicons:stretcher';
    case 'Medication':
      return 'healthicons:medicines';
    case 'Location':
      return 'healthicons:hospital';
    case 'Equipment':
      return 'healthicons:medical-equipment';
    case 'Test':
      return 'healthicons:lab-sample';
    case 'Document':
      return 'healthicons:clinical-documents';
    default:
      return 'healthicons:ui-menu';
  }
};

// Process item component
const ProcessItem = ({ item, onClick, isSelected }) => {
  const processTypeObj = PROCESS_TYPES[item.type];
  
  return (
    <div 
      className={`p-4 mb-3 rounded-lg cursor-pointer transition-all shadow-sm ${
        isSelected 
          ? `${processTypeObj.color} ${processTypeObj.darkColor} text-white shadow-md`
          : `bg-healthcare-surface dark:bg-healthcare-surface-dark border-2 ${processTypeObj.borderColor} ${processTypeObj.darkBorderColor} hover:bg-healthcare-background dark:hover:bg-healthcare-background-dark`
      }`}
      onClick={() => onClick(item)}
    >
      <div className="flex justify-between items-start mb-2">
        <h3 className={`font-semibold text-md ${isSelected ? 'text-white' : `${processTypeObj.textColor} ${processTypeObj.darkTextColor}`}`}>
          {item.title}
        </h3>
        <span className={`text-xs px-2 py-1 rounded-full font-medium ${
          isSelected 
            ? 'bg-white bg-opacity-30 text-white'
            : 'bg-healthcare-background dark:bg-healthcare-background-dark text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark'
        }`}>
          {item.status}
        </span>
      </div>
      <p className={`text-sm mb-2 ${isSelected ? 'text-white text-opacity-90' : 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark'}`}>
        <span className="font-medium">Location:</span> {item.location}
      </p>
      <p className={`text-xs ${isSelected ? 'text-white text-opacity-80' : 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark'}`}>
        <span className="font-medium">Date:</span> {new Date(item.date).toLocaleDateString()}
      </p>
      
      {/* OCEL Related Objects */}
      {item.relatedObjects && item.relatedObjects.length > 0 && (
        <div className={`flex flex-wrap gap-1 mt-2 pt-2 ${isSelected ? 'border-t border-white border-opacity-20' : 'border-t border-healthcare-border dark:border-healthcare-border-dark'}`}>
          {item.relatedObjects.map((obj, idx) => (
            <div 
              key={idx} 
              className={`flex items-center rounded-full px-2 py-1 text-xs ${
                isSelected
                  ? 'bg-white bg-opacity-20'
                  : 'bg-healthcare-background dark:bg-healthcare-background-dark'
              }`}
            >
              <Icon 
                icon={getObjectTypeIcon(obj.type)} 
                className={`w-3 h-3 mr-1 ${
                  isSelected 
                    ? 'text-white' 
                    : 'text-healthcare-primary dark:text-healthcare-primary-dark'
                }`} 
              />
              <span>{obj.type}</span>
            </div>
          ))}
        </div>
      )}
    </div>
  );
};

// Chat message component
const ChatMessage = ({ message, isUser }) => {
  // Function to convert newlines to <br> tags
  const formatMessage = (text) => {
    return text.split('\n').map((line, i) => (
      <React.Fragment key={i}>
        {line}
        {i < text.split('\n').length - 1 && <br />}
      </React.Fragment>
    ));
  };

  return (
    <div className={`flex mb-4 ${isUser ? 'justify-end' : 'justify-start'}`}>
      <div
        className={`max-w-3/4 rounded-lg p-3 ${
          isUser
            ? 'bg-healthcare-primary text-white dark:bg-healthcare-primary-dark'
            : 'bg-healthcare-background text-healthcare-text-primary dark:bg-healthcare-background-dark dark:text-healthcare-text-primary-dark'
        }`}
      >
        <p className="text-sm whitespace-pre-line">{formatMessage(message)}</p>
      </div>
    </div>
  );
};

// Deterministic helpers — no Math.random, so the same prop always renders the
// same items across mounts. A stable string hash drives every "spread" choice.
const stableHash = (str) => {
  let h = 0;
  for (let i = 0; i < str.length; i += 1) {
    h = (h * 31 + str.charCodeAt(i)) | 0;
  }
  return Math.abs(h);
};

// Map a root-cause row's free-text type onto one of the canonical PROCESS_TYPES
// keys so ProcessItem can resolve its color/label safely.
const mapToProcessType = (rawType) => {
  const t = (rawType || '').toLowerCase();
  if (t.includes('discharge') || t.includes('documentation')) return 'Discharge Process';
  if (t.includes('or ') || t.includes('pacu') || t.includes('perioperative') || t.includes('surg')) return 'Perioperative Process';
  if (t.includes('admission') || t.includes('ed to') || t.includes('inpatient')) return 'Admission Process';
  if (t.includes('medication') || t.includes('pharmacy')) return 'Medication Process';
  if (t.includes('barrier')) return 'Reported Barriers';
  return 'Patient Flow';
};

// Deterministically assign a workflow status from a seed so the New/In-Progress/
// Completed columns are stable across mounts.
const stableStatus = (seed) => STATUS_CATEGORIES[stableHash(`status:${seed}`) % STATUS_CATEGORIES.length];

// Deterministically map the server-provided `rootCauses` array onto the OCEL
// process-item shape the page renders. Every field is derived from the row
// itself (rank, type, causes, metrics) via a stable hash — no Math.random — so
// the New/In-Progress/Completed columns and OCEL insights are identical on every
// mount. Safe on an empty array (returns []).
const rootCausesToItems = (rootCauses) => {
  return (rootCauses || []).map((rc, i) => {
    const seed = `${rc.rank ?? i}:${rc.type ?? ''}`;
    const hash = stableHash(seed);
    const type = mapToProcessType(rc.type);
    const causes = Array.isArray(rc.causes) ? rc.causes : [];
    const metrics = Array.isArray(rc.metrics) ? rc.metrics : [];

    // 3 stable related objects drawn from OBJECT_TYPES, offset by the seed hash.
    const relatedObjects = [0, 1, 2].map((j) => {
      const objectType = OBJECT_TYPES[(hash + j * 3) % OBJECT_TYPES.length];
      return { type: objectType, id: `${objectType.toLowerCase()}_${(hash % 900) + 100 + j}` };
    });

    const patientCount = rc.impactedPatients ?? ((hash % 40) + 10);
    const details =
      `Impacts ${patientCount} patients with an average delay of ${rc.avgDelay ?? 'n/a'}. ` +
      `${rc.impactDetails ?? 'Downstream flow effects observed.'}`;

    // Stable date inside the last ~6 months, derived from rank so it is filterable.
    const date = new Date(2026, 0, 1 + ((rc.rank ?? i) * 23) % 170).toISOString();

    return {
      id: rc.rank ?? i,
      title: rc.type ?? PROCESS_TYPES[type].examples[hash % PROCESS_TYPES[type].examples.length],
      type,
      location: rc.location ?? 'Hospital-wide',
      date,
      status: stableStatus(seed),
      details,
      score: typeof rc.score === 'number' ? rc.score : (hash % 100) / 10,
      relatedObjects,
      ocelData: {
        eventCount: 100 + (hash % 900),
        objectInteractions: 10 + (hash % 50),
        averagePathLength: `${(2 + (hash % 50) / 10).toFixed(1)} hours`,
        commonPathways: [
          `${rc.location ?? 'Unit'} → Assessment → Order Processing`,
          `${rc.location ?? 'Unit'} → Handoff → Disposition`,
        ],
        // Surface the real causes as bottleneck activities when present.
        bottleneckActivities: causes.length > 0 ? causes : ['Documentation', 'Handoff Communication'],
        keyMetrics: metrics,
      },
    };
  });
};

// Main RootCause component
const RootCause = ({ rootCauses = [] }) => {
  // State for filters
  const [selectedLocation, setSelectedLocation] = useState('');
  const [selectedType, setSelectedType] = useState('');
  
  // Set default date range to show all items from the past month
  const defaultEndDate = new Date();
  const defaultStartDate = new Date();
  defaultStartDate.setMonth(defaultStartDate.getMonth() - 1); // One month ago
  
  const [startDate, setStartDate] = useState(defaultStartDate);
  const [endDate, setEndDate] = useState(defaultEndDate);
  
  // State for tabs
  const [activeTab, setActiveTab] = useState('New');
  
  // State for items
  const [newItems, setNewItems] = useState([]);
  const [inProgressItems, setInProgressItems] = useState([]);
  const [completedItems, setCompletedItems] = useState([]);
  
  // State for filtered items
  const [filteredNewItems, setFilteredNewItems] = useState([]);
  const [filteredInProgressItems, setFilteredInProgressItems] = useState([]);
  const [filteredCompletedItems, setFilteredCompletedItems] = useState([]);
  
  // State for selected item
  const [selectedItem, setSelectedItem] = useState(null);
  
  // State for chat
  const [messages, setMessages] = useState([
    { text: "Hello! I'm your AI assistant for process analysis. How can I help you today?", isUser: false }
  ]);
  const [inputValue, setInputValue] = useState('');
  const messagesEndRef = useRef(null);
  
  // State for analysis
  const [analysis, setAnalysis] = useState('');
  
  // Scroll to bottom of messages
  const scrollToBottom = () => {
    messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
  };
  
  // Apply filters to items
  const applyFilters = (items) => {
    return items.filter(item => {
      // Filter by location if selected
      if (selectedLocation && item.location !== selectedLocation) {
        return false;
      }
      
      // Filter by type if selected
      if (selectedType && item.type !== selectedType) {
        return false;
      }
      
      // Filter by date range if dates are valid
      if (startDate && endDate) {
        const itemDate = new Date(item.date);
        // Add one day to endDate to include the end date in the range
        const adjustedEndDate = new Date(endDate);
        adjustedEndDate.setDate(adjustedEndDate.getDate() + 1);
        
        if (itemDate < startDate || itemDate > adjustedEndDate) {
          return false;
        }
      }
      
      return true;
    });
  };
  
  // Update filtered items when filters or items change
  useEffect(() => {
    const filteredNewItems = applyFilters(newItems);
    const filteredInProgressItems = applyFilters(inProgressItems);
    const filteredCompletedItems = applyFilters(completedItems);
    
    setFilteredNewItems(filteredNewItems);
    setFilteredInProgressItems(filteredInProgressItems);
    setFilteredCompletedItems(filteredCompletedItems);
  }, [selectedLocation, selectedType, startDate, endDate, newItems, inProgressItems, completedItems]);
  
  // Derive process items deterministically from the server `rootCauses` prop.
  // No per-mount randomization: the same prop always produces the same items,
  // so the columns are stable across navigations/re-renders. Safe when empty.
  useEffect(() => {
    const items = rootCausesToItems(rootCauses);

    const newItems = items.filter(item => item.status === 'New');
    const inProgressItems = items.filter(item => item.status === 'In-Progress');
    const completedItems = items.filter(item => item.status === 'Completed');

    setNewItems(newItems);
    setInProgressItems(inProgressItems);
    setCompletedItems(completedItems);

    // Also update the filtered items initially
    setFilteredNewItems(newItems);
    setFilteredInProgressItems(inProgressItems);
    setFilteredCompletedItems(completedItems);
  }, [rootCauses]);
  
  // Helper function to generate random recommendations based on process type
  const getRandomRecommendation = (processType) => {
    const recommendations = {
      'Reported Barriers': [
        'Implement a standardized handoff protocol to reduce communication breakdowns',
        'Develop a resource allocation system that responds to real-time staffing needs',
        'Create dedicated time blocks for documentation to reduce administrative burden',
        'Implement mobile documentation solutions to reduce time spent at workstations',
        'Establish equipment tracking system to improve availability'
      ],
      'Admission Process': [
        'Implement parallel processing for admission tasks to reduce sequential delays',
        'Create a dedicated quick-admission pathway for common case types',
        'Establish electronic bed management system with real-time updates',
        'Develop standardized admission order sets for common conditions',
        'Implement a pre-arrival data collection process to reduce admission time'
      ],
      'Discharge Process': [
        'Begin discharge planning at admission with estimated discharge dates',
        'Implement medication reconciliation earlier in the patient stay',
        'Create a discharge pharmacy with dedicated staff for discharge medications',
        'Develop transportation scheduling system integrated with discharge process',
        'Implement discharge lounges to free up beds while patients wait for transportation'
      ],
      'Perioperative Process': [
        'Implement parallel room turnover teams to reduce OR downtime',
        'Standardize instrument trays for common procedures to reduce setup time',
        'Create dedicated fast-track pathways for routine cases',
        'Implement real-time OR schedule management with automated updates',
        'Develop pre-operative optimization protocols to reduce day-of-surgery cancellations'
      ],
      'Patient Flow': [
        'Implement a centralized patient flow command center with real-time tracking',
        'Create dedicated pathways for high-volume patient types',
        'Develop predictive analytics for patient volume and staffing needs',
        'Implement direct-to-room protocols for ED patients requiring admission',
        'Create flexible overflow capacity that can be activated during peak periods'
      ],
      'Medication Process': [
        'Implement barcode medication administration to reduce errors',
        'Create dedicated medication nurses during peak administration times',
        'Develop automated dispensing systems with priority queuing',
        'Implement standardized high-alert medication protocols',
        'Create a closed-loop medication system from ordering to administration'
      ]
    };
    
    const typeRecommendations = recommendations[processType] || recommendations['Reported Barriers'];
    return typeRecommendations[Math.floor(Math.random() * typeRecommendations.length)];
  };
  
  // Helper function to generate AI responses based on the selected item
  const generateAIResponse = (item) => {
    // Add a message from the AI about the selected item with OCEL insights
    let message = `I've loaded the ${item.type} process "${item.title}" from ${item.location}.`;
    
    if (item.details) {
      message += ` This process ${item.details.toLowerCase()}`;
    }
    
    if (item.relatedObjects) {
      const objectTypes = [...new Set(item.relatedObjects.map(obj => obj.type))];
      message += `\n\nThis process involves ${objectTypes.length} different object types: ${objectTypes.join(', ')}.`;
    }
    
    if (item.ocelData) {
      message += `\n\nOCEL analysis shows ${item.ocelData.eventCount} events with an average path length of ${item.ocelData.averagePathLength}.`;
      
      if (item.ocelData.commonPathways && item.ocelData.commonPathways.length > 0) {
        message += `\n\nCommon pathways include:\n- ${item.ocelData.commonPathways.join('\n- ')}`;
      }
      
      if (item.ocelData.bottleneckActivities && item.ocelData.bottleneckActivities.length > 0) {
        message += `\n\nI've identified potential bottlenecks in: ${item.ocelData.bottleneckActivities.join(', ')}.`;
      }
    }
    
    message += "\n\nWhat specific insights would you like about this process? I can help with bottleneck analysis, pathway optimization, or recommendations for improvement.";
    
    return message;
  };
  
  // Handle sending a message
  const handleSendMessage = (e) => {
    e.preventDefault();
    if (!inputValue.trim()) return;
    
    // Add user message
    const updatedMessages = [
      ...messages,
      { text: inputValue, isUser: true }
    ];
    setMessages(updatedMessages);
    
    // Generate AI response
    setTimeout(() => {
      let aiResponse = "I'm analyzing this process...";
      
      if (selectedItem) {
        // Generate contextual responses based on the query and selected item
        const query = inputValue.toLowerCase();
        
        if (query.includes('bottleneck') || query.includes('delay')) {
          if (selectedItem.ocelData && selectedItem.ocelData.bottleneckActivities.length > 0) {
            aiResponse = `Based on OCEL analysis, the main bottlenecks in this process are: ${selectedItem.ocelData.bottleneckActivities.join(', ')}. These activities are causing delays and affecting ${selectedItem.details.toLowerCase()}`;
          } else {
            aiResponse = "I don't see any significant bottlenecks in this process based on the available data.";
          }
        } else if (query.includes('pathway') || query.includes('flow') || query.includes('path')) {
          if (selectedItem.ocelData && selectedItem.ocelData.commonPathways.length > 0) {
            aiResponse = `The most common pathways for this process are:\n- ${selectedItem.ocelData.commonPathways.join('\n- ')}\nThese pathways represent the typical flow of objects through the system.`;
          } else {
            aiResponse = "I don't have detailed pathway information for this process.";
          }
        } else if (query.includes('object') || query.includes('relation')) {
          if (selectedItem.relatedObjects && selectedItem.relatedObjects.length > 0) {
            const objectTypes = selectedItem.relatedObjects.map(obj => obj.type);
            aiResponse = `This process involves ${objectTypes.length} different object types: ${objectTypes.join(', ')}. In OCEL 2.0, we track how these objects interact throughout the process, giving us a multi-dimensional view of the healthcare system.`;
          } else {
            aiResponse = "I don't have detailed object relationship information for this process.";
          }
        } else if (query.includes('recommend') || query.includes('improve') || query.includes('optimize')) {
          aiResponse = `Based on OCEL analysis of this ${selectedItem.type} process, I recommend:\n\n1. ${getRandomRecommendation(selectedItem.type)}\n2. ${getRandomRecommendation(selectedItem.type)}\n3. ${getRandomRecommendation(selectedItem.type)}\n\nWould you like me to elaborate on any of these recommendations?`;
        } else {
          aiResponse = `I'm analyzing the ${selectedItem.type} process "${selectedItem.title}". What specific aspect would you like to know about? I can provide insights on bottlenecks, common pathways, object relationships, or recommendations for improvement.`;
        }
      } else {
        aiResponse = "Please select a process item first so I can provide specific insights.";
      }
      
      setMessages([...updatedMessages, { text: aiResponse, isUser: false }]);
    }, 1000);
    
    setInputValue('');
  };
  
  useEffect(() => {
    scrollToBottom();
  }, [messages]);
  
  // Handle item selection
  const handleItemSelect = (item) => {
    setSelectedItem(item);
    
    // Generate AI response based on the selected item
    const aiResponse = generateAIResponse(item);
    
    // Add the AI response to the messages
    const updatedMessages = [...messages, { text: aiResponse, isUser: false }];
    setMessages(updatedMessages);
    
    // Scroll to bottom to show the new message
    setTimeout(() => {
      scrollToBottom();
    }, 100);
  };
  
  // Handle export
  const handleExport = () => {
    alert('Exporting analysis...');
    // Implementation would go here
  };
  
  // Handle publish
  const handlePublish = () => {
    if (!selectedItem || !analysis) {
      alert('Please select an item and complete the analysis before publishing.');
      return;
    }
    
    alert(`Publishing analysis for ${selectedItem.title}...`);
    // Implementation would go here
  };
  
  return (
    <DashboardLayout>
      <Head title="Root Cause Analysis - ZephyrusOR" />
      <PageContentLayout
        title="Root Cause Analysis"
        subtitle="Human-In-The-Loop AI Assistant for Process Analysis"
        className="flex flex-col"
      >
        <div className="flex flex-col gap-5">
          {/* Filters */}
          <Section title="Filters" icon="lucide:filter"
                   summary="Scope process items by location, type, and date range">
          <Panel className="p-4">
          <div className="grid grid-cols-3 gap-6">
            {/* Location filter */}
            <div>
              <label htmlFor="location-filter" className="block text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-2">
                Location
              </label>
              <select
                id="location-filter"
                value={selectedLocation}
                onChange={(e) => setSelectedLocation(e.target.value)}
                className="w-full rounded-md border-healthcare-border dark:border-healthcare-border-dark bg-healthcare-surface dark:bg-healthcare-surface-dark dark:text-white shadow-sm focus:border-healthcare-primary focus:ring focus:ring-healthcare-primary focus:ring-opacity-50"
              >
                <option value="">All Locations</option>
                {HOSPITAL_LOCATIONS.map((location) => (
                  <option key={location} value={location}>
                    {location}
                  </option>
                ))}
              </select>
            </div>
            
            {/* Process type filter */}
            <div>
              <label htmlFor="type-filter" className="block text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-2">
                Process Type
              </label>
              <select
                id="type-filter"
                value={selectedType}
                onChange={(e) => setSelectedType(e.target.value)}
                className="w-full rounded-md border-healthcare-border dark:border-healthcare-border-dark bg-healthcare-surface dark:bg-healthcare-surface-dark dark:text-white shadow-sm focus:border-healthcare-primary focus:ring focus:ring-healthcare-primary focus:ring-opacity-50"
              >
                <option value="">All Types</option>
                {Object.keys(PROCESS_TYPES).map((type) => (
                  <option key={type} value={type}>
                    {type}
                  </option>
                ))}
              </select>
            </div>
            
            {/* Date range */}
            <div className="mb-4">
              <label className="block text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-1">Date Range</label>
              <div className="flex space-x-2">
                <div className="flex-1">
                  <DatePicker
                    selected={startDate}
                    onChange={date => setStartDate(date)}
                    selectsStart
                    startDate={startDate}
                    endDate={endDate}
                    className="w-full px-3 py-2 border border-healthcare-border dark:border-healthcare-border-dark rounded-md shadow-sm focus:outline-none focus:ring-healthcare-primary focus:border-healthcare-primary bg-healthcare-surface dark:bg-healthcare-surface-dark dark:text-white text-sm"
                    dateFormat="MMM d, yyyy"
                    placeholderText="Start Date"
                  />
                </div>
                <div className="flex-1">
                  <DatePicker
                    selected={endDate}
                    onChange={date => setEndDate(date)}
                    selectsEnd
                    startDate={startDate}
                    endDate={endDate}
                    minDate={startDate}
                    className="w-full px-3 py-2 border border-healthcare-border dark:border-healthcare-border-dark rounded-md shadow-sm focus:outline-none focus:ring-healthcare-primary focus:border-healthcare-primary bg-healthcare-surface dark:bg-healthcare-surface-dark dark:text-white text-sm"
                    dateFormat="MMM d, yyyy"
                    placeholderText="End Date"
                  />
                </div>
              </div>
              <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mt-1">
                Showing items from {startDate.toLocaleDateString()} to {endDate.toLocaleDateString()}
              </p>
            </div>
          </div>
          </Panel>
          </Section>

          {/* Main content grid */}
          <Section title="Process Analysis" icon="lucide:git-branch"
                   summary="Human-in-the-loop AI assistant for root-cause analysis">
          <div className="grid grid-cols-4 gap-4 flex-1">
            {/* Process items column with tabs */}
            <div className="col-span-1 flex flex-col">
              {/* Tabs */}
              <div className="flex mb-4 border-b border-healthcare-border dark:border-healthcare-border-dark">
                <button
                  className={`py-2 px-4 font-medium border-b-2 transition-colors ${
                    activeTab === 'New'
                      ? 'border-healthcare-primary dark:border-healthcare-primary-dark text-healthcare-primary dark:text-healthcare-primary-dark'
                      : 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark'
                  }`}
                  onClick={() => setActiveTab('New')}
                >
                  New ({filteredNewItems.length})
                </button>
                <button
                  className={`py-2 px-4 font-medium border-b-2 transition-colors ${
                    activeTab === 'In-Progress'
                      ? 'border-healthcare-primary dark:border-healthcare-primary-dark text-healthcare-primary dark:text-healthcare-primary-dark'
                      : 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark'
                  }`}
                  onClick={() => setActiveTab('In-Progress')}
                >
                  In-Progress ({filteredInProgressItems.length})
                </button>
                <button
                  className={`py-2 px-4 font-medium border-b-2 transition-colors ${
                    activeTab === 'Completed'
                      ? 'border-healthcare-primary dark:border-healthcare-primary-dark text-healthcare-primary dark:text-healthcare-primary-dark'
                      : 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark'
                  }`}
                  onClick={() => setActiveTab('Completed')}
                >
                  Completed ({filteredCompletedItems.length})
                </button>
              </div>
              
              {/* Process Items Container */}
              <Panel className="overflow-y-auto p-4 mb-4 h-[950px]">
                <h3 className="font-medium text-healthcare-text-primary dark:text-white mb-3">{activeTab} Processes</h3>
                {activeTab === 'New' && (
                  <div className="space-y-4">
                    {filteredNewItems.length > 0 ? (
                      filteredNewItems.map((item, index) => (
                        <ProcessItem
                          key={index}
                          item={item}
                          isSelected={selectedItem && selectedItem.id === item.id}
                          onClick={() => handleItemSelect(item)}
                        />
                      ))
                    ) : (
                      <div className="text-center py-8 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                        No new items found matching your filters.
                      </div>
                    )}
                  </div>
                )}
                
                {activeTab === 'In-Progress' && (
                  <div className="space-y-4">
                    {filteredInProgressItems.length > 0 ? (
                      filteredInProgressItems.map((item, index) => (
                        <ProcessItem
                          key={index}
                          item={item}
                          isSelected={selectedItem && selectedItem.id === item.id}
                          onClick={() => handleItemSelect(item)}
                        />
                      ))
                    ) : (
                      <div className="text-center py-8 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                        No in-progress items found matching your filters.
                      </div>
                    )}
                  </div>
                )}
                
                {activeTab === 'Completed' && (
                  <div className="space-y-4">
                    {filteredCompletedItems.length > 0 ? (
                      filteredCompletedItems.map((item, index) => (
                        <ProcessItem
                          key={index}
                          item={item}
                          isSelected={selectedItem && selectedItem.id === item.id}
                          onClick={() => handleItemSelect(item)}
                        />
                      ))
                    ) : (
                      <div className="text-center py-8 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                        No completed items found matching your filters.
                      </div>
                    )}
                  </div>
                )}
              </Panel>
            </div>

            {/* Conversation and analysis column */}
            <div className="col-span-3 flex flex-col">
              {/* Chat interface */}
              <div className="flex flex-col mb-4">
                <h2 className="font-medium text-healthcare-text-primary dark:text-white mb-3">How Can I Help?</h2>
                <Panel className="flex flex-col h-[500px]">
                  <div className="flex-1 overflow-y-auto p-4 bg-healthcare-surface dark:bg-healthcare-surface-dark">
                    {messages.map((message, index) => (
                      <ChatMessage
                        key={index}
                        message={message.text}
                        isUser={message.isUser}
                      />
                    ))}
                    <div ref={messagesEndRef} />
                  </div>
                  
                  <div className="border-t border-healthcare-border dark:border-healthcare-border-dark p-4 bg-healthcare-background dark:bg-healthcare-background-dark flex">
                    <Input
                      type="text"
                      value={inputValue}
                      onChange={e => setInputValue(e.target.value)}
                      placeholder="Ask a question about this process..."
                      className="flex-1 mr-2"
                      onKeyPress={e => e.key === 'Enter' && handleSendMessage()}
                    />
                    <Button
                      onClick={handleSendMessage}
                      className="bg-healthcare-primary hover:bg-healthcare-primary-dark text-white dark:bg-healthcare-primary-dark dark:hover:bg-healthcare-primary"
                    >
                      <Icon icon="lucide:send" className="w-4 h-4 text-white" />
                    </Button>
                  </div>
                </Panel>
              </div>

              {/* OCEL Process Details */}
              {selectedItem && (
                <div className="mb-4">
                  <h2 className="font-medium text-healthcare-text-primary dark:text-white mb-3">Process Analysis</h2>
                  <Panel className="p-4">
                    <div className="grid grid-cols-2 gap-4 mb-4">
                      <div>
                        <h4 className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-1">Process Type</h4>
                        <p className="text-sm text-healthcare-text-primary dark:text-white">{selectedItem.type}</p>
                      </div>
                      <div>
                        <h4 className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-1">Location</h4>
                        <p className="text-sm text-healthcare-text-primary dark:text-white">{selectedItem.location}</p>
                      </div>
                      <div>
                        <h4 className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-1">Status</h4>
                        <p className="text-sm text-healthcare-text-primary dark:text-white">{selectedItem.status}</p>
                      </div>
                      <div>
                        <h4 className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-1">Date</h4>
                        <p className="text-sm text-healthcare-text-primary dark:text-white">{new Date(selectedItem.date).toLocaleDateString()}</p>
                      </div>
                    </div>
                    
                    {selectedItem.ocelData && (
                      <div className="mb-4 border border-healthcare-border dark:border-healthcare-border-dark rounded-lg p-4 bg-healthcare-background dark:bg-healthcare-background-dark">
                        <h4 className="text-sm font-medium text-healthcare-text-primary dark:text-white mb-3">OCEL Insights</h4>
                        <div className="grid grid-cols-2 gap-4 mb-4">
                          <div>
                            <h4 className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-1">Event Count</h4>
                            <p className="text-sm text-healthcare-text-primary dark:text-white">{selectedItem.ocelData.eventCount}</p>
                          </div>
                          <div>
                            <h4 className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-1">Average Path Length</h4>
                            <p className="text-sm text-healthcare-text-primary dark:text-white">{selectedItem.ocelData.averagePathLength}</p>
                          </div>
                        </div>
                        
                        {selectedItem.ocelData.bottleneckActivities && selectedItem.ocelData.bottleneckActivities.length > 0 && (
                          <div className="mb-4">
                            <h4 className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-2">Bottleneck Activities</h4>
                            <ul className="list-disc pl-5 text-sm text-healthcare-text-primary dark:text-white">
                              {selectedItem.ocelData.bottleneckActivities.map((activity, index) => (
                                <li key={index}>{activity}</li>
                              ))}
                            </ul>
                          </div>
                        )}
                        
                        {selectedItem.ocelData.commonPathways && selectedItem.ocelData.commonPathways.length > 0 && (
                          <div>
                            <h4 className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-2">Common Pathways</h4>
                            <ul className="list-disc pl-5 text-sm text-healthcare-text-primary dark:text-white">
                              {selectedItem.ocelData.commonPathways.map((pathway, index) => (
                                <li key={index}>{pathway}</li>
                              ))}
                            </ul>
                          </div>
                        )}
                      </div>
                    )}
                    
                    {selectedItem.relatedObjects && selectedItem.relatedObjects.length > 0 && (
                      <div className="border border-healthcare-border dark:border-healthcare-border-dark rounded-lg p-4 bg-healthcare-background dark:bg-healthcare-background-dark">
                        <h4 className="text-sm font-medium text-healthcare-text-primary dark:text-white mb-3">Related Objects</h4>
                        <div className="grid grid-cols-2 gap-2">
                          {selectedItem.relatedObjects.map((obj, index) => (
                            <div key={index} className="flex items-center p-2 bg-healthcare-surface dark:bg-healthcare-surface-dark rounded border border-healthcare-border dark:border-healthcare-border-dark">
                              <Icon 
                                icon={getObjectTypeIcon(obj.type)} 
                                className="w-4 h-4 mr-2 text-healthcare-primary dark:text-healthcare-primary-dark" 
                              />
                              <div>
                                <p className="text-sm font-medium text-healthcare-text-primary dark:text-white">{obj.type}</p>
                                <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{obj.id}</p>
                              </div>
                            </div>
                          ))}
                        </div>
                      </div>
                    )}
                  </Panel>
                </div>
              )}

              {/* Analysis section */}
              <div className="flex-1">
                <h2 className="font-medium text-healthcare-text-primary dark:text-white mb-3">Analysis</h2>
                <Panel className="h-[350px]">
                  <Textarea
                    value={analysis}
                    onChange={(e) => setAnalysis(e.target.value)}
                    placeholder="Enter your analysis here..."
                    className="w-full h-full resize-none border-0 focus:ring-0"
                  />
                </Panel>
                <div className="flex justify-end mt-4 gap-2">
                  <Button onClick={handleExport} variant="outline" size="sm">
                    <Icon icon="lucide:download" className="w-4 h-4 mr-1" />
                    Export
                  </Button>
                  <Button onClick={handlePublish} size="sm">
                    <Icon icon="lucide:send" className="w-4 h-4 mr-1" />
                    Publish
                  </Button>
                </div>
              </div>
            </div>
          </div>
          </Section>
        </div>
      </PageContentLayout>
    </DashboardLayout>
  );
};

export default RootCause;
