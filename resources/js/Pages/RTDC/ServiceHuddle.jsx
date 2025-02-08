import React, { useState } from 'react';
import RTDCPageLayout from '@/Components/RTDC/RTDCPageLayout';
import Card from '@/Components/Dashboard/Card';
import { Icon } from '@iconify/react';
import { serviceHuddleData } from '@/mock-data/rtdc-service-huddle';
import { services } from '@/mock-data/rtdc';
import BarriersModal from '@/Components/RTDC/BarriersModal';

const ANCILLARY_SERVICES = [
    'Physical Therapy',
    'Occupational Therapy',
    'Speech Therapy',
    'Social Work',
    'Respiratory Therapy',
];

const ServiceHuddle = () => {
    // State management
    const [patients, setPatients] = useState(serviceHuddleData.patients);
    const [selectedUnit, setSelectedUnit] = useState('All');
    const [selectedService, setSelectedService] = useState('All');
    const [taskInputs, setTaskInputs] = useState({});
    const [selectedServices, setSelectedServices] = useState({});
    const [isBarriersModalOpen, setIsBarriersModalOpen] = useState(false);
    const [selectedPatient, setSelectedPatient] = useState(null);

    // Get unique units and services for filters
    const allUnits = Array.from(new Set(patients.map((p) => p.unit))).sort();
    const allServices = Array.from(new Set(patients.map((p) => p.service))).sort();

    // Handle patient updates
    const handlePatientUpdate = (patientId, field, value) => {
        const updatedPatients = patients.map((p) =>
            p.id === patientId
                ? { ...p, [field]: value, lastUpdate: new Date().toISOString() }
                : p
        );
        setPatients(updatedPatients);
    };

    // Handle task management
    const handleTaskInputChange = (patientId, value) => {
        setTaskInputs((prev) => ({
            ...prev,
            [patientId]: value
        }));
    };

    const handleAddTask = (patientId) => {
        const taskText = taskInputs[patientId];
        if (!taskText?.trim()) return;

        const patient = patients.find((p) => p.id === patientId);
        if (!patient) return;

        const currentTasks = Array.isArray(patient.tasks) ? patient.tasks : [];
        const updatedTasks = [
            ...currentTasks,
            {
                id: Date.now(),
                text: taskText.trim(),
                completed: false,
            },
        ];

        handlePatientUpdate(patientId, 'tasks', updatedTasks);
        setTaskInputs((prev) => ({
            ...prev,
            [patientId]: ''
        }));
    };

    const handleTaskToggle = (patientId, taskId) => {
        const patient = patients.find((p) => p.id === patientId);
        if (!patient) return;

        const currentTasks = Array.isArray(patient.tasks) ? patient.tasks : [];
        const updatedTasks = currentTasks.map((t) =>
            t.id === taskId ? { ...t, completed: !t.completed } : t
        );

        handlePatientUpdate(patientId, 'tasks', updatedTasks);
    };

    // Handle adding a service task
    const handleAddServiceTask = (patientId) => {
        const selectedService = selectedServices[patientId];
        if (!selectedService) return;

        const patient = patients.find((p) => p.id === patientId);
        if (!patient) return;

        const currentTasks = Array.isArray(patient.tasks) ? patient.tasks : [];
        const updatedTasks = [
            ...currentTasks,
            {
                id: Date.now(),
                text: `Required Service: ${selectedService}`,
                completed: false,
            },
        ];

        handlePatientUpdate(patientId, 'tasks', updatedTasks);
        setSelectedServices((prev) => ({
            ...prev,
            [patientId]: ''
        }));
    };

    // Handle barriers save
    const handleBarriersSave = (barriers) => {
        if (selectedPatient) {
            const updatedPlan = {
                ...selectedPatient.dischargePlan,
                dischargeBarriers: barriers,
            };
            handlePatientUpdate(selectedPatient.id, 'dischargePlan', updatedPlan);
        }
    };

    // Status color mapping
    const getStatusColor = (status) => {
        switch (status.toLowerCase()) {
            case 'stable':
                return 'bg-healthcare-success text-healthcare-success-content';
            case 'guarded':
                return 'bg-healthcare-warning text-healthcare-warning-content';
            case 'critical':
                return 'bg-healthcare-critical text-healthcare-critical-content';
            default:
                return 'bg-healthcare-neutral text-healthcare-neutral-content';
        }
    };

    // Editable cell component
    const EditableCell = ({ value, onChange, type = 'text' }) => {
        const [isEditing, setIsEditing] = useState(false);
        const [tempValue, setTempValue] = useState(value);

        const handleBlur = () => {
            setIsEditing(false);
            if (tempValue !== value) {
                onChange(tempValue);
            }
        };

        if (isEditing) {
            return (
                <input
                    type={type}
                    className="w-full p-1 border rounded focus:ring-2 focus:ring-healthcare-primary"
                    value={tempValue}
                    onChange={(e) => setTempValue(e.target.value)}
                    onBlur={handleBlur}
                    autoFocus
                />
            );
        }

        return (
            <div
                className="cursor-pointer hover:bg-healthcare-background-hover dark:hover:bg-healthcare-background-hover-dark p-1 rounded"
                onClick={() => setIsEditing(true)}
            >
                {value}
            </div>
        );
    };

    return (
        <RTDCPageLayout
            title="Service Huddle"
            subtitle="Unit and departments patient management"
        >
            <div className="space-y-6">
                <Card>
                    <Card.Header>
                        <Card.Title>
                            <div className="flex justify-between items-center">
                                <div className="flex items-center space-x-2">
                                    <Icon icon="heroicons:users" className="w-5 h-5" />
                                    <span>Unit and Departments Dashboard</span>
                                </div>
                                <div className="flex gap-4">
                                    {/* Unit Selector */}
                                    <select
                                        className="w-48 border rounded-md px-3 py-2 focus:ring-2 focus:ring-healthcare-primary"
                                        value={selectedUnit}
                                        onChange={(e) => setSelectedUnit(e.target.value)}
                                    >
                                        <option value="All">All Units</option>
                                        {allUnits.map((unit) => (
                                            <option key={unit} value={unit}>
                                                {unit}
                                            </option>
                                        ))}
                                    </select>

                                    {/* Service Selector */}
                                    <select
                                        className="w-48 border rounded-md px-3 py-2 focus:ring-2 focus:ring-healthcare-primary"
                                        value={selectedService}
                                        onChange={(e) => setSelectedService(e.target.value)}
                                    >
                                        <option value="All">All Services</option>
                                        {allServices.map((service) => (
                                            <option key={service} value={service}>
                                                {service}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                            </div>
                        </Card.Title>
                    </Card.Header>
                    <Card.Content>
                        <div className="overflow-x-auto">
                            <table className="w-full border-collapse">
                                <thead>
                                    <tr className="border-b">
                                        <th className="px-4 py-2 text-left bg-healthcare-background dark:bg-healthcare-background-dark">
                                            Room
                                        </th>
                                        <th className="px-4 py-2 text-left">
                                            Patient Info
                                        </th>
                                        <th className="px-4 py-2 text-left bg-healthcare-background dark:bg-healthcare-background-dark">
                                            Clinical Status
                                        </th>
                                        <th className="px-4 py-2 text-left">
                                            Care Team
                                        </th>
                                        <th className="px-4 py-2 text-left bg-healthcare-background dark:bg-healthcare-background-dark">
                                            Care Plan
                                        </th>
                                        <th className="px-4 py-2 text-left w-96">
                                            Tasks
                                        </th>
                                        <th className="px-4 py-2 text-left bg-healthcare-background dark:bg-healthcare-background-dark">
                                            Discharge Planning
                                        </th>
                                        <th className="px-4 py-2 text-left">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {patients
                                        .filter(
                                            (patient) =>
                                                (selectedUnit === 'All' ||
                                                    patient.unit === selectedUnit) &&
                                                (selectedService === 'All' ||
                                                    patient.service === selectedService)
                                        )
                                        .map((patient) => (
                                            <tr
                                                key={patient.id}
                                                className="border-b hover:bg-healthcare-background-hover dark:hover:bg-healthcare-background-hover-dark"
                                            >
                                                {/* Room */}
                                                <td className="px-4 py-2 bg-healthcare-background dark:bg-healthcare-background-dark">
                                                    <EditableCell
                                                        value={patient.room}
                                                        onChange={(value) =>
                                                            handlePatientUpdate(
                                                                patient.id,
                                                                'room',
                                                                value
                                                            )
                                                        }
                                                    />
                                                </td>

                                                {/* Patient Info */}
                                                <td className="px-4 py-2">
                                                    <div className="space-y-1">
                                                        <EditableCell
                                                            value={patient.name}
                                                            onChange={(value) =>
                                                                handlePatientUpdate(
                                                                    patient.id,
                                                                    'name',
                                                                    value
                                                                )
                                                            }
                                                        />
                                                        <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                            MRN: {patient.mrn}
                                                            <br />
                                                            Age: {patient.age}
                                                            <br />
                                                            Admit:{' '}
                                                            {new Date(
                                                                patient.admitDate
                                                            ).toLocaleDateString()}
                                                        </div>
                                                    </div>
                                                </td>

                                                {/* Clinical Status */}
                                                <td className="px-4 py-2 bg-healthcare-background dark:bg-healthcare-background-dark">
                                                    <div className="space-y-2">
                                                        <span
                                                            className={`px-2 py-1 rounded-full text-xs font-semibold ${getStatusColor(
                                                                patient.status
                                                            )}`}
                                                        >
                                                            {patient.status}
                                                        </span>
                                                        <div className="text-sm">
                                                            <EditableCell
                                                                value={
                                                                    patient.vitalSigns.bp
                                                                }
                                                                onChange={(value) =>
                                                                    handlePatientUpdate(
                                                                        patient.id,
                                                                        'vitalSigns',
                                                                        {
                                                                            ...patient.vitalSigns,
                                                                            bp: value,
                                                                        }
                                                                    )
                                                                }
                                                            />
                                                            <EditableCell
                                                                value={
                                                                    patient.vitalSigns.o2sat
                                                                }
                                                                onChange={(value) =>
                                                                    handlePatientUpdate(
                                                                        patient.id,
                                                                        'vitalSigns',
                                                                        {
                                                                            ...patient.vitalSigns,
                                                                            o2sat: value,
                                                                        }
                                                                    )
                                                                }
                                                            />
                                                        </div>
                                                    </div>
                                                </td>

                                                {/* Care Team */}
                                                <td className="px-4 py-2">
                                                    <div className="space-y-1">
                                                        <div>
                                                            Primary:{' '}
                                                            {patient.primaryTeam}
                                                        </div>
                                                        <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                            Consults:{' '}
                                                            {patient.consultingServices.join(
                                                                ', '
                                                            )}
                                                        </div>
                                                        <div className="text-sm">
                                                            Nurse:{' '}
                                                            {patient.assignedNurse}
                                                        </div>
                                                    </div>
                                                </td>

                                                {/* Care Plan */}
                                                <td className="px-4 py-2 bg-healthcare-background dark:bg-healthcare-background-dark">
                                                    <div className="space-y-1">
                                                        <EditableCell
                                                            value={patient.code}
                                                            onChange={(value) =>
                                                                handlePatientUpdate(
                                                                    patient.id,
                                                                    'code',
                                                                    value
                                                                )
                                                            }
                                                        />
                                                        <EditableCell
                                                            value={patient.activity}
                                                            onChange={(value) =>
                                                                handlePatientUpdate(
                                                                    patient.id,
                                                                    'activity',
                                                                    value
                                                                )
                                                            }
                                                        />
                                                        <EditableCell
                                                            value={
                                                                patient.dietaryRestrictions
                                                            }
                                                            onChange={(value) =>
                                                                handlePatientUpdate(
                                                                    patient.id,
                                                                    'dietaryRestrictions',
                                                                    value
                                                                )
                                                            }
                                                        />
                                                    </div>
                                                </td>

                                                {/* Tasks */}
                                                <td className="px-4 py-2">
                                                    <div className="space-y-2">
                                                        {/* Required Services Section */}
                                                        <div className="flex items-center gap-2 mb-4">
                                                            <select
                                                                className="border rounded px-2 py-1 w-80 focus:ring-2 focus:ring-healthcare-primary"
                                                                value={selectedServices[patient.id] || ''}
                                                                onChange={(e) => {
                                                                    setSelectedServices(prev => ({
                                                                        ...prev,
                                                                        [patient.id]: e.target.value
                                                                    }));
                                                                }}
                                                            >
                                                                <option value="">Select Required Service...</option>
                                                                {ANCILLARY_SERVICES.map((service) => (
                                                                    <option key={service} value={service}>
                                                                        {service}
                                                                    </option>
                                                                ))}
                                                            </select>
                                                            <button
                                                                className="bg-healthcare-primary text-healthcare-primary-content px-3 py-1 rounded hover:bg-healthcare-primary-hover"
                                                                onClick={() => handleAddServiceTask(patient.id)}
                                                            >
                                                                Inform
                                                            </button>
                                                        </div>

                                                        {/* Task List */}
                                                        {(patient.tasks || []).map(
                                                            (task) => (
                                                                <div
                                                                    key={task.id}
                                                                    className="flex items-center gap-2"
                                                                >
                                                                    <input
                                                                        type="checkbox"
                                                                        checked={
                                                                            task.completed
                                                                        }
                                                                        onChange={() =>
                                                                            handleTaskToggle(
                                                                                patient.id,
                                                                                task.id
                                                                            )
                                                                        }
                                                                        className="rounded border-healthcare-border focus:ring-healthcare-primary"
                                                                    />
                                                                    <span
                                                                        className={
                                                                            task.completed
                                                                                ? 'line-through text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark'
                                                                                : ''
                                                                        }
                                                                    >
                                                                        {task.text}
                                                                    </span>
                                                                </div>
                                                            )
                                                        )}

                                                        {/* New Task Input */}
                                                        <div className="flex items-center gap-2">
                                                            <input
                                                                type="text"
                                                                className="border rounded px-2 py-1 w-full focus:ring-2 focus:ring-healthcare-primary"
                                                                placeholder="New task..."
                                                                value={taskInputs[patient.id] || ''}
                                                                onChange={(e) =>
                                                                    handleTaskInputChange(
                                                                        patient.id,
                                                                        e.target.value
                                                                    )
                                                                }
                                                                onKeyDown={(e) => {
                                                                    if (e.key === 'Enter') {
                                                                        e.preventDefault();
                                                                        handleAddTask(patient.id);
                                                                    }
                                                                }}
                                                            />
                                                            <button
                                                                className="bg-healthcare-primary text-healthcare-primary-content px-3 py-1 rounded hover:bg-healthcare-primary-hover"
                                                                onClick={() =>
                                                                    handleAddTask(patient.id)
                                                                }
                                                            >
                                                                Add
                                                            </button>
                                                        </div>
                                                    </div>
                                                </td>

                                                {/* Discharge Planning */}
                                                <td className="px-4 py-2 bg-healthcare-background dark:bg-healthcare-background-dark">
                                                    <div className="space-y-2">
                                                        <div className="flex flex-col gap-1">
                                                            <div>
                                                                <span className="text-sm font-semibold">
                                                                    Responsible:
                                                                </span>
                                                                <EditableCell
                                                                    value={
                                                                        patient
                                                                            .dischargePlan
                                                                            .responsiblePerson
                                                                    }
                                                                    onChange={(value) => {
                                                                        const updatedPlan = {
                                                                            ...patient.dischargePlan,
                                                                            responsiblePerson:
                                                                                value,
                                                                        };
                                                                        handlePatientUpdate(
                                                                            patient.id,
                                                                            'dischargePlan',
                                                                            updatedPlan
                                                                        );
                                                                    }}
                                                                />
                                                            </div>

                                                            <div>
                                                                <span className="text-sm font-semibold">
                                                                    Target Time:
                                                                </span>
                                                                <EditableCell
                                                                    value={
                                                                        patient
                                                                            .dischargePlan
                                                                            .targetTime
                                                                    }
                                                                    type="time"
                                                                    onChange={(value) => {
                                                                        const updatedPlan = {
                                                                            ...patient.dischargePlan,
                                                                            targetTime:
                                                                                value,
                                                                        };
                                                                        handlePatientUpdate(
                                                                            patient.id,
                                                                            'dischargePlan',
                                                                            updatedPlan
                                                                        );
                                                                    }}
                                                                />
                                                            </div>

                                                            <div>
                                                                <span className="text-sm font-semibold">
                                                                    Predicted by 2PM:
                                                                </span>
                                                                <select
                                                                    className="ml-2 border rounded px-2 py-1 w-20 focus:ring-2 focus:ring-healthcare-primary"
                                                                    value={
                                                                        patient
                                                                            .dischargePlan
                                                                            .predictedBy2PM
                                                                    }
                                                                    onChange={(e) => {
                                                                        const updatedPlan = {
                                                                            ...patient.dischargePlan,
                                                                            predictedBy2PM:
                                                                                e.target.value,
                                                                        };
                                                                        handlePatientUpdate(
                                                                            patient.id,
                                                                            'dischargePlan',
                                                                            updatedPlan
                                                                        );
                                                                    }}
                                                                >
                                                                    <option value="Yes">
                                                                        Yes
                                                                    </option>
                                                                    <option value="No">
                                                                        No
                                                                    </option>
                                                                </select>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>

                                                {/* Actions */}
                                                <td className="px-4 py-2">
                                                    <button
                                                        className="px-3 py-1 bg-healthcare-primary text-healthcare-primary-content rounded hover:bg-healthcare-primary-hover"
                                                        onClick={() => {
                                                            setSelectedPatient(
                                                                patient
                                                            );
                                                            setIsBarriersModalOpen(
                                                                true
                                                            );
                                                        }}
                                                    >
                                                        Describe Barriers
                                                    </button>
                                                </td>
                                            </tr>
                                        ))}
                                </tbody>
                            </table>
                        </div>
                    </Card.Content>
                </Card>

                {/* Unit Metrics Section */}
                <div className="grid grid-cols-3 gap-4">
                    {/* Unit Metrics */}
                    <Card>
                        <Card.Header>
                            <Card.Title>
                                <div className="flex items-center space-x-2">
                                    <Icon icon="heroicons:chart-bar" className="w-5 h-5" />
                                    <span>Unit Metrics</span>
                                </div>
                            </Card.Title>
                        </Card.Header>
                        <Card.Content>
                            <div className="space-y-4">
                                <div className="flex justify-between items-center">
                                    <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Occupancy</span>
                                    <span className="font-semibold">{serviceHuddleData.metrics.unitMetrics.occupancy}%</span>
                                </div>
                                <div className="flex justify-between items-center">
                                    <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Available Beds</span>
                                    <span className="font-semibold">{serviceHuddleData.metrics.unitMetrics.availableBeds}</span>
                                </div>
                                <div className="flex justify-between items-center">
                                    <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Pending Admissions</span>
                                    <span className="font-semibold">{serviceHuddleData.metrics.unitMetrics.pendingAdmissions}</span>
                                </div>
                                <div className="flex justify-between items-center">
                                    <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Expected Discharges</span>
                                    <span className="font-semibold">{serviceHuddleData.metrics.unitMetrics.expectedDischarges}</span>
                                </div>
                            </div>
                        </Card.Content>
                    </Card>

                    {/* Care Requirements */}
                    <Card>
                        <Card.Header>
                            <Card.Title>
                                <div className="flex items-center space-x-2">
                                    <Icon icon="heroicons:clipboard-document-list" className="w-5 h-5" />
                                    <span>Care Requirements</span>
                                </div>
                            </Card.Title>
                        </Card.Header>
                        <Card.Content>
                            <div className="space-y-4">
                                <div className="flex justify-between items-center">
                                    <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Critical Care</span>
                                    <span className="font-semibold">{serviceHuddleData.metrics.careRequirements.criticalCare}</span>
                                </div>
                                <div className="flex justify-between items-center">
                                    <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Telemetry</span>
                                    <span className="font-semibold">{serviceHuddleData.metrics.careRequirements.telemetry}</span>
                                </div>
                                <div className="flex justify-between items-center">
                                    <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Isolation</span>
                                    <span className="font-semibold">{serviceHuddleData.metrics.careRequirements.isolation}</span>
                                </div>
                                <div className="flex justify-between items-center">
                                    <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Special Equipment</span>
                                    <span className="font-semibold">{serviceHuddleData.metrics.careRequirements.specialEquipment}</span>
                                </div>
                            </div>
                        </Card.Content>
                    </Card>

                    {/* Acuity Status */}
                    <Card>
                        <Card.Header>
                            <Card.Title>
                                <div className="flex items-center space-x-2">
                                    <Icon icon="heroicons:heart" className="w-5 h-5" />
                                    <span>Acuity Status</span>
                                </div>
                            </Card.Title>
                        </Card.Header>
                        <Card.Content>
                            <div className="space-y-4">
                                <div className="flex justify-between items-center">
                                    <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Critical</span>
                                    <span className="font-semibold text-healthcare-critical">{serviceHuddleData.metrics.acuityStatus.critical}</span>
                                </div>
                                <div className="flex justify-between items-center">
                                    <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Guarded</span>
                                    <span className="font-semibold text-healthcare-warning">{serviceHuddleData.metrics.acuityStatus.guarded}</span>
                                </div>
                                <div className="flex justify-between items-center">
                                    <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Stable</span>
                                    <span className="font-semibold text-healthcare-success">{serviceHuddleData.metrics.acuityStatus.stable}</span>
                                </div>
                                <div className="flex justify-between items-center">
                                    <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Total Patients</span>
                                    <span className="font-semibold">{serviceHuddleData.metrics.acuityStatus.total}</span>
                                </div>
                            </div>
                        </Card.Content>
                    </Card>
                </div>
            </div>

            {/* Barriers Modal */}
            <BarriersModal
                isOpen={isBarriersModalOpen}
                onClose={() => {
                    setIsBarriersModalOpen(false);
                    setSelectedPatient(null);
                }}
                patient={selectedPatient}
                onSave={handleBarriersSave}
            />
        </RTDCPageLayout>
    );
};

export default ServiceHuddle;
