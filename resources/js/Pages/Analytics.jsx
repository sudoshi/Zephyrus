import React, { useState } from 'react';
import ErrorBoundary from '@/Components/Common/ErrorBoundary';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import ServiceDashboard from '@/Components/Analytics/ServiceAnalytics/ServiceDashboard';
import ProviderDashboard from '@/Components/Analytics/ProviderAnalytics/ProviderDashboard';
import TrendsOverview from '@/Components/Analytics/HistoricalTrends/TrendsOverview';
import BlockUtilizationDashboard from '@/Components/Analytics/BlockUtilization/BlockUtilizationDashboard';
import { Head, Link } from '@inertiajs/react';
import { Tab } from '@headlessui/react';
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
        },
        {
            id: 'block-utilization',
            label: 'Block Utilization',
            icon: 'heroicons:clock'
        }
    ];

    return (
        <DashboardLayout>
            <Head title="Analytics - ZephyrusOR" />
            <ErrorBoundary>
                <PageContentLayout
                    title="Analytics"
                    subtitle="Analyze OR performance and trends"
                >
                    <div className="flex justify-between items-center mb-6">
                        <div>
                            {/* Additional content if needed */}
                        </div>
                        <div className="flex items-center space-x-4">
                            <Link
                                href="/dashboard"
                                className="inline-flex items-center px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50"
                            >
                                <Icon icon="heroicons:arrow-left" className="w-5 h-5 mr-2" />
                                Back to Dashboard
                            </Link>
                        </div>
                    </div>

                    <Card>
                        <Tab.Group
                            selectedIndex={tabs.findIndex(t => t.id === activeTab)}
                            onChange={index => setActiveTab(tabs[index].id)}
                        >
                            <Card.Header className="border-b border-gray-200">
                                <div className="flex justify-between items-center">
                                    <Tab.List className="flex space-x-4">
                                        {tabs.map(tab => (
                                            <Tab
                                                key={tab.id}
                                                className={({ selected }) =>
                                                    `px-3 py-2 text-sm font-medium rounded-md transition-all duration-200 ${
                                                        selected
                                                            ? 'bg-indigo-50 text-indigo-700'
                                                            : 'text-gray-500 hover:text-gray-700 hover:bg-gray-50'
                                                    }`
                                                }
                                            >
                                                <div className="flex items-center space-x-2">
                                                    <Icon icon={tab.icon} className="w-5 h-5" />
                                                    <span>{tab.label}</span>
                                                </div>
                                            </Tab>
                                        ))}
                                    </Tab.List>
                                    <div className="flex items-center space-x-2">
                                        <button
                                            type="button"
                                            className="inline-flex items-center px-3 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50"
                                            onClick={() => window.print()}
                                        >
                                            <Icon icon="heroicons:printer" className="w-5 h-5 mr-2" />
                                            Print
                                        </button>
                                        <button
                                            type="button"
                                            className="inline-flex items-center px-3 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50"
                                        >
                                            <Icon icon="heroicons:arrow-down-tray" className="w-5 h-5 mr-2" />
                                            Export
                                        </button>
                                    </div>
                                </div>
                            </Card.Header>
                            <Card.Content className="p-0">
                                <Tab.Panels className="focus:outline-none">
                                    <Tab.Panel className="p-6 focus:outline-none">
                                        <ServiceDashboard />
                                    </Tab.Panel>
                                    <Tab.Panel className="p-6 focus:outline-none">
                                        <ProviderDashboard />
                                    </Tab.Panel>
                                    <Tab.Panel className="p-6 focus:outline-none">
                                        <TrendsOverview />
                                    </Tab.Panel>
                                    <Tab.Panel className="p-6 focus:outline-none">
                                        <BlockUtilizationDashboard />
                                    </Tab.Panel>
                                </Tab.Panels>
                            </Card.Content>
                        </Tab.Group>
                    </Card>
                </PageContentLayout>
            </ErrorBoundary>
        </DashboardLayout>
    );
};

export default Analytics;
