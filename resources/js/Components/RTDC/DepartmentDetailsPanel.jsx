import React from 'react';
import Card from '@/Components/Dashboard/Card';
import { Icon } from '@iconify/react';
import MetricsCard, { MetricsCardGroup } from '@/Components/Analytics/Common/MetricsCard';

const DepartmentDetailsPanel = ({ department, onClose }) => {
    if (!department) return null;

    return (
        <div className="mt-4">
            <Card>
                <Card.Header>
                    <div className="flex items-center justify-between">
                        <Card.Title>
                            <div className="flex items-center space-x-2">
                                <Icon icon="heroicons:information-circle" className="w-5 h-5" />
                                <span>{department.name} Details</span>
                            </div>
                        </Card.Title>
                        <button
                            onClick={onClose}
                            className="p-1 hover:bg-healthcare-background dark:hover:bg-healthcare-background-dark rounded-full transition-colors duration-300"
                        >
                            <Icon icon="heroicons:x-mark" className="w-5 h-5" />
                        </button>
                    </div>
                </Card.Header>
                <Card.Content>
                    <MetricsCardGroup cols={3}>
                        <MetricsCard
                            title="Bed Utilization"
                            value={`${department.occupiedBeds}/${department.totalBeds}`}
                            trend={department.occupancy > 90 ? 'down' : 'up'}
                            trendValue={department.occupancy}
                            icon="heroicons:home"
                            description={`${department.occupancy}% occupied`}
                        />
                        <MetricsCard
                            title="Staffing Level"
                            value={`${department.staffingLevel}%`}
                            trend={department.staffingLevel < 95 ? 'down' : 'up'}
                            trendValue={department.staffingLevel - 100}
                            icon="heroicons:users"
                            description="Current coverage"
                        />
                        {department.boardingPatients !== undefined ? (
                            <MetricsCard
                                title="Boarding Patients"
                                value={department.boardingPatients.toString()}
                                trend="up"
                                trendValue={2}
                                icon="heroicons:clock"
                                description="Awaiting beds"
                            />
                        ) : (
                            <MetricsCard
                                title="Pending Actions"
                                value={(department.pendingAdmissions + department.pendingDischarges).toString()}
                                trend="up"
                                trendValue={department.pendingAdmissions}
                                icon="heroicons:arrow-path"
                                description={`${department.pendingAdmissions} in, ${department.pendingDischarges} out`}
                            />
                        )}
                    </MetricsCardGroup>

                    <div className="mt-4 p-4 bg-healthcare-background dark:bg-healthcare-background-dark rounded-lg">
                        <h4 className="text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-2">
                            Status Indicators
                        </h4>
                        <div className="space-y-2">
                            <div className="flex items-center justify-between">
                                <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                    Occupancy Status
                                </span>
                                <div className={`px-2 py-1 rounded text-xs ${
                                    department.status === 'critical' ? 'bg-healthcare-critical/20 text-healthcare-critical dark:text-healthcare-critical-dark' :
                                    department.status === 'warning' ? 'bg-healthcare-warning/20 text-healthcare-warning dark:text-healthcare-warning-dark' :
                                    'bg-healthcare-success/20 text-healthcare-success dark:text-healthcare-success-dark'
                                }`}>
                                    {department.status.charAt(0).toUpperCase() + department.status.slice(1)}
                                </div>
                            </div>
                            <div className="flex items-center justify-between">
                                <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                    Staffing Status
                                </span>
                                <div className={`px-2 py-1 rounded text-xs ${
                                    department.staffingLevel < 90 ? 'bg-healthcare-critical/20 text-healthcare-critical dark:text-healthcare-critical-dark' :
                                    department.staffingLevel < 95 ? 'bg-healthcare-warning/20 text-healthcare-warning dark:text-healthcare-warning-dark' :
                                    'bg-healthcare-success/20 text-healthcare-success dark:text-healthcare-success-dark'
                                }`}>
                                    {department.staffingLevel < 90 ? 'Critical' :
                                     department.staffingLevel < 95 ? 'Warning' : 'Normal'}
                                </div>
                            </div>
                            {department.averageWaitTime !== undefined && (
                                <div className="flex items-center justify-between">
                                    <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                        Wait Time
                                    </span>
                                    <div className={`px-2 py-1 rounded text-xs ${
                                        department.averageWaitTime > 60 ? 'bg-healthcare-critical/20 text-healthcare-critical dark:text-healthcare-critical-dark' :
                                        department.averageWaitTime > 30 ? 'bg-healthcare-warning/20 text-healthcare-warning dark:text-healthcare-warning-dark' :
                                        'bg-healthcare-success/20 text-healthcare-success dark:text-healthcare-success-dark'
                                    }`}>
                                        {department.averageWaitTime} minutes
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>
                </Card.Content>
            </Card>
        </div>
    );
};

export default DepartmentDetailsPanel;
