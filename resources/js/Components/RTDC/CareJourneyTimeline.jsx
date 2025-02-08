import React from 'react';
import { Icon } from '@iconify/react';

const CareJourneyTimeline = ({ patient }) => {
    // Calculate progress percentage based on admission date and estimated discharge date
    const calculateProgress = () => {
        if (!patient?.admitDate || !patient?.dischargePlan?.estimatedDischargeDate) return 0;
        
        const admitDate = new Date(patient.admitDate);
        const dischargeDate = new Date(patient.dischargePlan.estimatedDischargeDate);
        const currentDate = new Date();
        
        const totalDuration = dischargeDate - admitDate;
        const currentDuration = currentDate - admitDate;
        
        const progress = (currentDuration / totalDuration) * 100;
        return Math.min(Math.max(progress, 0), 100); // Clamp between 0 and 100
    };

    // Get remaining days until discharge
    const getRemainingDays = () => {
        if (!patient?.dischargePlan?.estimatedDischargeDate) return 0;
        
        const dischargeDate = new Date(patient.dischargePlan.estimatedDischargeDate);
        const currentDate = new Date();
        const diffTime = Math.abs(dischargeDate - currentDate);
        const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
        
        return diffDays;
    };

    // Get status color based on progress
    const getStatusColor = (progress) => {
        if (progress >= 90) return 'text-healthcare-critical';
        if (progress >= 75) return 'text-healthcare-warning';
        return 'text-healthcare-success';
    };

    const progress = calculateProgress();
    const remainingDays = getRemainingDays();
    const statusColor = getStatusColor(progress);

    return (
        <div className="flex flex-col h-full">
            {/* Timeline Header */}
            <div className="flex items-center justify-between mb-2">
                <div className="flex items-center gap-2">
                    <Icon 
                        icon="heroicons:clock" 
                        className={`w-5 h-5 ${statusColor}`}
                    />
                    <span className="text-sm font-medium">Care Journey Progress</span>
                </div>
                <span className="text-sm text-gray-500">
                    {remainingDays} days until discharge
                </span>
            </div>

            {/* Timeline Bar */}
            <div className="relative pt-1">
                <div className="flex mb-2 items-center justify-between">
                    <div>
                        <span className="text-xs font-semibold inline-block py-1 px-2 uppercase rounded-full bg-healthcare-success/10 text-healthcare-success">
                            Admission
                        </span>
                    </div>
                    <div className="text-right">
                        <span className="text-xs font-semibold inline-block py-1 px-2 uppercase rounded-full bg-healthcare-critical/10 text-healthcare-critical">
                            Discharge
                        </span>
                    </div>
                </div>
                <div className="overflow-hidden h-2 mb-4 text-xs flex rounded bg-gray-200 dark:bg-gray-700">
                    <div
                        style={{ width: `${progress}%` }}
                        className="shadow-none flex flex-col text-center whitespace-nowrap text-white justify-center bg-healthcare-primary transition-all duration-500"
                    />
                </div>
                <div className="flex justify-between text-xs text-gray-500">
                    <span>{new Date(patient?.admitDate).toLocaleDateString()}</span>
                    <span>{new Date(patient?.dischargePlan?.estimatedDischargeDate).toLocaleDateString()}</span>
                </div>
            </div>

            {/* Milestones */}
            <div className="mt-4">
                <h4 className="text-sm font-medium mb-2">Key Milestones</h4>
                <div className="space-y-2">
                    {[
                        { label: 'Initial Assessment', completed: true, date: '2024-02-05' },
                        { label: 'Care Plan Established', completed: true, date: '2024-02-06' },
                        { label: 'Treatment Phase', completed: false, date: '2024-02-07' },
                        { label: 'Discharge Planning', completed: false, date: '2024-02-08' }
                    ].map((milestone, index) => (
                        <div 
                            key={index}
                            className="flex items-center gap-2 text-sm"
                        >
                            <Icon 
                                icon={milestone.completed ? 'heroicons:check-circle' : 'heroicons:clock'}
                                className={`w-5 h-5 ${milestone.completed ? 'text-healthcare-success' : 'text-gray-400'}`}
                            />
                            <span className={milestone.completed ? 'text-gray-900 dark:text-gray-100' : 'text-gray-500'}>
                                {milestone.label}
                            </span>
                            <span className="text-xs text-gray-500 ml-auto">
                                {new Date(milestone.date).toLocaleDateString()}
                            </span>
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
};

export default CareJourneyTimeline;
