import React, { useState, useRef, useEffect } from 'react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { Head } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { Button } from '@/Components/ui/button';
import Panel from '@/Components/ui/Panel';
import Input from '@/Components/ui/input';
import { DatePicker } from '@/Components/ui/flowbite/DatePicker';
import Textarea from '@/Components/ui/textarea';

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
const HOSPITAL_LOCATIONS = [
  'Virtua Marlton Hospital',
  'Virtua Mount Holly Hospital',
  'Virtua Our Lady of Lourdes Hospital',
  'Virtua Voorhees Hospital',
  'Virtua Willingboro Hospital'
];

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
          : `bg-white dark:bg-gray-800 border-2 ${processTypeObj.borderColor} ${processTypeObj.darkBorderColor} hover:bg-gray-50 dark:hover:bg-gray-700`
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
            : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300'
        }`}>
          {item.status}
        </span>
      </div>
      <p className={`text-sm mb-2 ${isSelected ? 'text-white text-opacity-90' : 'text-gray-600 dark:text-gray-400'}`}>
        <span className="font-medium">Location:</span> {item.location}
      </p>
      <p className={`text-xs ${isSelected ? 'text-white text-opacity-80' : 'text-gray-500 dark:text-gray-500'}`}>
        <span className="font-medium">Date:</span> {new Date(item.date).toLocaleDateString()}
      </p>
      
      {/* OCEL Related Objects */}
      {item.relatedObjects && item.relatedObjects.length > 0 && (
        <div className={`flex flex-wrap gap-1 mt-2 pt-2 ${isSelected ? 'border-t border-white border-opacity-20' : 'border-t border-gray-200 dark:border-gray-700'}`}>
          {item.relatedObjects.map((obj, idx) => (
            <div 
              key={idx} 
              className={`flex items-center rounded-full px-2 py-1 text-xs ${
                isSelected 
                  ? 'bg-white bg-opacity-20' 
                  : 'bg-gray-100 dark:bg-gray-700'
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
            : 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200'
        }`}
      >
        <p className="text-sm whitespace-pre-line">{formatMessage(message)}</p>
      </div>
    </div>
  );
};

// Helper function to get a random process type
const getRandomProcessType = () => {
  const types = Object.keys(PROCESS_TYPES);
  return types[Math.floor(Math.random() * types.length)];
};

// Helper function to get a random status
const getRandomStatus = () => {
  // Ensure we get a more balanced distribution of statuses
  const randomNum = Math.random();
  if (randomNum < 0.4) {
    return 'New';
  } else if (randomNum < 0.7) {
    return 'In-Progress';
  } else {
    return 'Completed';
  }
};

// Generate mock items for demonstration
const generateMockItems = (count) => {
  const items = [];
  for (let i = 0; i < count; i++) {
    const type = getRandomProcessType();
    const processTypeObj = PROCESS_TYPES[type];
    const exampleIndex = Math.floor(Math.random() * processTypeObj.examples.length);
    
    // Generate 2-4 related objects for OCEL representation
    const relatedObjectCount = Math.floor(Math.random() * 3) + 2;
    const relatedObjects = [];
    const usedTypes = new Set();
    
    for (let j = 0; j < relatedObjectCount; j++) {
      let objectType;
      do {
        objectType = OBJECT_TYPES[Math.floor(Math.random() * OBJECT_TYPES.length)];
      } while (usedTypes.has(objectType));
      
      usedTypes.add(objectType);
      relatedObjects.push({
        type: objectType,
        id: `${objectType.toLowerCase()}_${Math.floor(Math.random() * 1000) + 1}`
      });
    }
    
    // Generate realistic healthcare process data
    const location = HOSPITAL_LOCATIONS[Math.floor(Math.random() * HOSPITAL_LOCATIONS.length)];
    const patientCount = Math.floor(Math.random() * 50) + 10;
    const timeImpact = Math.floor(Math.random() * 120) + 15;
    let details = '';
    
    switch (type) {
      case 'Reported Barriers':
        details = `Impact on ${patientCount} patients per day with average delay of ${timeImpact} minutes. Staff reported ${Math.floor(Math.random() * 5) + 1} critical incidents related to this barrier in the past week.`;
        break;
      case 'Admission Process':
        details = `Average admission processing time: ${timeImpact} minutes. Affects ${patientCount} patients per day. ${Math.floor(Math.random() * 30) + 10}% of admissions experience delays over standard processing time.`;
        break;
      case 'Discharge Process':
        details = `Average time from discharge order to patient exit: ${timeImpact} minutes. ${Math.floor(Math.random() * 20) + 5}% of discharges delayed past noon, impacting ${patientCount} patients weekly.`;
        break;
      case 'Perioperative Process':
        details = `OR utilization affected by ${timeImpact}-minute average delays. Impacts ${patientCount} surgical cases weekly. Case cancellation rate: ${Math.floor(Math.random() * 5) + 1}%.`;
        break;
      case 'Patient Flow':
        details = `Bottleneck causing ${timeImpact}-minute average delays in patient movement. Affects ${patientCount} patients daily across ${Math.floor(Math.random() * 3) + 2} departments.`;
        break;
      case 'Medication Process':
        details = `Medication process delays averaging ${timeImpact} minutes from order to administration. Affects ${patientCount} medication administrations daily. Error rate: ${(Math.random() * 2).toFixed(1)}%.`;
        break;
      default:
        details = `Impact on ${patientCount} patients per day with average delay of ${timeImpact} minutes`;
    }
    
    // Create OCEL-inspired process item
    items.push({
      id: i,
      title: processTypeObj.examples[exampleIndex],
      type: type,
      location: location,
      date: new Date(2025, 1, Math.floor(Math.random() * 28) + 1).toISOString(),
      status: getRandomStatus(),
      details: details,
      score: Math.random() * 10,
      relatedObjects: relatedObjects,
      // Add OCEL-specific data
      ocelData: {
        eventCount: Math.floor(Math.random() * 1000) + 100,
        objectInteractions: Math.floor(Math.random() * 50) + 10,
        averagePathLength: (Math.random() * 5 + 2).toFixed(1) + " hours",
        commonPathways: [
          `${location} ED → Radiology → ${location} ED → Admission`,
          `${location} ED → Admission → Ward`,
          `${location} ED → Discharge`
        ].slice(0, Math.floor(Math.random() * 2) + 2),
        bottleneckActivities: [
          'Documentation',
          'Waiting for Resources',
          'Handoff Communication',
          'Order Processing',
          'Test Results'
        ].slice(0, Math.floor(Math.random() * 3) + 1)
      }
    });
  }
  return items;
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
  
  // Load mock data on component mount
  useEffect(() => {
    // Generate mock data
    const mockItems = generateMockItems(20);
    console.log('Generated mock items:', mockItems);
    
    // Set the items by status
    const newItems = mockItems.filter(item => item.status === 'New');
    const inProgressItems = mockItems.filter(item => item.status === 'In-Progress');
    const completedItems = mockItems.filter(item => item.status === 'Completed');
    
    console.log('New items:', newItems);
    console.log('In-Progress items:', inProgressItems);
    console.log('Completed items:', completedItems);
    
    setNewItems(newItems);
    setInProgressItems(inProgressItems);
    setCompletedItems(completedItems);
    
    // Also update the filtered items initially
    setFilteredNewItems(newItems);
    setFilteredInProgressItems(inProgressItems);
    setFilteredCompletedItems(completedItems);
  }, []); 
  
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
        <Panel className="p-6 flex flex-col">
          {/* Filters */}
          <div className="grid grid-cols-3 gap-6 mb-8">
            {/* Location filter */}
            <div>
              <label htmlFor="location-filter" className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                Location
              </label>
              <select
                id="location-filter"
                value={selectedLocation}
                onChange={(e) => setSelectedLocation(e.target.value)}
                className="w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:border-healthcare-primary focus:ring focus:ring-healthcare-primary focus:ring-opacity-50"
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
              <label htmlFor="type-filter" className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                Process Type
              </label>
              <select
                id="type-filter"
                value={selectedType}
                onChange={(e) => setSelectedType(e.target.value)}
                className="w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:border-healthcare-primary focus:ring focus:ring-healthcare-primary focus:ring-opacity-50"
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
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Date Range</label>
              <div className="flex space-x-2">
                <div className="flex-1">
                  <DatePicker
                    selected={startDate}
                    onChange={date => setStartDate(date)}
                    selectsStart
                    startDate={startDate}
                    endDate={endDate}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-healthcare-primary focus:border-healthcare-primary dark:bg-gray-700 dark:text-white text-sm"
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
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-healthcare-primary focus:border-healthcare-primary dark:bg-gray-700 dark:text-white text-sm"
                    dateFormat="MMM d, yyyy"
                    placeholderText="End Date"
                  />
                </div>
              </div>
              <p className="text-xs text-gray-500 dark:text-gray-400 mt-1">
                Showing items from {startDate.toLocaleDateString()} to {endDate.toLocaleDateString()}
              </p>
            </div>
          </div>
          
          {/* Main content grid */}
          <div className="grid grid-cols-4 gap-4 flex-1">
            {/* Process items column with tabs */}
            <div className="col-span-1 flex flex-col">
              {/* Tabs */}
              <div className="flex mb-4 border-b border-gray-200 dark:border-gray-700">
                <button
                  className={`py-2 px-4 font-medium border-b-2 transition-colors ${
                    activeTab === 'New'
                      ? 'border-healthcare-primary dark:border-healthcare-primary-dark text-healthcare-primary dark:text-healthcare-primary-dark'
                      : 'text-gray-500 dark:text-gray-400'
                  }`}
                  onClick={() => setActiveTab('New')}
                >
                  New ({filteredNewItems.length})
                </button>
                <button
                  className={`py-2 px-4 font-medium border-b-2 transition-colors ${
                    activeTab === 'In-Progress'
                      ? 'border-healthcare-primary dark:border-healthcare-primary-dark text-healthcare-primary dark:text-healthcare-primary-dark'
                      : 'text-gray-500 dark:text-gray-400'
                  }`}
                  onClick={() => setActiveTab('In-Progress')}
                >
                  In-Progress ({filteredInProgressItems.length})
                </button>
                <button
                  className={`py-2 px-4 font-medium border-b-2 transition-colors ${
                    activeTab === 'Completed'
                      ? 'border-healthcare-primary dark:border-healthcare-primary-dark text-healthcare-primary dark:text-healthcare-primary-dark'
                      : 'text-gray-500 dark:text-gray-400'
                  }`}
                  onClick={() => setActiveTab('Completed')}
                >
                  Completed ({filteredCompletedItems.length})
                </button>
              </div>
              
              {/* Process Items Container */}
              <div className="overflow-y-auto bg-white dark:bg-gray-800 border-2 border-gray-200 dark:border-gray-700 rounded-lg p-4 mb-4 h-[950px]">
                <h3 className="font-medium text-gray-900 dark:text-white mb-3">{activeTab} Processes</h3>
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
                      <div className="text-center py-8 text-gray-500 dark:text-gray-400">
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
                      <div className="text-center py-8 text-gray-500 dark:text-gray-400">
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
                      <div className="text-center py-8 text-gray-500 dark:text-gray-400">
                        No completed items found matching your filters.
                      </div>
                    )}
                  </div>
                )}
              </div>
            </div>
            
            {/* Conversation and analysis column */}
            <div className="col-span-3 flex flex-col">
              {/* Chat interface */}
              <div className="flex flex-col mb-4">
                <h2 className="font-medium text-gray-900 dark:text-white mb-3">How Can I Help?</h2>
                <div className="flex flex-col border-2 border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden h-[500px]">
                  <div className="flex-1 overflow-y-auto p-4 bg-white dark:bg-gray-800">
                    {messages.map((message, index) => (
                      <ChatMessage
                        key={index}
                        message={message.text}
                        isUser={message.isUser}
                      />
                    ))}
                    <div ref={messagesEndRef} />
                  </div>
                  
                  <div className="border-t border-gray-200 dark:border-gray-700 p-4 bg-gray-50 dark:bg-gray-700 flex">
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
                </div>
              </div>
              
              {/* OCEL Process Details */}
              {selectedItem && (
                <div className="mb-4">
                  <h2 className="font-medium text-gray-900 dark:text-white mb-3">Process Analysis</h2>
                  <div className="border-2 border-gray-200 dark:border-gray-700 rounded-lg p-4 bg-white dark:bg-gray-800">
                    <div className="grid grid-cols-2 gap-4 mb-4">
                      <div>
                        <h4 className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Process Type</h4>
                        <p className="text-sm text-gray-900 dark:text-white">{selectedItem.type}</p>
                      </div>
                      <div>
                        <h4 className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Location</h4>
                        <p className="text-sm text-gray-900 dark:text-white">{selectedItem.location}</p>
                      </div>
                      <div>
                        <h4 className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Status</h4>
                        <p className="text-sm text-gray-900 dark:text-white">{selectedItem.status}</p>
                      </div>
                      <div>
                        <h4 className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Date</h4>
                        <p className="text-sm text-gray-900 dark:text-white">{new Date(selectedItem.date).toLocaleDateString()}</p>
                      </div>
                    </div>
                    
                    {selectedItem.ocelData && (
                      <div className="mb-4 border border-gray-200 dark:border-gray-700 rounded-lg p-4 bg-gray-50 dark:bg-gray-700">
                        <h4 className="text-sm font-medium text-gray-900 dark:text-white mb-3">OCEL Insights</h4>
                        <div className="grid grid-cols-2 gap-4 mb-4">
                          <div>
                            <h4 className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Event Count</h4>
                            <p className="text-sm text-gray-900 dark:text-white">{selectedItem.ocelData.eventCount}</p>
                          </div>
                          <div>
                            <h4 className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Average Path Length</h4>
                            <p className="text-sm text-gray-900 dark:text-white">{selectedItem.ocelData.averagePathLength}</p>
                          </div>
                        </div>
                        
                        {selectedItem.ocelData.bottleneckActivities && selectedItem.ocelData.bottleneckActivities.length > 0 && (
                          <div className="mb-4">
                            <h4 className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Bottleneck Activities</h4>
                            <ul className="list-disc pl-5 text-sm text-gray-900 dark:text-white">
                              {selectedItem.ocelData.bottleneckActivities.map((activity, index) => (
                                <li key={index}>{activity}</li>
                              ))}
                            </ul>
                          </div>
                        )}
                        
                        {selectedItem.ocelData.commonPathways && selectedItem.ocelData.commonPathways.length > 0 && (
                          <div>
                            <h4 className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Common Pathways</h4>
                            <ul className="list-disc pl-5 text-sm text-gray-900 dark:text-white">
                              {selectedItem.ocelData.commonPathways.map((pathway, index) => (
                                <li key={index}>{pathway}</li>
                              ))}
                            </ul>
                          </div>
                        )}
                      </div>
                    )}
                    
                    {selectedItem.relatedObjects && selectedItem.relatedObjects.length > 0 && (
                      <div className="border border-gray-200 dark:border-gray-700 rounded-lg p-4 bg-gray-50 dark:bg-gray-700">
                        <h4 className="text-sm font-medium text-gray-900 dark:text-white mb-3">Related Objects</h4>
                        <div className="grid grid-cols-2 gap-2">
                          {selectedItem.relatedObjects.map((obj, index) => (
                            <div key={index} className="flex items-center p-2 bg-white dark:bg-gray-800 rounded border border-gray-200 dark:border-gray-600">
                              <Icon 
                                icon={getObjectTypeIcon(obj.type)} 
                                className="w-4 h-4 mr-2 text-healthcare-primary dark:text-healthcare-primary-dark" 
                              />
                              <div>
                                <p className="text-sm font-medium text-gray-900 dark:text-white">{obj.type}</p>
                                <p className="text-xs text-gray-500 dark:text-gray-400">{obj.id}</p>
                              </div>
                            </div>
                          ))}
                        </div>
                      </div>
                    )}
                  </div>
                </div>
              )}
              
              {/* Analysis section */}
              <div className="flex-1">
                <h2 className="font-medium text-gray-900 dark:text-white mb-3">Analysis</h2>
                <div className="border-2 border-gray-200 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-800 h-[350px]">
                  <Textarea 
                    value={analysis}
                    onChange={(e) => setAnalysis(e.target.value)}
                    placeholder="Enter your analysis here..."
                    className="w-full h-full resize-none border-0 focus:ring-0"
                  />
                </div>
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
        </Panel>
      </PageContentLayout>
    </DashboardLayout>
  );
};

export default RootCause;
