import React, { useState, useEffect } from 'react';
import Card from '../Dashboard/Card';
import Calendar from './Calendar';
import Button from '../PrimaryButton';
import Modal from '../Modal';
import Form from './Form';
import Select from './Select';
import { Icon } from '@iconify/react';
import { usePage } from '@inertiajs/react';
import DataService from '@/services/data-service';

const BlockScheduleManager = () => {
    const { auth } = usePage().props;
    const [blocks, setBlocks] = useState([]);
    const [utilization, setUtilization] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const dataService = DataService.useDataService();
    const [selectedDate, setSelectedDate] = useState(() => {
        const date = new Date();
        date.setHours(0, 0, 0, 0);
        return date;
    });
    const [showModal, setShowModal] = useState(false);
    const [services, setServices] = useState([]);
    const [rooms, setRooms] = useState([]);
    useEffect(() => {
        const fetchData = async () => {
            setLoading(true);
            setError(null);
            
            try {
                const [blocks, utilization, services] = await Promise.all([
                    dataService.getBlockTemplates().catch(err => {
                        console.error('Error fetching block templates:', err);
                        throw new Error('Failed to load block templates');
                    }),
                    dataService.getBlockUtilization(selectedDate.toISOString().split('T')[0]).catch(err => {
                        console.error('Error fetching block utilization:', err);
                        throw new Error('Failed to load block utilization');
                    }),
                    dataService.getServices().catch(err => {
                        console.error('Error fetching services:', err);
                        throw new Error('Failed to load services');
                    })
                ]);

                setBlocks(blocks);
                setUtilization(utilization);
                setServices(services);
                setRooms([
                    { room_id: 1, name: 'OR-1' },
                    { room_id: 2, name: 'OR-2' },
                    { room_id: 3, name: 'OR-3' },
                    { room_id: 4, name: 'OR-4' },
                    { room_id: 5, name: 'OR-5' }
                ]);
            } catch (err) {
                setError(err.message || 'Failed to load schedule data');
                // Reset states on error
                setBlocks([]);
                setUtilization(null);
                setServices([]);
            } finally {
                setLoading(false);
            }
        };

        fetchData();
    }, [selectedDate]);

    const getBlocksForDate = (date) => {
        return blocks.filter(block => {
            // Convert Sunday (0) to 7, otherwise use getDay() directly
            const dayOfWeek = date.getDay() === 0 ? 7 : date.getDay();
            return block.days_of_week.includes(dayOfWeek);
        }).map(block => ({
            ...block,
            block_date: date.toISOString().split('T')[0],
            service_name: services.find(s => s.service_id === block.service_id)?.name,
            title: `${services.find(s => s.service_id === block.service_id)?.name} Block`
        }));
    };

    const getUtilizationColor = (percentage) => {
        if (percentage >= 80) return 'text-green-600';
        if (percentage >= 60) return 'text-yellow-600';
        return 'text-red-600';
    };

    const [formData, setFormData] = useState({
        service_id: '',
        room_id: '',
        block_date: '',
        start_time: '',
        end_time: ''
    });

    const handleInputChange = (field, value) => {
        setFormData(prev => ({
            ...prev,
            [field]: value
        }));
    };

    const handleSubmit = async (e) => {
        e.preventDefault();
        try {
            // In mock mode, just update the local state with new block
            // Format the date and time strings
            const date = formData.block_date;
            const startDateTime = `${date}T${formData.start_time}:00`;
            const endDateTime = `${date}T${formData.end_time}:00`;

            const newBlock = {
                block_id: blocks.length + 1,
                room_id: formData.room_id,
                service_id: formData.service_id,
                service_name: services.find(s => s.service_id === formData.service_id)?.name,
                block_date: formData.block_date,
                start_time: startDateTime,
                end_time: endDateTime,
                title: `${services.find(s => s.service_id === formData.service_id)?.name} Block`,
                days_of_week: [new Date(date).getDay()] // Add the day of week for the selected date
            };
            
            setBlocks([...blocks, newBlock]);
            setShowModal(false);
            setFormData({
                service_id: '',
                room_id: '',
                block_date: '',
                start_time: '',
                end_time: ''
            });
        } catch (err) {
            console.error('Error creating block:', err);
        }
    };

    const renderBlockContent = (block) => {
        const blockUtil = utilization?.utilization?.find(u => u.block_id === block.block_id);
        return (
            <div className="p-2">
                <div className="font-medium">{block.service_name}</div>
                <div className="text-sm text-gray-500">
                    {new Date(block.start_time).toLocaleTimeString('en-US', {
                        hour: 'numeric',
                        minute: '2-digit',
                        hour12: true
                    })} - {new Date(block.end_time).toLocaleTimeString('en-US', {
                        hour: 'numeric',
                        minute: '2-digit',
                        hour12: true
                    })}
                </div>
                {blockUtil && (
                    <div className={`text-sm ${getUtilizationColor(blockUtil.utilization_percentage)}`}>
                        {Math.round(blockUtil.utilization_percentage)}% Utilized
                    </div>
                )}
            </div>
        );
    };

    const renderDayContent = (date) => {
        const dayBlocks = getBlocksForDate(date);
        if (dayBlocks.length === 0) return null;

        return (
            <div className="mt-1">
                {dayBlocks.map(block => (
                    <div 
                        key={block.block_id}
                        className="text-xs bg-indigo-50 rounded-sm mb-1 overflow-hidden"
                    >
                        {renderBlockContent(block)}
                    </div>
                ))}
            </div>
        );
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
        <div className="space-y-6 p-6">
            <div className="flex justify-between items-center">
                <h2 className="text-2xl font-semibold">Block Schedule</h2>
                <Button 
                    variant="primary"
                    onClick={() => setShowModal(true)}
                >
                    <Icon icon="heroicons:plus" className="w-5 h-5 mr-2" />
                    Add Block
                </Button>
            </div>

            <div className="mb-6">
                <Card>
                    <Card.Header>
                        <Card.Title>Block Utilization</Card.Title>
                        <Card.Description>Last 30 days performance</Card.Description>
                    </Card.Header>
                    <Card.Content>
                        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
                            {utilization?.utilization
                                ?.sort((a, b) => b.utilization_percentage - a.utilization_percentage)
                                .slice(0, 5)
                                .map(block => (
                                    <Card.Item
                                        key={block.block_id}
                                        title={block.title}
                                        subtitle={block.service_name}
                                        meta={
                                            <div className={`text-right ${getUtilizationColor(block.utilization_percentage)}`}>
                                                {Math.round(block.utilization_percentage)}%
                                            </div>
                                        }
                                    />
                                ))
                            }
                        </div>
                    </Card.Content>
                </Card>
            </div>

            <Card className="h-[calc(100vh-20rem)] flex flex-col">
                <Card.Content className="flex-1 p-0 overflow-hidden">
                    <div className="h-full w-full">
                        <Calendar
                            value={selectedDate}
                            onChange={setSelectedDate}
                            renderDayContent={renderDayContent}
                            className="h-full"
                        />
                    </div>
                </Card.Content>
            </Card>

            <Modal show={showModal} onClose={() => setShowModal(false)} maxWidth="lg">
                <div className="p-6">
                    <h3 className="text-lg font-medium text-gray-900 mb-4">Add Block Time</h3>
                    <Form onSubmit={handleSubmit} className="space-y-4">
                        <Form.Field>
                            <Form.Label>Service</Form.Label>
                            <Select
                                value={formData.service_id}
                                onChange={(value) => handleInputChange('service_id', value)}
                                options={services.map(s => ({
                                    label: s.name,
                                    value: s.service_id
                                }))}
                            />
                        </Form.Field>
                        <Form.Field>
                            <Form.Label>Room</Form.Label>
                            <Select
                                value={formData.room_id}
                                onChange={(value) => handleInputChange('room_id', value)}
                                options={rooms.map(r => ({
                                    label: r.name,
                                    value: r.room_id
                                }))}
                            />
                        </Form.Field>
                        <Form.Field>
                            <Form.Label>Date</Form.Label>
                            <Form.Input
                                type="date"
                                value={formData.block_date}
                                onChange={(e) => handleInputChange('block_date', e.target.value)}
                            />
                        </Form.Field>
                        <Form.Field>
                            <Form.Label>Start Time</Form.Label>
                            <Form.Input
                                type="time"
                                value={formData.start_time}
                                onChange={(e) => handleInputChange('start_time', e.target.value)}
                            />
                        </Form.Field>
                        <Form.Field>
                            <Form.Label>End Time</Form.Label>
                            <Form.Input
                                type="time"
                                value={formData.end_time}
                                onChange={(e) => handleInputChange('end_time', e.target.value)}
                            />
                        </Form.Field>
                        <div className="flex justify-end space-x-4">
                            <Button variant="secondary" onClick={() => setShowModal(false)}>
                                Cancel
                            </Button>
                            <Button variant="primary" type="submit">
                                Save Block
                            </Button>
                        </div>
                    </Form>
                </div>
            </Modal>
        </div>
    );
};

export default BlockScheduleManager;
