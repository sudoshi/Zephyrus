import React, { useState, useEffect } from 'react';
import Modal from '@/Components/Modal';
import Button from '@/Components/PrimaryButton';
import Select from '@/Components/BlockSchedule/Select';
import { Icon } from '@iconify/react';
import { mockReferenceData } from '@/mock-data/cases';
import { mockServices } from '@/mock-data/dashboard';

const CaseForm = ({ isOpen, onClose, onSubmit, initialData = null }) => {
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

    const rooms = [
        { room_id: 1, name: 'OR-1' },
        { room_id: 2, name: 'OR-2' },
        { room_id: 3, name: 'OR-3' },
        { room_id: 4, name: 'OR-4' },
        { room_id: 5, name: 'OR-5' }
    ];

    const providers = [
        { provider_id: 1, name: 'Dr. Sarah Johnson' },
        { provider_id: 2, name: 'Dr. Michael Smith' },
        { provider_id: 3, name: 'Dr. James Wilson' },
        { provider_id: 4, name: 'Dr. Emily Brown' }
    ];

    useEffect(() => {
        if (initialData) {
            setFormData({
                ...initialData,
                surgery_date: initialData.surgery_date,
                scheduled_start_time: initialData.scheduled_start_time.split('T')[1].substring(0, 5),
                estimated_duration: Math.round(initialData.estimated_duration / 60)
            });
        } else {
            setFormData({
                patient_name: '',
                mrn: '',
                procedure_name: '',
                service_id: '',
                room_id: '',
                primary_surgeon_id: '',
                surgery_date: new Date().toISOString().split('T')[0],
                scheduled_start_time: '',
                estimated_duration: '',
                case_class: '',
                asa_rating: '',
                case_type: '',
                patient_class: '',
                notes: ''
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
        
        // Format the data
        const data = {
            ...formData,
            estimated_duration: parseInt(formData.estimated_duration) * 60,
            scheduled_start_time: `${formData.surgery_date}T${formData.scheduled_start_time}:00`,
            service_name: mockServices.find(s => s.service_id.toString() === formData.service_id)?.name,
            room_name: rooms.find(r => r.room_id.toString() === formData.room_id)?.name,
            surgeon_name: providers.find(p => p.provider_id.toString() === formData.primary_surgeon_id)?.name
        };

        await onSubmit(data);
    };

    return (
        <Modal show={isOpen} onClose={onClose} maxWidth="2xl">
            <form onSubmit={handleSubmit} className="p-6 space-y-4">
                <div className="flex justify-between items-center border-b pb-3">
                    <h3 className="text-lg font-medium text-gray-900">
                        {initialData ? 'Edit Case' : 'Add New Case'}
                    </h3>
                    <button
                        type="button"
                        onClick={onClose}
                        className="text-gray-400 hover:text-gray-500"
                    >
                        <Icon icon="heroicons:x-mark" className="w-6 h-6" />
                    </button>
                </div>

                <div className="grid grid-cols-2 gap-4">
                    <div className="col-span-2">
                        <label className="block text-sm font-medium text-gray-700 mb-1">
                            Patient Name
                        </label>
                        <input
                            type="text"
                            value={formData.patient_name}
                            onChange={(e) => handleInputChange('patient_name', e.target.value)}
                            className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                            required
                        />
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">
                            MRN
                        </label>
                        <input
                            type="text"
                            value={formData.mrn}
                            onChange={(e) => handleInputChange('mrn', e.target.value)}
                            className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                            required
                        />
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">
                            Service
                        </label>
                        <Select
                            value={formData.service_id}
                            onChange={(value) => handleInputChange('service_id', value)}
                            options={mockServices.map(s => ({
                                label: s.name,
                                value: s.service_id.toString()
                            }))}
                        />
                    </div>

                    <div className="col-span-2">
                        <label className="block text-sm font-medium text-gray-700 mb-1">
                            Procedure
                        </label>
                        <input
                            type="text"
                            value={formData.procedure_name}
                            onChange={(e) => handleInputChange('procedure_name', e.target.value)}
                            className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                            required
                        />
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">
                            Primary Surgeon
                        </label>
                        <Select
                            value={formData.primary_surgeon_id}
                            onChange={(value) => handleInputChange('primary_surgeon_id', value)}
                            options={providers.map(p => ({
                                label: p.name,
                                value: p.provider_id.toString()
                            }))}
                        />
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">
                            Room
                        </label>
                        <Select
                            value={formData.room_id}
                            onChange={(value) => handleInputChange('room_id', value)}
                            options={rooms.map(r => ({
                                label: r.name,
                                value: r.room_id.toString()
                            }))}
                        />
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">
                            Surgery Date
                        </label>
                        <input
                            type="date"
                            value={formData.surgery_date}
                            onChange={(e) => handleInputChange('surgery_date', e.target.value)}
                            className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                            required
                        />
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">
                            Start Time
                        </label>
                        <input
                            type="time"
                            value={formData.scheduled_start_time}
                            onChange={(e) => handleInputChange('scheduled_start_time', e.target.value)}
                            className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                            required
                        />
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">
                            Estimated Duration (hours)
                        </label>
                        <input
                            type="number"
                            min="0.5"
                            step="0.5"
                            value={formData.estimated_duration}
                            onChange={(e) => handleInputChange('estimated_duration', e.target.value)}
                            className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                            required
                        />
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">
                            Case Class
                        </label>
                        <Select
                            value={formData.case_class}
                            onChange={(value) => handleInputChange('case_class', value)}
                            options={mockReferenceData.caseClasses}
                        />
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">
                            ASA Rating
                        </label>
                        <Select
                            value={formData.asa_rating}
                            onChange={(value) => handleInputChange('asa_rating', value)}
                            options={mockReferenceData.asaRatings}
                        />
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">
                            Case Type
                        </label>
                        <Select
                            value={formData.case_type}
                            onChange={(value) => handleInputChange('case_type', value)}
                            options={mockReferenceData.caseTypes}
                        />
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">
                            Patient Class
                        </label>
                        <Select
                            value={formData.patient_class}
                            onChange={(value) => handleInputChange('patient_class', value)}
                            options={mockReferenceData.patientClasses}
                        />
                    </div>

                    <div className="col-span-2">
                        <label className="block text-sm font-medium text-gray-700 mb-1">
                            Notes
                        </label>
                        <textarea
                            value={formData.notes}
                            onChange={(e) => handleInputChange('notes', e.target.value)}
                            rows={3}
                            className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                        />
                    </div>
                </div>

                <div className="flex justify-end space-x-4 mt-6 pt-4 border-t">
                    <Button
                        type="button"
                        onClick={onClose}
                        className="bg-white text-gray-700 border-gray-300 hover:bg-gray-50"
                    >
                        Cancel
                    </Button>
                    <Button type="submit">
                        {initialData ? 'Update Case' : 'Create Case'}
                    </Button>
                </div>
            </form>
        </Modal>
    );
};

export default CaseForm;
