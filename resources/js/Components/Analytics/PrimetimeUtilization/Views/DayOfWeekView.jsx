import React, { useMemo, useState } from 'react';
import { mockPrimetimeUtilization } from '../../../../mock-data/primetime-utilization';
import { ResponsiveBar } from '@nivo/bar';
import Panel from '../../../ui/Panel';
import { Icon } from '@iconify/react';
import { useDarkMode } from '@/Layouts/AuthenticatedLayout';
import getChartTheme from '@/utils/chartTheme';

// Create a custom heatmap component to avoid defaultProps warnings
const CustomHeatMap = ({
  xLabels,
  yLabels,
  data,
  cellStyle,
  cellRender,
  title,
  xLabelsStyle,
  yLabelsStyle,
  xLabelWidth = 100,
  yLabelWidth = 60,
  onClick
}) => {
  const { isDarkMode } = useDarkMode();
  
  // Default styles
  const defaultXLabelStyle = {
    fontSize: '12px',
    textAnchor: 'middle',
    fill: isDarkMode ? '#e5e7eb' : '#374151',
    paddingTop: '10px',
    paddingBottom: '10px',
    lineHeight: '1.5',
  };
  
  const defaultYLabelStyle = {
    fontSize: '12px',
    textAnchor: 'end',
    fill: isDarkMode ? '#e5e7eb' : '#374151',
    paddingRight: '10px',
    paddingLeft: '5px',
    lineHeight: '1.5',
  };
  
  const defaultCellStyle = (value) => ({
    fontSize: '11px',
    color: '#374151',
    cursor: 'pointer',
  });
  
  // Apply custom or default styles
  const getXLabelStyle = (x) => {
    return { ...defaultXLabelStyle, ...(xLabelsStyle ? xLabelsStyle(x) : {}) };
  };
  
  const getYLabelStyle = (y) => {
    return { ...defaultYLabelStyle, ...(yLabelsStyle ? yLabelsStyle(y) : {}) };
  };
  
  const getCellStyle = (x, y, value) => {
    // Start with default cell style
    const defaultStyles = defaultCellStyle(value);
    
    // Apply custom styles, prioritizing them over defaults
    return { 
      ...defaultStyles, 
      ...(cellStyle ? cellStyle(x, y, value) : {}) 
    };
  };
  
  const getCellContent = (value) => {
    return cellRender ? cellRender(value) : value;
  };
  
  const getCellTitle = (x, y) => {
    return title ? title(x, y) : '';
  };
  
  const handleCellClick = (x, y) => {
    if (onClick) onClick(x, y);
  };
  
  return (
    <div className="custom-heatmap">
      {/* X Labels (top) */}
      <div className="flex" style={{ marginLeft: `${yLabelWidth}px` }}>
        {xLabels.map((label, x) => (
          <div 
            key={`x-label-${x}`} 
            className="flex-1 text-center"
            style={getXLabelStyle(x)}
          >
            {label}
          </div>
        ))}
      </div>
      
      {/* Grid with Y Labels */}
      <div className="flex flex-col">
        {yLabels.map((yLabel, y) => (
          <div key={`row-${y}`} className="flex items-center">
            {/* Y Label */}
            <div 
              className="flex-shrink-0 text-right pr-2"
              style={{ width: `${yLabelWidth}px`, ...getYLabelStyle(y) }}
            >
              {yLabel}
            </div>
            
            {/* Row of cells */}
            <div className="flex flex-1">
              {xLabels.map((_, x) => {
                const value = data.find(item => item.x === x && item.y === y)?.value || null;
                const style = getCellStyle(x, y, value);
                return (
                  <div
                    key={`cell-${x}-${y}`}
                    className="flex-1 flex items-center justify-center p-2 m-px transition-colors"
                    style={style}
                    title={getCellTitle(x, y)}
                    onClick={() => handleCellClick(x, y)}
                    role="button"
                    tabIndex={0}
                  >
                    {getCellContent(value)}
                  </div>
                );
              })}
            </div>
          </div>
        ))}
      </div>
    </div>
  );
};

const DayOfWeekView = ({ filters }) => {
  // State for modal
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [selectedTimeBlock, setSelectedTimeBlock] = useState(null);
  const { isDarkMode } = useDarkMode();
  
  // Get chart theme with proper dark mode setting
  const chartTheme = getChartTheme(isDarkMode);
  
  // Extract filter values
  const { selectedHospital, selectedLocation, selectedSpecialty, dateRange } = filters;
  
  // Format day of week data
  const dayOfWeekData = useMemo(() => {
    // Default to 'MARH OR' if no location is selected
    const location = selectedLocation || 'MARH OR';
    
    // Check if the location exists in the weekdayData
    const locationData = mockPrimetimeUtilization.weekdayData[location] || mockPrimetimeUtilization.weekdayData['MARH OR'];
    
    // Create an array of day data
    return Object.entries(locationData).map(([day, data]) => ({
      day,
      utilization: data.utilization || 0,
      cases: Math.round(data.utilization * 5) || 0 // Mock case count based on utilization
    }));
  }, [selectedHospital, selectedLocation, selectedSpecialty, dateRange]);
  
  // Helper function to determine color based on utilization value
  const getColorForValue = (value) => {
    if (value < 60) return 'rgb(239, 68, 68)'; // Red for low utilization
    if (value < 75) return 'rgb(234, 179, 8)';  // Yellow for medium utilization
    return 'rgb(34, 197, 94)';                  // Green for high utilization
  };
  
  // Mock data for specialties with characteristics
  const specialtyCharacteristics = {
    'Orthopedics': { 
      avgDuration: 120, 
      variability: 30, 
      morningPreference: 0.7, 
      afternoonPreference: 0.5,
      procedures: [
        { name: 'Total Knee Replacement', duration: '2.5 hrs', complexity: 'high' },
        { name: 'ACL Reconstruction', duration: '1.5 hrs', complexity: 'medium' },
        { name: 'Hip Replacement', duration: '3 hrs', complexity: 'high' },
        { name: 'Shoulder Arthroscopy', duration: '1 hr', complexity: 'medium' },
        { name: 'Carpal Tunnel Release', duration: '45 min', complexity: 'low' }
      ]
    },
    'General Surgery': { 
      avgDuration: 90, 
      variability: 40, 
      morningPreference: 0.6, 
      afternoonPreference: 0.6,
      procedures: [
        { name: 'Laparoscopic Cholecystectomy', duration: '1 hr', complexity: 'medium' },
        { name: 'Appendectomy', duration: '45 min', complexity: 'medium' },
        { name: 'Hernia Repair', duration: '1.5 hrs', complexity: 'medium' },
        { name: 'Colon Resection', duration: '3 hrs', complexity: 'high' },
        { name: 'Breast Biopsy', duration: '30 min', complexity: 'low' }
      ]
    },
    'Neurosurgery': { 
      avgDuration: 180, 
      variability: 60, 
      morningPreference: 0.8, 
      afternoonPreference: 0.3,
      procedures: [
        { name: 'Craniotomy', duration: '4 hrs', complexity: 'high' },
        { name: 'Spinal Fusion', duration: '3.5 hrs', complexity: 'high' },
        { name: 'Laminectomy', duration: '2 hrs', complexity: 'medium' },
        { name: 'Brain Tumor Resection', duration: '5 hrs', complexity: 'high' },
        { name: 'Microdiscectomy', duration: '1.5 hrs', complexity: 'medium' }
      ]
    },
    'Cardiothoracic': { 
      avgDuration: 240, 
      variability: 60, 
      morningPreference: 0.9, 
      afternoonPreference: 0.2,
      procedures: [
        { name: 'CABG', duration: '4 hrs', complexity: 'high' },
        { name: 'Valve Replacement', duration: '3.5 hrs', complexity: 'high' },
        { name: 'Lung Resection', duration: '3 hrs', complexity: 'high' },
        { name: 'Aortic Aneurysm Repair', duration: '5 hrs', complexity: 'high' },
        { name: 'Pacemaker Insertion', duration: '1 hr', complexity: 'medium' }
      ]
    },
    'ENT': { 
      avgDuration: 60, 
      variability: 30, 
      morningPreference: 0.5, 
      afternoonPreference: 0.7,
      procedures: [
        { name: 'Tonsillectomy', duration: '45 min', complexity: 'low' },
        { name: 'Septoplasty', duration: '1.5 hrs', complexity: 'medium' },
        { name: 'Thyroidectomy', duration: '2 hrs', complexity: 'medium' },
        { name: 'Mastoidectomy', duration: '2.5 hrs', complexity: 'high' },
        { name: 'Endoscopic Sinus Surgery', duration: '1 hr', complexity: 'medium' }
      ]
    },
    'Ophthalmology': { 
      avgDuration: 45, 
      variability: 15, 
      morningPreference: 0.6, 
      afternoonPreference: 0.6,
      procedures: [
        { name: 'Cataract Surgery', duration: '30 min', complexity: 'low' },
        { name: 'Vitrectomy', duration: '1.5 hrs', complexity: 'medium' },
        { name: 'Glaucoma Surgery', duration: '1 hr', complexity: 'medium' },
        { name: 'Corneal Transplant', duration: '2 hrs', complexity: 'high' },
        { name: 'Strabismus Correction', duration: '1 hr', complexity: 'medium' }
      ]
    },
    'Urology': { 
      avgDuration: 75, 
      variability: 30, 
      morningPreference: 0.5, 
      afternoonPreference: 0.7,
      procedures: [
        { name: 'TURP', duration: '1 hr', complexity: 'medium' },
        { name: 'Nephrectomy', duration: '3 hrs', complexity: 'high' },
        { name: 'Cystoscopy', duration: '30 min', complexity: 'low' },
        { name: 'Prostatectomy', duration: '2.5 hrs', complexity: 'high' },
        { name: 'Ureteroscopy', duration: '1 hr', complexity: 'medium' }
      ]
    },
    'Plastic Surgery': { 
      avgDuration: 120, 
      variability: 60, 
      morningPreference: 0.4, 
      afternoonPreference: 0.8,
      procedures: [
        { name: 'Breast Reconstruction', duration: '3 hrs', complexity: 'high' },
        { name: 'Rhinoplasty', duration: '2 hrs', complexity: 'medium' },
        { name: 'Facial Reconstruction', duration: '4 hrs', complexity: 'high' },
        { name: 'Skin Grafting', duration: '1.5 hrs', complexity: 'medium' },
        { name: 'Liposuction', duration: '2 hrs', complexity: 'medium' }
      ]
    }
  };
  
  // Function to generate realistic procedure data for a specialty
  const generateProceduresForSpecialty = (specialty, caseCount) => {
    const specialtyData = specialtyCharacteristics[specialty];
    if (!specialtyData) return [];
    
    const procedures = [];
    let remainingCases = caseCount;
    
    // Distribute cases among procedures based on complexity
    specialtyData.procedures.forEach(proc => {
      let procCaseCount;
      if (proc.complexity === 'high') {
        procCaseCount = Math.floor(remainingCases * 0.15); // 15% of cases are high complexity
      } else if (proc.complexity === 'medium') {
        procCaseCount = Math.floor(remainingCases * 0.3); // 30% of cases are medium complexity
      } else {
        procCaseCount = Math.floor(remainingCases * 0.55); // 55% of cases are low complexity
      }
      
      // Ensure at least 1 case if we have any remaining
      procCaseCount = Math.max(1, Math.min(procCaseCount, remainingCases));
      remainingCases -= procCaseCount;
      
      if (procCaseCount > 0) {
        procedures.push({
          name: proc.name,
          count: procCaseCount,
          duration: proc.duration
        });
      }
    });
    
    // If we still have remaining cases, add them to the first procedure
    if (remainingCases > 0 && procedures.length > 0) {
      procedures[0].count += remainingCases;
    }
    
    return procedures;
  };
  
  // Function to generate room data for a location
  const generateRoomsForLocation = (locationName, caseCount, baseUtilization) => {
    const roomCount = locationName.includes('Main') ? 6 : locationName.includes('Satellite') ? 3 : 2;
    const rooms = [];
    
    let remainingCases = caseCount;
    let totalUtilization = baseUtilization * roomCount; // Total utilization to distribute
    
    for (let i = 1; i <= roomCount; i++) {
      // Vary utilization slightly for each room
      const utilVariation = Math.random() * 20 - 10; // -10 to +10
      let roomUtil = Math.min(100, Math.max(10, baseUtilization + utilVariation));
      
      // Calculate cases for this room proportional to its utilization
      const roomCases = Math.floor((roomUtil / totalUtilization) * caseCount);
      remainingCases -= roomCases;
      
      rooms.push({
        number: i,
        utilization: Math.round(roomUtil),
        cases: roomCases
      });
    }
    
    // Distribute any remaining cases
    if (remainingCases > 0) {
      for (let i = 0; i < remainingCases; i++) {
        rooms[i % rooms.length].cases += 1;
      }
    }
    
    return rooms;
  };

  // Generate time of day heatmap data with realistic utilization patterns
  const generateTimeOfDayHeatmapData = () => {
    const daysOfWeek = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    const timeSlots = [
      '06:00', '06:20', '06:40', '07:00', '07:20', '07:40', 
      '08:00', '08:20', '08:40', '09:00', '09:20', '09:40',
      '10:00', '10:20', '10:40', '11:00', '11:20', '11:40',
      '12:00', '12:20', '12:40', '13:00', '13:20', '13:40',
      '14:00', '14:20', '14:40', '15:00', '15:20', '15:40',
      '16:00', '16:20', '16:40', '17:00', '17:20', '17:40',
      '18:00', '18:20', '18:40', '19:00'
    ];
    
    return daysOfWeek.map(day => {
      return timeSlots.map(timeSlot => {
        // Base utilization varies by day and time
        let baseUtilization;
        const dayFactor = day === 'Monday' || day === 'Friday' ? 0.9 : 1.0; // Slightly lower on Monday/Friday
        
        // Time-based patterns
        if (timeSlot === '06:00' || timeSlot === '06:20' || timeSlot === '06:40') {
          baseUtilization = 65 * dayFactor; // Early morning - moderate
        } else if (timeSlot === '07:00' || timeSlot === '07:20' || timeSlot === '07:40' || timeSlot === '08:00' || timeSlot === '08:20' || timeSlot === '08:40') {
          baseUtilization = 85 * dayFactor; // Mid-morning to early afternoon - peak
        } else if (timeSlot === '09:00' || timeSlot === '09:20' || timeSlot === '09:40' || timeSlot === '10:00' || timeSlot === '10:20' || timeSlot === '10:40') {
          baseUtilization = 75 * dayFactor; // Early afternoon - high
        } else if (timeSlot === '11:00' || timeSlot === '11:20' || timeSlot === '11:40' || timeSlot === '12:00' || timeSlot === '12:20' || timeSlot === '12:40') {
          baseUtilization = 70 * dayFactor; // Late afternoon - moderate to high
        } else if (timeSlot === '13:00' || timeSlot === '13:20' || timeSlot === '13:40' || timeSlot === '14:00' || timeSlot === '14:20' || timeSlot === '14:40') {
          baseUtilization = 65 * dayFactor; // Late afternoon - moderate
        } else if (timeSlot === '15:00' || timeSlot === '15:20' || timeSlot === '15:40' || timeSlot === '16:00' || timeSlot === '16:20' || timeSlot === '16:40') {
          baseUtilization = 60 * dayFactor; // Late afternoon - moderate
        } else if (timeSlot === '17:00' || timeSlot === '17:20' || timeSlot === '17:40' || timeSlot === '18:00' || timeSlot === '18:20' || timeSlot === '18:40') {
          baseUtilization = 55 * dayFactor; // Evening - moderate
        } else {
          baseUtilization = 50 * dayFactor; // Evening - lower
        }
        
        // Add some randomness
        const utilization = Math.min(100, Math.max(30, baseUtilization + (Math.random() * 20 - 10)));
        
        // Generate services data
        const serviceCount = Math.floor(Math.random() * 3) + 2; // 2-4 services
        const services = [];
        
        // Select random specialties without duplicates
        const specialties = Object.keys(specialtyCharacteristics);
        const selectedSpecialties = [];
        
        while (selectedSpecialties.length < serviceCount && selectedSpecialties.length < specialties.length) {
          const specialty = specialties[Math.floor(Math.random() * specialties.length)];
          if (!selectedSpecialties.includes(specialty)) {
            selectedSpecialties.push(specialty);
          }
        }
        
        // Create service data for each specialty
        selectedSpecialties.forEach(specialty => {
          const specialtyData = specialtyCharacteristics[specialty];
          
          // Adjust utilization based on specialty preferences for time of day
          let timePreference;
          if (timeSlot === '06:00' || timeSlot === '06:20' || timeSlot === '06:40' || timeSlot === '07:00' || timeSlot === '07:20' || timeSlot === '07:40' || timeSlot === '08:00' || timeSlot === '08:20' || timeSlot === '08:40') {
            timePreference = specialtyData.morningPreference;
          } else {
            timePreference = specialtyData.afternoonPreference;
          }
          
          const serviceUtilization = Math.min(100, Math.max(20, 
            utilization * timePreference + (Math.random() * 15 - 7.5)
          ));
          
          // Calculate case count based on utilization and average duration
          const totalMinutes = 20; // 20-minute time slot
          const adjustedDuration = specialtyData.avgDuration * (1 + (Math.random() * 0.2 - 0.1)); // +/- 10%
          const caseCount = Math.max(1, Math.round((serviceUtilization / 100) * (totalMinutes / adjustedDuration) * 5)); // Multiply by 5 for more realistic numbers
          
          // Generate location data
          const locations = [
            { 
              name: 'Main OR', 
              utilization: Math.round(serviceUtilization * (0.8 + Math.random() * 0.4)),
              rooms: generateRoomsForLocation('Main OR', Math.ceil(caseCount * 0.7), serviceUtilization)
            },
            { 
              name: 'Satellite OR', 
              utilization: Math.round(serviceUtilization * (0.7 + Math.random() * 0.3)),
              rooms: generateRoomsForLocation('Satellite OR', Math.floor(caseCount * 0.3), serviceUtilization * 0.8)
            }
          ];
          
          services.push({
            service: specialty,
            utilization: Math.round(serviceUtilization),
            cases: caseCount,
            locations: locations,
            procedures: generateProceduresForSpecialty(specialty, caseCount)
          });
        });
        
        return {
          day,
          timeSlot,
          utilization: Math.round(utilization),
          services
        };
      });
    }).flat();
  };

  // Define time slots with 20-minute granularity starting at 06:00
  const timeSlots = useMemo(() => [
    '06:00', '06:20', '06:40', '07:00', '07:20', '07:40', 
    '08:00', '08:20', '08:40', '09:00', '09:20', '09:40',
    '10:00', '10:20', '10:40', '11:00', '11:20', '11:40',
    '12:00', '12:20', '12:40', '13:00', '13:20', '13:40',
    '14:00', '14:20', '14:40', '15:00', '15:20', '15:40',
    '16:00', '16:20', '16:40', '17:00', '17:20', '17:40',
    '18:00', '18:20', '18:40', '19:00'
  ], []);
  
  // Define days of week (now x-axis) including weekends
  const daysOfWeek = useMemo(() => ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'], []);
  
  // Format time of day data for HeatMap visualization with realistic patterns
  const timeOfDayHeatmapData = useMemo(() => generateTimeOfDayHeatmapData(), [timeSlots, daysOfWeek]);

  // Transform data for heatmap
  const transformedData = useMemo(() => {
    const result = [];
    
    // Create a mapping for quick lookup
    const dataMap = {};
    timeOfDayHeatmapData.forEach(item => {
      dataMap[`${item.day}-${item.timeSlot}`] = item.utilization;
    });
    
    // Generate the data in the format expected by the heatmap
    timeSlots.forEach((timeSlot, y) => {
      daysOfWeek.forEach((day, x) => {
        const key = `${day}-${timeSlot}`;
        const value = dataMap[key] || 0;
        
        result.push({
          x,
          y,
          value
        });
      });
    });
    
    return result;
  }, [timeOfDayHeatmapData, timeSlots, daysOfWeek]);

  // Handle cell click
  const handleCellClick = (x, y) => {
    // Find the corresponding data point
    const dataPoint = timeOfDayHeatmapData.find(
      point => point.day === daysOfWeek[x] && point.timeSlot === timeSlots[y]
    );
    
    if (dataPoint) {
      setSelectedTimeBlock({
        day: dataPoint.day,
        time: dataPoint.timeSlot,
        utilization: dataPoint.utilization,
        services: dataPoint.services
      });
      
      setIsModalOpen(true);
    }
  };

  return (
    <div className="space-y-6">
      <Panel title="Time of Day Utilization" isSubpanel dropLightIntensity="medium">
        <div className="h-auto min-h-[500px] flex flex-col items-center justify-center">
          <div className="w-full h-full py-8 px-4">
            <CustomHeatMap
              xLabels={daysOfWeek}
              yLabels={timeSlots}
              data={transformedData}
              cellStyle={(x, y, value) => ({
                background: getColorForValue(value),
                fontSize: '11px',
                color: '#fff',
                opacity: (x === 0 || x === 6) ? 0.8 : 1,
                cursor: 'pointer', // Add pointer cursor to indicate clickable
              })}
              cellRender={value => value && `${value}%`}
              title={(x, y) => {
                if (y < timeOfDayHeatmapData.length && x < timeOfDayHeatmapData[0].length) {
                  return `${timeSlots[y]} on ${daysOfWeek[x]}: ${timeOfDayHeatmapData[y][x]}% utilization`;
                }
                return '';
              }}
              // Apply custom styles for axis labels with proper padding
              xLabelsStyle={() => ({
                fontSize: '12px',
                textAnchor: 'middle',
                fill: 'currentColor',
                paddingTop: '10px',
                paddingBottom: '10px',
                lineHeight: '1.5',
              })}
              yLabelsStyle={() => ({
                fontSize: '12px',
                textAnchor: 'end',
                fill: 'currentColor',
                paddingRight: '10px',
                paddingLeft: '5px',
                lineHeight: '1.5',
              })}
              // Configure overall heatmap layout
              xLabelWidth={100}
              yLabelWidth={60}
              onClick={handleCellClick}
            />
          </div>
          <div className="flex flex-col items-center mt-4 text-sm text-gray-500 dark:text-gray-400 space-y-2">
            <div>
              Click Time Block for Details
            </div>
            <div className="flex space-x-4 mt-2">
              <div className="flex items-center">
                <div className="w-4 h-4 mr-1 rounded bg-red-500"></div>
                <span>Low (&lt;60%)</span>
              </div>
              <div className="flex items-center">
                <div className="w-4 h-4 mr-1 rounded bg-yellow-500"></div>
                <span>Medium (60-75%)</span>
              </div>
              <div className="flex items-center">
                <div className="w-4 h-4 mr-1 rounded bg-green-500"></div>
                <span>High (&gt;75%)</span>
              </div>
            </div>
          </div>
        </div>
      </Panel>

      <Panel title="Prime Time Utilization by Day of Week" isSubpanel dropLightIntensity="medium">
        <div className="h-96 bg-gray-900 rounded-lg p-4">
          <ResponsiveBar
            data={dayOfWeekData}
            keys={['utilization']}
            indexBy="day"
            margin={{ top: 20, right: 130, bottom: 50, left: 60 }}
            padding={0.3}
            colors={{ scheme: 'category10' }}
            axisBottom={{
              legend: 'Day of Week',
              legendPosition: 'middle',
              legendOffset: 32
            }}
            axisLeft={{
              legend: 'Utilization (%)',
              legendPosition: 'middle',
              legendOffset: -40
            }}
            labelSkipWidth={12}
            labelSkipHeight={12}
            theme={chartTheme}
            legends={[
              {
                dataFrom: 'keys',
                anchor: 'bottom-right',
                direction: 'column',
                justify: false,
                translateX: 120,
                translateY: 0,
                itemsSpacing: 2,
                itemWidth: 100,
                itemHeight: 20,
                itemDirection: 'left-to-right',
                itemOpacity: 0.85,
                symbolSize: 20
              }
            ]}
          />
        </div>
      </Panel>

      <Panel title="Cases by Day of Week" isSubpanel dropLightIntensity="medium">
        <div className="h-96 bg-gray-900 rounded-lg p-4">
          <ResponsiveBar
            data={dayOfWeekData}
            keys={['cases']}
            indexBy="day"
            margin={{ top: 20, right: 130, bottom: 50, left: 60 }}
            padding={0.3}
            colors={{ scheme: 'accent' }}
            axisBottom={{
              legend: 'Day of Week',
              legendPosition: 'middle',
              legendOffset: 32
            }}
            axisLeft={{
              legend: 'Number of Cases',
              legendPosition: 'middle',
              legendOffset: -40
            }}
            labelSkipWidth={12}
            labelSkipHeight={12}
            theme={chartTheme}
            legends={[
              {
                dataFrom: 'keys',
                anchor: 'bottom-right',
                direction: 'column',
                justify: false,
                translateX: 120,
                translateY: 0,
                itemsSpacing: 2,
                itemWidth: 100,
                itemHeight: 20,
                itemDirection: 'left-to-right',
                itemOpacity: 0.85,
                symbolSize: 20
              }
            ]}
          />
        </div>
      </Panel>

      {/* Custom Modal Implementation */}
      {isModalOpen && selectedTimeBlock && (
        <div className="fixed inset-0 z-50 overflow-y-auto">
          {/* Backdrop */}
          <div className="fixed inset-0 backdrop-blur-sm bg-black/30 transition-opacity" />
          
          {/* Modal */}
          <div className="flex min-h-screen items-center justify-center p-4">
            <div className={`relative w-full max-w-6xl ${isDarkMode ? 'bg-gray-800' : 'bg-white'} rounded-xl shadow-2xl transform transition-all`}>
              {/* Header */}
              <div className="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <div className="flex items-center justify-between">
                  <div className="flex items-center space-x-3">
                    <Icon 
                      icon="heroicons:chart-bar" 
                      className="w-6 h-6 text-healthcare-primary dark:text-healthcare-primary-dark" 
                    />
                    <h2 className="text-xl font-semibold text-gray-900 dark:text-gray-100">
                      {selectedTimeBlock.day} at {selectedTimeBlock.time} - {selectedTimeBlock.utilization}% Utilization
                    </h2>
                  </div>
                  <button
                    onClick={() => setIsModalOpen(false)}
                    className="text-gray-400 hover:text-gray-500 dark:hover:text-gray-300 transition-colors"
                  >
                    <span className="sr-only">Close</span>
                    <Icon icon="heroicons:x-mark" className="w-6 h-6" />
                  </button>
                </div>
              </div>
              
              {/* Content */}
              <div className="px-6 py-4">
                <div className="space-y-6">
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                      <h4 className="text-lg font-medium mb-2">Service Utilization</h4>
                      <div className="overflow-x-auto">
                        <table className="w-full text-sm text-left text-gray-500 dark:text-gray-400">
                          <thead className="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-gray-700 dark:text-gray-400">
                            <tr>
                              <th scope="col" className="px-6 py-3">Service</th>
                              <th scope="col" className="px-6 py-3">Utilization</th>
                              <th scope="col" className="px-6 py-3">Cases</th>
                            </tr>
                          </thead>
                          <tbody>
                            {selectedTimeBlock.services.map((service, index) => (
                              <tr 
                                key={index} 
                                className={`border-b dark:border-gray-700 ${index % 2 === 0 ? 'bg-white dark:bg-gray-800' : 'bg-gray-50 dark:bg-gray-700'}`}
                              >
                                <td className="px-6 py-4 font-medium text-gray-900 dark:text-white whitespace-nowrap">
                                  {service.service}
                                </td>
                                <td className="px-6 py-4">
                                  <div className="flex items-center">
                                    <span className="mr-2">{service.utilization}%</span>
                                    <div className="w-full bg-gray-200 rounded-full h-2.5 dark:bg-gray-700">
                                      <div 
                                        className={`h-2.5 rounded-full ${
                                          service.utilization < 60 ? 'bg-red-500' : 
                                          service.utilization < 75 ? 'bg-yellow-500' : 'bg-green-500'
                                        }`}
                                        style={{ width: `${service.utilization}%` }}
                                      ></div>
                                    </div>
                                  </div>
                                </td>
                                <td className="px-6 py-4">{service.cases}</td>
                              </tr>
                            ))}
                          </tbody>
                        </table>
                      </div>
                    </div>
                    
                    <div>
                      <h4 className="text-lg font-medium mb-2">Location Utilization</h4>
                      <div className="overflow-x-auto">
                        <table className="w-full text-sm text-left text-gray-500 dark:text-gray-400">
                          <thead className="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-gray-700 dark:text-gray-400">
                            <tr>
                              <th scope="col" className="px-6 py-3">Location</th>
                              <th scope="col" className="px-6 py-3">Utilization</th>
                            </tr>
                          </thead>
                          <tbody>
                            {selectedTimeBlock.services[0]?.locations.map((location, index) => (
                              <tr 
                                key={index} 
                                className={`border-b dark:border-gray-700 ${index % 2 === 0 ? 'bg-white dark:bg-gray-800' : 'bg-gray-50 dark:bg-gray-700'}`}
                              >
                                <td className="px-6 py-4 font-medium text-gray-900 dark:text-white whitespace-nowrap">
                                  {location.name}
                                </td>
                                <td className="px-6 py-4">
                                  <div className="flex items-center">
                                    <span className="mr-2">{location.utilization}%</span>
                                    <div className="w-full bg-gray-200 rounded-full h-2.5 dark:bg-gray-700">
                                      <div 
                                        className={`h-2.5 rounded-full ${
                                          location.utilization < 60 ? 'bg-red-500' : 
                                          location.utilization < 75 ? 'bg-yellow-500' : 'bg-green-500'
                                        }`}
                                        style={{ width: `${location.utilization}%` }}
                                      ></div>
                                    </div>
                                  </div>
                                </td>
                              </tr>
                            ))}
                          </tbody>
                        </table>
                      </div>
                    </div>
                  </div>
                  
                  {/* Procedure Details Section */}
                  <div>
                    <h4 className="text-lg font-medium mb-2">Procedure Details</h4>
                    <div className="overflow-x-auto">
                      <table className="w-full text-sm text-left text-gray-500 dark:text-gray-400">
                        <thead className="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-gray-700 dark:text-gray-400">
                          <tr>
                            <th scope="col" className="px-6 py-3">Service</th>
                            <th scope="col" className="px-6 py-3">Procedure</th>
                            <th scope="col" className="px-6 py-3">Count</th>
                            <th scope="col" className="px-6 py-3">Duration</th>
                          </tr>
                        </thead>
                        <tbody>
                          {selectedTimeBlock.services.flatMap((service, serviceIndex) => 
                            service.procedures.map((procedure, procedureIndex) => (
                              <tr 
                                key={`${serviceIndex}-${procedureIndex}`} 
                                className={`border-b dark:border-gray-700 ${(serviceIndex + procedureIndex) % 2 === 0 ? 'bg-white dark:bg-gray-800' : 'bg-gray-50 dark:bg-gray-700'}`}
                              >
                                <td className="px-6 py-4 font-medium text-gray-900 dark:text-white whitespace-nowrap">
                                  {service.service}
                                </td>
                                <td className="px-6 py-4">
                                  {procedure.name}
                                </td>
                                <td className="px-6 py-4">
                                  {procedure.count}
                                </td>
                                <td className="px-6 py-4">
                                  {procedure.duration}
                                </td>
                              </tr>
                            ))
                          )}
                        </tbody>
                      </table>
                    </div>
                  </div>
                  
                  {/* Room Utilization Section */}
                  <div>
                    <h4 className="text-lg font-medium mb-2">Room Utilization</h4>
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                      {selectedTimeBlock.services[0]?.locations.map((location, locationIndex) => (
                        <div key={locationIndex} className="bg-white dark:bg-gray-800 p-4 rounded-lg shadow">
                          <h5 className="font-medium text-gray-900 dark:text-white mb-2">{location.name}</h5>
                          <div className="space-y-3">
                            {location.rooms?.map((room, roomIndex) => (
                              <div key={roomIndex} className="flex items-center justify-between">
                                <span className="text-sm text-gray-500 dark:text-gray-400">Room {room.number}</span>
                                <div className="flex items-center">
                                  <span className="text-sm text-gray-500 dark:text-gray-400 mr-2">{room.utilization}%</span>
                                  <div className="w-24 bg-gray-200 rounded-full h-2 dark:bg-gray-700">
                                    <div 
                                      className={`h-2 rounded-full ${
                                        room.utilization < 60 ? 'bg-red-500' : 
                                        room.utilization < 75 ? 'bg-yellow-500' : 'bg-green-500'
                                      }`}
                                      style={{ width: `${room.utilization}%` }}
                                    ></div>
                                  </div>
                                  <span className="ml-2 text-xs text-gray-500 dark:text-gray-400">{room.cases} cases</span>
                                </div>
                              </div>
                            ))}
                          </div>
                        </div>
                      ))}
                    </div>
                  </div>
                  
                  <div>
                    <h4 className="text-lg font-medium mb-2">Opportunities for Improvement</h4>
                    <ul className="list-disc pl-5 space-y-1">
                      <li>Optimize scheduling for {selectedTimeBlock.utilization < 60 ? 'underutilized' : 'highly utilized'} time blocks</li>
                      <li>Consider {selectedTimeBlock.utilization < 60 ? 'increasing' : 'maintaining'} case volume during this time period</li>
                      <li>Review staffing levels to ensure appropriate coverage</li>
                      {selectedTimeBlock.utilization > 85 && (
                        <li>Monitor for potential overutilization that could lead to delays or overtime</li>
                      )}
                      {selectedTimeBlock.services.some(s => s.utilization < 50) && (
                        <li>Evaluate low-utilization services for potential block time reallocation</li>
                      )}
                    </ul>
                  </div>
                </div>
              </div>
              
              {/* Footer */}
              <div className="px-6 py-4 border-t border-gray-200 dark:border-gray-700 flex items-center justify-between">
                <div className="text-sm text-gray-500 dark:text-gray-400">
                  Data updated: {new Date().toLocaleDateString()}
                </div>
                <div className="flex items-center space-x-3">
                  <button
                    onClick={() => setIsModalOpen(false)}
                    className="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-healthcare-primary hover:bg-healthcare-primary-dark focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-healthcare-primary"
                  >
                    Close
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default DayOfWeekView;
