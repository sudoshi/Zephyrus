import React, { useState, useEffect } from 'react';
import { Modal, Form, Select, Button } from '@heroui/react';
import { Icon } from '@iconify/react';
import axios from 'axios';

const CaseForm = ({ isOpen, onClose, onSubmit, initialData = null }) => {
    const [services, setServices] = useState([]);
    const [rooms, setRooms] = useState([]);
    const [providers, setProviders] = useState([]);
    const [loading, setLoading] = useState(true);
    const [formData, setFormData] = useState({
        patient_name: '',
        mrn: '',
        procedure_name: '',
        service_id: '',
        room_id: '',
        primary_surgeon_id: '',
        surgery_date: '',
        scheduled_start_time: '',
        estimated_duration: '',
        case_class: '',
        asa_rating: '',
        case_type: '',
        patient_class: '',
        notes: ''
    });

    useEffect(() => {
        const fetchReferenceData = async () => {
            try {
                const [servicesRes, roomsRes, providersRes] = await Promise.all([
                    axios.get('/api/services'),
                    axios.get('/api/rooms'),
                    axios.get('/api/providers')
                ]);

                setServices(servicesRes.data);
                setRooms(roomsRes.data);
                setProviders(providersRes.data);
            } catch (err) {
                console.error('Error fetching reference data:', err);
            } finally {
                setLoading(false);
            }
        };

        fetchReferenceData();
    }, []);

    useEffect(() => {
        if (initialData) {
            setFormData({
                ...initialData,
                surgery_date: initialData.surgery_date.split('T')[0],
                scheduled_start_time: initialData.scheduled_start_time.split('T')[1].substring(0, 5),
                estimated_duration: Math.round(initialData.estimated_duration / 60)
            });
        }
    }, [initialData]);

    const handleInputChange = (field, value) => {
        setFormData(prev => ({
            ...prev,
            [field]: value
        }));
    };

    const handleSubmit = async (e) => {
        e.preventDefault();
        
        // Convert duration from hours to minutes
        const data = {
            ...formData,
            estimated_duration: parseInt(formData.estimated_duration) * 60
        };

        await onSubmit(data);
        onClose();
    };

    if (loading) {
        return (
            <Modal open={isOpen} onClose={onClose}>
                <div className="flex justify-center items-center h-32">
                    <Spinner size="lg" />
                </div>
            </Modal>
        );
    }

    return (
        <Modal 
            open={isOpen} 
            onClose={onClose}
            title={initialData ? 'Edit Case' : 'Add New Case'}
        >
            <Form onSubmit={handleSubmit} className="space-y-4">
                <div className="grid grid-cols-2 gap-4">
                    <Form.Field className="col-span-2">
                        <Form.Label>Patient Name</Form.Label>
                        <Form.Input
                            value={formData.patient_name}
                            onChange={(e) => handleInputChange('patient_name', e.target.value)}
                            required
                        />
                    </Form.Field>

                    <Form.Field>
                        <Form.Label>MRN</Form.Label>
                        <Form.Input
                            value={formData.mrn}
                            onChange={(e) => handleInputChange('mrn', e.target.value)}
                            required
                        />
                    </Form.Field>

                    <Form.Field>
                        <Form.Label>Service</Form.Label>
                        <Select
                            value={formData.service_id}
                            onChange={(value) => handleInputChange('service_id', value)}
                            options={services.map(s => ({
                                label: s.name,
                                value: s.service_id
                            }))}
                            required
                        />
                    </Form.Field>

                    <Form.Field className="col-span-2">
                        <Form.Label>Procedure</Form.Label>
                        <Form.Input
                            value={formData.procedure_name}
                            onChange={(e) => handleInputChange('procedure_name', e.target.value)}
                            required
                        />
                    </Form.Field>

                    <Form.Field>
                        <Form.Label>Primary Surgeon</Form.Label>
                        <Select
                            value={formData.primary_surgeon_id}
                            onChange={(value) => handleInputChange('primary_surgeon_id', value)}
                            options={providers.map(p => ({
                                label: p.name,
                                value: p.provider_id
                            }))}
                            required
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
                            required
                        />
                    </Form.Field>

                    <Form.Field>
                        <Form.Label>Surgery Date</Form.Label>
                        <Form.Input
                            type="date"
                            value={formData.surgery_date}
                            onChange={(e) => handleInputChange('surgery_date', e.target.value)}
                            required
                        />
                    </Form.Field>

                    <Form.Field>
                        <Form.Label>Start Time</Form.Label>
                        <Form.Input
                            type="time"
                            value={formData.scheduled_start_time}
                            onChange={(e) => handleInputChange('scheduled_start_time', e.target.value)}
                            required
                        />
                    </Form.Field>

                    <Form.Field>
                        <Form.Label>Estimated Duration (hours)</Form.Label>
                        <Form.Input
                            type="number"
                            min="0.5"
                            step="0.5"
                            value={formData.estimated_duration}
                            onChange={(e) => handleInputChange('estimated_duration', e.target.value)}
                            required
                        />
                    </Form.Field>

                    <Form.Field>
                        <Form.Label>Case Class</Form.Label>
                        <Select
                            value={formData.case_class}
                            onChange={(value) => handleInputChange('case_class', value)}
                            options={[
                                { label: 'Elective', value: 'Elective' },
                                { label: 'Urgent', value: 'Urgent' },
                                { label: 'Emergency', value: 'Emergency' }
                            ]}
                            required
                        />
                    </Form.Field>

                    <Form.Field className="col-span-2">
                        <Form.Label>Notes</Form.Label>
                        <Form.Textarea
                            value={formData.notes}
                            onChange={(e) => handleInputChange('notes', e.target.value)}
                            rows={3}
                        />
                    </Form.Field>
                </div>

                <div className="flex justify-end space-x-4 mt-6">
                    <Button variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="primary" type="submit">
                        {initialData ? 'Update Case' : 'Create Case'}
                    </Button>
                </div>
            </Form>
        </Modal>
    );
};

export default CaseForm;
