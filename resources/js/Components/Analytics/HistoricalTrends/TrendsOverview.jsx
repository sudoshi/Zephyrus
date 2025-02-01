import React, { useState, useEffect } from 'react';
import { Spinner } from '@heroui/react';
import Card from '@/Components/Dashboard/Card';
import { Icon } from '@iconify/react';
import { useMode } from '@/Contexts/ModeContext';
import DataService from '@/services/data-service';
import DateRangeSelector from '@/Components/Common/DateRangeSelector';
import TrendChart, { formatters } from '@/Components/Common/TrendChart';

const TrendsOverview = () => {
    const [startDate, setStartDate] = useState(new Date(Date.now() - 365 * 24 * 60 * 60 * 1000).toISOString().split('T')[0]);
    const [endDate, setEndDate] = useState(new Date().toISOString().split('T')[0]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [data, setData] = useState(null);

    const { mode } = useMode();
    const dataService = DataService.useDataService();

    const fetchData = async () => {
        try {
            dataService.setMode(mode);
            const [performance, capacity] = await Promise.all([
                dataService.getPerformanceMetrics(),
             dataService.getCapacityAnalysis()
            ]);
            setData({
                performance,
                capacity
            });
            setError(null);
        } catch (err) {
            console.error('Error fetching historical trends:', err);
            setError('Failed to load historical data');
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

    return (
        <div className="space-y-6">
            <DateRangeSelector
                startDate={startDate}
                endDate={endDate}
                onDateChange={handleDateChange}
                onQuickSelect={handleQuickSelect}
            />

            <div className="grid grid-cols-2 gap-6">
                <Card>
                    <Card.Header>
                        <Card.Title>Case Volume Trends</Card.Title>
                        <Card.Description>Monthly case volumes over time</Card.Description>
                    </Card.Header>
                    <Card.Content>
                        <TrendChart
                            data={data?.performance?.volume_trends || []}
                            series={[
                                { dataKey: 'total_cases', name: 'Total Cases' }
                            ]}
                            yAxis={{ formatter: formatters.number }}
                            tooltip={{ formatter: formatters.number }}
                        />
                    </Card.Content>
                </Card>

                <Card>
                    <Card.Header>
                        <Card.Title>Utilization Trends</Card.Title>
                        <Card.Description>Monthly OR utilization percentage</Card.Description>
                    </Card.Header>
                    <Card.Content>
                        <TrendChart
                            data={data?.performance?.utilization_trends || []}
                            series={[
                                { dataKey: 'utilization', name: 'Utilization %' }
                            ]}
                            yAxis={{ formatter: formatters.percentage }}
                            tooltip={{ formatter: formatters.percentage }}
                        />
                    </Card.Content>
                </Card>

                <Card>
                    <Card.Header>
                        <Card.Title>On-Time Performance</Card.Title>
                        <Card.Description>Monthly on-time start percentage</Card.Description>
                    </Card.Header>
                    <Card.Content>
                        <TrendChart
                            data={data?.performance?.ontime_trends || []}
                            series={[
                                { dataKey: 'on_time_percentage', name: 'On-Time %' }
                            ]}
                            yAxis={{ formatter: formatters.percentage }}
                            tooltip={{ formatter: formatters.percentage }}
                        />
                    </Card.Content>
                </Card>

                <Card>
                    <Card.Header>
                        <Card.Title>Capacity Analysis</Card.Title>
                        <Card.Description>Monthly room capacity and usage</Card.Description>
                    </Card.Header>
                    <Card.Content>
                        <TrendChart
                            data={data?.capacity?.trends || []}
                            series={[
                                { dataKey: 'used_minutes', name: 'Used Time' },
                                { dataKey: 'available_minutes', name: 'Available Time' }
                            ]}
                            yAxis={{ formatter: formatters.duration }}
                            tooltip={{ formatter: formatters.duration }}
                            stacked={true}
                        />
                    </Card.Content>
                </Card>
            </div>
        </div>
    );
};

export default TrendsOverview;
