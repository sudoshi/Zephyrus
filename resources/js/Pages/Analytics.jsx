import React, { useState } from 'react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import ServiceDashboard from '@/Components/Analytics/ServiceAnalytics/ServiceDashboard';
import ProviderDashboard from '@/Components/Analytics/ProviderAnalytics/ProviderDashboard';
import TrendsOverview from '@/Components/Analytics/HistoricalTrends/TrendsOverview';
import { Head } from '@inertiajs/react';
import { Tabs } from '@heroui/react';
import Card from '@/Components/Dashboard/Card';
import { Icon } from '@iconify/react';

const Analytics = () => {
    const [activeTab, setActiveTab] = useState('services');

    const tabs = [
        {
            id: 'services',
            label: 'Service Analytics',
            icon: 'heroicons:building-office-2'
        },
        {
            id: 'providers',
            label: 'Provider Analytics',
            icon: 'heroicons:user-group'
        },
        {
            id: 'historical',
            label: 'Historical Trends',
            icon: 'heroicons:chart-bar'
        }
    ];

    const renderContent = () => {
        switch (activeTab) {
            case 'services':
                return <ServiceDashboard />;
            case 'providers':
                return <ProviderDashboard />;
            case 'historical':
                return <TrendsOverview />;
            default:
                return null;
        }
    };

    return (
        <DashboardLayout>
            <Head title="Analytics - ZephyrusOR" />
            <div className="p-6">
                <div className="mb-6">
                    <h1 className="text-2xl font-semibold">Analytics</h1>
                    <p className="text-gray-500">Analyze OR performance and trends</p>
                </div>

                <Card>
                    <Card.Header>
                        <Tabs
                            value={activeTab}
                            onChange={setActiveTab}
                            items={tabs.map(tab => ({
                                value: tab.id,
                                label: (
                                    <div className="flex items-center space-x-2">
                                        <Icon icon={tab.icon} className="w-5 h-5" />
                                        <span>{tab.label}</span>
                                    </div>
                                )
                            }))}
                        />
                    </Card.Header>
                    <Card.Content>
                        {renderContent()}
                    </Card.Content>
                </Card>
            </div>
        </DashboardLayout>
    );
};

export default Analytics;
