import React from 'react';
import RTDCPageLayout from '../RTDCPageLayout';
import Card from '@/Components/Dashboard/Card';
import MetricsCard, { MetricsCardGroup } from '@/Components/Analytics/Common/MetricsCard';
import { Icon } from '@iconify/react';
import TrendChart from '@/Components/Analytics/Common/TrendChart';
import DateRangeSelector from '@/Components/Common/DateRangeSelector';

const DepartmentCensus = () => {
    return (
        <RTDCPageLayout
            title="Department Census"
            subtitle="Real-time and historical census data by department"
        >
            {/* Date Range Filter */}
            <div className="mb-6">
                <DateRangeSelector
                    onChange={(range) => console.log('Date range changed:', range)}
                />
            </div>

            {/* Current Census Overview */}
            <Card>
                <Card.Header>
                    <Card.Title>
                        <div className="flex items-center space-x-2">
                            <Icon icon="heroicons:building-office-2" className="w-5 h-5" />
                            <span>Current Census Overview</span>
                        </div>
                    </Card.Title>
                    <Card.Description>Real-time department occupancy metrics</Card.Description>
                </Card.Header>
                <Card.Content>
                    <MetricsCardGroup cols={3}>
                        <MetricsCard
                            title="Medical/Surgical"
                            value="92%"
                            trend="up"
                            trendValue="3%"
                            icon="heroicons:heart"
                            description="147/160 beds"
                        />
                        <MetricsCard
                            title="ICU"
                            value="85%"
                            trend="down"
                            trendValue="2%"
                            icon="heroicons:beaker"
                            description="17/20 beds"
                        />
                        <MetricsCard
                            title="Emergency"
                            value="78%"
                            trend="up"
                            trendValue="5%"
                            icon="heroicons:bolt"
                            description="35/45 beds"
                        />
                    </MetricsCardGroup>
                </Card.Content>
            </Card>

            {/* Census Trends */}
            <Card>
                <Card.Header>
                    <Card.Title>
                        <div className="flex items-center space-x-2">
                            <Icon icon="heroicons:chart-bar" className="w-5 h-5" />
                            <span>Census Trends</span>
                        </div>
                    </Card.Title>
                    <Card.Description>Historical census patterns by department</Card.Description>
                </Card.Header>
                <Card.Content>
                    <div className="h-96">
                        <TrendChart
                            data={[
                                { date: '2024-01-01', value: 85 },
                                { date: '2024-01-02', value: 87 },
                                { date: '2024-01-03', value: 89 },
                                { date: '2024-01-04', value: 92 },
                                { date: '2024-01-05', value: 88 },
                            ]}
                            xKey="date"
                            yKey="value"
                            yAxisLabel="Occupancy %"
                        />
                    </div>
                </Card.Content>
            </Card>

            {/* Department Details */}
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <Card>
                    <Card.Header>
                        <Card.Title>
                            <div className="flex items-center space-x-2">
                                <Icon icon="heroicons:user-group" className="w-5 h-5" />
                                <span>Staffing Impact</span>
                            </div>
                        </Card.Title>
                        <Card.Description>Census impact on staffing requirements</Card.Description>
                    </Card.Header>
                    <Card.Content>
                        <MetricsCardGroup cols={2}>
                            <MetricsCard
                                title="Required Staff"
                                value="42"
                                trend="up"
                                trendValue="2"
                                icon="heroicons:users"
                                description="Based on current census"
                            />
                            <MetricsCard
                                title="Staff Coverage"
                                value="95%"
                                trend="down"
                                trendValue="3%"
                                icon="heroicons:clipboard-document-check"
                                description="Current shift"
                            />
                        </MetricsCardGroup>
                    </Card.Content>
                </Card>

                <Card>
                    <Card.Header>
                        <Card.Title>
                            <div className="flex items-center space-x-2">
                                <Icon icon="heroicons:arrow-trending-up" className="w-5 h-5" />
                                <span>Capacity Planning</span>
                            </div>
                        </Card.Title>
                        <Card.Description>Projected census and capacity needs</Card.Description>
                    </Card.Header>
                    <Card.Content>
                        <MetricsCardGroup cols={2}>
                            <MetricsCard
                                title="Projected Peak"
                                value="96%"
                                trend="up"
                                trendValue="4%"
                                icon="heroicons:presentation-chart-line"
                                description="Next 24 hours"
                            />
                            <MetricsCard
                                title="Additional Beds"
                                value="5"
                                trend="up"
                                trendValue="2"
                                icon="heroicons:plus"
                                description="Needed for peak"
                            />
                        </MetricsCardGroup>
                    </Card.Content>
                </Card>
            </div>
        </RTDCPageLayout>
    );
};

export default DepartmentCensus;
