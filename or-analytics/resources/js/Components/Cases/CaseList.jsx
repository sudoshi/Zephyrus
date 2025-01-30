import React, { useState, useEffect } from 'react';
import { Card, Table, Badge, Button, Select, Input } from '@heroui/react';
import { Icon } from '@iconify/react';
import axios from 'axios';
import CaseForm from './CaseForm';

const CaseList = () => {
    const [cases, setCases] = useState([]);
    const [services, setServices] = useState([]);
    const [rooms, setRooms] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [showForm, setShowForm] = useState(false);
    const [selectedCase, setSelectedCase] = useState(null);
    const [filters, setFilters] = useState({
        date: new Date().toISOString().split('T')[0],
        status: '',
        service: '',
        room: ''
    });

    useEffect(() => {
        const fetchData = async () => {
            try {
                const [casesRes, servicesRes, roomsRes] = await Promise.all([
                    axios.get('/api/cases', { params: filters }),
                    axios.get('/api/services'),
                    axios.get('/api/rooms')
                ]);

                setCases(casesRes.data);
                setServices(servicesRes.data);
                setRooms(roomsRes.data);
                setError(null);
            } catch (err) {
                console.error('Error fetching data:', err);
                setError('Failed to load data');
            } finally {
                setLoading(false);
            }
        };

        fetchData();
    }, [filters]);

    const getStatusColor = (status) => {
        const colors = {
            'Scheduled': 'blue',
            'In Progress': 'green',
            'Completed': 'gray',
            'Delayed': 'yellow',
            'Cancelled': 'red'
        };
        return colors[status] || 'gray';
    };

    const handleFilterChange = (field, value) => {
        setFilters(prev => ({
            ...prev,
            [field]: value
        }));
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
        <Card>
            <Card.Header>
                <div className="flex justify-between items-center">
                    <div>
                        <Card.Title>OR Cases</Card.Title>
                        <Card.Description>Manage and track surgical cases</Card.Description>
                    </div>
                    <Button 
                        variant="primary"
                        onClick={() => {
                            setSelectedCase(null);
                            setShowForm(true);
                        }}
                    >
                        <Icon icon="heroicons:plus" className="w-5 h-5 mr-2" />
                        Add Case
                    </Button>
                </div>
            </Card.Header>

            <div className="p-4 border-b">
                <div className="grid grid-cols-4 gap-4">
                    <div>
                        <Input
                            type="date"
                            value={filters.date}
                            onChange={(e) => handleFilterChange('date', e.target.value)}
                            label="Date"
                        />
                    </div>
                    <div>
                        <Select
                            value={filters.status}
                            onChange={(value) => handleFilterChange('status', value)}
                            label="Status"
                            options={[
                                { label: 'All Statuses', value: '' },
                                { label: 'Scheduled', value: 'Scheduled' },
                                { label: 'In Progress', value: 'In Progress' },
                                { label: 'Completed', value: 'Completed' },
                                { label: 'Delayed', value: 'Delayed' },
                                { label: 'Cancelled', value: 'Cancelled' }
                            ]}
                        />
                    </div>
                    <div>
                        <Select
                            value={filters.service}
                            onChange={(value) => handleFilterChange('service', value)}
                            label="Service"
                            options={[
                                { label: 'All Services', value: '' },
                                ...services.map(service => ({
                                    label: service.name,
                                    value: service.service_id
                                }))
                            ]}
                        />
                    </div>
                    <div>
                        <Select
                            value={filters.room}
                            onChange={(value) => handleFilterChange('room', value)}
                            label="Room"
                            options={[
                                { label: 'All Rooms', value: '' },
                                ...rooms.map(room => ({
                                    label: room.name,
                                    value: room.room_id
                                }))
                            ]}
                        />
                    </div>
                </div>
            </div>

            <Table>
                <Table.Header>
                    <Table.Row>
                        <Table.HeaderCell>Time</Table.HeaderCell>
                        <Table.HeaderCell>Patient</Table.HeaderCell>
                        <Table.HeaderCell>Procedure</Table.HeaderCell>
                        <Table.HeaderCell>Surgeon</Table.HeaderCell>
                        <Table.HeaderCell>Room</Table.HeaderCell>
                        <Table.HeaderCell>Status</Table.HeaderCell>
                        <Table.HeaderCell>Actions</Table.HeaderCell>
                    </Table.Row>
                </Table.Header>
                <Table.Body>
                    {cases.map(orCase => (
                        <Table.Row key={orCase.case_id}>
                            <Table.Cell>
                                <div className="text-sm">
                                    <div className="font-medium">
                                        {new Date(orCase.scheduled_start_time).toLocaleTimeString('en-US', {
                                            hour: 'numeric',
                                            minute: '2-digit'
                                        })}
                                    </div>
                                    <div className="text-gray-500">
                                        {Math.round(orCase.estimated_duration / 60)} hrs
                                    </div>
                                </div>
                            </Table.Cell>
                            <Table.Cell>
                                <div className="text-sm">
                                    <div className="font-medium">{orCase.patient_name}</div>
                                    <div className="text-gray-500">{orCase.mrn}</div>
                                </div>
                            </Table.Cell>
                            <Table.Cell>
                                <div className="text-sm">
                                    <div className="font-medium">{orCase.procedure_name}</div>
                                    <div className="text-gray-500">{orCase.service_name}</div>
                                </div>
                            </Table.Cell>
                            <Table.Cell>
                                <div className="text-sm font-medium">{orCase.surgeon_name}</div>
                            </Table.Cell>
                            <Table.Cell>
                                <div className="text-sm font-medium">{orCase.room_name}</div>
                            </Table.Cell>
                            <Table.Cell>
                                <Badge color={getStatusColor(orCase.status)}>
                                    {orCase.status}
                                </Badge>
                            </Table.Cell>
                            <Table.Cell>
                                <div className="flex space-x-2">
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => {
                                            setSelectedCase(orCase);
                                            setShowForm(true);
                                        }}
                                    >
                                        <Icon icon="heroicons:pencil" className="w-4 h-4" />
                                    </Button>
                                </div>
                            </Table.Cell>
                        </Table.Row>
                    ))}
                </Table.Body>
            </Table>

            <CaseForm
                isOpen={showForm}
                onClose={() => {
                    setShowForm(false);
                    setSelectedCase(null);
                }}
                initialData={selectedCase}
                onSubmit={async (data) => {
                    try {
                        if (selectedCase) {
                            await axios.put(`/api/cases/${selectedCase.case_id}`, data);
                        } else {
                            await axios.post('/api/cases', data);
                        }
                        // Refresh the case list
                        const response = await axios.get('/api/cases', { params: filters });
                        setCases(response.data);
                    } catch (err) {
                        console.error('Error saving case:', err);
                        // You might want to show an error message to the user here
                    }
                }}
            />
        </Card>
    );
};

export default CaseList;
