import React from 'react';
import { useDarkMode, HEALTHCARE_COLORS } from '@/hooks/useDarkMode';
import PropTypes from 'prop-types';
import {
    LineChart,
    Line,
    XAxis,
    YAxis,
    CartesianGrid,
    Tooltip,
    Legend,
    ResponsiveContainer
} from 'recharts';

const SimpleTrendChart = ({
    data,
    series = [],
    xAxis = {
        dataKey: 'date',
        type: 'category',
        formatter: (value) => new Date(value).toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric'
        })
    },
    yAxis = {
        formatter: (value) => value
    },
    tooltip = {
        formatter: (value) => value
    },
    colors = ['#4F46E5', '#10B981', '#F59E0B', '#EF4444']
}) => {
    const [isDarkMode] = useDarkMode();
    const themeColors = HEALTHCARE_COLORS[isDarkMode ? 'dark' : 'light'];
    const chartColors = {
        grid: isDarkMode ? '#374151' : '#E5E7EB', // dark: gray-700, light: gray-200
        text: themeColors.text.primary,
        background: themeColors.surface,
    };

    return (
        <ResponsiveContainer width="100%" height="100%">
            <LineChart
                data={data}
                margin={{
                    top: 5,
                    right: 30,
                    left: 20,
                    bottom: 5
                }}
                style={{ backgroundColor: chartColors.background }}
            >
                <CartesianGrid strokeDasharray="3 3" stroke={chartColors.grid} />
                <XAxis
                    dataKey={xAxis.dataKey}
                    type={xAxis.type}
                    tickFormatter={xAxis.formatter}
                    tick={{ fill: chartColors.text }}
                    axisLine={{ stroke: chartColors.text }}
                    tickLine={{ stroke: chartColors.text }}
                />
                <YAxis
                    tickFormatter={yAxis.formatter}
                    tick={{ fill: chartColors.text }}
                    axisLine={{ stroke: chartColors.text }}
                    tickLine={{ stroke: chartColors.text }}
                />
                <Tooltip
                    formatter={tooltip.formatter}
                    labelFormatter={xAxis.formatter}
                    contentStyle={{ backgroundColor: chartColors.background, borderColor: chartColors.grid }}
                    itemStyle={{ color: chartColors.text }}
                    labelStyle={{ color: chartColors.text }}
                />
                {series.length > 1 && <Legend />}
                {series.map((s, index) => (
                    <Line
                        key={s.dataKey}
                        type="monotone"
                        dataKey={s.dataKey}
                        name={s.name}
                        stroke={colors[index % colors.length]}
                        strokeWidth={2}
                        dot={false}
                        activeDot={{ r: 6 }}
                    />
                ))}
            </LineChart>
        </ResponsiveContainer>
    );
};

SimpleTrendChart.propTypes = {
    data: PropTypes.array.isRequired,
    series: PropTypes.arrayOf(PropTypes.shape({
        dataKey: PropTypes.string.isRequired,
        name: PropTypes.string.isRequired
    })),
    xAxis: PropTypes.object,
    yAxis: PropTypes.object,
    tooltip: PropTypes.object,
    colors: PropTypes.arrayOf(PropTypes.string),
};

export default SimpleTrendChart;
