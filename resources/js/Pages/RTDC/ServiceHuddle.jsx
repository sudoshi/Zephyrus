import React, { useState } from 'react';
import RTDCPageLayout from '@/Components/RTDC/RTDCPageLayout';
import { Card, CardHeader, CardTitle, CardContent } from '@/Components/ui/Card';
import { Icon } from '@iconify/react';
import { serviceHuddleData } from '@/mock-data/rtdc-service-huddle';
import StatusUpdateModal from '@/Components/RTDC/StatusUpdateModal';
import CareJourneySummary from '@/Components/RTDC/CareJourneySummary';
import PatientJourneyModal from '@/Components/RTDC/PatientJourneyModal';

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
    const [selectedServices, setSelectedServices] = useState({});
    const [isStatusModalOpen, setIsStatusModalOpen] = useState(false);
    const [isJourneyModalOpen, setIsJourneyModalOpen] = useState(false);
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

    // Handle status update save
    const handleStatusSave = (update) => {
        if (selectedPatient) {
            const updatedPatients = patients.map(p => {
                if (p.id === selectedPatient.id) {
                    return {
                        ...p,
                        tasks: update.tasks,
                        dischargePlan: update.dischargePlan,
                        clinicalStatus: update.clinicalStatus,
                        dischargeRequirements: update.dischargeRequirements,
                        carePlan: update.carePlan,
                        teamCommunication: [
                            ...(p.teamCommunication || []),
                            ...(update.teamCommunication || [])
                        ],
                        lastUpdate: update.timestamp
                    };
                }
                return p;
            });
            setPatients(updatedPatients);
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
                    <CardHeader>
                        <CardTitle>
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
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
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
                                            Care Journey
                                        </th>
                                        <th className="px-4 py-2 text-left">
                                            Clinical Status
                                        </th>
                                        <th className="px-4 py-2 text-left bg-healthcare-background dark:bg-healthcare-background-dark">
                                            Care Team
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

                                                {/* Care Journey */}
                                                <td className="px-4 py-4 bg-healthcare-background dark:bg-healthcare-background-dark min-w-[250px]">
                                                    <CareJourneySummary 
                                                        patient={patient} 
                                                        onClick={() => {
                                                            setSelectedPatient(patient);
                                                            setIsJourneyModalOpen(true);
                                                        }}
                                                    />
                                                </td>

                                                {/* Clinical Status */}
                                                <td className="px-4 py-2">
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
                                                <td className="px-4 py-2 bg-healthcare-background dark:bg-healthcare-background-dark">
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

                                                {/* Actions */}
                                                <td className="px-4 py-2">
                                                    <button
                                                        className="inline-flex items-center gap-2 px-5 py-2.5 bg-healthcare-primary text-healthcare-primary-content border border-healthcare-primary/20 rounded-md shadow-md hover:shadow-lg hover:scale-105 hover:bg-healthcare-primary-hover active:scale-95 focus:outline-none focus:ring-4 focus:ring-healthcare-primary/30 transition-all duration-200 group"
                                                        onClick={() => {
                                                            setSelectedPatient(patient);
                                                            setIsStatusModalOpen(true);
                                                        }}
                                                    >
                                                        <Icon 
                                                            icon="heroicons:document-text" 
                                                            className="w-6 h-6 group-hover:rotate-6 transition-transform duration-200" 
                                                        />
                                                        <span>Status Update</span>
                                                    </button>
                                                </td>
                                            </tr>
                                        ))}
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>

                {/* Unit Metrics Section */}
                <div className="grid grid-cols-3 gap-4">
                    {/* Unit Metrics */}
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                <div className="flex items-center space-x-2">
                                    <Icon icon="heroicons:chart-bar" className="w-5 h-5" />
                                    <span>Unit Metrics</span>
                                </div>
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
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
                        </CardContent>
                    </Card>

                    {/* Care Requirements */}
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                <div className="flex items-center space-x-2">
                                    <Icon icon="heroicons:clipboard-document-list" className="w-5 h-5" />
                                    <span>Care Requirements</span>
                                </div>
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
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
                        </CardContent>
                    </Card>

                    {/* Acuity Status */}
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                <div className="flex items-center space-x-2">
                                    <Icon icon="heroicons:heart" className="w-5 h-5" />
                                    <span>Acuity Status</span>
                                </div>
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
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
                        </CardContent>
                    </Card>
                </div>
            </div>

            {/* Status Update Modal */}
            <StatusUpdateModal
                isOpen={isStatusModalOpen}
                onClose={() => {
                    setIsStatusModalOpen(false);
                    setSelectedPatient(null);
                }}
                patient={selectedPatient}
                onSave={handleStatusSave}
            />

            {/* Patient Journey Modal */}
            <PatientJourneyModal
                isOpen={isJourneyModalOpen}
                onClose={() => {
                    setIsJourneyModalOpen(false);
                    setSelectedPatient(null);
                }}
                patient={selectedPatient}
            />
        </RTDCPageLayout>
    );
};

export default ServiceHuddle;
