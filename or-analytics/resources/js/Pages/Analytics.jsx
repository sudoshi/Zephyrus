import React, { useState } from 'react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import ServiceDashboard from '@/Components/Analytics/ServiceAnalytics/ServiceDashboard';
import { Head } from '@inertiajs/react';
import { Card, Tabs } from '@heroui/react';

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
                return (
                    <div className="p-6 text-center text-gray-500">
                        <Icon icon="heroicons:clock" className="w-12 h-12 mx-auto mb-4" />
                        <p>Provider Analytics coming soon</p>
                    </div>
                );
            case 'historical':
                return (
                    <div className="p-6 text-center text-gray-500">
                        <Icon icon="heroicons:clock" className="w-12 h-12 mx-auto mb-4" />
                        <p>Historical Trends coming soon</p>
                    </div>
                );
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
