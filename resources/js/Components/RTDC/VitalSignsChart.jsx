import React from 'react';
import LineChart from '@/Components/Dashboard/Charts/LineChart';

const VitalSignsChart = ({ vitalsHistory }) => {
    if (!vitalsHistory?.length) return null;

    // Transform data for the chart
    const chartData = vitalsHistory.map(v => ({
        month: new Date(v.time).toLocaleTimeString(),
        'Heart Rate': v.hr,
        'O2 Saturation': v.o2sat,
        'Respiratory Rate': v.rr
    }));

    return (
        <div className="h-64">
            <LineChart
                data={chartData}
                height={256}
                ariaLabel="Vital signs trend chart showing heart rate, oxygen saturation, and respiratory rate"
            />
        </div>
    );
};

export default VitalSignsChart;
