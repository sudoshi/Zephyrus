import React from 'react';
import { Icon } from '@iconify/react';
import Card from '@/Components/Dashboard/Card';
import MetricsCard, { MetricsCardGroup } from '@/Components/Analytics/Common/MetricsCard';
import TrendChart from '@/Components/Analytics/Common/TrendChart';

const StaffingBreakdown = ({ title, data, total }) => {
    const getPercentage = (value) => Math.round((value / total) * 100);

    return (
        <div className="space-y-2">
            <h4 className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                {title}
            </h4>
            <div className="space-y-2">
                {Object.entries(data).map(([role, counts]) => {
                    const percentage = getPercentage(counts.present || counts.scheduled);
                    const required = counts.required;
                    const current = counts.present || counts.scheduled;
                    const isShort = current < required;

                    return (
                        <div key={role} className="flex items-center justify-between">
                            <div className="flex items-center space-x-2">
                                <span className="text-sm uppercase text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                    {role}
                                </span>
                                <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                    {current}/{required}
                                </span>
                            </div>
                            <div className="flex items-center space-x-2">
                                <div className="w-24 h-2 bg-healthcare-border dark:bg-healthcare-border-dark rounded-full overflow-hidden">
                                    <div
                                        className={`h-full rounded-full ${
                                            isShort
                                                ? 'bg-healthcare-warning dark:bg-healthcare-warning-dark'
                                                : 'bg-healthcare-success dark:bg-healthcare-success-dark'
                                        }`}
                                        style={{ width: `${percentage}%` }}
                                    />
                                </div>
                                <span className={`text-xs ${
                                    isShort
                                        ? 'text-healthcare-warning dark:text-healthcare-warning-dark'
                                        : 'text-healthcare-success dark:text-healthcare-success-dark'
                                }`}>
                                    {percentage}%
                                </span>
                            </div>
                        </div>
                    );
                })}
            </div>
        </div>
    );
};

const StaffingOverview = ({ staffingData }) => {
    return (
        <div className="space-y-6">
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {/* Current Shift Overview */}
                <Card>
                    <Card.Header>
                        <Card.Title>
                            <div className="flex items-center space-x-2">
                                <Icon icon="heroicons:user-group" className="w-5 h-5" />
                                <span>Current Shift</span>
                            </div>
                        </Card.Title>
                    </Card.Header>
                    <Card.Content>
                        <MetricsCardGroup cols={2}>
                            <MetricsCard
                                title="Staff Present"
                                value={staffingData.currentShift.present.toString()}
                                icon="heroicons:users"
                                description={`${staffingData.currentShift.coverage}% coverage`}
                                trend={staffingData.currentShift.coverage < 90 ? 'down' : 'up'}
                                trendValue={Math.abs(staffingData.currentShift.required - staffingData.currentShift.present)}
                            />
                            <MetricsCard
                                title="Required"
                                value={staffingData.currentShift.required.toString()}
                                icon="heroicons:clipboard-document-check"
                                description="Target staffing"
                            />
                        </MetricsCardGroup>

                        <div className="mt-6">
                            <StaffingBreakdown
                                title="Skill Mix"
                                data={staffingData.currentShift.skillMix}
                                total={staffingData.currentShift.present}
                            />
                        </div>
                    </Card.Content>
                </Card>

                {/* Next Shift Planning */}
                <Card>
                    <Card.Header>
                        <Card.Title>
                            <div className="flex items-center space-x-2">
                                <Icon icon="heroicons:clock" className="w-5 h-5" />
                                <span>Next Shift</span>
                            </div>
                        </Card.Title>
                    </Card.Header>
                    <Card.Content>
                        <MetricsCardGroup cols={2}>
                            <MetricsCard
                                title="Scheduled"
                                value={staffingData.nextShift.scheduled.toString()}
                                icon="heroicons:calendar"
                                description="Staff scheduled"
                                trend={staffingData.nextShift.scheduled < staffingData.nextShift.required ? 'down' : 'up'}
                                trendValue={Math.abs(staffingData.nextShift.required - staffingData.nextShift.scheduled)}
                            />
                            <MetricsCard
                                title="Predicted Need"
                                value={staffingData.nextShift.predicted.toString()}
                                icon="heroicons:chart-bar"
                                description="Based on census"
                            />
                        </MetricsCardGroup>

                        <div className="mt-6">
                            <StaffingBreakdown
                                title="Projected Skill Mix"
                                data={staffingData.nextShift.skillMix}
                                total={staffingData.nextShift.scheduled}
                            />
                        </div>
                    </Card.Content>
                </Card>
            </div>

            {/* Coverage Trends */}
            <Card>
                <Card.Header>
                    <Card.Title>
                        <div className="flex items-center space-x-2">
                            <Icon icon="heroicons:chart-bar" className="w-5 h-5" />
                            <span>Coverage Trends</span>
                        </div>
                    </Card.Title>
                </Card.Header>
                <Card.Content>
                    <div className="h-48">
                        <TrendChart
                            data={staffingData.trends.daily}
                            series={[
                                {
                                    dataKey: 'coverage',
                                    name: 'Coverage',
                                },
                            ]}
                            xAxis={{
                                dataKey: 'date',
                                type: 'category',
                                formatter: (value) =>
                                    new Date(value).toLocaleDateString('en-US', {
                                        month: 'short',
                                        day: 'numeric',
                                    }),
                            }}
                            yAxis={{
                                formatter: (value) => `${value}%`,
                            }}
                        />
                    </div>
                </Card.Content>
            </Card>
        </div>
    );
};

export default StaffingOverview;
