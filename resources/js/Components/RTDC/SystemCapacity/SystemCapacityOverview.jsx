import React from 'react';
import { Icon } from '@iconify/react';
import MetricsCard from '@/Components/Analytics/Common/MetricsCard';
import { useDarkMode } from '@/hooks/useDarkMode';

const SystemCapacityOverview = ({ metrics }) => {
    const [isDarkMode] = useDarkMode();

    // Determine critical states
    const isCriticalBeds = metrics.availableBeds < 5;
    const isHighPendingRequests = metrics.pendingRequests > metrics.availableBeds;
    const hasCriticalUnits = metrics.criticalUnits > 0;

    // Animation class for critical values
    const pulseAnimation = "animate-pulse";

    // Enhanced tooltips
    const tooltips = {
        availableBeds: "Total number of staffed beds currently available for new patients",
        expectedDC: "Anticipated discharges/transfers within the next 4 hours",
        pendingRequests: "Current pending bed requests from ED, OR, and transfers",
        criticalUnits: "Number of units operating at or above capacity"
    };

    return (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <div 
                className={`relative ${isCriticalBeds ? 'order-first' : ''}`}
                role="status"
                aria-label={`Available Beds: ${metrics.availableBeds}`}
            >
                <MetricsCard
                    title="Available Beds"
                    value={metrics.availableBeds.toString()}
                    icon="heroicons:home"
                    className={`
                        ${isCriticalBeds ? 'border-2 border-healthcare-critical dark:border-healthcare-critical-dark' : ''}
                        ${isCriticalBeds ? pulseAnimation : ''}
                        bg-healthcare-blue-50 dark:bg-healthcare-blue-900/20
                        hover:shadow-lg transition-shadow duration-300
                    `}
                    description={tooltips.availableBeds}
                    trend={isCriticalBeds ? 'down' : undefined}
                    trendValue={isCriticalBeds ? 'Critical' : undefined}
                />
                {isCriticalBeds && (
                    <div className="absolute -top-2 -right-2 w-4 h-4 bg-healthcare-critical rounded-full animate-ping" />
                )}
            </div>

            <MetricsCard
                title="Expected DC"
                value={metrics.expectedDC.toString()}
                icon="heroicons:arrow-right-circle"
                className="bg-healthcare-green-50 dark:bg-healthcare-green-900/20 hover:shadow-lg transition-shadow duration-300"
                description={tooltips.expectedDC}
                trend={metrics.expectedDC > 0 ? 'up' : undefined}
            />

            <div className={`relative ${isHighPendingRequests ? 'order-first md:order-none' : ''}`}>
                <MetricsCard
                    title="Pending Requests"
                    value={metrics.pendingRequests.toString()}
                    icon="heroicons:clock"
                    className={`
                        ${isHighPendingRequests ? 'border-2 border-healthcare-warning dark:border-healthcare-warning-dark' : ''}
                        bg-healthcare-yellow-50 dark:bg-healthcare-yellow-900/20
                        hover:shadow-lg transition-shadow duration-300
                    `}
                    description={tooltips.pendingRequests}
                    trend={isHighPendingRequests ? 'up' : undefined}
                    trendValue={isHighPendingRequests ? 'High' : undefined}
                />
            </div>

            <div className={`relative ${hasCriticalUnits ? 'order-first lg:order-none' : ''}`}>
                <MetricsCard
                    title="Critical Units"
                    value={metrics.criticalUnits.toString()}
                    icon="heroicons:exclamation-circle"
                    className={`
                        ${hasCriticalUnits ? 'border-2 border-healthcare-critical dark:border-healthcare-critical-dark' : ''}
                        ${hasCriticalUnits ? pulseAnimation : ''}
                        bg-healthcare-red-50 dark:bg-healthcare-red-900/20
                        hover:shadow-lg transition-shadow duration-300
                    `}
                    description={tooltips.criticalUnits}
                    trend={hasCriticalUnits ? 'up' : undefined}
                    trendValue={hasCriticalUnits ? 'Alert' : undefined}
                />
                {hasCriticalUnits && (
                    <div className="absolute -top-2 -right-2 w-4 h-4 bg-healthcare-critical rounded-full animate-ping" />
                )}
            </div>

            {/* Screen reader only summary */}
            <div className="sr-only" role="status" aria-live="polite">
                {isCriticalBeds && "Alert: Critical bed availability."}
                {isHighPendingRequests && "Alert: High number of pending requests."}
                {hasCriticalUnits && "Alert: Units in critical status."}
            </div>
        </div>
    );
};

export default SystemCapacityOverview;
