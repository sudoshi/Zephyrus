import React, { useState, useEffect } from 'react';
import Card from '@/Components/Dashboard/Card';
import { Icon } from '@iconify/react';
import { mockRoomStatus, mockRoomMetrics } from '@/mock-data/room-status';

const RoomStatusBoard = () => {
    const [rooms, setRooms] = useState(mockRoomStatus);
    const [metrics, setMetrics] = useState(mockRoomMetrics);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);

    const getStatusColor = (status) => {
        switch (status) {
            case 'In Progress':
                return 'bg-green-100 text-green-800';
            case 'Turnover':
                return 'bg-yellow-100 text-yellow-800';
            case 'Available':
                return 'bg-blue-100 text-blue-800';
            case 'Delayed':
                return 'bg-red-100 text-red-800';
            default:
                return 'bg-gray-100 text-gray-800';
        }
    };

    const getProgressColor = (progress) => {
        if (progress >= 100) return 'bg-red-600';
        if (progress >= 90) return 'bg-yellow-600';
        return 'bg-green-600';
    };

    const calculateProgress = (startTime, duration) => {
        if (!startTime || !duration) return 0;
        const start = new Date(startTime);
        const now = new Date();
        const elapsed = (now - start) / 1000 / 60; // minutes
        return Math.min(Math.round((elapsed / duration) * 100), 100);
    };

    const formatDuration = (minutes) => {
        const hours = Math.floor(minutes / 60);
        const mins = minutes % 60;
        return hours > 0 ? `${hours}h ${mins}m` : `${mins}m`;
    };

    if (loading) {
        return (
            <div className="flex justify-center items-center h-96">
                <div className="animate-spin rounded-full h-12 w-12 border-4 border-indigo-600 border-t-transparent"></div>
            </div>
        );
    }

    if (error) {
        return (
            <div className="p-6 text-center text-red-600">
                <Icon icon="heroicons:exclamation-circle" className="w-12 h-12 mx-auto mb-4" />
                <p>{error}</p>
            </div>
        );
    }

    return (
        <div className="space-y-6">
            {/* Summary Metrics */}
            <div className="grid grid-cols-4 gap-4">
                <Card>
                    <Card.Content>
                        <div className="flex items-center justify-between">
                            <div>
                                <div className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Overall Utilization</div>
                                <div className="mt-1 text-2xl font-semibold">{metrics.overall_utilization}%</div>
                            </div>
                            <div className="p-3 bg-healthcare-info bg-opacity-10 dark:bg-healthcare-info-dark dark:bg-opacity-20 rounded-full">
                                <Icon icon="heroicons:chart-bar" className="w-6 h-6 text-healthcare-info dark:text-healthcare-info-dark" />
                            </div>
                        </div>
                    </Card.Content>
                </Card>

                <Card>
                    <Card.Content>
                        <div className="flex items-center justify-between">
                            <div>
                                <div className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Average Turnover</div>
                                <div className="mt-1 text-2xl font-semibold">{metrics.average_turnover}m</div>
                            </div>
                            <div className="p-3 bg-healthcare-success bg-opacity-10 dark:bg-healthcare-success-dark dark:bg-opacity-20 rounded-full">
                                <Icon icon="heroicons:clock" className="w-6 h-6 text-healthcare-success dark:text-healthcare-success-dark" />
                            </div>
                        </div>
                    </Card.Content>
                </Card>

                <Card>
                    <Card.Content>
                        <div className="flex items-center justify-between">
                            <div>
                                <div className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">On-Time Starts</div>
                                <div className="mt-1 text-2xl font-semibold">{metrics.on_time_starts}/{metrics.on_time_starts + metrics.late_starts}</div>
                            </div>
                            <div className="p-3 bg-healthcare-warning bg-opacity-10 dark:bg-healthcare-warning-dark dark:bg-opacity-20 rounded-full">
                                <Icon icon="heroicons:check-circle" className="w-6 h-6 text-healthcare-warning dark:text-healthcare-warning-dark" />
                            </div>
                        </div>
                    </Card.Content>
                </Card>

                <Card>
                    <Card.Content>
                        <div className="flex items-center justify-between">
                            <div>
                                <div className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Delays Today</div>
                                <div className="mt-1 text-2xl font-semibold">{metrics.delays_today}</div>
                            </div>
                            <div className="p-3 bg-healthcare-critical bg-opacity-10 dark:bg-healthcare-critical-dark dark:bg-opacity-20 rounded-full">
                                <Icon icon="heroicons:exclamation-triangle" className="w-6 h-6 text-healthcare-critical dark:text-healthcare-critical-dark" />
                            </div>
                        </div>
                    </Card.Content>
                </Card>
            </div>

            {/* Room Status Grid */}
            <div className="grid grid-cols-3 gap-6">
                {rooms.map(room => {
                    const progress = room.current_case ? 
                        calculateProgress(room.or_in_time, room.current_case.estimated_duration) : 0;

                    return (
                        <Card key={room.room_id}>
                            <Card.Header>
                                <div className="flex justify-between items-center">
                                    <div>
                                        <Card.Title>{room.room_name}</Card.Title>
                                        <span className={`px-2 py-1 text-xs font-medium rounded-full ${getStatusColor(room.status)}`}>
                                            {room.status}
                                        </span>
                                    </div>
                                    {room.status === 'In Progress' && (
                                        <div className="text-right">
                            <div className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Case Time</div>
                            <div className="text-healthcare-success dark:text-healthcare-success-dark font-medium">
                                {formatDuration(Math.round((new Date() - new Date(room.or_in_time)) / 1000 / 60))}
                            </div>
                                        </div>
                                    )}
                                </div>
                            </Card.Header>

                            <Card.Content>
                                {room.current_case ? (
                                    <div className="space-y-4">
                                        <div>
                                            <div className="font-medium">{room.current_case.procedure_name}</div>
                                            <div className="text-sm text-gray-500">
                                                {room.current_case.surgeon_name} • {room.current_case.service_name}
                                            </div>
                                        </div>
                                        <div className="relative pt-1">
                                            <div className="flex mb-2 items-center justify-between">
                                                <div>
                                                    <span className="text-xs font-semibold inline-block text-gray-600">
                                                        Progress
                                                    </span>
                                                </div>
                                                <div className="text-right">
                                                    <span className="text-xs font-semibold inline-block text-gray-600">
                                                        {progress}%
                                                    </span>
                                                </div>
                                            </div>
                                            <div className="overflow-hidden h-2 text-xs flex rounded bg-gray-200">
                                                <div
                                                    style={{ width: `${progress}%` }}
                                                    className={`shadow-none flex flex-col text-center whitespace-nowrap text-white justify-center ${getProgressColor(progress)}`}
                                                ></div>
                                            </div>
                                        </div>
                                        <div className="text-sm text-gray-500">
                                            Estimated Duration: {formatDuration(room.current_case.estimated_duration)}
                                        </div>
                                    </div>
                                ) : room.next_case ? (
                                    <div className="space-y-2">
                                        <div className="text-sm text-gray-500">Next Case</div>
                                        <div>
                                            <div className="font-medium">{room.next_case.procedure_name}</div>
                                            <div className="text-sm text-gray-500">
                                                {new Date(room.next_case.scheduled_start_time).toLocaleTimeString('en-US', {
                                                    hour: 'numeric',
                                                    minute: '2-digit'
                                                })} • {formatDuration(room.next_case.estimated_duration)}
                                            </div>
                                        </div>
                                    </div>
                                ) : (
                                    <div className="text-sm text-gray-500">
                                        No cases scheduled
                                    </div>
                                )}

                                {/* Room Stats */}
                                <div className="mt-4 pt-4 border-t grid grid-cols-3 gap-4">
                                    <div>
                                        <div className="text-sm font-medium text-gray-500">Today</div>
                                        <div className="mt-1 text-lg font-semibold">{room.utilization.today}%</div>
                                    </div>
                                    <div>
                                        <div className="text-sm font-medium text-gray-500">Week</div>
                                        <div className="mt-1 text-lg font-semibold">{room.utilization.week}%</div>
                                    </div>
                                    <div>
                                        <div className="text-sm font-medium text-gray-500">Month</div>
                                        <div className="mt-1 text-lg font-semibold">{room.utilization.month}%</div>
                                    </div>
                                </div>
                            </Card.Content>
                        </Card>
                    );
                })}
            </div>
        </div>
    );
};

export default RoomStatusBoard;
