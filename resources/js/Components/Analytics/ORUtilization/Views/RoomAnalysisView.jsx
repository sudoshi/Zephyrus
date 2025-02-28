import React from 'react';
import Panel from '@/Components/ui/Panel';
import { BarChart } from '@/Components/ui/charts/BarChart';
import { 
  mockRoomData, 
  mockRoomTurnoverData, 
  mockRoomSchedulingData,
  mockRoomHeatmapData
} from '../mockData';

const RoomAnalysisView = ({ data }) => {
  // Get location data
  const getSelectedLocationData = () => {
    if (!data || !data.locations) return null;
    return data.locations[Object.keys(data.locations)[0]];
  };
  
  // Get selected location name
  const getSelectedLocationName = () => {
    const locationData = getSelectedLocationData();
    if (locationData) {
      return locationData.fullName || locationData.name;
    }
    return 'All Locations';
  };

  // Get room data
  const getRoomData = () => {
    const locationData = getSelectedLocationData();
    const realRoomData = locationData?.rooms || [];
    return realRoomData.length > 0 ? realRoomData : mockRoomData;
  };

  // Format room utilization data for bar chart with validation
  const formatRoomUtilizationData = () => {
    const roomData = getRoomData();
    return roomData.map(room => {
      // Ensure utilization is a valid number
      const utilization = room.utilization !== undefined && !isNaN(room.utilization) 
        ? Math.round(room.utilization * 100) 
        : 0;
      
      return {
        name: room.name || 'Unknown Room',
        utilization: utilization
      };
    });
  };

  // Format room turnover data for bar chart with validation
  const formatRoomTurnoverData = () => {
    return mockRoomTurnoverData.map(item => {
      // Ensure actual and benchmark are valid numbers
      const actual = item.actual !== undefined && !isNaN(item.actual) ? item.actual : 0;
      const benchmark = item.benchmark !== undefined && !isNaN(item.benchmark) ? item.benchmark : 0;
      
      return {
        name: item.room || 'Unknown Room',
        actual: actual,
        benchmark: benchmark
      };
    });
  };

  // Format cases per room data with validation
  const formatCasesPerRoomData = () => {
    const roomData = getRoomData();
    return roomData.map(room => ({
      name: room.name || 'Unknown Room',
      cases: room.cases !== undefined && !isNaN(room.cases) ? room.cases : 0
    }));
  };

  return (
    <div>
      <Panel title={`Room Analysis: ${getSelectedLocationName()}`} className="mb-6">
        <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-4">
          Detailed analysis of individual operating room performance and utilization metrics.
        </p>
        
        <Panel isSubpanel={true} dropLightIntensity="medium" title="Room Utilization Comparison" className="mb-6">
          <div className="h-64">
            <BarChart 
              data={formatRoomUtilizationData()}
              keys={['utilization']}
              indexBy="name"
              margin={{ top: 20, right: 20, bottom: 50, left: 60 }}
              padding={0.3}
              axisBottom={{
                tickSize: 5,
                tickPadding: 5,
                tickRotation: -45,
                legend: 'Operating Room',
                legendPosition: 'middle',
                legendOffset: 40
              }}
              axisLeft={{
                tickSize: 5,
                tickPadding: 5,
                tickRotation: 0,
                legend: 'Utilization (%)',
                legendPosition: 'middle',
                legendOffset: -50
              }}
              labelFormat={value => `${value}%`}
              colorScheme="primary"
              labelSkipWidth={12}
              labelSkipHeight={12}
              labelTextColor={{ from: 'color', modifiers: [['darker', 1.6]] }}
              animate={true}
              motionStiffness={90}
              motionDamping={15}
            />
          </div>
        </Panel>
        
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <Panel isSubpanel={true} dropLightIntensity="medium" title="Room Turnover Analysis">
            <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-4">
              Analysis of room turnover times compared to benchmarks, highlighting opportunities for improvement.
            </p>
            <div className="h-64">
              <BarChart 
                data={formatRoomTurnoverData()}
                keys={['actual', 'benchmark']}
                indexBy="name"
                margin={{ top: 20, right: 20, bottom: 50, left: 60 }}
                padding={0.3}
                groupMode="grouped"
                axisBottom={{
                  tickSize: 5,
                  tickPadding: 5,
                  tickRotation: -45,
                  legend: 'Operating Room',
                  legendPosition: 'middle',
                  legendOffset: 40
                }}
                axisLeft={{
                  tickSize: 5,
                  tickPadding: 5,
                  tickRotation: 0,
                  legend: 'Minutes',
                  legendPosition: 'middle',
                  legendOffset: -50
                }}
                labelFormat={value => `${value} min`}
                colorScheme="mixed"
                labelSkipWidth={12}
                labelSkipHeight={12}
                labelTextColor={{ from: 'color', modifiers: [['darker', 1.6]] }}
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
                animate={true}
                motionStiffness={90}
                motionDamping={15}
              />
            </div>
          </Panel>
          
          <Panel isSubpanel={true} dropLightIntensity="medium" title="Cases Per Room">
            <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-4">
              Analysis of case volume by room, showing which rooms are handling the most cases.
            </p>
            <div className="h-64">
              <BarChart 
                data={formatCasesPerRoomData()}
                keys={['cases']}
                indexBy="name"
                margin={{ top: 20, right: 20, bottom: 50, left: 60 }}
                padding={0.3}
                colors={{ scheme: 'blues' }}
                axisBottom={{
                  tickSize: 5,
                  tickPadding: 5,
                  tickRotation: -45,
                  legend: 'Operating Room',
                  legendPosition: 'middle',
                  legendOffset: 40
                }}
                axisLeft={{
                  tickSize: 5,
                  tickPadding: 5,
                  tickRotation: 0,
                  legend: 'Number of Cases',
                  legendPosition: 'middle',
                  legendOffset: -50
                }}
                colorScheme="success"
                labelSkipWidth={12}
                labelSkipHeight={12}
                labelTextColor={{ from: 'color', modifiers: [['darker', 1.6]] }}
                animate={true}
                motionStiffness={90}
                motionDamping={15}
              />
            </div>
          </Panel>
        </div>
        
        <Panel isSubpanel={true} dropLightIntensity="strong" title="Room Utilization Heatmap" className="mt-6">
          <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-4">
            Heatmap showing room utilization patterns across different times of day.
          </p>
          <div className="h-80 overflow-x-auto">
            <div className="min-w-full">
              <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead className="bg-gray-50 dark:bg-gray-800">
                  <tr>
                    <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                      Room
                    </th>
                    {Object.keys(mockRoomHeatmapData[0]).filter(key => key !== 'room').map(hour => (
                      <th key={hour} scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                        {hour}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody className="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-700">
                  {mockRoomHeatmapData.map((room, idx) => (
                    <tr key={idx} className={idx % 2 === 0 ? 'bg-white dark:bg-gray-900' : 'bg-gray-50 dark:bg-gray-800'}>
                      <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-gray-100">
                        {room.room}
                      </td>
                      {Object.keys(room).filter(key => key !== 'room').map(hour => {
                        const utilization = room[hour] !== undefined && !isNaN(room[hour]) ? room[hour] : 0;
                        let bgColor = 'bg-green-100 dark:bg-green-900';
                        let textColor = 'text-green-800 dark:text-green-200';
                        
                        if (utilization < 0.5) {
                          bgColor = 'bg-red-100 dark:bg-red-900';
                          textColor = 'text-red-800 dark:text-red-200';
                        } else if (utilization < 0.7) {
                          bgColor = 'bg-yellow-100 dark:bg-yellow-900';
                          textColor = 'text-yellow-800 dark:text-yellow-200';
                        }
                        
                        return (
                          <td key={hour} className="px-6 py-4 whitespace-nowrap text-sm">
                            <span className={`px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full ${bgColor} ${textColor}`}>
                              {Math.round(utilization * 100)}%
                            </span>
                          </td>
                        );
                      })}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </Panel>
      </Panel>
    </div>
  );
};

export default RoomAnalysisView;
