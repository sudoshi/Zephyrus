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
                return 'bg-blue-100 text-blue-800';
            case 'In Progress':
                return 'bg-green-100 text-green-800';
            case 'Completed':
                return 'bg-gray-100 text-gray-800';
            case 'Delayed':
                return 'bg-yellow-100 text-yellow-800';
            case 'Cancelled':
                return 'bg-red-100 text-red-800';
            default:
                return 'bg-gray-100 text-gray-800';
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
                        <label className="block text-sm font-medium text-gray-700 mb-1">Date</label>
                        <input
                            type="date"
                            value={filters.date}
                            onChange={(e) => handleFilterChange('date', e.target.value)}
                            className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                        />
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">Status</label>
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
                        <label className="block text-sm font-medium text-gray-700 mb-1">Service</label>
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
                        <label className="block text-sm font-medium text-gray-700 mb-1">Room</label>
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
                <table className="min-w-full divide-y divide-gray-200">
                    <thead className="bg-gray-50">
                        <tr>
                            <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Time</th>
                            <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Patient</th>
                            <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Procedure</th>
                            <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Surgeon</th>
                            <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Room</th>
                            <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody className="bg-white divide-y divide-gray-200">
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
                                        <div className="text-gray-500">
                                            {Math.round(orCase.estimated_duration / 60)} hrs
                                        </div>
                                    </div>
                                </td>
                                <td className="px-6 py-4 whitespace-nowrap">
                                    <div className="text-sm">
                                        <div className="font-medium">{orCase.patient_name}</div>
                                        <div className="text-gray-500">{orCase.mrn}</div>
                                    </div>
                                </td>
                                <td className="px-6 py-4 whitespace-nowrap">
                                    <div className="text-sm">
                                        <div className="font-medium">{orCase.procedure_name}</div>
                                        <div className="text-gray-500">{orCase.service_name}</div>
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
