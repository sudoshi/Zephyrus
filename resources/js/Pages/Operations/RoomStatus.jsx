import React, { useState } from 'react';
import { Head } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import Card from '@/Components/Dashboard/Card';
import MetricsCard from '@/Components/Common/MetricsCard';

const RoomStatus = () => {
    const [selectedLocation, setSelectedLocation] = useState('all');

    return (
        <DashboardLayout>
            <Head title="Room Status - ZephyrusOR" />
            <PageContentLayout
                title="Room Status"
                subtitle="Monitor real-time operating room status and activities"
            >
                <div className="space-y-6">
                    {/* Filter Panel */}
                    <Card>
                        <Card.Content>
                            <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                                <div className="flex items-center space-x-4">
                                    <div className="relative">
                                        <select 
                                            value={selectedLocation}
                                            onChange={(e) => setSelectedLocation(e.target.value)}
                                            className="text-sm border-healthcare-border dark:border-healthcare-border-dark rounded-md pl-8 pr-4 py-2 appearance-none bg-healthcare-surface dark:bg-healthcare-surface-dark hover:border-healthcare-info dark:hover:border-healthcare-info-dark transition-colors duration-300"
                                        >
                                            <option value="all">All Locations</option>
                                            <option value="main">Main OR</option>
                                            <option value="endo">Endoscopy</option>
                                            <option value="cardiac">Cardiac OR</option>
                                        </select>
                                        <Icon 
                                            icon="heroicons:building-office" 
                                            className="absolute left-2 top-1/2 transform -translate-y-1/2 w-4 h-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark transition-colors duration-300"
                                        />
                                    </div>
                                </div>
                                <div className="flex items-center space-x-4">
                                    <button className="inline-flex items-center text-sm text-healthcare-info dark:text-healthcare-info-dark hover:text-healthcare-info-dark dark:hover:text-healthcare-info font-medium transition-colors duration-300">
                                        <Icon icon="heroicons:arrow-path" className="w-4 h-4 mr-1" />
                                        Refresh
                                    </button>
                                </div>
                            </div>
                        </Card.Content>
                    </Card>

                    {/* Metrics Grid */}
                    <div className="grid grid-cols-1 lg:grid-cols-4 gap-6">
                        <MetricsCard
                            title="Total Rooms"
                            value="24"
                            icon="heroicons:building-office-2"
                        />
                        <MetricsCard
                            title="In Use"
                            value="18"
                            trend={75}
                            trendLabel="occupancy"
                            icon="heroicons:check-circle"
                        />
                        <MetricsCard
                            title="Available"
                            value="6"
                            trend={25}
                            trendLabel="availability"
                            icon="heroicons:clock"
                        />
                        <MetricsCard
                            title="Turnovers"
                            value="3"
                            icon="heroicons:arrow-path"
                        />
                    </div>

                    {/* Room Status Grid */}
                    <Card>
                        <Card.Content>
                            <div className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
                                <h3 className="text-lg font-semibold mb-4">Room Status Board</h3>
                                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                                    {Array.from({ length: 12 }).map((_, index) => (
                                        <div 
                                            key={index}
                                            className="border border-healthcare-border dark:border-healthcare-border-dark rounded-lg p-4 bg-healthcare-surface dark:bg-healthcare-surface-dark transition-colors duration-300"
                                        >
                                            <div className="flex items-center justify-between mb-2">
                                                <span className="font-semibold">Room {index + 1}</span>
                                                <span className={`px-2 py-1 rounded-full text-xs font-medium ${
                                                    index % 3 === 0 ? 'bg-healthcare-success bg-opacity-10 dark:bg-opacity-20 text-healthcare-success dark:text-healthcare-success-dark' :
                                                    index % 3 === 1 ? 'bg-healthcare-warning bg-opacity-10 dark:bg-opacity-20 text-healthcare-warning dark:text-healthcare-warning-dark' :
                                                    'bg-healthcare-info bg-opacity-10 dark:bg-opacity-20 text-healthcare-info dark:text-healthcare-info-dark'
                                                }`}>
                                                    {index % 3 === 0 ? 'In Progress' : index % 3 === 1 ? 'Turnover' : 'Available'}
                                                </span>
                                            </div>
                                            <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                {index % 3 === 0 && (
                                                    <>
                                                        <p>Case: Total Hip Replacement</p>
                                                        <p>Time Remaining: 1h 30m</p>
                                                    </>
                                                )}
                                                {index % 3 === 1 && (
                                                    <>
                                                        <p>Next Case: 10:30 AM</p>
                                                        <p>Est. Ready: 15m</p>
                                                    </>
                                                )}
                                                {index % 3 === 2 && (
                                                    <>
                                                        <p>Next Case: None</p>
                                                        <p>Status: Ready</p>
                                                    </>
                                                )}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </Card.Content>
                    </Card>
                </div>
            </PageContentLayout>
        </DashboardLayout>
    );
};

export default RoomStatus;
