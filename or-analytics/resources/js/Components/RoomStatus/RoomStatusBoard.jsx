import React, { useState, useEffect } from 'react';
import { Card, Grid, Badge, Progress } from '@heroui/react';
import { Icon } from '@iconify/react';
import axios from 'axios';

const RoomStatusBoard = () => {
    const [rooms, setRooms] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    useEffect(() => {
        const fetchRoomStatus = async () => {
            try {
                const [statusRes, casesRes] = await Promise.all([
                    axios.get('/api/cases/room-status'),
                    axios.get('/api/cases/today')
                ]);

                // Combine room status with case details
                const roomsWithCases = statusRes.data.map(room => {
                    const currentCase = casesRes.data.find(c => 
                        c.room_id === room.room_id && 
                        c.status === 'In Progress'
                    );
                    const nextCase = casesRes.data.find(c => 
                        c.room_id === room.room_id && 
                        c.status === 'Scheduled' &&
                        !c.actual_start_time
                    );
                    
                    return {
                        ...room,
                        currentCase,
                        nextCase
                    };
                });

                setRooms(roomsWithCases);
                setError(null);
            } catch (err) {
                console.error('Error fetching room status:', err);
                setError('Failed to load room status');
            } finally {
                setLoading(false);
            }
        };

        fetchRoomStatus();
        // Refresh every minute
        const interval = setInterval(fetchRoomStatus, 60 * 1000);
        return () => clearInterval(interval);
    }, []);

    const getStatusColor = (status) => {
        const colors = {
            'In Progress': 'green',
            'Turnover': 'yellow',
            'Available': 'blue',
            'Delayed': 'red'
        };
        return colors[status] || 'gray';
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
                <Spinner size="lg" />
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
            <Grid cols={3} gap={6}>
                {rooms.map(room => {
                    const color = getStatusColor(room.status);
                    const progress = room.currentCase ? 
                        calculateProgress(room.or_in_time, room.currentCase.estimated_duration) : 0;

                    return (
                        <Card key={room.room_id} className={`bg-${color}-50`}>
                            <Card.Header>
                                <div className="flex justify-between items-center">
                                    <div>
                                        <Card.Title>{room.room_name}</Card.Title>
                                        <Badge color={color}>{room.status}</Badge>
                                    </div>
                                    {room.status === 'In Progress' && (
                                        <div className="text-right">
                                            <div className="text-sm font-medium">Case Time</div>
                                            <div className={`text-${color}-600`}>
                                                {formatDuration(Math.round((new Date() - new Date(room.or_in_time)) / 1000 / 60))}
                                            </div>
                                        </div>
                                    )}
                                </div>
                            </Card.Header>

                            <Card.Content>
                                {room.currentCase ? (
                                    <div className="space-y-4">
                                        <div>
                                            <div className="font-medium">{room.currentCase.procedure_name}</div>
                                            <div className="text-sm text-gray-500">
                                                {room.currentCase.surgeon_name} • {room.currentCase.service_name}
                                            </div>
                                        </div>
                                        <Progress 
                                            value={progress}
                                            color={progress > 100 ? 'red' : color}
                                            label={`${progress}% Complete`}
                                        />
                                        <div className="text-sm text-gray-500">
                                            Estimated Duration: {formatDuration(room.currentCase.estimated_duration)}
                                        </div>
                                    </div>
                                ) : room.nextCase ? (
                                    <div className="space-y-2">
                                        <div className="text-sm text-gray-500">Next Case</div>
                                        <div>
                                            <div className="font-medium">{room.nextCase.procedure_name}</div>
                                            <div className="text-sm text-gray-500">
                                                {new Date(room.nextCase.scheduled_start_time).toLocaleTimeString('en-US', {
                                                    hour: 'numeric',
                                                    minute: '2-digit'
                                                })} • {formatDuration(room.nextCase.estimated_duration)}
                                            </div>
                                        </div>
                                    </div>
                                ) : (
                                    <div className="text-sm text-gray-500">
                                        No cases scheduled
                                    </div>
                                )}
                            </Card.Content>
                        </Card>
                    );
                })}
            </Grid>
        </div>
    );
};

export default RoomStatusBoard;
