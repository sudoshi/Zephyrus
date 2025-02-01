import React, { useState, useEffect } from 'react';
import { Spinner } from '@heroui/react';
import Card from '@/Components/Dashboard/Card';
import { Icon } from '@iconify/react';
import { useMode } from '@/Contexts/ModeContext';
import DataService from '@/services/data-service';
import DateRangeSelector from '@/Components/Common/DateRangeSelector';
import MetricsCard, { MetricsCardGroup } from '@/Components/Common/MetricsCard';
import TrendChart, { formatters } from '@/Components/Common/TrendChart';

const ProviderDashboard = () => {
    const [startDate, setStartDate] = useState(new Date(Date.now() - 30 * 24 * 60 * 60 * 1000).toISOString().split('T')[0]);
    const [endDate, setEndDate] = useState(new Date().toISOString().split('T')[0]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [data, setData] = useState(null);

    const { mode } = useMode();
    const dataService = DataService.useDataService();

    const fetchData = async () => {
        try {
            dataService.setMode(mode);
            const response = await dataService.getProviderPerformance(startDate, endDate);
            setData(response);
            setError(null);
        } catch (err) {
            console.error('Error fetching provider analytics:', err);
            setError('Failed to load provider analytics data');
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchData();
    }, [startDate, endDate]);

    const handleDateChange = (type, value) => {
        if (type === 'start') setStartDate(value);
        else setEndDate(value);
    };

    const handleQuickSelect = (start, end) => {
        setStartDate(start);
        setEndDate(end);
    };

    if (loading) {
        return (
            <div className="flex justify-center items-center h-96">
                <Spinner size="lg" />
            </div>
        );
    }

    if (error) {
        return (
            <div className="p-6 text-center text-red-600">
                <Icon icon="heroicons:exclamation-circle" className="w-12 h-12 mx-auto mb-4" />
                <p>{error}</p>
            </div>
        );
    }

    const providerMetrics = data?.providers?.map(provider => ({
        ...provider,
        trend: provider.on_time_percentage > 80 ? 'up' : provider.on_time_percentage < 60 ? 'down' : 'neutral'
    })) || [];

    return (
        <div className="space-y-6">
            <DateRangeSelector
                startDate={startDate}
                endDate={endDate}
                onDateChange={handleDateChange}
                onQuickSelect={handleQuickSelect}
            />

            <MetricsCardGroup>
                <MetricsCard
                    title="Average Cases/Day"
                    value={providerMetrics.reduce((acc, p) => acc + p.avg_cases_per_day, 0) / providerMetrics.length}
                    formatter={formatters.decimal}
                    icon="heroicons:clipboard-document-list"
                    color="indigo"
                />
                <MetricsCard
                    title="On-Time Starts"
                    value={providerMetrics.reduce((acc, p) => acc + p.on_time_percentage, 0) / providerMetrics.length}
                    formatter={formatters.percentage}
                    icon="heroicons:clock"
                    color="emerald"
                />
                <MetricsCard
                    title="Average Duration"
                    value={providerMetrics.reduce((acc, p) => acc + p.avg_case_duration, 0) / providerMetrics.length}
                    formatter={formatters.duration}
                    icon="heroicons:clock-circle"
                    color="amber"
                />
                <MetricsCard
                    title="Case Volume"
                    value={providerMetrics.reduce((acc, p) => acc + p.total_cases, 0)}
                    formatter={formatters.number}
                    icon="heroicons:chart-bar"
                    color="blue"
                />
            </MetricsCardGroup>

            <div className="grid grid-cols-2 gap-6">
                <TrendChart
                    title="Provider Case Volume Trends"
                    description="Daily case volume by provider"
                    data={data?.trends || []}
                    series={[
                        { dataKey: 'case_count', name: 'Cases' }
                    ]}
                    yAxis={{ formatter: formatters.number }}
                    tooltip={{ formatter: formatters.number }}
                />

                <TrendChart
                    title="On-Time Start Performance"
                    description="Daily on-time start percentage by provider"
                    data={data?.trends || []}
                    series={[
                        { dataKey: 'on_time_percentage', name: 'On-Time %' }
                    ]}
                    yAxis={{ formatter: formatters.percentage }}
                    tooltip={{ formatter: formatters.percentage }}
                />
            </div>

            <Card>
                <Card.Header>
                    <Card.Title>Provider Performance Details</Card.Title>
                    <Card.Description>Detailed metrics by provider</Card.Description>
                </Card.Header>
                <Card.Content>
                    <div className="overflow-x-auto">
                        <table className="min-w-full">
                            <thead>
                                <tr className="border-b">
                                    <th className="text-left py-2">Provider</th>
                                    <th className="text-right py-2">Total Cases</th>
                                    <th className="text-right py-2">Avg Cases/Day</th>
                                    <th className="text-right py-2">On-Time Starts</th>
                                    <th className="text-right py-2">Avg Duration</th>
                                    <th className="text-right py-2">Utilization</th>
                                </tr>
                            </thead>
                            <tbody>
                                {providerMetrics.map(provider => (
                                    <tr key={provider.provider_id} className="border-b">
                                        <td className="py-2">
                                            <div className="font-medium">{provider.provider_name}</div>
                                            <div className="text-sm text-gray-500">{provider.specialty}</div>
                                        </td>
                                        <td className="text-right py-2">{formatters.number(provider.total_cases)}</td>
                                        <td className="text-right py-2">{formatters.decimal(provider.avg_cases_per_day)}</td>
                                        <td className="text-right py-2">
                                            <div className={getPerformanceColor(provider.on_time_percentage)}>
                                                {formatters.percentage(provider.on_time_percentage)}
                                            </div>
                                        </td>
                                        <td className="text-right py-2">{formatters.duration(provider.avg_case_duration)}</td>
                                        <td className="text-right py-2">
                                            <div className={getPerformanceColor(provider.utilization)}>
                                                {formatters.percentage(provider.utilization)}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Card.Content>
            </Card>
        </div>
    );
};

const getPerformanceColor = (value) => {
    if (value >= 80) return 'text-green-600';
    if (value >= 60) return 'text-yellow-600';
    return 'text-red-600';
};

export default ProviderDashboard;
