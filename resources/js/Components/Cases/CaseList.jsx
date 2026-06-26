import React, { useState, useEffect } from 'react';
import Card from '@/Components/Dashboard/Card';
import Button from '@/Components/PrimaryButton';
import Select from '@/Components/BlockSchedule/Select';
import { Icon } from '@iconify/react';
import CaseForm from './CaseForm';
import { mockCases, mockReferenceData } from '@/mock-data/cases';
import { mockServices } from '@/mock-data/dashboard';

const CaseList = () => {
    const [cases, setCases] = useState(mockCases);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);
    const [showForm, setShowForm] = useState(false);
    const [selectedCase, setSelectedCase] = useState(null);
    const [filters, setFilters] = useState({
        date: new Date().toISOString().split('T')[0],
        status: '',
        service: '',
        room: ''
    });

    const rooms = [
        { room_id: 1, name: 'OR-1' },
        { room_id: 2, name: 'OR-2' },
        { room_id: 3, name: 'OR-3' },
        { room_id: 4, name: 'OR-4' },
        { room_id: 5, name: 'OR-5' }
    ];

    useEffect(() => {
        // Filter cases based on selected filters
        const filteredCases = mockCases.filter(orCase => {
            const dateMatch = orCase.surgery_date === filters.date;
            const statusMatch = !filters.status || orCase.status === filters.status;
            const serviceMatch = !filters.service || orCase.service_id.toString() === filters.service;
            const roomMatch = !filters.room || orCase.room_id.toString() === filters.room;
            return dateMatch && statusMatch && serviceMatch && roomMatch;
        });
        setCases(filteredCases);
    }, [filters]);

    const getStatusColor = (status) => {
        switch (status) {
            case 'Scheduled':
                return 'bg-healthcare-info/10 dark:bg-healthcare-info/20 text-healthcare-info dark:text-healthcare-info-dark';
            case 'In Progress':
                return 'bg-healthcare-success/10 dark:bg-healthcare-success/20 text-healthcare-success dark:text-healthcare-success-dark';
            case 'Completed':
                return 'bg-healthcare-background dark:bg-healthcare-background-dark text-healthcare-text-primary dark:text-healthcare-text-primary-dark';
            case 'Delayed':
                return 'bg-healthcare-warning/10 dark:bg-healthcare-warning/20 text-healthcare-warning dark:text-healthcare-warning-dark';
            case 'Cancelled':
                return 'bg-healthcare-critical/10 dark:bg-healthcare-critical/20 text-healthcare-critical dark:text-healthcare-critical-dark';
            default:
                return 'bg-healthcare-background dark:bg-healthcare-background-dark text-healthcare-text-primary dark:text-healthcare-text-primary-dark';
        }
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
                <div className="animate-spin rounded-full h-12 w-12 border-4 border-indigo-600 border-t-transparent"></div>
            </div>
        );
    }

    if (error) {
        return (
            <div className="p-6 text-center text-healthcare-critical dark:text-healthcare-critical-dark">
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
                        <label className="block text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-1">Date</label>
                        <input
                            type="date"
                            value={filters.date}
                            onChange={(e) => handleFilterChange('date', e.target.value)}
                            className="block w-full rounded-md border-healthcare-border dark:border-healthcare-border-dark shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                        />
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-1">Status</label>
                        <Select
                            value={filters.status}
                            onChange={(value) => handleFilterChange('status', value)}
                            options={[
                                { label: 'All Statuses', value: '' },
                                ...mockReferenceData.statuses
                            ]}
                        />
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-1">Service</label>
                        <Select
                            value={filters.service}
                            onChange={(value) => handleFilterChange('service', value)}
                            options={[
                                { label: 'All Services', value: '' },
                                ...mockServices.map(service => ({
                                    label: service.name,
                                    value: service.service_id.toString()
                                }))
                            ]}
                        />
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-1">Room</label>
                        <Select
                            value={filters.room}
                            onChange={(value) => handleFilterChange('room', value)}
                            options={[
                                { label: 'All Rooms', value: '' },
                                ...rooms.map(room => ({
                                    label: room.name,
                                    value: room.room_id.toString()
                                }))
                            ]}
                        />
                    </div>
                </div>
            </div>

            <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                    <thead className="bg-healthcare-background dark:bg-healthcare-background-dark">
                        <tr>
                            <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">Time</th>
                            <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">Patient</th>
                            <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">Procedure</th>
                            <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">Surgeon</th>
                            <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">Room</th>
                            <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">Status</th>
                            <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody className="bg-healthcare-surface dark:bg-healthcare-surface-dark divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                        {cases.map(orCase => (
                            <tr key={orCase.case_id}>
                                <td className="px-6 py-4 whitespace-nowrap">
                                    <div className="text-sm">
                                        <div className="font-medium">
                                            {new Date(orCase.scheduled_start_time).toLocaleTimeString('en-US', {
                                                hour: 'numeric',
                                                minute: '2-digit'
                                            })}
                                        </div>
                                        <div className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                            {Math.round(orCase.estimated_duration / 60)} hrs
                                        </div>
                                    </div>
                                </td>
                                <td className="px-6 py-4 whitespace-nowrap">
                                    <div className="text-sm">
                                        <div className="font-medium">{orCase.patient_name}</div>
                                        <div className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{orCase.mrn}</div>
                                    </div>
                                </td>
                                <td className="px-6 py-4 whitespace-nowrap">
                                    <div className="text-sm">
                                        <div className="font-medium">{orCase.procedure_name}</div>
                                        <div className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{orCase.service_name}</div>
                                    </div>
                                </td>
                                <td className="px-6 py-4 whitespace-nowrap">
                                    <div className="text-sm font-medium">{orCase.surgeon_name}</div>
                                </td>
                                <td className="px-6 py-4 whitespace-nowrap">
                                    <div className="text-sm font-medium">{orCase.room_name}</div>
                                </td>
                                <td className="px-6 py-4 whitespace-nowrap">
                                    <span className={`px-2 inline-flex text-xs leading-5 font-semibold rounded-full ${getStatusColor(orCase.status)}`}>
                                        {orCase.status}
                                    </span>
                                </td>
                                <td className="px-6 py-4 whitespace-nowrap">
                                    <button
                                        onClick={() => {
                                            setSelectedCase(orCase);
                                            setShowForm(true);
                                        }}
                                        className="text-indigo-600 hover:text-indigo-900"
                                    >
                                        <Icon icon="heroicons:pencil" className="w-4 h-4" />
                                    </button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <CaseForm
                isOpen={showForm}
                onClose={() => {
                    setShowForm(false);
                    setSelectedCase(null);
                }}
                initialData={selectedCase}
                onSubmit={async (data) => {
                    try {
                        // In mock mode, just update the local state
                        if (selectedCase) {
                            setCases(prev => prev.map(c => 
                                c.case_id === selectedCase.case_id ? { ...c, ...data } : c
                            ));
                        } else {
                            const newCase = {
                                case_id: Math.max(...cases.map(c => c.case_id)) + 1,
                                ...data,
                                status: 'Scheduled'
                            };
                            setCases(prev => [...prev, newCase]);
                        }
                        setShowForm(false);
                        setSelectedCase(null);
                    } catch (err) {
                        console.error('Error saving case:', err);
                    }
                }}
            />
        </Card>
    );
};

export default CaseList;
