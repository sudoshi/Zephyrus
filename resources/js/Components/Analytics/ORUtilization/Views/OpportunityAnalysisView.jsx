import React from 'react';
import Panel from '@/Components/ui/Panel';
import { BarChart } from '@/Components/ui/charts/BarChart';
import { PieChart } from '@/Components/ui/charts/PieChart';
import { 
  mockBlockOptimizationDataOpportunityAnalysis as mockBlockOptimizationData, 
  mockEfficiencyImprovementDataOpportunityAnalysis as mockEfficiencyImprovementData,
  mockFinancialImpactDataOpportunityAnalysis as mockFinancialImpactData
} from '../mockData';

const OpportunityAnalysisView = ({ data, derivedMetrics }) => {
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

  // Extract location data
  const locationData = getSelectedLocationData();
  
  // Get opportunity metrics
  const opportunityMetrics = derivedMetrics?.opportunity ? {
    utilizationGap: derivedMetrics.opportunity.utilizationGap,
    potentialAdditionalCases: derivedMetrics.opportunity.potentialAdditionalCases,
    targetUtilization: derivedMetrics.opportunity.targetUtilization,
    currentUtilization: locationData?.utilization || 0
  } : null;

  // Format block optimization data for bar chart
  const formatBlockOptimizationData = () => {
    return mockBlockOptimizationData.map(item => ({
      specialty: item.specialty,
      allocated: item.allocated,
      utilized: item.utilized,
      opportunity: item.opportunity
    }));
  };

  // Format efficiency improvement data for bar chart
  const formatEfficiencyImprovementData = () => {
    return mockEfficiencyImprovementData.map(item => ({
      category: item.category,
      current: item.current,
      benchmark: item.benchmark,
      opportunity: item.opportunity
    }));
  };

  // Format financial impact data for pie chart
  const formatFinancialImpactData = () => {
    return mockFinancialImpactData.map(item => ({
      id: item.category,
      label: item.category,
      value: item.value
    }));
  };

  return (
    <div>
      <Panel title={`Opportunity Analysis: ${getSelectedLocationName()}`} className="mb-6">
        <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-4">
          Identify opportunities to improve OR utilization and efficiency, with actionable recommendations.
        </p>
        
        <Panel isSubpanel={true} dropLightIntensity="strong" title="Utilization Improvement Opportunities" className="mb-6">
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4">
              <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-2">
                Current Utilization
              </h3>
              <div className="flex items-center justify-center h-24">
                <span className="text-4xl font-bold text-healthcare-primary dark:text-healthcare-primary-dark">
                  {Math.round((opportunityMetrics?.currentUtilization || locationData?.utilization || 0.75) * 100)}%
                </span>
              </div>
            </div>
            
            <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4">
              <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-2">
                Target Utilization
              </h3>
              <div className="flex items-center justify-center h-24">
                <span className="text-4xl font-bold text-healthcare-success dark:text-healthcare-success-dark">
                  {Math.round((opportunityMetrics?.targetUtilization || 0.85) * 100)}%
                </span>
              </div>
            </div>
            
            <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4">
              <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-2">
                Potential Additional Cases
              </h3>
              <div className="flex items-center justify-center h-24">
                <span className="text-4xl font-bold text-healthcare-secondary dark:text-healthcare-secondary-dark">
                  {opportunityMetrics?.potentialAdditionalCases || 120}
                </span>
              </div>
            </div>
          </div>
        </Panel>
        
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <Panel isSubpanel={true} dropLightIntensity="medium" title="Block Time Optimization">
            <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-4">
              Analysis of block time allocation and utilization by specialty, highlighting opportunities for reallocation.
            </p>
            <div className="h-64">
              <BarChart 
                data={formatBlockOptimizationData()}
                keys={['utilized', 'opportunity']}
                indexBy="specialty"
                margin={{ top: 20, right: 20, bottom: 50, left: 60 }}
                padding={0.3}
                groupMode="stacked"
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
                  legend: 'Hours',
                  legendPosition: 'middle',
                  legendOffset: -50
                }}
                colors={['#4C9AFF', '#FF5630']}
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
                    symbolSize: 20,
                    effects: [
                      {
                        on: 'hover',
                        style: {
                          itemOpacity: 1
                        }
                      }
                    ]
                  }
                ]}
                animate={true}
                motionStiffness={90}
                motionDamping={15}
              />
            </div>
          </Panel>
          
          <Panel isSubpanel={true} dropLightIntensity="medium" title="Efficiency Improvement Opportunities">
            <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-4">
              Key metrics with improvement opportunities, comparing current performance to benchmarks.
            </p>
            <div className="h-64">
              <BarChart 
                data={formatEfficiencyImprovementData()}
                keys={['current', 'benchmark']}
                indexBy="category"
                margin={{ top: 20, right: 20, bottom: 50, left: 60 }}
                padding={0.3}
                groupMode="grouped"
                axisBottom={{
                  tickSize: 5,
                  tickPadding: 5,
                  tickRotation: -45,
                  legend: 'Category',
                  legendPosition: 'middle',
                  legendOffset: 40
                }}
                axisLeft={{
                  tickSize: 5,
                  tickPadding: 5,
                  tickRotation: 0,
                  legend: 'Value',
                  legendPosition: 'middle',
                  legendOffset: -50
                }}
                colors={['#6554C0', '#00B8D9']}
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
        
        <Panel isSubpanel={true} dropLightIntensity="strong" title="Financial Impact Analysis" className="mt-6">
          <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-4">
            Estimated financial impact of implementing recommended improvements.
          </p>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div className="h-64">
              <PieChart 
                data={formatFinancialImpactData().filter(item => !item.id.includes('Potential'))}
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
                valueFormat={value => `$${(value / 1000000).toFixed(1)}M`}
                colors={['#36B37E', '#FF5630', '#00B8D9']}
              />
            </div>
            <div>
              <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-4">
                Potential Financial Benefits
              </h3>
              <div className="space-y-4">
                {formatFinancialImpactData()
                  .filter(item => item.id.includes('Potential'))
                  .map((item, index) => (
                    <div key={index} className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4">
                      <h4 className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                        {item.label}
                      </h4>
                      <p className="text-2xl font-bold text-healthcare-success dark:text-healthcare-success-dark">
                        ${(item.value / 1000000).toFixed(1)}M
                      </p>
                    </div>
                  ))}
                <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4">
                  <h4 className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    Potential ROI
                  </h4>
                  <p className="text-2xl font-bold text-healthcare-success dark:text-healthcare-success-dark">
                    81%
                  </p>
                </div>
              </div>
            </div>
          </div>
        </Panel>
      </Panel>
    </div>
  );
};

export default OpportunityAnalysisView;
