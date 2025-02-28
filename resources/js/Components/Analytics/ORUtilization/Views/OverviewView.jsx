import React from 'react';
import Panel from '@/Components/ui/Panel';
import EfficiencyMetricsCard from '../EfficiencyMetricsCard';
import OpportunityMetricsCard from '../OpportunityMetricsCard';
import UtilizationTrendsCard from '../UtilizationTrendsCard';
import SpecialtyDistributionCard from '../SpecialtyDistributionCard';
import RoomUtilizationCard from '../RoomUtilizationCard';

const OverviewView = ({ data, derivedMetrics }) => {
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

  // Extract necessary data
  const locationData = getSelectedLocationData();
  
  // Get efficiency metrics
  const efficiencyMetrics = derivedMetrics?.efficiencyRatio ? {
    efficiencyRatio: derivedMetrics.efficiencyRatio,
    casesPerDay: locationData?.casesPerDay || 0,
    turnoverTime: locationData?.averageTurnoverTime || 0,
    caseDuration: locationData?.averageCaseDuration || 0
  } : null;
  
  // Get opportunity metrics
  const opportunityMetrics = derivedMetrics?.opportunity ? {
    utilizationGap: derivedMetrics.opportunity.utilizationGap,
    potentialAdditionalCases: derivedMetrics.opportunity.potentialAdditionalCases,
    targetUtilization: derivedMetrics.opportunity.targetUtilization,
    currentUtilization: locationData?.utilization || 0
  } : null;

  // Get trends data
  const trendsData = data?.trends?.[Object.keys(data.trends)[0]]?.utilization?.map(item => ({
    date: item.month,
    utilization: item.value
  })) || [];

  // Get room data
  const roomData = locationData?.rooms || [];

  return (
    <div>
      <Panel title="OR Utilization Overview" className="mb-6">
        <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-4">
          Comprehensive view of operating room utilization metrics and opportunities for {getSelectedLocationName()}.
        </p>
        
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
          {efficiencyMetrics && (
            <Panel isSubpanel={true} dropLightIntensity="medium" title="Efficiency Metrics">
              <EfficiencyMetricsCard {...efficiencyMetrics} />
            </Panel>
          )}
          
          {opportunityMetrics && (
            <Panel isSubpanel={true} dropLightIntensity="medium" title="Opportunity Metrics">
              <OpportunityMetricsCard {...opportunityMetrics} />
            </Panel>
          )}
        </div>
        
        <Panel isSubpanel={true} dropLightIntensity="medium" title="Utilization Trends" className="mb-6">
          <UtilizationTrendsCard 
            trendsData={trendsData}
            comparisonTrendsData={[]}
            showComparison={false}
          />
        </Panel>
        
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <Panel isSubpanel={true} dropLightIntensity="medium" title="Specialty Distribution">
            <SpecialtyDistributionCard 
              specialtyData={data?.specialties || {}}
            />
          </Panel>
          
          <Panel isSubpanel={true} dropLightIntensity="medium" title="Room Utilization">
            <RoomUtilizationCard 
              roomData={roomData}
            />
          </Panel>
        </div>
      </Panel>
    </div>
  );
};

export default OverviewView;
