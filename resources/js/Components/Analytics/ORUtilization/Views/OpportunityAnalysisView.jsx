import React from 'react';
import Panel from '@/Components/ui/Panel';

// P5: the fabricated Block Time Optimization / Efficiency Improvement /
// Financial Impact panels were 100% mock-bundle content and are gone. This
// view now shows only the live opportunity metrics derived from the
// OrUtilizationService payload (current vs target utilization, potential
// additional cases) — with honest placeholders when nothing is derivable.
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

  const locationData = getSelectedLocationData();

  const opportunityMetrics = derivedMetrics?.opportunity
    ? {
        utilizationGap: derivedMetrics.opportunity.utilizationGap,
        potentialAdditionalCases: derivedMetrics.opportunity.potentialAdditionalCases,
        targetUtilization: derivedMetrics.opportunity.targetUtilization,
        currentUtilization: locationData?.utilization || 0,
      }
    : null;

  const pct = (value) =>
    value !== undefined && value !== null && !Number.isNaN(value)
      ? `${Math.round(value * 100)}%`
      : '—';

  return (
    <div>
      <Panel title={`Opportunity Analysis: ${getSelectedLocationName()}`} className="mb-6">
        <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-4">
          Identify opportunities to improve OR utilization and efficiency, with actionable recommendations.
        </p>

        <Panel isSubpanel={true} dropLightIntensity="strong" title="Utilization Improvement Opportunities">
          {opportunityMetrics || locationData ? (
            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
              <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-lg shadow-sm p-4">
                <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-2">
                  Current Utilization
                </h3>
                <div className="flex items-center justify-center h-24">
                  <span className="text-4xl font-semibold text-healthcare-primary dark:text-healthcare-primary-dark tabular-nums">
                    {pct(opportunityMetrics?.currentUtilization ?? locationData?.utilization)}
                  </span>
                </div>
              </div>

              <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-lg shadow-sm p-4">
                <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-2">
                  Target Utilization
                </h3>
                <div className="flex items-center justify-center h-24">
                  <span className="text-4xl font-semibold text-healthcare-success dark:text-healthcare-success-dark tabular-nums">
                    {pct(opportunityMetrics?.targetUtilization)}
                  </span>
                </div>
              </div>

              <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-lg shadow-sm p-4">
                <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-2">
                  Potential Additional Cases
                </h3>
                <div className="flex items-center justify-center h-24">
                  <span className="text-4xl font-semibold text-healthcare-secondary dark:text-healthcare-secondary-dark tabular-nums">
                    {opportunityMetrics?.potentialAdditionalCases ?? '—'}
                  </span>
                </div>
              </div>
            </div>
          ) : (
            <p className="p-6 text-center text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              No opportunity metrics are available for this period.
            </p>
          )}
        </Panel>
      </Panel>
    </div>
  );
};

export default OpportunityAnalysisView;
