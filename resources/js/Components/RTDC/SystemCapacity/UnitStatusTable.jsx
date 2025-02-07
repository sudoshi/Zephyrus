import React, { useState } from 'react';
import { Icon } from '@iconify/react';
import { useDarkMode } from '@/hooks/useDarkMode';

const UnitStatusTable = ({ units }) => {
    const [isDarkMode] = useDarkMode();
    const [focusedRow, setFocusedRow] = useState(null);

    const getStatusColor = (value) => {
        if (value <= -2) return 'text-healthcare-critical dark:text-healthcare-critical-dark';
        if (value <= -1) return 'text-healthcare-warning dark:text-healthcare-warning-dark';
        return 'text-healthcare-success dark:text-healthcare-success-dark';
    };

    const getStatusPattern = (value) => {
        if (value <= -2) return '●●●'; // Triple dot for critical
        if (value <= -1) return '●●'; // Double dot for warning
        return '●'; // Single dot for normal
    };

    const getAvailableBedsColor = (value) => {
        if (value === 0) return 'text-healthcare-critical dark:text-healthcare-critical-dark';
        if (value <= 2) return 'text-healthcare-warning dark:text-healthcare-warning-dark';
        return 'text-healthcare-success dark:text-healthcare-success-dark';
    };

    const getCapacityStatus = (unit) => {
        const capacity = unit.availableBeds + unit.predictedDC;
        const demand = unit.targetedRequests;
        if (capacity < demand) return 'Deficit';
        if (capacity === demand) return 'At Capacity';
        return 'Available';
    };

    return (
        <div className="overflow-x-auto" role="region" aria-label="Unit Status Table">
            <table className="min-w-full" role="table">
                <thead>
                    <tr className="bg-healthcare-background dark:bg-healthcare-background-dark">
                        <th scope="col" className="px-4 py-2 text-left text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                            Nursing Unit
                        </th>
                        <th scope="col" className="px-4 py-2 text-left text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                            Unit Description
                        </th>
                        <th scope="col" className="px-4 py-2 text-center text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                            # of Available Beds
                        </th>
                        <th scope="col" className="px-4 py-2 text-center text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                            Predicted DC / Transfers by 2 PM
                        </th>
                        <th scope="col" className="px-4 py-2 text-center text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                            Targeted Requests by 2 PM
                        </th>
                        <th scope="col" className="px-4 py-2 text-center text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                            Status by 2 PM
                        </th>
                        <th scope="col" className="px-4 py-2 text-left text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                            Red Stretch Plan
                        </th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                    {units.map((unit, index) => {
                        const capacityStatus = getCapacityStatus(unit);
                        const statusColor = getStatusColor(unit.status);
                        const statusPattern = getStatusPattern(unit.status);
                        
                        return (
                            <tr 
                                key={unit.id}
                                className={`
                                    ${focusedRow === index ? 'bg-healthcare-background/80 dark:bg-healthcare-background-dark/80' : 'hover:bg-healthcare-background/50 dark:hover:bg-healthcare-background-dark/50'}
                                    transition-colors duration-150
                                    ${unit.status <= -2 ? 'animate-pulse' : ''}
                                `}
                                onFocus={() => setFocusedRow(index)}
                                onBlur={() => setFocusedRow(null)}
                                tabIndex="0"
                                role="row"
                                aria-label={`${unit.name} - ${unit.description} - Status: ${capacityStatus}`}
                            >
                                <td className="px-4 py-3 text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                    {unit.name}
                                </td>
                                <td className="px-4 py-3 text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                    {unit.description}
                                </td>
                                <td className={`px-4 py-3 text-sm text-center font-medium ${getAvailableBedsColor(unit.availableBeds)}`}>
                                    <div className="flex items-center justify-center space-x-1">
                                        <span>{unit.availableBeds}</span>
                                        {unit.availableBeds === 0 && (
                                            <Icon icon="heroicons:exclamation-circle" className="w-4 h-4" />
                                        )}
                                    </div>
                                </td>
                                <td className="px-4 py-3 text-sm text-center text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                    {unit.predictedDC}
                                </td>
                                <td className="px-4 py-3 text-sm text-center text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                    {unit.targetedRequests}
                                </td>
                                <td className={`px-4 py-3 text-sm text-center font-medium ${statusColor}`}>
                                    <div className="flex items-center justify-center space-x-2">
                                        <span aria-hidden="true">{statusPattern}</span>
                                        <span>{unit.status}</span>
                                    </div>
                                </td>
                                <td className="px-4 py-3 text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                    {unit.redStretchPlan && (
                                        <div 
                                            className="flex items-center gap-2 cursor-help"
                                            title={unit.redStretchPlan}
                                            role="tooltip"
                                        >
                                            <Icon 
                                                icon="heroicons:exclamation-triangle" 
                                                className="w-4 h-4 text-healthcare-warning dark:text-healthcare-warning-dark flex-shrink-0" 
                                            />
                                            <span className="truncate">{unit.redStretchPlan}</span>
                                        </div>
                                    )}
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>

            {/* Screen reader only legend */}
            <div className="sr-only">
                <h3>Status Indicators:</h3>
                <ul>
                    <li>Single dot (●): Normal status</li>
                    <li>Double dot (●●): Warning status</li>
                    <li>Triple dot (●●●): Critical status</li>
                </ul>
            </div>
        </div>
    );
};

export default UnitStatusTable;
