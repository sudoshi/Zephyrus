import React from 'react';
import { Icon } from '@iconify/react';

const ConfidenceIndicator = ({ level }) => {
    if (level >= 90) {
        return <Icon icon="heroicons:check-circle" className="w-4 h-4 text-healthcare-success" />;
    } else if (level >= 85) {
        return <Icon icon="heroicons:check-circle" className="w-4 h-4 text-healthcare-success" />;
    } else if (level >= 80) {
        return <Icon icon="heroicons:exclamation-circle" className="w-4 h-4 text-healthcare-warning" />;
    } else {
        return <Icon icon="heroicons:exclamation-circle" className="w-4 h-4 text-healthcare-warning" />;
    }
};

const StaffingForecastTable = ({ forecasts }) => {
    return (
        <div className="h-full overflow-hidden">
            <table className="min-w-full">
                <thead>
                    <tr className="border-b border-healthcare-border dark:border-healthcare-border-dark">
                        <th className="py-2 text-left text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                            Time
                        </th>
                        <th className="py-2 text-left text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                            Department
                        </th>
                        <th className="py-2 text-right text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                            Predicted Needed
                        </th>
                        <th className="py-2 text-center text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                            Confidence
                        </th>
                        <th className="py-2 text-right text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                            Lower Bound
                        </th>
                        <th className="py-2 text-right text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                            Upper Bound
                        </th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                    {forecasts.map((forecast, index) => (
                        <tr 
                            key={`${forecast.time}-${forecast.department}`}
                            className="hover:bg-healthcare-background dark:hover:bg-healthcare-background-dark"
                        >
                            <td className="py-2 text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark whitespace-nowrap">
                                {forecast.time}
                            </td>
                            <td className="py-2 text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                {forecast.department}
                            </td>
                            <td className="py-2 text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark text-right">
                                {forecast.predicted}
                            </td>
                            <td className="py-2 text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                <div className="flex items-center justify-center space-x-1">
                                    <ConfidenceIndicator level={forecast.confidence} />
                                    <span>{forecast.confidence}%</span>
                                </div>
                            </td>
                            <td className="py-2 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark text-right">
                                {forecast.lowerBound}
                            </td>
                            <td className="py-2 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark text-right">
                                {forecast.upperBound}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
};

export default StaffingForecastTable;
