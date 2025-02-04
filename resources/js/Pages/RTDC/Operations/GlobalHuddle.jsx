import React from 'react';
import RTDCPageLayout from '../RTDCPageLayout';
import Card from '@/Components/Dashboard/Card';
import MetricsCard, { MetricsCardGroup } from '@/Components/Analytics/Common/MetricsCard';
import { Icon } from '@iconify/react';

const GlobalHuddle = () => {
    const departments = [
        {
            name: 'Emergency Department',
            metrics: {
                occupancy: '78%',
                waitTime: '42 mins',
                criticalCases: '3',
                pendingAdmits: '5'
            },
            status: 'warning'
        },
        {
            name: 'Medical/Surgical',
            metrics: {
                occupancy: '92%',
                discharges: '8',
                pendingBeds: '4',
                staffing: '95%'
            },
            status: 'critical'
        },
        {
            name: 'ICU',
            metrics: {
                occupancy: '85%',
                transfers: '2',
                ventilators: '6/10',
                staffing: '100%'
            },
            status: 'normal'
        }
    ];

    const getStatusColor = (status) => {
        switch (status) {
            case 'critical':
                return 'bg-healthcare-critical dark:bg-healthcare-critical-dark';
            case 'warning':
                return 'bg-healthcare-warning dark:bg-healthcare-warning-dark';
            default:
                return 'bg-healthcare-success dark:bg-healthcare-success-dark';
        }
    };

    return (
        <RTDCPageLayout
            title="Global Huddle"
            subtitle="Hospital-wide operational status and coordination"
        >
            {/* Hospital Overview */}
            <Card>
                <Card.Header>
                    <Card.Title>
                        <div className="flex items-center space-x-2">
                            <Icon icon="heroicons:building-office-2" className="w-5 h-5" />
                            <span>Hospital Overview</span>
                        </div>
                    </Card.Title>
                    <Card.Description>Current hospital-wide metrics</Card.Description>
                </Card.Header>
                <Card.Content>
                    <MetricsCardGroup cols={4}>
                        <MetricsCard
                            title="Total Census"
                            value="446"
                            trend="up"
                            trendValue="12"
                            icon="heroicons:users"
                            description="92% occupancy"
                        />
                        <MetricsCard
                            title="ED Boarding"
                            value="5"
                            trend="up"
                            trendValue="2"
                            icon="heroicons:clock"
                            description="Awaiting beds"
                        />
                        <MetricsCard
                            title="Pending Discharges"
                            value="18"
                            trend="down"
                            trendValue="3"
                            icon="heroicons:arrow-right"
                            description="Expected today"
                        />
                        <MetricsCard
                            title="Critical Resources"
                            value="85%"
                            trend="up"
                            trendValue="5%"
                            icon="heroicons:wrench-screwdriver"
                            description="Utilization"
                        />
                    </MetricsCardGroup>
                </Card.Content>
            </Card>

            {/* Department Status */}
            <div className="space-y-4">
                {departments.map((dept) => (
                    <Card key={dept.name}>
                        <div className="flex items-start p-6">
                            {/* Status Indicator */}
                            <div className={`w-2 h-2 rounded-full mt-2 ${getStatusColor(dept.status)}`} />
                            
                            {/* Department Info */}
                            <div className="flex-grow ml-4">
                                <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                    {dept.name}
                                </h3>
                                
                                {/* Metrics Grid */}
                                <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mt-4">
                                    {Object.entries(dept.metrics).map(([key, value]) => (
                                        <div key={key} className="bg-healthcare-background dark:bg-healthcare-background-dark rounded-lg p-4">
                                            <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark capitalize">
                                                {key.replace(/([A-Z])/g, ' $1').trim()}
                                            </div>
                                            <div className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mt-1">
                                                {value}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </div>

                            {/* Action Button */}
                            <button className="ml-4 px-4 py-2 bg-healthcare-primary dark:bg-healthcare-primary-dark text-white rounded-md hover:bg-healthcare-primary-dark dark:hover:bg-healthcare-primary transition-colors duration-300">
                                View Details
                            </button>
                        </div>
                    </Card>
                ))}
            </div>

            {/* Action Items */}
            <Card>
                <Card.Header>
                    <Card.Title>
                        <div className="flex items-center space-x-2">
                            <Icon icon="heroicons:clipboard-document-list" className="w-5 h-5" />
                            <span>Action Items</span>
                        </div>
                    </Card.Title>
                    <Card.Description>Critical tasks requiring attention</Card.Description>
                </Card.Header>
                <Card.Content>
                    <div className="space-y-4">
                        {[
                            {
                                title: 'ED Capacity Alert',
                                description: 'High volume in ED requiring additional staffing',
                                priority: 'high',
                                time: '10 mins ago'
                            },
                            {
                                title: 'ICU Transfer Pending',
                                description: 'Patient in ED requiring ICU bed',
                                priority: 'medium',
                                time: '25 mins ago'
                            },
                            {
                                title: 'Discharge Coordination',
                                description: '8 discharges pending transportation',
                                priority: 'low',
                                time: '1 hour ago'
                            }
                        ].map((item) => (
                            <div key={item.title} className="flex items-center justify-between p-4 bg-healthcare-background dark:bg-healthcare-background-dark rounded-lg">
                                <div className="flex items-start space-x-4">
                                    <div className={`w-2 h-2 mt-2 rounded-full ${
                                        item.priority === 'high' ? 'bg-healthcare-critical dark:bg-healthcare-critical-dark' :
                                        item.priority === 'medium' ? 'bg-healthcare-warning dark:bg-healthcare-warning-dark' :
                                        'bg-healthcare-success dark:bg-healthcare-success-dark'
                                    }`} />
                                    <div>
                                        <h4 className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                            {item.title}
                                        </h4>
                                        <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mt-1">
                                            {item.description}
                                        </p>
                                        <span className="text-xs text-healthcare-text-tertiary dark:text-healthcare-text-tertiary-dark mt-2 block">
                                            {item.time}
                                        </span>
                                    </div>
                                </div>
                                <button className="px-4 py-2 text-sm bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-md hover:bg-healthcare-background dark:hover:bg-healthcare-background-dark transition-colors duration-300">
                                    Take Action
                                </button>
                            </div>
                        ))}
                    </div>
                </Card.Content>
            </Card>
        </RTDCPageLayout>
    );
};

export default GlobalHuddle;
