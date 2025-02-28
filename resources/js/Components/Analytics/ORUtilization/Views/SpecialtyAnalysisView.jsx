import React from 'react';
import Panel from '@/Components/ui/Panel';
import { BarChart } from '@/Components/ui/charts/BarChart';
import { PieChart } from '@/Components/ui/charts/PieChart';
import { 
  mockSpecialtyData, 
  mockSpecialtyCaseDurationData 
} from '../mockData';

const SpecialtyAnalysisView = ({ data }) => {
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

  // Format specialty distribution data for pie chart
  const formatSpecialtyDistributionData = () => {
    const specialtyData = data?.specialties || mockSpecialtyData;
    
    return Object.entries(specialtyData).map(([specialty, details]) => ({
      id: specialty,
      label: specialty,
      value: details.cases !== undefined && !isNaN(details.cases) ? details.cases : 0
    }));
  };

  // Format specialty utilization data for bar chart
  const formatSpecialtyUtilizationData = () => {
    const specialtyData = data?.specialties || mockSpecialtyData;
    
    return Object.entries(specialtyData).map(([specialty, details]) => ({
      specialty,
      utilization: details.utilization !== undefined && !isNaN(details.utilization) 
        ? Math.round(details.utilization * 100) 
        : 0
    }));
  };

  // Format specialty turnover time data for bar chart
  const formatSpecialtyTurnoverData = () => {
    const specialtyData = data?.specialties || mockSpecialtyData;
    
    return Object.entries(specialtyData).map(([specialty, details]) => ({
      specialty,
      turnoverTime: details.turnoverTime !== undefined && !isNaN(details.turnoverTime) 
        ? details.turnoverTime 
        : 0
    }));
  };

  // Format specialty case duration accuracy data for bar chart
  const formatSpecialtyCaseDurationData = () => {
    return mockSpecialtyCaseDurationData.map(item => ({
      specialty: item.specialty,
      scheduled: item.scheduled !== undefined && !isNaN(item.scheduled) ? item.scheduled : 0,
      actual: item.actual !== undefined && !isNaN(item.actual) ? item.actual : 0
    }));
  };

  return (
    <div>
      <Panel title={`Specialty Analysis: ${getSelectedLocationName()}`} className="mb-6">
        <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-4">
          Utilization metrics broken down by surgical specialty, showing performance and opportunities by service line.
        </p>
        
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
          <Panel isSubpanel={true} dropLightIntensity="medium" title="Specialty Distribution">
            <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-4">
              Distribution of cases by surgical specialty, showing the relative volume of each service line.
            </p>
            <div className="h-64">
              <PieChart 
                data={formatSpecialtyDistributionData()}
                margin={{ top: 20, right: 20, bottom: 20, left: 20 }}
                innerRadius={0.5}
                padAngle={0.7}
                cornerRadius={3}
                activeOuterRadiusOffset={8}
                borderWidth={1}
                borderColor={{ from: 'color', modifiers: [['darker', 0.2]] }}
                arcLinkLabelsSkipAngle={10}
                arcLinkLabelsTextColor={{ from: 'color', modifiers: [] }}
                arcLinkLabelsThickness={2}
                arcLinkLabelsColor={{ from: 'color' }}
                arcLabelsSkipAngle={10}
                arcLabelsTextColor={{ from: 'color', modifiers: [['darker', 2]] }}
                legends={[
                  {
                    anchor: 'right',
                    direction: 'column',
                    justify: false,
                    translateX: 0,
                    translateY: 0,
                    itemsSpacing: 0,
                    itemWidth: 100,
                    itemHeight: 20,
                    itemTextColor: '#999',
                    itemDirection: 'left-to-right',
                    itemOpacity: 1,
                    symbolSize: 18,
                    symbolShape: 'circle'
                  }
                ]}
              />
            </div>
          </Panel>
          
          <Panel isSubpanel={true} dropLightIntensity="medium" title="Specialty Utilization">
            <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-4">
              Utilization rates by specialty, showing which service lines are most efficiently using their allocated time.
            </p>
            <div className="h-64">
              <BarChart 
                data={formatSpecialtyUtilizationData()}
                keys={['utilization']}
                indexBy="specialty"
                margin={{ top: 20, right: 20, bottom: 50, left: 60 }}
                padding={0.3}
                axisBottom={{
                  tickSize: 5,
                  tickPadding: 5,
                  tickRotation: -45,
                  legend: 'Specialty',
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
        </div>
        
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <Panel isSubpanel={true} dropLightIntensity="medium" title="Specialty Turnover Times">
            <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-4">
              Average turnover times by specialty, highlighting opportunities for process improvement.
            </p>
            <div className="h-64">
              <BarChart 
                data={formatSpecialtyTurnoverData()}
                keys={['turnoverTime']}
                indexBy="specialty"
                margin={{ top: 20, right: 20, bottom: 50, left: 60 }}
                padding={0.3}
                axisBottom={{
                  tickSize: 5,
                  tickPadding: 5,
                  tickRotation: -45,
                  legend: 'Specialty',
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
                colorScheme="secondary"
                labelSkipWidth={12}
                labelSkipHeight={12}
                labelTextColor={{ from: 'color', modifiers: [['darker', 1.6]] }}
                animate={true}
                motionStiffness={90}
                motionDamping={15}
              />
            </div>
          </Panel>
          
          <Panel isSubpanel={true} dropLightIntensity="medium" title="Specialty Case Duration Accuracy">
            <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-4">
              Comparison of scheduled vs. actual case durations by specialty, showing scheduling accuracy.
            </p>
            <div className="h-64">
              <BarChart 
                data={formatSpecialtyCaseDurationData()}
                keys={['scheduled', 'actual']}
                indexBy="specialty"
                margin={{ top: 20, right: 20, bottom: 50, left: 60 }}
                padding={0.3}
                groupMode="grouped"
                axisBottom={{
                  tickSize: 5,
                  tickPadding: 5,
                  tickRotation: -45,
                  legend: 'Specialty',
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
        </div>
      </Panel>
    </div>
  );
};

export default SpecialtyAnalysisView;
