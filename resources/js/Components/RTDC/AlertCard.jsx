import React from 'react';
import { Icon } from '@iconify/react';
const AlertCard = ({ alert }) => {
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

    const getStatusBadge = (status) => {
        switch (status) {
            case 'unacknowledged':
                return {
                    bg: 'bg-healthcare-critical/10 dark:bg-healthcare-critical-dark/10',
                    text: 'text-healthcare-critical dark:text-healthcare-critical-dark'
                };
            case 'in_progress':
                return {
                    bg: 'bg-healthcare-warning/10 dark:bg-healthcare-warning-dark/10',
                    text: 'text-healthcare-warning dark:text-healthcare-warning-dark'
                };
            case 'acknowledged':
                return {
                    bg: 'bg-healthcare-info/10 dark:bg-healthcare-info-dark/10',
                    text: 'text-healthcare-info dark:text-healthcare-info-dark'
                };
            case 'resolved':
                return {
                    bg: 'bg-healthcare-success/10 dark:bg-healthcare-success-dark/10',
                    text: 'text-healthcare-success dark:text-healthcare-success-dark'
                };
            default:
                return {
                    bg: 'bg-healthcare-background-alt dark:bg-healthcare-background-alt-dark',
                    text: 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark'
                };
        }
    };

    const styles = getPriorityStyles(alert.priority);
    const statusStyles = getStatusBadge(alert.status);

    return (
        <div className={`p-4 border rounded-lg ${styles.bg} ${styles.border}`}>
            <div className="flex items-start space-x-4">
                <div className={`p-2 rounded-lg ${styles.bg}`}>
                    <Icon icon={styles.icon} className={`w-5 h-5 ${styles.text}`} />
                </div>
                <div className="flex-grow">
                    <div className="flex items-center justify-between">
                        <h4 className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                            {alert.title}
                        </h4>
                        <div className="flex items-center space-x-2">
                            <div className="relative group/tooltip cursor-help">
                                <div className="p-1 rounded-md hover:bg-healthcare-background dark:hover:bg-healthcare-background-dark">
                                    <Icon 
                                        icon={getCategoryIcon(alert.category)} 
                                        className="w-4 h-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" 
                                    />
                                </div>
                                <div className="absolute z-10 w-32 p-2 mt-2 text-xs bg-healthcare-surface dark:bg-healthcare-surface-dark text-healthcare-text-primary dark:text-healthcare-text-primary-dark rounded-lg shadow-lg border border-healthcare-border dark:border-healthcare-border-dark opacity-0 group-hover/tooltip:opacity-100 transition-opacity duration-200 pointer-events-none left-0">
                                    {alert.category.charAt(0).toUpperCase() + alert.category.slice(1)}
                                </div>
                            </div>
                            <span className={`px-2 py-1 text-xs rounded-full ${statusStyles.bg} ${statusStyles.text}`}>
                                {alert.status.replace('_', ' ')}
                            </span>
                        </div>
                    </div>
                    <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mt-1">
                        {alert.description}
                    </p>
                    <div className="flex items-center justify-between mt-3">
                        <div className="flex items-center space-x-2 text-xs text-healthcare-text-tertiary dark:text-healthcare-text-tertiary-dark">
                            <span>{alert.department}</span>
                            <span>•</span>
                            <span>{alert.time}</span>
                        </div>
                        <div className="flex items-center space-x-2">
                            {alert.actions.map((action, index) => (
                                <button
                                    key={index}
                                    className={`px-2 py-1 text-xs rounded-md ${styles.text} hover:${styles.bg} transition-colors duration-150`}
                                >
                                    {action}
                                </button>
                            ))}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
};

export default AlertCard;
