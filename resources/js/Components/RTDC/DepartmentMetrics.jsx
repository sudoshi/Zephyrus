import React from 'react';
import { Icon } from '@iconify/react';
import Card from '@/Components/Dashboard/Card';
import StatusDot from '@/Components/RoomStatus/StatusDot';

const DepartmentCard = ({ department }) => {
    const getStatusColor = (status) => {
        switch (status) {
            case 'critical':
                return 'text-healthcare-critical dark:text-healthcare-critical-dark';
            case 'warning':
                return 'text-healthcare-warning dark:text-healthcare-warning-dark';
            default:
                return 'text-healthcare-success dark:text-healthcare-success-dark';
        }
    };

    const getAcuityBreakdown = (acuity) => {
        if ('level1' in acuity) {
            // ED acuity levels
            return (
                <div className="grid grid-cols-5 gap-1">
                    {[1, 2, 3, 4, 5].map((level) => (
                        <div key={level} className="text-center">
                            <div className="text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                L{level}
                            </div>
                            <div className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                {acuity[`level${level}`]}
                            </div>
                        </div>
                    ))}
                </div>
            );
        } else {
            // Standard acuity (high/medium/low)
            return (
                <div className="grid grid-cols-3 gap-1">
                    {Object.entries(acuity).map(([level, count]) => (
                        <div key={level} className="text-center">
                            <div className="text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                {level.charAt(0).toUpperCase() + level.slice(1)}
                            </div>
                            <div className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                {count}
                            </div>
                        </div>
                    ))}
                </div>
            );
        }
    };

    return (
        <Card>
            <Card.Content>
                <div className="space-y-4">
                    {/* Header */}
                    <div className="flex items-center justify-between">
                        <div className="flex items-center space-x-3">
                            <StatusDot status={department.status} size="lg" />
                            <h3 className="text-lg font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                {department.name}
                            </h3>
                        </div>
                        <div className={`text-lg font-medium ${getStatusColor(department.status)}`}>
                            {department.occupancy}%
                        </div>
                    </div>

                    {/* Metrics Grid */}
                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <div className="flex items-center space-x-2 mb-1">
                                <Icon icon="heroicons:building-office-2" className="w-4 h-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" />
                                <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                    Capacity
                                </span>
                            </div>
                            <div className="text-sm">
                                <span className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                    {department.occupiedBeds}/{department.totalBeds}
                                </span>
                                <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                    {' '}beds
                                </span>
                            </div>
                        </div>

                        <div>
                            <div className="flex items-center space-x-2 mb-1">
                                <Icon icon="heroicons:users" className="w-4 h-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" />
                                <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                    Staffing
                                </span>
                            </div>
                            <div className="text-sm">
                                <span className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                    {department.staffing.current}/{department.staffing.required}
                                </span>
                                <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                    {' '}staff
                                </span>
                            </div>
                        </div>

                        <div>
                            <div className="flex items-center space-x-2 mb-1">
                                <Icon icon="heroicons:arrow-right-circle" className="w-4 h-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" />
                                <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                    Pending In
                                </span>
                            </div>
                            <div className="text-sm">
                                <span className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                    {department.pendingAdmissions}
                                </span>
                                <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                    {' '}admissions
                                </span>
                            </div>
                        </div>

                        <div>
                            <div className="flex items-center space-x-2 mb-1">
                                <Icon icon="heroicons:arrow-left-circle" className="w-4 h-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" />
                                <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                    Pending Out
                                </span>
                            </div>
                            <div className="text-sm">
                                <span className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                    {department.pendingDischarges}
                                </span>
                                <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                    {' '}discharges
                                </span>
                            </div>
                        </div>
                    </div>

                    {/* Acuity Breakdown */}
                    <div>
                        <div className="flex items-center space-x-2 mb-2">
                            <Icon icon="heroicons:chart-bar" className="w-4 h-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" />
                            <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                Acuity Breakdown
                            </span>
                        </div>
                        {getAcuityBreakdown(department.acuity)}
                    </div>

                    {/* Staffing Changes */}
                    {(department.staffing.incoming > 0 || department.staffing.outgoing > 0) && (
                        <div className="flex items-center justify-between text-sm">
                            {department.staffing.incoming > 0 && (
                                <div className="text-healthcare-success dark:text-healthcare-success-dark">
                                    +{department.staffing.incoming} arriving
                                </div>
                            )}
                            {department.staffing.outgoing > 0 && (
                                <div className="text-healthcare-warning dark:text-healthcare-warning-dark">
                                    -{department.staffing.outgoing} departing
                                </div>
                            )}
                        </div>
                    )}
                </div>
            </Card.Content>
        </Card>
    );
};

const DepartmentMetrics = ({ departments }) => {
    return (
        <div className="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-6">
            {Object.values(departments).map((department) => (
                <DepartmentCard key={department.name} department={department} />
            ))}
        </div>
    );
};

export default DepartmentMetrics;
