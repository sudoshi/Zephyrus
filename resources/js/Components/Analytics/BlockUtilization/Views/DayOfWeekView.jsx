import React, { useMemo } from 'react';
import { ResponsiveBar } from '@nivo/bar';
import { MetricCard, Panel } from '@/Components/ui';

const DayOfWeekView = ({ filters, data }) => {
  // Extract filter values from the new filter structure
  const { selectedHospital, selectedLocation, selectedSpecialty, dateRange } = filters;
  
  // Filter data based on hierarchical filters
  const filteredData = useMemo(() => {
    // In a real application, we would filter the day of week data based on the selected filters
    // For now, we'll just use the mock data
    return data.dayOfWeekData;
  }, [selectedHospital, selectedLocation, selectedSpecialty, dateRange]);

  // Helper function to format percentages
  const formatPercentage = (value) => {
    if (typeof value === 'number') {
      return `${value.toFixed(1)}%`;
    } else if (typeof value === 'string') {
      // If it's already a string, just return it (it might already have % sign)
      return value;
    }
    return 'N/A';
  };

  // Get metrics based on filtered data
  const getFilteredMetrics = () => {
    // In a real application, we would calculate metrics based on filtered data
    // For now, we'll just use the overall metrics
    return data.overallMetrics;
  };
  
  const metrics = getFilteredMetrics();

  return (
    <div className="animate-fadeIn">
      <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <MetricCard 
          title="In-Block Utilization" 
          value={formatPercentage(metrics.inBlockUtilization)} 
          trend="+2.3%" 
          trendDirection="up"
          icon="chart-bar"
          iconColor="text-blue-500"
          isSubpanel={true}
        />
        <MetricCard 
          title="Total Block Utilization" 
          value={formatPercentage(metrics.totalBlockUtilization)} 
          trend="+1.8%" 
          trendDirection="up"
          icon="chart-pie"
          iconColor="text-emerald-500"
          isSubpanel={true}
        />
        <MetricCard 
          title="Non-Prime Time" 
          value={formatPercentage(metrics.nonPrimePercentage)} 
          trend="-0.7%" 
          trendDirection="down"
          icon="clock"
          iconColor="text-purple-500"
          isSubpanel={true}
        />
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <Panel title="Day of Week Utilization">
          <div className="h-80">
            <ResponsiveBar
              data={[
                { day: 'Monday', utilization: 72.3 },
                { day: 'Tuesday', utilization: 76.5 },
                { day: 'Wednesday', utilization: 74.8 },
                { day: 'Thursday', utilization: 71.2 },
                { day: 'Friday', utilization: 65.7 },
              ]}
              keys={['utilization']}
              indexBy="day"
              margin={{ top: 10, right: 30, bottom: 50, left: 60 }}
              padding={0.3}
              valueScale={{ type: 'linear' }}
              indexScale={{ type: 'band', round: true }}
              colors={['#3B82F6']}
              borderColor={{ from: 'color', modifiers: [['darker', 1.6]] }}
              axisTop={null}
              axisRight={null}
              axisBottom={{
                tickSize: 5,
                tickPadding: 5,
                tickRotation: 0,
                legend: 'Day of Week',
                legendPosition: 'middle',
                legendOffset: 32,
                truncateTickAt: 0
              }}
              axisLeft={{
                tickSize: 5,
                tickPadding: 5,
                tickRotation: 0,
                legend: 'Utilization (%)',
                legendPosition: 'middle',
                legendOffset: -40,
                truncateTickAt: 0
              }}
              labelSkipWidth={12}
              labelSkipHeight={12}
              labelTextColor={{ from: 'color', modifiers: [['darker', 1.6]] }}
              role="application"
              ariaLabel="Day of week utilization chart"
              barAriaLabel={e => e.id + ": " + e.formattedValue + " on " + e.indexValue}
            />
          </div>
        </Panel>

        <Panel title="Daily Breakdown">
          <div className="space-y-4">
            {[
              { name: 'Monday', utilization: 72.3 },
              { name: 'Tuesday', utilization: 76.5 },
              { name: 'Wednesday', utilization: 74.8 },
              { name: 'Thursday', utilization: 71.2 },
              { name: 'Friday', utilization: 65.7 },
            ].map((day, index) => (
              <div key={index} className="border-b pb-3 last:border-0">
                <div className="flex justify-between mb-2">
                  <h3 className="font-medium dark:text-white">{day.name}</h3>
                  <span className="text-healthcare-info dark:text-healthcare-info-dark">{formatPercentage(day.utilization)}</span>
                </div>
                <div className="w-full bg-healthcare-border dark:bg-healthcare-border-dark rounded-full h-2.5">
                  <div 
                    className={`h-2.5 rounded-full ${
                      day.utilization > 75 ? 'bg-healthcare-success dark:bg-healthcare-success-dark' :
                      day.utilization > 70 ? 'bg-healthcare-info dark:bg-healthcare-info-dark' :
                      day.utilization > 65 ? 'bg-healthcare-warning dark:bg-healthcare-warning-dark' : 'bg-healthcare-critical dark:bg-healthcare-critical-dark'
                    }`}
                    style={{ width: `${day.utilization}%` }}
                  ></div>
                </div>
              </div>
            ))}
          </div>
        </Panel>
      </div>

      <div className="mt-6">
        <Panel title="Day of Week Insights">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <Panel title="Time Distribution" isSubpanel={true} dropLightIntensity="medium">
              <div className="space-y-3">
                <div>
                  <h4 className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-1">Morning (8am-12pm)</h4>
                  <div className="flex justify-between text-sm mb-1">
                    <span className="dark:text-white">Utilization</span>
                    <span className="text-healthcare-info dark:text-healthcare-info-dark">74.2%</span>
                  </div>
                  <div className="w-full bg-healthcare-border dark:bg-healthcare-border-dark rounded-full h-2">
                    <div className="bg-healthcare-info dark:bg-healthcare-info-dark h-2 rounded-full" style={{ width: '74.2%' }}></div>
                  </div>
                </div>
                <div>
                  <h4 className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-1">Afternoon (12pm-4pm)</h4>
                  <div className="flex justify-between text-sm mb-1">
                    <span className="dark:text-white">Utilization</span>
                    <span className="text-healthcare-info dark:text-healthcare-info-dark">76.8%</span>
                  </div>
                  <div className="w-full bg-healthcare-border dark:bg-healthcare-border-dark rounded-full h-2">
                    <div className="bg-healthcare-info dark:bg-healthcare-info-dark h-2 rounded-full" style={{ width: '76.8%' }}></div>
                  </div>
                </div>
                <div>
                  <h4 className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-1">Evening (4pm-8pm)</h4>
                  <div className="flex justify-between text-sm mb-1">
                    <span className="dark:text-white">Utilization</span>
                    <span className="text-healthcare-info dark:text-healthcare-info-dark">68.5%</span>
                  </div>
                  <div className="w-full bg-healthcare-border dark:bg-healthcare-border-dark rounded-full h-2">
                    <div className="bg-healthcare-warning dark:bg-healthcare-warning-dark h-2 rounded-full" style={{ width: '68.5%' }}></div>
                  </div>
                </div>
              </div>
            </Panel>
            
            <Panel title="Recommendations" isSubpanel={true} dropLightIntensity="medium">
              <ul className="space-y-2">
                <li className="p-2 bg-healthcare-info/10 dark:bg-healthcare-info-dark/20 rounded">
                  <h4 className="font-medium text-healthcare-info dark:text-healthcare-info-dark mb-1">Optimize Friday Scheduling</h4>
                  <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    Friday has the lowest utilization. Consider rescheduling high-demand procedures to this day.
                  </p>
                </li>
                <li className="p-2 bg-healthcare-success/10 dark:bg-healthcare-success-dark/20 rounded">
                  <h4 className="font-medium text-healthcare-success dark:text-healthcare-success-dark mb-1">Leverage Tuesday Efficiency</h4>
                  <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    Tuesday shows highest utilization. Analyze workflows to replicate this efficiency on other days.
                  </p>
                </li>
                <li className="p-2 bg-healthcare-warning/10 dark:bg-healthcare-warning-dark/20 rounded">
                  <h4 className="font-medium text-healthcare-warning dark:text-healthcare-warning-dark mb-1">Evening Slot Utilization</h4>
                  <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    Evening slots are underutilized. Consider incentives for scheduling during these hours.
                  </p>
                </li>
              </ul>
            </Panel>
          </div>
        </Panel>
      </div>
    </div>
  );
};

export default DayOfWeekView;
