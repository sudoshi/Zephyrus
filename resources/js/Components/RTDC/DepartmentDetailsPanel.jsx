import React from 'react';
import Card from '@/Components/Dashboard/Card';
import { Icon } from '@iconify/react';
import PropTypes from 'prop-types';
import MetricsCard, { MetricsCardGroup } from '@/Components/Analytics/Common/MetricsCard';

const DepartmentDetailsPanel = ({ department, onClose }) => {
    if (!department) return null;

    const {
        name = 'Department',
        occupancy = 0,
        occupiedBeds = 0,
        totalBeds = 0,
        status = 'unknown',
        staffingLevel = 0,
        boardingPatients,
        pendingAdmissions = 0,
        pendingDischarges = 0,
        averageWaitTime,
    } = department;

    return (
        <div className="mt-4">
            <Card>
                <Card.Header>
                    <div className="flex items-center justify-between">
                        <Card.Title>
                            <div className="flex items-center space-x-2">
                                <Icon icon="heroicons:information-circle" className="w-5 h-5" />
                                <span>{name} Details</span>
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
                            value={`${occupiedBeds}/${totalBeds}`}
                            trend={occupancy > 90 ? 'down' : 'up'}
                            trendValue={occupancy}
                            icon="heroicons:home"
                            description={`${occupancy}% occupied`}
                        />
                        <MetricsCard
                            title="Staffing Level"
                            value={`${staffingLevel}%`}
                            trend={staffingLevel < 95 ? 'down' : 'up'}
                            trendValue={staffingLevel - 100}
                            icon="heroicons:users"
                            description="Current coverage"
                        />
                        {boardingPatients !== undefined ? (
                            <MetricsCard
                                title="Boarding Patients"
                                value={boardingPatients.toString()}
                                trend="up"
                                trendValue={2}
                                icon="heroicons:clock"
                                description="Awaiting beds"
                            />
                        ) : (
                            <MetricsCard
                                title="Pending Actions"
                                value={(pendingAdmissions + pendingDischarges).toString()}
                                trend="up"
                                trendValue={pendingAdmissions}
                                icon="heroicons:arrow-path"
                                description={`${pendingAdmissions} in, ${pendingDischarges} out`}
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
                                <div
                                    className={`px-2 py-1 rounded text-xs ${
                                        status === 'critical'
                                            ? 'bg-healthcare-critical/20 text-healthcare-critical dark:text-healthcare-critical-dark'
                                            : status === 'warning'
                                            ? 'bg-healthcare-warning/20 text-healthcare-warning dark:text-healthcare-warning-dark'
                                            : 'bg-healthcare-success/20 text-healthcare-success dark:text-healthcare-success-dark'
                                    }`}
                                >
                                    {status.charAt(0).toUpperCase() + status.slice(1)}
                                </div>
                            </div>
                            <div className="flex items-center justify-between">
                                <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                    Staffing Status
                                </span>
                                <div
                                    className={`px-2 py-1 rounded text-xs ${
                                        staffingLevel < 90
                                            ? 'bg-healthcare-critical/20 text-healthcare-critical dark:text-healthcare-critical-dark'
                                            : staffingLevel < 95
                                            ? 'bg-healthcare-warning/20 text-healthcare-warning dark:text-healthcare-warning-dark'
                                            : 'bg-healthcare-success/20 text-healthcare-success dark:text-healthcare-success-dark'
                                    }`}
                                >
                                    {staffingLevel < 90
                                        ? 'Critical'
                                        : staffingLevel < 95
                                        ? 'Warning'
                                        : 'Normal'}
                                </div>
                            </div>
                            {averageWaitTime !== undefined && (
                                <div className="flex items-center justify-between">
                                    <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                        Wait Time
                                    </span>
                                    <div
                                        className={`px-2 py-1 rounded text-xs ${
                                            averageWaitTime > 60
                                                ? 'bg-healthcare-critical/20 text-healthcare-critical dark:text-healthcare-critical-dark'
                                                : averageWaitTime > 30
                                                ? 'bg-healthcare-warning/20 text-healthcare-warning dark:text-healthcare-warning-dark'
                                                : 'bg-healthcare-success/20 text-healthcare-success dark:text-healthcare-success-dark'
                                        }`}
                                    >
                                        {averageWaitTime} minutes
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

DepartmentDetailsPanel.propTypes = {
    department: PropTypes.shape({
        name: PropTypes.string.isRequired,
        occupancy: PropTypes.number.isRequired,
        occupiedBeds: PropTypes.number,
        totalBeds: PropTypes.number,
        status: PropTypes.string.isRequired,
        staffingLevel: PropTypes.number,
        boardingPatients: PropTypes.number,
        pendingAdmissions: PropTypes.number,
        pendingDischarges: PropTypes.number,
        averageWaitTime: PropTypes.number,
    }),
    onClose: PropTypes.func.isRequired,
};

export default DepartmentDetailsPanel;
