import React, { useState, useEffect } from 'react';
import Card from '../Card';
import Calendar from './Calendar';
import Button from '../PrimaryButton';
import Modal from '../Modal';
import Form from './Form';
import Select from './Select';
import { Icon } from '@iconify/react';
import axios from 'axios';
import { usePage, router } from '@inertiajs/react';

const BlockScheduleManager = () => {
    const { auth } = usePage().props;
    const [blocks, setBlocks] = useState([]);
    const [utilization, setUtilization] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [selectedDate, setSelectedDate] = useState(new Date());
    const [showModal, setShowModal] = useState(false);
    const [services, setServices] = useState([]);
    const [rooms, setRooms] = useState([]);
    useEffect(() => {
        if (!auth.user) {
            router.visit('/login');
            return;
        }

        const fetchData = async () => {
            try {
                const [blocksRes, utilizationRes, servicesRes, roomsRes] = await Promise.all([
                    axios.get('/api/blocks'),
                    axios.get('/api/blocks/utilization'),
                    axios.get('/api/services'),
                    axios.get('/api/rooms')
                ]);

                setBlocks(blocksRes.data);
                setUtilization(utilizationRes.data);
                setServices(servicesRes.data);
                setRooms(roomsRes.data);
                setError(null);
            } catch (err) {
                console.error('Error fetching data:', err);
                setError('Failed to load schedule data');
            } finally {
                setLoading(false);
            }
        };

        fetchData();
    }, []);

    const getBlocksForDate = (date) => {
        return blocks.filter(block => 
            new Date(block.block_date).toDateString() === date.toDateString()
        );
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
            await axios.post('/api/blocks', formData);
            const [blocksRes, utilizationRes] = await Promise.all([
                axios.get('/api/blocks'),
                axios.get('/api/blocks/utilization')
            ]);
            setBlocks(blocksRes.data);
            setUtilization(utilizationRes.data);
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
                        minute: '2-digit'
                    })} - {new Date(block.end_time).toLocaleTimeString('en-US', {
                        hour: 'numeric',
                        minute: '2-digit'
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

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div className="lg:col-span-2">
                    <Card>
                        <Card.Content>
                            <Calendar
                                value={selectedDate}
                                onChange={setSelectedDate}
                                renderDayContent={renderDayContent}
                                className="h-[600px]"
                            />
                        </Card.Content>
                    </Card>
                </div>

                <Card>
                    <Card.Header>
                        <Card.Title>Block Utilization</Card.Title>
                        <Card.Description>Last 30 days performance</Card.Description>
                    </Card.Header>
                    <Card.Content>
                        <div className="space-y-4">
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

            <Modal
                open={showModal}
                onClose={() => setShowModal(false)}
                title="Add Block Time"
            >
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
            </Modal>
        </div>
    );
};

export default BlockScheduleManager;
