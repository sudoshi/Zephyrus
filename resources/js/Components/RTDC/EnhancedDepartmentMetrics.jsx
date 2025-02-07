import React, { useState } from 'react';
import { Icon } from '@iconify/react';
import Card from '@/Components/Dashboard/Card';
import StatusDot from '@/Components/RoomStatus/StatusDot';
import SimpleTrendChart from '@/Components/Analytics/Common/SimpleTrendChart';
import DrillDownModal from '@/Components/Dashboard/DrillDownModal';

const DepartmentOverviewCard = ({ department, onViewDetails }) => {
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

    return (
        <Card className="h-full">
            <Card.Content>
                <div className="space-y-4">
                    {/* Header with Status */}
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

                    {/* Key Metrics */}
                    <div className="grid grid-cols-2 gap-3">
                        <div className="bg-healthcare-background dark:bg-healthcare-background-dark p-3 rounded-lg">
                            <div className="flex items-center space-x-2 mb-1">
                                <Icon icon="heroicons:building-office-2" className="w-4 h-4" />
                                <span className="text-sm font-medium">Beds</span>
                            </div>
                            <div className="text-lg font-semibold">
                                {department.occupiedBeds}/{department.totalBeds}
                            </div>
                        </div>
                        <div className="bg-healthcare-background dark:bg-healthcare-background-dark p-3 rounded-lg">
                            <div className="flex items-center space-x-2 mb-1">
                                <Icon icon="heroicons:users" className="w-4 h-4" />
                                <span className="text-sm font-medium">Staff</span>
                            </div>
                            <div className="text-lg font-semibold">
                                {department.staffing.current}/{department.staffing.required}
                            </div>
                        </div>
                    </div>

                    {/* Patient Flow */}
                    <div className="flex items-center justify-between text-sm">
                        <div className="flex items-center space-x-2">
                            <Icon icon="heroicons:arrow-right-circle" className="w-4 h-4 text-healthcare-success dark:text-healthcare-success-dark" />
                            <span>+{department.pendingAdmissions}</span>
                        </div>
                        <div className="flex items-center space-x-2">
                            <span>-{department.pendingDischarges}</span>
                            <Icon icon="heroicons:arrow-left-circle" className="w-4 h-4 text-healthcare-warning dark:text-healthcare-warning-dark" />
                        </div>
                    </div>

                    {/* View Details Button */}
                    <button
                        onClick={onViewDetails}
                        className="w-full py-2 px-4 text-sm font-medium text-healthcare-primary dark:text-healthcare-primary-dark hover:bg-healthcare-background dark:hover:bg-healthcare-background-dark rounded-md transition-colors duration-200"
                    >
                        View Details
                    </button>
                </div>
            </Card.Content>
        </Card>
    );
};

const DepartmentDetailsModal = ({ department, isOpen, onClose }) => {
    const [activeTab, setActiveTab] = useState('capacity');

    const tabs = [
        { id: 'capacity', label: 'Capacity & Flow', icon: 'heroicons:building-office-2' },
        { id: 'staffing', label: 'Staffing', icon: 'heroicons:users' },
        { id: 'acuity', label: 'Acuity', icon: 'heroicons:chart-bar' },
    ];

    const renderTabContent = () => {
        switch (activeTab) {
            case 'capacity':
                return (
                    <div className="space-y-6">
                        <div className="grid grid-cols-2 gap-4">
                            <div className="bg-healthcare-background dark:bg-healthcare-background-dark p-4 rounded-lg">
                                <h4 className="text-sm font-medium mb-2">Current Occupancy</h4>
                                <div className="text-2xl font-bold">{department.occupancy}%</div>
                                <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                    {department.occupiedBeds} of {department.totalBeds} beds
                                </div>
                            </div>
                            <div className="bg-healthcare-background dark:bg-healthcare-background-dark p-4 rounded-lg">
                                <h4 className="text-sm font-medium mb-2">Patient Flow</h4>
                                <div className="space-y-2">
                                    <div className="flex justify-between">
                                        <span>Pending Admissions</span>
                                        <span className="font-medium">+{department.pendingAdmissions}</span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span>Pending Discharges</span>
                                        <span className="font-medium">-{department.pendingDischarges}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                );
            case 'staffing':
                return (
                    <div className="space-y-6">
                        <div className="grid grid-cols-2 gap-4">
                            <div className="bg-healthcare-background dark:bg-healthcare-background-dark p-4 rounded-lg">
                                <h4 className="text-sm font-medium mb-2">Current Coverage</h4>
                                <div className="text-2xl font-bold">{department.staffingLevel}%</div>
                                <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                    {department.staffing.current} of {department.staffing.required} required
                                </div>
                            </div>
                            <div className="bg-healthcare-background dark:bg-healthcare-background-dark p-4 rounded-lg">
                                <h4 className="text-sm font-medium mb-2">Staff Changes</h4>
                                <div className="space-y-2">
                                    <div className="flex justify-between">
                                        <span>Incoming</span>
                                        <span className="font-medium text-healthcare-success dark:text-healthcare-success-dark">
                                            +{department.staffing.incoming}
                                        </span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span>Outgoing</span>
                                        <span className="font-medium text-healthcare-warning dark:text-healthcare-warning-dark">
                                            -{department.staffing.outgoing}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                );
            case 'acuity':
                return (
                    <div className="space-y-6">
                        <div className="bg-healthcare-background dark:bg-healthcare-background-dark p-4 rounded-lg">
                            <h4 className="text-sm font-medium mb-4">Acuity Distribution</h4>
                            {'level1' in department.acuity ? (
                                <div className="grid grid-cols-5 gap-4">
                                    {[1, 2, 3, 4, 5].map((level) => (
                                        <div key={level} className="text-center">
                                            <div className="text-sm font-medium mb-1">Level {level}</div>
                                            <div className="text-lg font-bold">{department.acuity[`level${level}`]}</div>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <div className="grid grid-cols-3 gap-4">
                                    {Object.entries(department.acuity).map(([level, count]) => (
                                        <div key={level} className="text-center">
                                            <div className="text-sm font-medium mb-1">
                                                {level.charAt(0).toUpperCase() + level.slice(1)}
                                            </div>
                                            <div className="text-lg font-bold">{count}</div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    </div>
                );
            default:
                return null;
        }
    };

    return (
        <DrillDownModal
            isOpen={isOpen}
            onClose={onClose}
            title={
                <div className="flex items-center space-x-3">
                    <StatusDot status={department.status} size="lg" />
                    <span>{department.name} Department</span>
                </div>
            }
        >
            <div className="space-y-6">
                {/* Tabs */}
                <div className="flex space-x-2 border-b border-healthcare-border dark:border-healthcare-border-dark">
                    {tabs.map((tab) => (
                        <button
                            key={tab.id}
                            onClick={() => setActiveTab(tab.id)}
                            className={`flex items-center space-x-2 px-4 py-2 border-b-2 transition-colors duration-200 ${
                                activeTab === tab.id
                                    ? 'border-healthcare-primary dark:border-healthcare-primary-dark text-healthcare-primary dark:text-healthcare-primary-dark'
                                    : 'border-transparent text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark hover:text-healthcare-text-primary dark:hover:text-healthcare-text-primary-dark'
                            }`}
                        >
                            <Icon icon={tab.icon} className="w-4 h-4" />
                            <span>{tab.label}</span>
                        </button>
                    ))}
                </div>

                {/* Tab Content */}
                {renderTabContent()}
            </div>
        </DrillDownModal>
    );
};

const EnhancedDepartmentMetrics = ({ departments }) => {
    const [selectedDepartment, setSelectedDepartment] = useState(null);

    return (
        <div className="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-6">
            {Object.values(departments).map((department) => (
                <DepartmentOverviewCard
                    key={department.name}
                    department={department}
                    onViewDetails={() => setSelectedDepartment(department)}
                />
            ))}

            {/* Details Modal */}
            {selectedDepartment && (
                <DepartmentDetailsModal
                    department={selectedDepartment}
                    isOpen={true}
                    onClose={() => setSelectedDepartment(null)}
                />
            )}
        </div>
    );
};

export default EnhancedDepartmentMetrics;
