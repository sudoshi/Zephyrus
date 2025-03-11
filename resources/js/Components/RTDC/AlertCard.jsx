import React from 'react';
import { Icon } from '@iconify/react';
import { useDarkMode } from '@/hooks/useDarkMode';

const AlertCard = ({ alert }) => {
    const [isDarkMode] = useDarkMode();

    const getAlertStyles = (type) => {
        switch (type) {
            case 'critical':
                return {
                    bg: 'bg-healthcare-critical/20',
                    text: 'text-healthcare-critical dark:text-healthcare-critical-dark',
                    icon: 'heroicons:exclamation-triangle',
                    pattern: '⚠', // Warning symbol for colorblind users
                    animation: 'animate-pulse'
                };
            case 'warning':
                return {
                    bg: 'bg-healthcare-warning/20',
                    text: 'text-healthcare-warning dark:text-healthcare-warning-dark',
                    icon: 'heroicons:exclamation-circle',
                    pattern: '⚡', // Lightning symbol for colorblind users
                    animation: ''
                };
            default:
                return {
                    bg: 'bg-healthcare-info/20',
                    text: 'text-healthcare-info dark:text-healthcare-info-dark',
                    icon: 'heroicons:information-circle',
                    pattern: 'ℹ', // Info symbol for colorblind users
                    animation: ''
                };
        }
    };

    const styles = getAlertStyles(alert.type);

    return (
        <div 
            className={`
                flex items-center justify-between p-4 
                bg-healthcare-background dark:bg-healthcare-background-dark 
                rounded-lg border-l-4 transition-all duration-300
                hover:shadow-lg
                ${styles.animation}
                ${alert.type === 'critical' ? 'border-healthcare-critical' : 
                  alert.type === 'warning' ? 'border-healthcare-warning' : 
                  'border-healthcare-info'}
            `}
            role="alert"
            aria-live={alert.type === 'critical' ? 'assertive' : 'polite'}
        >
            <div className="flex items-center space-x-4">
                <div className={`p-2 rounded-lg ${styles.bg} ${styles.text}`}>
                    <div className="relative">
                        <Icon 
                            icon={styles.icon}
                            className="w-5 h-5"
                        />
                        <span className="sr-only">{styles.pattern}</span>
                    </div>
                </div>
                <div>
                    <div className="flex items-center space-x-2">
                        <div className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                            {alert.message}
                        </div>
                        {alert.type === 'critical' && (
                            <span 
                                className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-healthcare-critical/10 text-healthcare-critical dark:text-healthcare-critical-dark"
                                role="status"
                            >
                                Critical
                            </span>
                        )}
                    </div>
                    <div className="flex items-center space-x-2 mt-1">
                        <span className="text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                            {alert.unit}
                        </span>
                        <span className="text-xs text-healthcare-text-tertiary dark:text-healthcare-text-tertiary-dark">
                            • {alert.time}
                        </span>
                    </div>
                </div>
            </div>
            
            {/* Keyboard navigation support */}
            <button 
                className="p-2 hover:bg-healthcare-background-dark/10 dark:hover:bg-healthcare-background/10 rounded-full transition-colors duration-150"
                aria-label="View alert details"
                onClick={() => {/* Add click handler for alert details */}}
            >
                <Icon 
                    icon="heroicons:chevron-right" 
                    className="w-5 h-5 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"
                />
            </button>
        </div>
    );
};

export default AlertCard;
