import React, { useState } from 'react';
import TimePeriodSelector from './TimePeriodSelector';
import ALOSChart from './ALOSChart';
import EDConversionChart from './EDConversionChart';
import BedOccupancyChart from './BedOccupancyChart';
import FlowMetricsChart from './FlowMetricsChart';
import StaffingCensusChart from './StaffingCensusChart';
import QualityIndicatorsChart from './QualityIndicatorsChart';
import { historicalMetrics } from '@/mock-data/historical-metrics';

const HistoricalMetricsSection = () => {
    const [selectedPeriod, setSelectedPeriod] = useState('1M');

    // Filter data based on selected time period
    const filterDataByPeriod = (data) => {
        const now = new Date();
        const periods = {
            '1W': 7,
            '1M': 30,
            '3M': 90,
            '6M': 180,
        };
        const daysToInclude = periods[selectedPeriod];
        return data.slice(-daysToInclude);
    };

    const filteredData = {
        alos: filterDataByPeriod(historicalMetrics.alos),
        edConversion: filterDataByPeriod(historicalMetrics.edConversion),
        bedOccupancy: filterDataByPeriod(historicalMetrics.bedOccupancy),
        flowMetrics: filterDataByPeriod(historicalMetrics.flowMetrics),
        staffing: filterDataByPeriod(historicalMetrics.staffing),
        quality: filterDataByPeriod(historicalMetrics.quality),
    };

    return (
        <div className="healthcare-card overflow-visible">
            {/* Section Header */}
            <div className="flex items-center justify-between mb-8">
                <div>
                    <h2 className="text-xl font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                        Historical Trends
                    </h2>
                    <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mt-1">
                        Key performance metrics over time
                    </p>
                </div>
                <TimePeriodSelector
                    selectedPeriod={selectedPeriod}
                    onPeriodChange={setSelectedPeriod}
                />
            </div>

            {/* Charts Grid - Added min-h-0 to enable proper grid sizing and prevent overflow */}
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-8 overflow-visible">
                {/* Row 1 */}
                <div className="lg:col-span-1">
                    <ALOSChart data={filteredData.alos} />
                </div>
                <div className="lg:col-span-1">
                    <EDConversionChart data={filteredData.edConversion} />
                </div>
                {/* Row 2 */}
                <div className="lg:col-span-1">
                    <BedOccupancyChart 
                        data={filteredData.bedOccupancy} 
                        serviceLines={historicalMetrics.serviceLines} 
                    />
                </div>
                <div className="lg:col-span-1">
                    <FlowMetricsChart data={filteredData.flowMetrics} />
                </div>
                {/* Row 3 */}
                <div className="lg:col-span-1">
                    <StaffingCensusChart data={filteredData.staffing} />
                </div>
                <div className="lg:col-span-1">
                    <QualityIndicatorsChart data={filteredData.quality} />
                </div>
            </div>
        </div>
    );
};

export default HistoricalMetricsSection;
