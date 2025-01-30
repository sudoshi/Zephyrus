import React, { useState, useEffect } from 'react';
import Card from './Card';
import Stats from './Stats';
import { Icon } from '@iconify/react';
import { AreaChart, Area, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer } from 'recharts';
import axios from 'axios';
import { usePage, router } from '@inertiajs/react';

const DashboardOverview = () => {
    const { auth } = usePage().props;
    const [metrics, setMetrics] = useState(null);
    const [todaysCases, setTodaysCases] = useState([]);
    const [roomStatus, setRoomStatus] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    useEffect(() => {
        if (!auth.user) {
            router.visit('/login');
            return;
        }

        const fetchDashboardData = async () => {
            try {
                const [metricsRes, casesRes, roomsRes] = await Promise.all([
                    axios.get('/api/cases/metrics'),
                    axios.get('/api/cases/today'),
                    axios.get('/api/cases/room-status')
                ]);

                setMetrics(metricsRes.data);
                setTodaysCases(casesRes.data);
                setRoomStatus(roomsRes.data);
                setError(null);
            } catch (err) {
                console.error('Error fetching dashboard data:', err);
                setError('Failed to load dashboard data');
            } finally {
                setLoading(false);
            }
        };

        fetchDashboardData();

        // Refresh data every 5 minutes
        const interval = setInterval(fetchDashboardData, 5 * 60 * 1000);
        return () => clearInterval(interval);
    }, []);

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

    const utilizationData = metrics?.utilization || [];
    const summary = metrics?.summary || {};

    return (
        <div className="space-y-6 p-6">
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                <Stats
                    title="OR Utilization"
                    value={`${Math.round(summary.avg_utilization)}%`}
                    description={`${utilizationData.length} day average`}
                    trend={summary.avg_utilization > 80 ? 'up' : 'down'}
                    icon={<Icon icon="heroicons:chart-bar" />}
                />
                <Stats
                    title="Cases Today"
                    value={todaysCases.length}
                    description={`${todaysCases.filter(c => !c.actual_end_time).length} remaining`}
                    icon={<Icon icon="heroicons:clipboard-document-list" />}
                />
                <Stats
                    title="On-Time Starts"
                    value={`${Math.round(todaysCases.filter(c => 
                        new Date(c.actual_start_time) <= new Date(c.scheduled_start_time)
                    ).length / todaysCases.length * 100)}%`}
                    description="Today's cases"
                    icon={<Icon icon="heroicons:clock" />}
                />
                <Stats
                    title="Avg Turnover"
                    value={`${Math.round(summary.avg_turnover)}min`}
                    description={`${Math.round(summary.avg_turnover) < 30 ? 'Under' : 'Over'} target`}
                    trend={Math.round(summary.avg_turnover) < 30 ? 'down' : 'up'}
                    icon={<Icon icon="heroicons:arrow-path" />}
                />
            </div>

            <Card>
                <Card.Header>
                    <Card.Title>OR Utilization Trend</Card.Title>
                    <Card.Description>Daily utilization percentage over the last 7 days</Card.Description>
                </Card.Header>
                <Card.Content>
                    <div className="h-80">
                        <ResponsiveContainer width="100%" height="100%">
                            <AreaChart data={utilizationData}>
                                <CartesianGrid strokeDasharray="3 3" />
                                <XAxis 
                                    dataKey="date" 
                                    tickFormatter={(date) => new Date(date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })}
                                />
                                <YAxis />
                                <Tooltip 
                                    formatter={(value) => [`${value}%`, 'Utilization']}
                                    labelFormatter={(date) => new Date(date).toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' })}
                                />
                                <Area 
                                    type="monotone" 
                                    dataKey="utilization" 
                                    stroke="#4F46E5" 
                                    fill="#4F46E5" 
                                    fillOpacity={0.2} 
                                />
                            </AreaChart>
                        </ResponsiveContainer>
                    </div>
                </Card.Content>
            </Card>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <Card>
                <Card.Header>
                    <Card.Title>Today's Schedule</Card.Title>
                    <Card.Description>Upcoming and in-progress cases</Card.Description>
                </Card.Header>
                <Card.Content>
                    <div className="space-y-4">
                        {todaysCases
                            .filter(c => !c.actual_end_time)
                            .slice(0, 5)
                            .map(c => (
                                <Card.Item
                                    key={c.case_id}
                                    title={c.procedure_name}
                                    subtitle={`${c.surgeon_name} - ${c.room_name}`}
                                    meta={
                                        <div className="text-right">
                                            <div className="font-medium">
                                                {new Date(c.scheduled_start_time).toLocaleTimeString('en-US', {
                                                    hour: 'numeric',
                                                    minute: '2-digit'
                                                })}
                                            </div>
                                            <div className="text-sm text-gray-500">
                                                {Math.round(c.estimated_duration / 60)} hours
                                            </div>
                                        </div>
                                    }
                                />
                            ))}
                        {todaysCases.filter(c => !c.actual_end_time).length === 0 && (
                            <div className="text-center text-gray-500 py-4">
                                No upcoming cases
                            </div>
                        )}
                        </div>
                    </Card.Content>
                </Card>

                <Card>
                    <Card.Header>
                        <Card.Title>Room Status</Card.Title>
                        <Card.Description>Current status of operating rooms</Card.Description>
                    </Card.Header>
                    <Card.Content>
                        <div className="space-y-4">
                            {roomStatus.map(room => {
                                const statusColors = {
                                    'In Progress': 'green',
                                    'Turnover': 'yellow',
                                    'Available': 'blue'
                                };
                                const color = statusColors[room.status] || 'gray';
                                
                                return (
                                    <Card.Item
                                        key={room.room_id}
                                        title={room.room_name}
                                        subtitle={<span className={`text-${color}-600`}>{room.status}</span>}
                                        meta={
                                            <div className="text-right">
                                                <div className="font-medium">
                                                    {room.status === 'In Progress' ? 'Case Time' : 'Next Case'}
                                                </div>
                                                <div className={`text-sm text-${color}-600`}>
                                                    {room.status === 'In Progress' 
                                                        ? `${Math.round((new Date() - new Date(room.or_in_time)) / 1000 / 60)} min`
                                                        : room.next_case_time 
                                                            ? new Date(room.next_case_time).toLocaleTimeString('en-US', {
                                                                hour: 'numeric',
                                                                minute: '2-digit'
                                                            })
                                                            : 'No cases scheduled'
                                                    }
                                                </div>
                                            </div>
                                        }
                                        className={`bg-${color}-50`}
                                    />
                                );
                            })}
                        </div>
                    </Card.Content>
                </Card>
            </div>
        </div>
    );
};

export default DashboardOverview;
