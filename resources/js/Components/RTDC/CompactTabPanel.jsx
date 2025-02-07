import React, { useState } from 'react';
import { Icon } from '@iconify/react';
import StaffingForecastTable from '@/Components/RTDC/Staffing/StaffingForecastTable';

const CompactTabPanel = ({ alerts, alertStats, bedTypes, staffingData }) => {
    const [activeTab, setActiveTab] = useState('alerts');

    const getTabIndicators = () => {
        return {
            alerts: {
                critical: alertStats.byPriority.high,
                warning: alertStats.byPriority.medium,
                icon: 'heroicons:bell-alert',
                label: 'Alerts'
            },
            capacity: {
                critical: Object.values(bedTypes).filter(type => 
                    Math.round((type.occupied / type.total) * 100) >= 90
                ).length,
                warning: Object.values(bedTypes).filter(type => {
                    const rate = Math.round((type.occupied / type.total) * 100);
                    return rate >= 80 && rate < 90;
                }).length,
                icon: 'heroicons:building-office-2',
                label: 'Capacity'
            },
            staffing: {
                critical: staffingData.currentShift.coverage < 85 ? 1 : 0,
                warning: staffingData.currentShift.coverage < 95 ? 1 : 0,
                icon: 'heroicons:users',
                label: 'Staffing'
            }
        };
    };

    const indicators = getTabIndicators();

    const TabButton = ({ id, active }) => {
        const indicator = indicators[id];
        const hasCritical = indicator.critical > 0;
        const hasWarning = indicator.warning > 0;
        
        return (
            <button
                onClick={() => setActiveTab(id)}
                className={`
                    flex items-center justify-center px-6 py-3 relative
                    ${active ? 
                        'border-b-2 border-healthcare-primary dark:border-healthcare-primary-dark' : 
                        'border-b-2 border-transparent'}
                    ${active ? 'text-healthcare-text-primary dark:text-healthcare-text-primary-dark' : 
                        'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark hover:text-healthcare-text-primary dark:hover:text-healthcare-text-primary-dark'}
                    transition-all duration-150 focus:outline-none focus:ring-2 focus:ring-healthcare-primary/50
                    min-w-[120px]
                `}
                role="tab"
                aria-selected={active}
                aria-controls={`${id}-panel`}
            >
                <div className="flex items-center space-x-2">
                    <Icon icon={indicator.icon} className="w-5 h-5" />
                    <span className="font-medium">{indicator.label}</span>
                    {(hasCritical || hasWarning) && (
                        <span className={`
                            inline-flex items-center justify-center w-6 h-6 rounded-full text-xs font-medium
                            ${hasCritical ? 
                                'bg-healthcare-critical/10 text-healthcare-critical dark:text-healthcare-critical-dark' : 
                                'bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark'}
                        `}>
                            {hasCritical ? indicator.critical : indicator.warning}
                        </span>
                    )}
                </div>
            </button>
        );
    };

    return (
        <div className="healthcare-card">
            <div className="flex items-center justify-between mb-4">
                <h2 className="text-xl font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    System Status
                </h2>
            </div>

            <div className="border-b border-healthcare-border dark:border-healthcare-border-dark">
                <div className="flex" role="tablist">
                    <TabButton id="alerts" active={activeTab === 'alerts'} />
                    <TabButton id="capacity" active={activeTab === 'capacity'} />
                    <TabButton id="staffing" active={activeTab === 'staffing'} />
                </div>
            </div>
            
            <div className="h-[400px] overflow-hidden mt-4">
                {activeTab === 'alerts' && (
                    <div 
                        role="tabpanel"
                        id="alerts-panel"
                        aria-label="Active system alerts"
                        className="h-full overflow-y-auto px-4"
                    >
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                            {alerts
                                .sort((a, b) => {
                                    const typePriority = { critical: 0, warning: 1, info: 2 };
                                    return typePriority[a.type] - typePriority[b.type];
                                })
                                .map((alert) => (
                                    <div 
                                        key={alert.id}
                                        className={`
                                            p-3 rounded-lg border-l-4 h-full
                                            ${alert.type === 'critical' ? 
                                                'bg-healthcare-critical/5 border-healthcare-critical' : 
                                                alert.type === 'warning' ?
                                                    'bg-healthcare-warning/5 border-healthcare-warning' :
                                                    'bg-healthcare-info/5 border-healthcare-info'}
                                        `}
                                    >
                                        <div className="flex items-center justify-between">
                                            <div className="flex items-center space-x-3">
                                                <Icon 
                                                    icon={alert.type === 'critical' ? 'heroicons:exclamation-triangle' : 
                                                          alert.type === 'warning' ? 'heroicons:exclamation-circle' : 
                                                          'heroicons:information-circle'} 
                                                    className={`w-5 h-5 ${
                                                        alert.type === 'critical' ? 'text-healthcare-critical' :
                                                        alert.type === 'warning' ? 'text-healthcare-warning' :
                                                        'text-healthcare-info'
                                                    }`}
                                                />
                                                <div className="min-w-0">
                                                    <div className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark truncate">
                                                        {alert.message}
                                                    </div>
                                                    <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mt-0.5">
                                                        {alert.unit} • {alert.time}
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                ))
                            }
                        </div>
                    </div>
                )}
                
                {activeTab === 'capacity' && (
                    <div 
                        role="tabpanel"
                        id="capacity-panel"
                        aria-label="System capacity overview"
                        className="h-full overflow-y-auto px-4"
                    >
                        <div className="grid grid-cols-2 lg:grid-cols-3 gap-4">
                            {Object.entries(bedTypes).map(([type, data]) => {
                                const occupancyRate = Math.round((data.occupied / data.total) * 100);
                                return (
                                    <div 
                                        key={type}
                                        className={`
                                            p-4 rounded-lg border
                                            ${occupancyRate >= 90 ? 'border-healthcare-critical bg-healthcare-critical/5' :
                                              occupancyRate >= 80 ? 'border-healthcare-warning bg-healthcare-warning/5' :
                                              'border-healthcare-success bg-healthcare-success/5'}
                                        `}
                                    >
                                        <div className="flex items-center justify-between mb-2">
                                            <span className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark capitalize">
                                                {type}
                                            </span>
                                            <span className={`
                                                font-medium
                                                ${occupancyRate >= 90 ? 'text-healthcare-critical' :
                                                  occupancyRate >= 80 ? 'text-healthcare-warning' :
                                                  'text-healthcare-success'}
                                            `}>
                                                {occupancyRate}%
                                            </span>
                                        </div>
                                        <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                            {data.occupied}/{data.total} beds
                                            {data.pending > 0 && (
                                                <span className="ml-2 text-healthcare-warning dark:text-healthcare-warning-dark">
                                                    (+{data.pending})
                                                </span>
                                            )}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                )}
                
                {activeTab === 'staffing' && (
                    <div 
                        role="tabpanel"
                        id="staffing-panel"
                        aria-label="Staffing forecast"
                        className="h-full overflow-y-auto px-4"
                    >
                        <StaffingForecastTable forecasts={staffingData.forecasts} />
                    </div>
                )}
            </div>
        </div>
    );
};

export default CompactTabPanel;
