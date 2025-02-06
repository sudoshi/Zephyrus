import React, { useState } from 'react';
import { Icon } from '@iconify/react';
import Card from '@/Components/Dashboard/Card';
import DrillDownModal from '@/Components/Dashboard/DrillDownModal';

const AlertDetails = ({ alert, onClose }) => {
    const getPriorityStyles = (priority) => {
        switch (priority) {
            case 'high':
                return {
                    bg: 'bg-healthcare-critical/10 dark:bg-healthcare-critical-dark/10',
                    border: 'border-healthcare-critical dark:border-healthcare-critical-dark',
                    text: 'text-healthcare-critical dark:text-healthcare-critical-dark',
                    icon: 'heroicons:exclamation-triangle'
                };
            case 'medium':
                return {
                    bg: 'bg-healthcare-warning/10 dark:bg-healthcare-warning-dark/10',
                    border: 'border-healthcare-warning dark:border-healthcare-warning-dark',
                    text: 'text-healthcare-warning dark:text-healthcare-warning-dark',
                    icon: 'heroicons:exclamation-circle'
                };
            default:
                return {
                    bg: 'bg-healthcare-info/10 dark:bg-healthcare-info-dark/10',
                    border: 'border-healthcare-info dark:border-healthcare-info-dark',
                    text: 'text-healthcare-info dark:text-healthcare-info-dark',
                    icon: 'heroicons:information-circle'
                };
        }
    };

    const styles = getPriorityStyles(alert.priority);

    return (
        <DrillDownModal
            isOpen={true}
            onClose={onClose}
            title={
                <div className="flex items-center space-x-3">
                    <Icon icon={styles.icon} className={`w-5 h-5 ${styles.text}`} />
                    <span>{alert.title}</span>
                </div>
            }
        >
            <div className="space-y-6">
                <div className={`p-4 rounded-lg ${styles.bg} ${styles.border}`}>
                    <p className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                        {alert.description}
                    </p>
                </div>

                <div className="grid grid-cols-2 gap-4">
                    <div>
                        <h4 className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-1">
                            Department
                        </h4>
                        <p className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                            {alert.department}
                        </p>
                    </div>
                    <div>
                        <h4 className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-1">
                            Time
                        </h4>
                        <p className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                            {alert.time}
                        </p>
                    </div>
                </div>

                <div>
                    <h4 className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-2">
                        Actions
                    </h4>
                    <div className="flex flex-wrap gap-2">
                        {alert.actions.map((action, index) => (
                            <button
                                key={index}
                                className={`px-3 py-1.5 text-sm rounded-md ${styles.text} hover:${styles.bg} transition-colors duration-150`}
                            >
                                {action}
                            </button>
                        ))}
                    </div>
                </div>
            </div>
        </DrillDownModal>
    );
};

const CompactAlertItem = ({ alert, onClick }) => {
    const getPriorityStyles = (priority) => {
        switch (priority) {
            case 'high':
                return 'text-healthcare-critical dark:text-healthcare-critical-dark';
            case 'medium':
                return 'text-healthcare-warning dark:text-healthcare-warning-dark';
            default:
                return 'text-healthcare-info dark:text-healthcare-info-dark';
        }
    };

    const getCategoryIcon = (category) => {
        switch (category) {
            case 'capacity':
                return 'heroicons:building-office-2';
            case 'flow':
                return 'heroicons:arrows-right-left';
            case 'staffing':
                return 'heroicons:users';
            case 'service':
                return 'heroicons:wrench-screwdriver';
            default:
                return 'heroicons:bell-alert';
        }
    };

    return (
        <button
            onClick={onClick}
            className="w-full text-left px-3 py-2 hover:bg-healthcare-background dark:hover:bg-healthcare-background-dark rounded-md transition-colors duration-150"
        >
            <div className="flex items-center justify-between">
                <div className="flex items-center space-x-3">
                    <Icon
                        icon={getCategoryIcon(alert.category)}
                        className={`w-4 h-4 ${getPriorityStyles(alert.priority)}`}
                    />
                    <span className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                        {alert.title}
                    </span>
                </div>
                <div className="flex items-center space-x-2 text-xs text-healthcare-text-tertiary dark:text-healthcare-text-tertiary-dark">
                    <span>{alert.department}</span>
                    <span>•</span>
                    <span>{alert.time}</span>
                </div>
            </div>
        </button>
    );
};

const CompactAlerts = ({ alerts, statistics }) => {
    const [isExpanded, setIsExpanded] = useState(false);
    const [selectedAlert, setSelectedAlert] = useState(null);

    const criticalAlerts = alerts.filter(alert => alert.priority === 'high');
    const mostCritical = criticalAlerts[0];

    return (
        <Card>
            <div className="p-4">
                {/* Summary Bar */}
                <div className="flex items-center justify-between">
                    <div className="flex items-center space-x-4">
                        <div className="flex items-center space-x-2">
                            <Icon icon="heroicons:bell-alert" className="w-5 h-5 text-healthcare-critical dark:text-healthcare-critical-dark" />
                            <span className="font-medium">Alerts:</span>
                        </div>
                        <div className="flex items-center space-x-3 text-sm">
                            <span className="text-healthcare-critical dark:text-healthcare-critical-dark">
                                {statistics.byPriority.high} High
                            </span>
                            <span className="text-healthcare-warning dark:text-healthcare-warning-dark">
                                {statistics.byPriority.medium} Medium
                            </span>
                            <span className="text-healthcare-info dark:text-healthcare-info-dark">
                                {statistics.byPriority.low} Low
                            </span>
                        </div>
                    </div>
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

                {/* Most Critical Alert Preview */}
                {!isExpanded && mostCritical && (
                    <div className="mt-2">
                        <button
                            onClick={() => setSelectedAlert(mostCritical)}
                            className="w-full text-left px-3 py-2 bg-healthcare-critical/5 dark:bg-healthcare-critical-dark/5 rounded-md hover:bg-healthcare-critical/10 dark:hover:bg-healthcare-critical-dark/10 transition-colors duration-150"
                        >
                            <div className="flex items-center justify-between">
                                <div className="flex items-center space-x-2">
                                    <Icon icon="heroicons:exclamation-triangle" className="w-4 h-4 text-healthcare-critical dark:text-healthcare-critical-dark" />
                                    <span className="text-sm font-medium text-healthcare-critical dark:text-healthcare-critical-dark">
                                        {mostCritical.title}
                                    </span>
                                </div>
                                <span className="text-xs text-healthcare-text-tertiary dark:text-healthcare-text-tertiary-dark">
                                    {mostCritical.time}
                                </span>
                            </div>
                        </button>
                    </div>
                )}

                {/* Expanded Alert List */}
                {isExpanded && (
                    <div className="mt-3 space-y-1">
                        {alerts.map((alert) => (
                            <CompactAlertItem
                                key={alert.id}
                                alert={alert}
                                onClick={() => setSelectedAlert(alert)}
                            />
                        ))}
                    </div>
                )}
            </div>

            {/* Alert Details Modal */}
            {selectedAlert && (
                <AlertDetails
                    alert={selectedAlert}
                    onClose={() => setSelectedAlert(null)}
                />
            )}
        </Card>
    );
};

export default CompactAlerts;
