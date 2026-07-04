import React from 'react';
import Panel from '@/Components/ui/Panel';
import { BarChart } from '@/Components/ui/charts/BarChart';

// P5: mock-bundle fallbacks removed — room slices render from the live
// OrUtilizationService payload only. The fabricated turnover-benchmark and
// hourly-heatmap panels are gone (no live source yet).
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

  // Get room data (live only — empty array renders empty charts, never fiction)
  const getRoomData = () => {
    const locationData = getSelectedLocationData();
    return locationData?.rooms || [];
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
        
        <div className="grid grid-cols-1 gap-6">
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
      </Panel>
    </div>
  );
};

export default RoomAnalysisView;
