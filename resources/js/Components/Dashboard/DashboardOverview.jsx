import React, { useState } from 'react';
import LastMonthSection from './LastMonthSection';
import MonthToDateSection from './MonthToDateSection';
import { Icon } from '@iconify/react';
import { syntheticData } from '../../mock-data/dashboard';
import Card from '@/Components/Dashboard/Card';

const DashboardOverview = () => {
    const [selectedLocation, setSelectedLocation] = useState('all');
    const [selectedService, setSelectedService] = useState('all');
    const [selectedSurgeon, setSelectedSurgeon] = useState('all');
    const [dateRange, setDateRange] = useState('mtd'); // mtd, last-month, custom

    // Quick stats data
    const quickStats = [
        {
            label: 'On-Time Starts',
            value: '85%',
            trend: 'up',
            delta: '3%',
            icon: 'heroicons:clock',
            color: 'healthcare-success'
        },
        {
            label: 'Block Utilization',
            value: '78%',
            trend: 'up',
            delta: '5%',
            icon: 'heroicons:chart-bar',
            color: 'healthcare-info'
        },
        {
            label: 'Cases Today',
            value: '24',
            trend: 'down',
            delta: '2',
            icon: 'heroicons:clipboard-document-list',
            color: 'healthcare-warning'
        },
        {
            label: 'Avg Turnover',
            value: '32m',
            trend: 'up',
            delta: '4m',
            icon: 'heroicons:arrow-path',
            color: 'healthcare-critical'
        }
    ];

    return (
        <div className="space-y-6">
            {/* Filters */}
            <Card>
                <Card.Content>
                    <div className="flex items-center justify-between">
                        <h1 className="text-2xl font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
                            OR Manager Home
                        </h1>
                        <div className="flex items-center space-x-4">
                            <div className="relative">
                                <select 
                                    className="text-sm border-healthcare-border dark:border-healthcare-border-dark rounded-md pl-8 pr-4 py-2 appearance-none bg-healthcare-surface dark:bg-healthcare-surface-dark hover:border-healthcare-info dark:hover:border-healthcare-info-dark transition-colors duration-300"
                                    value={selectedLocation}
                                    onChange={(e) => setSelectedLocation(e.target.value)}
                                >
                                    <option value="all">All Locations</option>
                                    <option value="loc1">Location A</option>
                                    <option value="loc2">Location B</option>
                                </select>
                                <Icon 
                                    icon="heroicons:building-office" 
                                    className="absolute left-2 top-1/2 transform -translate-y-1/2 w-4 h-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"
                                />
                            </div>
                            <div className="relative">
                                <select 
                                    className="text-sm border-healthcare-border dark:border-healthcare-border-dark rounded-md pl-8 pr-4 py-2 appearance-none bg-healthcare-surface dark:bg-healthcare-surface-dark hover:border-healthcare-info dark:hover:border-healthcare-info-dark transition-colors duration-300"
                                    value={selectedService}
                                    onChange={(e) => setSelectedService(e.target.value)}
                                >
                                    <option value="all">All Services</option>
                                    <option value="ortho">Orthopedics</option>
                                    <option value="cardio">Cardiology</option>
                                </select>
                                <Icon 
                                    icon="heroicons:rectangle-stack" 
                                    className="absolute left-2 top-1/2 transform -translate-y-1/2 w-4 h-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"
                                />
                            </div>
                            <div className="relative">
                                <select 
                                    className="text-sm border-healthcare-border dark:border-healthcare-border-dark rounded-md pl-8 pr-4 py-2 appearance-none bg-healthcare-surface dark:bg-healthcare-surface-dark hover:border-healthcare-info dark:hover:border-healthcare-info-dark transition-colors duration-300"
                                    value={selectedSurgeon}
                                    onChange={(e) => setSelectedSurgeon(e.target.value)}
                                >
                                    <option value="all">All Surgeons</option>
                                    <option value="surg1">Dr. Smith</option>
                                    <option value="surg2">Dr. Johnson</option>
                                </select>
                                <Icon 
                                    icon="heroicons:user" 
                                    className="absolute left-2 top-1/2 transform -translate-y-1/2 w-4 h-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"
                                />
                            </div>
                            <div className="relative">
                                <select 
                                    className="text-sm border-healthcare-border dark:border-healthcare-border-dark rounded-md pl-8 pr-4 py-2 appearance-none bg-healthcare-surface dark:bg-healthcare-surface-dark hover:border-healthcare-info dark:hover:border-healthcare-info-dark transition-colors duration-300"
                                    value={dateRange}
                                    onChange={(e) => setDateRange(e.target.value)}
                                >
                                    <option value="mtd">Month to Date</option>
                                    <option value="last-month">Last Month</option>
                                    <option value="custom">Custom Range</option>
                                </select>
                                <Icon 
                                    icon="heroicons:calendar" 
                                    className="absolute left-2 top-1/2 transform -translate-y-1/2 w-4 h-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"
                                />
                            </div>
                        </div>
                    </div>
                </Card.Content>
            </Card>

            {/* Quick Stats */}
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                {quickStats.map((stat, index) => (
                    <Card key={index}>
                        <Card.Content>
                            <div className="flex items-center justify-between group">
                                <div className="flex items-center space-x-3">
                                    <div className={`bg-${stat.color} bg-opacity-10 dark:bg-opacity-20 p-2 rounded-lg group-hover:bg-opacity-20 dark:group-hover:bg-opacity-30 transition-colors duration-300`}>
                                        <Icon 
                                            icon={stat.icon} 
                                            className={`w-6 h-6 text-${stat.color} dark:text-${stat.color}-dark`} 
                                        />
                                    </div>
                                    <div>
                                        <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark transition-colors duration-300">
                                            {stat.label}
                                        </div>
                                        <div className="text-xl font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
                                            {stat.value}
                                        </div>
                                    </div>
                                </div>
                                <div className="flex flex-col items-end">
                                    <div className={`flex items-center ${
                                        stat.trend === 'up' 
                                            ? 'text-healthcare-success dark:text-healthcare-success-dark' 
                                            : 'text-healthcare-critical dark:text-healthcare-critical-dark'
                                    } transition-colors duration-300`}>
                                        <Icon 
                                            icon={stat.trend === 'up' ? 'heroicons:arrow-up' : 'heroicons:arrow-down'} 
                                            className="w-4 h-4 mr-1" 
                                        />
                                        <span className="text-sm font-medium">{stat.delta}</span>
                                    </div>
                                    <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark transition-colors duration-300 mt-1">
                                        vs. last month
                                    </div>
                                </div>
                            </div>
                        </Card.Content>
                    </Card>
                ))}
            </div>

            {/* Main Content */}
            <div className="space-y-6">
                <LastMonthSection />
                <MonthToDateSection />
            </div>
        </div>
    );
};

export default DashboardOverview;
