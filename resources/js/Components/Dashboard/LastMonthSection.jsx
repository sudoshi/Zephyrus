import React, { useState } from 'react';
import { Icon } from '@iconify/react';
import MetricCard from './MetricCard';
import { syntheticData } from '../../mock-data/dashboard';
import DrillDownModal from './DrillDownModal';
import { useDarkMode, HEALTHCARE_COLORS } from '@/hooks/useDarkMode';

const LastMonthSection = () => {
    const data = syntheticData.lastMonth;
    const [selectedMetric, setSelectedMetric] = useState(null);
    const [isDarkMode] = useDarkMode();
    const colors = HEALTHCARE_COLORS[isDarkMode ? 'dark' : 'light'];

    // Generate sparkline data (mock data for demonstration)
    const generateSparklineData = (base, variance) => {
        return Array(24).fill(0).map(() => base + (Math.random() - 0.5) * variance);
    };

    const handleMetricClick = (metric) => {
        setSelectedMetric(metric);
    };

    return (
        <section className="mb-8">
            <div className="flex items-center justify-between mb-4">
                <div className="flex items-center space-x-2">
                    <h2 className="text-xl font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
                        Last Month
                    </h2>
                    <div className="relative group">
                        <Icon 
                            icon="heroicons:information-circle" 
                            className="w-5 h-5 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark hover:text-healthcare-text-primary dark:hover:text-healthcare-text-primary-dark transition-colors duration-300 cursor-help"
                        />
                        <div className="
                            absolute z-10 w-64 p-2 mt-2 text-sm rounded-lg shadow-lg
                            bg-healthcare-surface dark:bg-healthcare-surface-dark
                            border border-healthcare-border dark:border-healthcare-border-dark
                            text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark
                            opacity-0 group-hover:opacity-100 transition-all duration-300 pointer-events-none left-0
                        ">
                            Performance metrics and statistics from the previous calendar month
                        </div>
                    </div>
                </div>
                <div className="flex items-center space-x-4">
                    <button className="
                        inline-flex items-center text-sm font-medium
                        text-healthcare-info dark:text-healthcare-info-dark
                        hover:text-healthcare-info-dark dark:hover:text-healthcare-info
                        transition-colors duration-300
                    ">
                        <Icon icon="heroicons:document-arrow-down" className="w-4 h-4 mr-1" />
                        Export Data
                    </button>
                    <div className="relative">
                        <select className="
                            text-sm rounded-md pl-8 pr-4 py-2 appearance-none
                            bg-healthcare-surface dark:bg-healthcare-surface-dark
                            text-healthcare-text-primary dark:text-healthcare-text-primary-dark
                            border border-healthcare-border dark:border-healthcare-border-dark
                            focus:border-healthcare-info dark:focus:border-healthcare-info-dark
                            focus:ring-1 focus:ring-healthcare-info dark:focus:ring-healthcare-info-dark
                            transition-colors duration-300
                        ">
                            <option>All Locations</option>
                            <option>Location A</option>
                            <option>Location B</option>
                        </select>
                        <Icon 
                            icon="heroicons:funnel" 
                            className="absolute left-2 top-1/2 transform -translate-y-1/2 w-4 h-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark transition-colors duration-300"
                        />
                    </div>
                </div>
            </div>
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4 transition-all duration-200">
                <MetricCard 
                    title="First Case On Time Starts"
                    value={data.firstCaseOnTime.value}
                    trend={data.firstCaseOnTime.trend}
                    previousValue={`${data.firstCaseOnTime.previousValue}%`}
                    date={data.firstCaseOnTime.date}
                    info="Displays the percentage of cases that were on time and took place within the previous calendar month."
                    sparklineData={generateSparklineData(data.firstCaseOnTime.value, 20)}
                    threshold={{ warning: 80, critical: 70 }}
                    miniStats={[
                        { label: 'Total Cases', value: '156' },
                        { label: 'Delayed', value: '23' }
                    ]}
                    onClick={() => handleMetricClick('ontime')}
                />
                <MetricCard 
                    title="Average Room Turnover"
                    value={data.avgTurnover.value}
                    trend={data.avgTurnover.trend}
                    previousValue={`${data.avgTurnover.previousValue} min`}
                    date={data.avgTurnover.date}
                    showAsPercentage={false}
                    info="Displays the average room and procedure turnover for cases that took place within the previous calendar month."
                    sparklineData={generateSparklineData(data.avgTurnover.value, 10)}
                    threshold={{ warning: 45, critical: 60 }}
                    miniStats={[
                        { label: 'Min Time', value: '22m' },
                        { label: 'Max Time', value: '68m' }
                    ]}
                    onClick={() => handleMetricClick('turnover')}
                />
                <MetricCard 
                    title="Case Length Accuracy"
                    value={data.caseLengthAccuracy.value}
                    trend={data.caseLengthAccuracy.trend}
                    previousValue={`${data.caseLengthAccuracy.previousValue}%`}
                    date={data.caseLengthAccuracy.date}
                    info="Displays the percentage of cases performed within the previous calendar month that were accurately scheduled."
                    sparklineData={generateSparklineData(data.caseLengthAccuracy.value, 15)}
                    threshold={{ warning: 75, critical: 65 }}
                    miniStats={[
                        { label: 'Under', value: '12%' },
                        { label: 'Over', value: '15%' }
                    ]}
                    onClick={() => handleMetricClick('accuracy')}
                />
                <MetricCard 
                    title="Performed Cases"
                    value={data.performedCases.value}
                    trend={data.performedCases.trend}
                    previousValue={data.performedCases.previousValue}
                    date={data.performedCases.date}
                    showAsPercentage={false}
                    info="Displays the volume of cases that were performed within the previous calendar month."
                    sparklineData={generateSparklineData(data.performedCases.value, 50)}
                    threshold={{ warning: 300, critical: 250 }}
                    miniStats={[
                        { label: 'Emergency', value: '45' },
                        { label: 'Elective', value: '278' }
                    ]}
                    onClick={() => handleMetricClick('cases')}
                />
                <MetricCard 
                    title="DoS Cancellations"
                    value={data.doSCancellations.value}
                    trend={data.doSCancellations.trend}
                    previousValue={data.doSCancellations.previousValue}
                    date={data.doSCancellations.date}
                    showAsPercentage={false}
                    info="Displays the number of cases from the previous calendar month that were canceled, rescheduled, or for which no procedure was performed on the day of surgery."
                    sparklineData={generateSparklineData(data.doSCancellations.value, 8)}
                    threshold={{ warning: 15, critical: 25 }}
                    miniStats={[
                        { label: 'Patient', value: '8' },
                        { label: 'Facility', value: '4' }
                    ]}
                    onClick={() => handleMetricClick('cancellations')}
                />
                <MetricCard 
                    title="Block Utilization"
                    value={data.blockUtilization.value}
                    trend={data.blockUtilization.trend}
                    previousValue={`${data.blockUtilization.previousValue}%`}
                    date={data.blockUtilization.date}
                    info="Displays a high-level summary of OR block utilization data for your OR locations. The data is only refreshed when utilization batch jobs are run."
                    sparklineData={generateSparklineData(data.blockUtilization.value, 12)}
                    threshold={{ warning: 75, critical: 65 }}
                    miniStats={[
                        { label: 'Released', value: '12%' },
                        { label: 'Unused', value: '8%' }
                    ]}
                    onClick={() => handleMetricClick('utilization')}
                />
                <MetricCard 
                    title="Primetime Utilization"
                    value={data.primetimeUtilization.staffed}
                    trend={data.primetimeUtilization.trend}
                    previousValue={`${data.primetimeUtilization.unstaffed}%`}
                    date={data.primetimeUtilization.date}
                    info="Displays the total in-room and setup/cleanup minutes over the available primetime minutes from the previous calendar month. The Primetime Room Utilization percentage includes rooms closed due to staff being unavailable, and cases only count against open available time for the room they are performed in. The Primetime Staffed Room Utilization percentage does not include rooms closed due to staff being unavailable in the denominator, but cases will 'float' across rooms to be in the numerator of the percentage."
                    sparklineData={generateSparklineData(data.primetimeUtilization.staffed, 10)}
                    threshold={{ warning: 80, critical: 70 }}
                    miniStats={[
                        { label: 'In Room', value: '82%' },
                        { label: 'Setup', value: '18%' }
                    ]}
                    onClick={() => handleMetricClick('primetime')}
                />
            </div>

            {/* Drill Down Modal */}
            {selectedMetric && (
                <DrillDownModal
                    isOpen={!!selectedMetric}
                    onClose={() => setSelectedMetric(null)}
                    metric={selectedMetric}
                    data={data[selectedMetric]}
                />
            )}
        </section>
    );
};

export default LastMonthSection;
