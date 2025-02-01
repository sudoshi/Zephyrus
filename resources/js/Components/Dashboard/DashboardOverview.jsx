import React, { useState } from 'react';
import LastMonthSection from './LastMonthSection';
import MonthToDateSection from './MonthToDateSection';
import { Icon } from '@iconify/react';
import { syntheticData } from '../../mock-data/dashboard';

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
            icon: 'heroicons:clock'
        },
        {
            label: 'Block Utilization',
            value: '78%',
            trend: 'up',
            delta: '5%',
            icon: 'heroicons:chart-bar'
        },
        {
            label: 'Cases Today',
            value: '24',
            trend: 'down',
            delta: '2',
            icon: 'heroicons:clipboard-document-list'
        },
        {
            label: 'Avg Turnover',
            value: '32m',
            trend: 'up',
            delta: '4m',
            icon: 'heroicons:arrow-path'
        }
    ];

    return (
        <div className="min-h-screen bg-gray-50">
            <div className="max-w-[1600px] mx-auto p-6 space-y-6">
                {/* Header */}
                <div className="bg-white rounded-lg shadow-sm p-4">
                    <div className="flex items-center justify-between mb-4">
                        <h1 className="text-2xl font-bold text-gray-900">OR Manager Home</h1>
                        <div className="flex items-center space-x-4">
                            <div className="relative">
                                <select 
                                    className="text-sm border-gray-300 rounded-md pl-8 pr-4 py-2 appearance-none bg-white hover:border-indigo-300 transition-colors duration-200"
                                    value={selectedLocation}
                                    onChange={(e) => setSelectedLocation(e.target.value)}
                                >
                                    <option value="all">All Locations</option>
                                    <option value="loc1">Location A</option>
                                    <option value="loc2">Location B</option>
                                </select>
                                <Icon 
                                    icon="heroicons:building-office" 
                                    className="absolute left-2 top-1/2 transform -translate-y-1/2 w-4 h-4 text-gray-400"
                                />
                            </div>
                            <div className="relative">
                                <select 
                                    className="text-sm border-gray-300 rounded-md pl-8 pr-4 py-2 appearance-none bg-white hover:border-indigo-300 transition-colors duration-200"
                                    value={selectedService}
                                    onChange={(e) => setSelectedService(e.target.value)}
                                >
                                    <option value="all">All Services</option>
                                    <option value="ortho">Orthopedics</option>
                                    <option value="cardio">Cardiology</option>
                                </select>
                                <Icon 
                                    icon="heroicons:rectangle-stack" 
                                    className="absolute left-2 top-1/2 transform -translate-y-1/2 w-4 h-4 text-gray-400"
                                />
                            </div>
                            <div className="relative">
                                <select 
                                    className="text-sm border-gray-300 rounded-md pl-8 pr-4 py-2 appearance-none bg-white hover:border-indigo-300 transition-colors duration-200"
                                    value={selectedSurgeon}
                                    onChange={(e) => setSelectedSurgeon(e.target.value)}
                                >
                                    <option value="all">All Surgeons</option>
                                    <option value="surg1">Dr. Smith</option>
                                    <option value="surg2">Dr. Johnson</option>
                                </select>
                                <Icon 
                                    icon="heroicons:user" 
                                    className="absolute left-2 top-1/2 transform -translate-y-1/2 w-4 h-4 text-gray-400"
                                />
                            </div>
                            <div className="relative">
                                <select 
                                    className="text-sm border-gray-300 rounded-md pl-8 pr-4 py-2 appearance-none bg-white hover:border-indigo-300 transition-colors duration-200"
                                    value={dateRange}
                                    onChange={(e) => setDateRange(e.target.value)}
                                >
                                    <option value="mtd">Month to Date</option>
                                    <option value="last-month">Last Month</option>
                                    <option value="custom">Custom Range</option>
                                </select>
                                <Icon 
                                    icon="heroicons:calendar" 
                                    className="absolute left-2 top-1/2 transform -translate-y-1/2 w-4 h-4 text-gray-400"
                                />
                            </div>
                        </div>
                    </div>

                    {/* Quick Stats */}
                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                        {quickStats.map((stat, index) => (
                            <div 
                                key={index}
                                className="bg-white rounded-lg p-4 flex items-center justify-between hover:bg-gray-50 border border-gray-100 hover:border-indigo-100 transition-all duration-200 cursor-pointer group"
                            >
                                <div className="flex items-center space-x-3">
                                    <div className="bg-indigo-50 p-2 rounded-lg group-hover:bg-indigo-100 transition-colors duration-200">
                                        <Icon icon={stat.icon} className="w-6 h-6 text-indigo-600" />
                                    </div>
                                    <div>
                                        <div className="text-sm text-gray-600">{stat.label}</div>
                                        <div className="text-xl font-bold">{stat.value}</div>
                                    </div>
                                </div>
                                <div className="flex flex-col items-end">
                                    <div className={`flex items-center ${
                                        stat.trend === 'up' ? 'text-green-600' : 'text-red-600'
                                    }`}>
                                        <Icon 
                                            icon={stat.trend === 'up' ? 'heroicons:arrow-up' : 'heroicons:arrow-down'} 
                                            className="w-4 h-4 mr-1" 
                                        />
                                        <span className="text-sm font-medium">{stat.delta}</span>
                                    </div>
                                    <div className="text-xs text-gray-500 mt-1">vs. last month</div>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>

                {/* Main Content */}
                <div className="space-y-6">
                    <LastMonthSection />
                    <MonthToDateSection />
                </div>
            </div>
        </div>
    );
};

export default DashboardOverview;
