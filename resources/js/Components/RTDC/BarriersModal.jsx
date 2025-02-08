import React, { useState, useEffect } from 'react';
import Modal from '@/Components/Modal';

const BarriersModal = ({ isOpen, onClose, patient, onSave }) => {
    const [barriers, setBarriers] = useState([]);
    const [newBarrier, setNewBarrier] = useState('');

    useEffect(() => {
        if (patient && patient.dischargePlan.dischargeBarriers) {
            setBarriers(patient.dischargePlan.dischargeBarriers);
        } else {
            setBarriers([]);
        }
    }, [patient]);

    const handleAddBarrier = () => {
        if (newBarrier.trim()) {
            setBarriers([...barriers, newBarrier.trim()]);
            setNewBarrier('');
        }
    };

    const handleRemoveBarrier = (index) => {
        setBarriers(barriers.filter((_, i) => i !== index));
    };

    const handleSave = () => {
        onSave(barriers);
        onClose();
    };

    return (
        <Modal show={isOpen} onClose={onClose} maxWidth="md">
            <div className="p-6">
                <div className="text-lg font-semibold mb-4">
                    Discharge Barriers
                    {patient && (
                        <span className="text-sm font-normal text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark ml-2">
                            {patient.name} - Room {patient.room}
                        </span>
                    )}
                </div>

                {/* Current Barriers List */}
                <div className="mb-4">
                    <div className="text-sm font-medium mb-2">Current Barriers:</div>
                    {barriers.length === 0 ? (
                        <div className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark text-sm italic">
                            No barriers identified
                        </div>
                    ) : (
                        <div className="space-y-2">
                            {barriers.map((barrier, index) => (
                                <div
                                    key={index}
                                    className="flex items-center justify-between bg-healthcare-background dark:bg-healthcare-background-dark p-2 rounded"
                                >
                                    <span>{barrier}</span>
                                    <button
                                        onClick={() => handleRemoveBarrier(index)}
                                        className="text-healthcare-critical dark:text-healthcare-critical-dark hover:text-healthcare-critical-hover"
                                    >
                                        <svg
                                            className="w-4 h-4"
                                            fill="none"
                                            stroke="currentColor"
                                            viewBox="0 0 24 24"
                                        >
                                            <path
                                                strokeLinecap="round"
                                                strokeLinejoin="round"
                                                strokeWidth="2"
                                                d="M6 18L18 6M6 6l12 12"
                                            />
                                        </svg>
                                    </button>
                                </div>
                            ))}
                        </div>
                    )}
                </div>

                {/* Add New Barrier */}
                <div className="mb-4">
                    <div className="text-sm font-medium mb-2">Add New Barrier:</div>
                    <div className="flex gap-2">
                        <input
                            type="text"
                            value={newBarrier}
                            onChange={(e) => setNewBarrier(e.target.value)}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') {
                                    e.preventDefault();
                                    handleAddBarrier();
                                }
                            }}
                            className="flex-1 border rounded px-3 py-2 focus:ring-2 focus:ring-healthcare-primary"
                            placeholder="Enter barrier description..."
                        />
                        <button
                            onClick={handleAddBarrier}
                            className="bg-healthcare-primary text-healthcare-primary-content px-4 py-2 rounded hover:bg-healthcare-primary-hover"
                        >
                            Add
                        </button>
                    </div>
                </div>

                {/* Action Buttons */}
                <div className="flex justify-end gap-2">
                    <button
                        onClick={onClose}
                        className="px-4 py-2 border border-healthcare-border rounded hover:bg-healthcare-background dark:hover:bg-healthcare-background-dark"
                    >
                        Cancel
                    </button>
                    <button
                        onClick={handleSave}
                        className="bg-healthcare-primary text-healthcare-primary-content px-4 py-2 rounded hover:bg-healthcare-primary-hover"
                    >
                        Save Changes
                    </button>
                </div>
            </div>
        </Modal>
    );
};

export default BarriersModal;
