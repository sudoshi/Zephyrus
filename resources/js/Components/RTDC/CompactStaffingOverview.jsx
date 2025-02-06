import React, { useState } from 'react';
import { Icon } from '@iconify/react';
import Card from '@/Components/Dashboard/Card';
import DrillDownModal from '@/Components/Dashboard/DrillDownModal';
import TrendChart from '@/Components/Analytics/Common/TrendChart';

// Utility function for status colors
const getStatusColor = (coverage) => {
    if (coverage < 85) return 'text-healthcare-critical dark:text-healthcare-critical-dark';
    if (coverage < 95) return 'text-healthcare-warning dark:text-healthcare-warning-dark';
    return 'text-healthcare-success dark:text-healthcare-success-dark';
};

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

const StaffingDetailsModal = ({ staffingData, isOpen, onClose }) => {
    return (
        <DrillDownModal
            isOpen={isOpen}
            onClose={onClose}
            title={
                <div className="flex items-center space-x-2">
                    <Icon icon="heroicons:users" className="w-5 h-5" />
                    <span>Staffing Details</span>
                </div>
            }
        >
            <div className="space-y-6">
                {/* Current Shift */}
                <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark p-4 rounded-lg">
                    <h3 className="text-lg font-medium mb-4">Current Shift</h3>
                    <div className="grid grid-cols-2 gap-4 mb-4">
                        <div>
                            <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-1">
                                Staff Present
                            </div>
                            <div className="text-2xl font-bold">
                                {staffingData.currentShift.present}/{staffingData.currentShift.required}
                            </div>
                            <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                {staffingData.currentShift.coverage}% coverage
                            </div>
                        </div>
                        <div>
                            <StaffingBreakdown
                                title="Skill Mix"
                                data={staffingData.currentShift.skillMix}
                                total={staffingData.currentShift.present}
                            />
                        </div>
                    </div>
                </div>

                {/* Next Shift */}
                <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark p-4 rounded-lg">
                    <h3 className="text-lg font-medium mb-4">Next Shift</h3>
                    <div className="grid grid-cols-2 gap-4 mb-4">
                        <div>
                            <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-1">
                                Staff Scheduled
                            </div>
                            <div className="text-2xl font-bold">
                                {staffingData.nextShift.scheduled}/{staffingData.nextShift.required}
                            </div>
                            <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                Predicted need: {staffingData.nextShift.predicted}
                            </div>
                        </div>
                        <div>
                            <StaffingBreakdown
                                title="Projected Mix"
                                data={staffingData.nextShift.skillMix}
                                total={staffingData.nextShift.scheduled}
                            />
                        </div>
                    </div>
                </div>

                {/* Coverage Trends */}
                <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark p-4 rounded-lg">
                    <h3 className="text-lg font-medium mb-4">Coverage Trends</h3>
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
                </div>
            </div>
        </DrillDownModal>
    );
};

const CompactStaffingOverview = ({ staffingData }) => {
    const [isExpanded, setIsExpanded] = useState(false);
    const [showDetails, setShowDetails] = useState(false);

    const currentCoverage = staffingData.currentShift.coverage;
    const nextShiftCoverage = Math.round((staffingData.nextShift.scheduled / staffingData.nextShift.required) * 100);

    // Find most critical skill gap
    const criticalSkill = Object.entries(staffingData.currentShift.skillMix).reduce((critical, [role, counts]) => {
        const coverage = Math.round((counts.present / counts.required) * 100);
        if (!critical || coverage < critical.coverage) {
            return { role, coverage, gap: counts.required - counts.present };
        }
        return critical;
    }, null);

    return (
        <Card>
            <div className="p-4">
                {/* Summary Bar */}
                <div className="flex items-center justify-between">
                    <div className="flex items-center space-x-4">
                        <div className="flex items-center space-x-2">
                            <Icon icon="heroicons:users" className="w-5 h-5" />
                            <span className="font-medium">Staffing:</span>
                        </div>
                        <div className="flex items-center space-x-3 text-sm">
                            <span className={getStatusColor(currentCoverage)}>
                                {currentCoverage}% Coverage
                            </span>
                            <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                ({staffingData.currentShift.present}/{staffingData.currentShift.required})
                            </span>
                        </div>
                    </div>
                    <div className="flex items-center space-x-2">
                        <button
                            onClick={() => setShowDetails(true)}
                            className="p-1 hover:bg-healthcare-background dark:hover:bg-healthcare-background-dark rounded-md transition-colors duration-150"
                        >
                            <Icon icon="heroicons:chart-bar" className="w-5 h-5 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" />
                        </button>
                        <button
                            onClick={() => setIsExpanded(!isExpanded)}
                            className="p-1 hover:bg-healthcare-background dark:hover:bg-healthcare-background-dark rounded-md transition-colors duration-150"
                        >
                            <Icon
                                icon={isExpanded ? 'heroicons:chevron-up' : 'heroicons:chevron-down'}
                                className="w-5 h-5 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"
                            />
                        </button>
                    </div>
                </div>

                {/* Critical Status Preview */}
                {!isExpanded && criticalSkill && (
                    <div className="mt-2">
                        <div className={`px-3 py-2 rounded-md ${
                            criticalSkill.coverage < 85 ? 'bg-healthcare-critical/5 dark:bg-healthcare-critical-dark/5' : 'bg-healthcare-warning/5 dark:bg-healthcare-warning-dark/5'
                        }`}>
                            <div className="flex items-center justify-between">
                                <div className="flex items-center space-x-2">
                                    <Icon 
                                        icon={criticalSkill.coverage < 85 ? 'heroicons:exclamation-triangle' : 'heroicons:exclamation-circle'} 
                                        className={`w-4 h-4 ${getStatusColor(criticalSkill.coverage)}`}
                                    />
                                    <span className={`text-sm font-medium ${getStatusColor(criticalSkill.coverage)}`}>
                                        {criticalSkill.role.toUpperCase()} shortage: {criticalSkill.gap} needed
                                    </span>
                                </div>
                                <span className="text-xs text-healthcare-text-tertiary dark:text-healthcare-text-tertiary-dark">
                                    Next shift: {nextShiftCoverage}% coverage
                                </span>
                            </div>
                        </div>
                    </div>
                )}

                {/* Expanded View */}
                {isExpanded && (
                    <div className="mt-3 space-y-4">
                        {/* Current Shift */}
                        <div className="bg-healthcare-background dark:bg-healthcare-background-dark p-3 rounded-lg">
                            <div className="flex items-center justify-between mb-2">
                                <span className="text-sm font-medium">Current Shift</span>
                                <span className={`text-sm font-medium ${getStatusColor(currentCoverage)}`}>
                                    {currentCoverage}% Coverage
                                </span>
                            </div>
                            <StaffingBreakdown
                                title="Skill Mix"
                                data={staffingData.currentShift.skillMix}
                                total={staffingData.currentShift.present}
                            />
                        </div>

                        {/* Next Shift */}
                        <div className="bg-healthcare-background dark:bg-healthcare-background-dark p-3 rounded-lg">
                            <div className="flex items-center justify-between mb-2">
                                <span className="text-sm font-medium">Next Shift</span>
                                <span className={`text-sm font-medium ${getStatusColor(nextShiftCoverage)}`}>
                                    {nextShiftCoverage}% Coverage
                                </span>
                            </div>
                            <StaffingBreakdown
                                title="Projected Mix"
                                data={staffingData.nextShift.skillMix}
                                total={staffingData.nextShift.scheduled}
                            />
                        </div>
                    </div>
                )}
            </div>

            {/* Details Modal */}
            <StaffingDetailsModal
                staffingData={staffingData}
                isOpen={showDetails}
                onClose={() => setShowDetails(false)}
            />
        </Card>
    );
};

export default CompactStaffingOverview;
