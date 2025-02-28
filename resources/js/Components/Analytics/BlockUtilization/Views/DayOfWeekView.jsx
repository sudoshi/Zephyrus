import React, { useMemo } from 'react';
import { ResponsiveBar } from '@nivo/bar';
import { mockBlockUtilization } from '@/mock-data/block-utilization';
import { MetricCard, Panel } from '@/Components/ui';

const DayOfWeekView = ({ filters }) => {
  // Extract filter values from the new filter structure
  const { selectedHospital, selectedLocation, selectedSpecialty, dateRange } = filters;
  
  // Filter data based on hierarchical filters
  const filteredData = useMemo(() => {
    // In a real application, we would filter the day of week data based on the selected filters
    // For now, we'll just use the mock data
    return mockBlockUtilization.dayOfWeekData;
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
    return mockBlockUtilization.overallMetrics;
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
                  <span className="text-blue-600 dark:text-blue-300">{formatPercentage(day.utilization)}</span>
                </div>
                <div className="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2.5">
                  <div 
                    className={`h-2.5 rounded-full ${
                      day.utilization > 75 ? 'bg-emerald-500' : 
                      day.utilization > 70 ? 'bg-blue-600' : 
                      day.utilization > 65 ? 'bg-amber-500' : 'bg-red-500'
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
                  <h4 className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Morning (8am-12pm)</h4>
                  <div className="flex justify-between text-sm mb-1">
                    <span className="dark:text-white">Utilization</span>
                    <span className="text-blue-600 dark:text-blue-300">74.2%</span>
                  </div>
                  <div className="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                    <div className="bg-blue-600 h-2 rounded-full" style={{ width: '74.2%' }}></div>
                  </div>
                </div>
                <div>
                  <h4 className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Afternoon (12pm-4pm)</h4>
                  <div className="flex justify-between text-sm mb-1">
                    <span className="dark:text-white">Utilization</span>
                    <span className="text-blue-600 dark:text-blue-300">76.8%</span>
                  </div>
                  <div className="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                    <div className="bg-blue-600 h-2 rounded-full" style={{ width: '76.8%' }}></div>
                  </div>
                </div>
                <div>
                  <h4 className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Evening (4pm-8pm)</h4>
                  <div className="flex justify-between text-sm mb-1">
                    <span className="dark:text-white">Utilization</span>
                    <span className="text-blue-600 dark:text-blue-300">68.5%</span>
                  </div>
                  <div className="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                    <div className="bg-amber-500 h-2 rounded-full" style={{ width: '68.5%' }}></div>
                  </div>
                </div>
              </div>
            </Panel>
            
            <Panel title="Recommendations" isSubpanel={true} dropLightIntensity="medium">
              <ul className="space-y-2">
                <li className="p-2 bg-blue-50 dark:bg-blue-900/20 rounded">
                  <h4 className="font-medium text-blue-800 dark:text-blue-300 mb-1">Optimize Friday Scheduling</h4>
                  <p className="text-sm text-gray-600 dark:text-gray-300">
                    Friday has the lowest utilization. Consider rescheduling high-demand procedures to this day.
                  </p>
                </li>
                <li className="p-2 bg-emerald-50 dark:bg-emerald-900/20 rounded">
                  <h4 className="font-medium text-emerald-800 dark:text-emerald-300 mb-1">Leverage Tuesday Efficiency</h4>
                  <p className="text-sm text-gray-600 dark:text-gray-300">
                    Tuesday shows highest utilization. Analyze workflows to replicate this efficiency on other days.
                  </p>
                </li>
                <li className="p-2 bg-amber-50 dark:bg-amber-900/20 rounded">
                  <h4 className="font-medium text-amber-800 dark:text-amber-300 mb-1">Evening Slot Utilization</h4>
                  <p className="text-sm text-gray-600 dark:text-gray-300">
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
