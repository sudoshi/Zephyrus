import React from 'react';
import Card from '@/Components/Dashboard/Card';
import { useDarkMode, HEALTHCARE_COLORS } from '@/hooks/useDarkMode';
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

const TrendChart = ({
    data,
    title,
    description,
    series,
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
        <Card>
            <Card.Header>
                <Card.Title>{title}</Card.Title>
                {description && (
                    <Card.Description>{description}</Card.Description>
                )}
            </Card.Header>
            <Card.Content>
                <div className="h-[400px]">
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
                </div>
            </Card.Content>
        </Card>
    );
};

export const formatters = {
    percentage: (value) => `${Math.round(value)}%`,
    duration: (minutes) => {
        const hours = Math.floor(minutes / 60);
        const mins = minutes % 60;
        return hours > 0 ? `${hours}h ${mins}m` : `${mins}m`;
    },
    number: (value) => value.toLocaleString(),
    currency: (value) => new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD'
    }).format(value),
    date: (value) => new Date(value).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric'
    })
};

export default TrendChart;
