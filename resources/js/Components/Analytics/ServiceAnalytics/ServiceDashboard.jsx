import React, { useState, useEffect } from 'react';
import { Button, Spinner } from '@heroui/react';
import Card from '@/Components/Dashboard/Card';
import { Icon } from '@iconify/react';
import { useMode } from '@/Contexts/ModeContext';
import DataService from '@/services/data-service';
import DateRangeSelector from '@/Components/Common/DateRangeSelector';
import MetricsCard, { MetricsCardGroup } from '@/Components/Common/MetricsCard';
import TrendChart, { formatters } from '@/Components/Common/TrendChart';

const ServiceDashboard = () => {
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
            const response = await dataService.getPerformanceMetrics();
            setData(response);
            setError(null);
        } catch (err) {
            console.error('Error fetching service analytics:', err);
            setError('Failed to load service analytics data');
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

    const serviceMetrics = data?.utilization?.map(service => ({
        ...service,
        trend: service.avg_utilization > 75 ? 'up' : service.avg_utilization < 60 ? 'down' : 'neutral'
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
                    title="Average Utilization"
                    value={serviceMetrics.reduce((acc, s) => acc + s.avg_utilization, 0) / serviceMetrics.length}
                    formatter={formatters.percentage}
                    icon="heroicons:chart-bar"
                    color="indigo"
                />
                <MetricsCard
                    title="Total Cases"
                    value={serviceMetrics.reduce((acc, s) => acc + s.case_count, 0)}
                    formatter={formatters.number}
                    icon="heroicons:clipboard-document-list"
                    color="emerald"
                />
                <MetricsCard
                    title="Average Turnover"
                    value={serviceMetrics.reduce((acc, s) => acc + s.avg_turnover, 0) / serviceMetrics.length}
                    formatter={formatters.duration}
                    icon="heroicons:arrow-path"
                    color="amber"
                />
                <MetricsCard
                    title="On-Time Starts"
                    value={serviceMetrics.reduce((acc, s) => acc + s.on_time_start_percentage, 0) / serviceMetrics.length}
                    formatter={formatters.percentage}
                    icon="heroicons:clock"
                    color="blue"
                />
            </MetricsCardGroup>

            <div className="grid grid-cols-2 gap-6">
                <TrendChart
                    title="Service Utilization Trends"
                    description="Daily utilization percentage by service"
                    data={data?.trends || []}
                    series={[
                        { dataKey: 'utilization', name: 'Utilization %' }
                    ]}
                    yAxis={{ formatter: formatters.percentage }}
                    tooltip={{ formatter: formatters.percentage }}
                />

                <TrendChart
                    title="Case Volume Distribution"
                    description="Cases per day by service"
                    data={data?.trends || []}
                    series={[
                        { dataKey: 'case_count', name: 'Cases' }
                    ]}
                    yAxis={{ formatter: formatters.number }}
                    tooltip={{ formatter: formatters.number }}
                />
            </div>

            <Card>
                <Card.Header>
                    <Card.Title>Service Performance Details</Card.Title>
                    <Card.Description>Detailed metrics by service line</Card.Description>
                </Card.Header>
                <Card.Content>
                    <div className="overflow-x-auto">
                        <table className="min-w-full">
                            <thead>
                                <tr className="border-b">
                                    <th className="text-left py-2">Service</th>
                                    <th className="text-right py-2">Cases</th>
                                    <th className="text-right py-2">Utilization</th>
                                    <th className="text-right py-2">Turnover</th>
                                    <th className="text-right py-2">On-Time Starts</th>
                                    <th className="text-right py-2">Avg Duration</th>
                                </tr>
                            </thead>
                            <tbody>
                                {serviceMetrics.map(service => (
                                    <tr key={service.service_id} className="border-b">
                                        <td className="py-2">
                                            <div className="font-medium">{service.service_name}</div>
                                        </td>
                                        <td className="text-right py-2">{formatters.number(service.case_count)}</td>
                                        <td className="text-right py-2">
                                            <div className={getUtilizationColor(service.avg_utilization)}>
                                                {formatters.percentage(service.avg_utilization)}
                                            </div>
                                        </td>
                                        <td className="text-right py-2">{formatters.duration(service.avg_turnover)}</td>
                                        <td className="text-right py-2">{formatters.percentage(service.on_time_start_percentage)}</td>
                                        <td className="text-right py-2">{formatters.duration(service.avg_duration)}</td>
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

const getUtilizationColor = (value) => {
    if (value >= 80) return 'text-green-600';
    if (value >= 60) return 'text-yellow-600';
    return 'text-red-600';
};

export default ServiceDashboard;
