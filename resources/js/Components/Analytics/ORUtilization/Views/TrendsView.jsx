import React from 'react';
import Panel from '@/Components/ui/Panel';
import { LineChart } from '@/Components/ui/charts/LineChart';

// P5: the fabricated series (previous-year comparison, day-of-week,
// time-of-day) were mock-bundle content and are gone — this view charts only
// the live monthly utilization trend from OrUtilizationService, with an
// honest empty state when the period is bare.
const TrendsView = ({ data }) => {
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

  // Live monthly utilization trend for the first (selected) location.
  const trendsData = (data?.trends?.[Object.keys(data.trends)[0]]?.utilization ?? []).map(item => ({
    date: item.month,
    utilization: item.value,
  }));

  const series = trendsData.length > 0
    ? [
        {
          id: 'Utilization',
          data: trendsData.map(item => ({
            x: item.date,
            y: Math.round(item.utilization * 100),
          })),
        },
      ]
    : [];

  return (
    <div>
      <Panel title={`Utilization Trends: ${getSelectedLocationName()}`} className="mb-6">
        <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-4">
          Historical monthly OR utilization for the selected location.
        </p>

        <Panel isSubpanel={true} dropLightIntensity="medium" title="Monthly Utilization Trends">
          {series.length > 0 ? (
            <div className="h-80">
              <LineChart
                data={series}
                margin={{ top: 20, right: 20, bottom: 60, left: 60 }}
                xScale={{ type: 'point' }}
                yScale={{ type: 'linear', min: 0, max: 100 }}
                axisBottom={{
                  tickRotation: -45,
                  legend: 'Month',
                  legendOffset: 50,
                  legendPosition: 'middle'
                }}
                axisLeft={{
                  legend: 'Utilization (%)',
                  legendOffset: -40,
                  legendPosition: 'middle'
                }}
                enablePoints={true}
                pointSize={8}
                pointColor={{ theme: 'background' }}
                pointBorderWidth={2}
                pointBorderColor={{ from: 'serieColor' }}
                enableSlices="x"
                useMesh={true}
              />
            </div>
          ) : (
            <p className="p-6 text-center text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              No utilization trend data is available for this period.
            </p>
          )}
        </Panel>
      </Panel>
    </div>
  );
};

export default TrendsView;
