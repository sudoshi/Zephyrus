import React from 'react';
import { Icon } from '@iconify/react';
import Card from '@/Components/Dashboard/Card';

const PostDischargeRequirements = ({ requirements }) => {
    if (!requirements) return null;

    const RequirementSection = ({ title, icon, items, scheduled }) => (
        <div className="space-y-3">
            <div className="flex items-center justify-between">
                <div className="flex items-center gap-2">
                    <div className="p-2 bg-healthcare-primary/10 dark:bg-healthcare-primary-dark/20 rounded-full">
                        <Icon icon={icon} className="w-5 h-5 text-healthcare-primary dark:text-healthcare-primary-dark" />
                    </div>
                    <h3 className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{title}</h3>
                </div>
                {scheduled !== undefined && (
                    <span className={`px-2 py-1 text-xs font-medium rounded-full ${
                        scheduled
                            ? 'bg-healthcare-success/10 dark:bg-healthcare-success-dark/20 text-healthcare-success dark:text-healthcare-success-dark'
                            : 'bg-healthcare-warning/10 dark:bg-healthcare-warning-dark/20 text-healthcare-warning dark:text-healthcare-warning-dark'
                    }`}>
                        {scheduled ? 'Scheduled' : 'Pending'}
                    </span>
                )}
            </div>
            <div className="ml-9 space-y-2">
                {items.map((item, index) => (
                    <div 
                        key={index}
                        className="flex items-center justify-between p-3 bg-healthcare-background dark:bg-healthcare-background-dark rounded-lg"
                    >
                        <div className="flex-1">
                            <div className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                {item.specialty || item}
                            </div>
                            {item.timeframe && (
                                <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                    Within {item.timeframe}
                                </div>
                            )}
                        </div>
                        {item.scheduled !== undefined && (
                            <div className={`flex items-center gap-2 text-sm ${
                                item.scheduled
                                    ? 'text-healthcare-success dark:text-healthcare-success-dark'
                                    : 'text-healthcare-warning dark:text-healthcare-warning-dark'
                            }`}>
                                <Icon 
                                    icon={item.scheduled ? 'heroicons:check-circle' : 'heroicons:clock'} 
                                    className="w-5 h-5" 
                                />
                                {item.scheduled ? 'Confirmed' : 'Pending'}
                            </div>
                        )}
                    </div>
                ))}
                {items.length === 0 && (
                    <div className="text-center py-3 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                        No requirements specified
                    </div>
                )}
            </div>
        </div>
    );

    return (
        <Card>
            <Card.Header>
                <div className="flex justify-between items-center">
                    <Card.Title>Post-Discharge Requirements</Card.Title>
                    <button className="text-sm text-healthcare-primary dark:text-healthcare-primary-dark hover:opacity-80 flex items-center gap-1">
                        <Icon icon="heroicons:plus" className="w-4 h-4" />
                        Add Requirement
                    </button>
                </div>
            </Card.Header>
            <Card.Content>
                <div className="space-y-6">
                    <RequirementSection
                        title="Follow-up Appointments"
                        icon="heroicons:calendar"
                        items={requirements.followUp}
                    />
                    
                    <RequirementSection
                        title="Medical Equipment"
                        icon="heroicons:cube"
                        items={requirements.equipment}
                    />
                    
                    <RequirementSection
                        title="Home Care Services"
                        icon="heroicons:home"
                        items={requirements.services}
                    />

                    {requirements.caregiverInfo && (
                        <div className="space-y-3">
                            <div className="flex items-center gap-2">
                                <div className="p-2 bg-healthcare-primary/10 dark:bg-healthcare-primary-dark/20 rounded-full">
                                    <Icon 
                                        icon="heroicons:users" 
                                        className="w-5 h-5 text-healthcare-primary dark:text-healthcare-primary-dark" 
                                    />
                                </div>
                                <h3 className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                    Caregiver Information
                                </h3>
                            </div>
                            <div className="ml-9 p-4 bg-healthcare-background dark:bg-healthcare-background-dark rounded-lg space-y-3">
                                <div className="grid grid-cols-2 gap-4">
                                    <div>
                                        <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Name</div>
                                        <div className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                            {requirements.caregiverInfo.name}
                                        </div>
                                    </div>
                                    <div>
                                        <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Relationship</div>
                                        <div className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                            {requirements.caregiverInfo.relationship}
                                        </div>
                                    </div>
                                    <div>
                                        <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Phone</div>
                                        <div className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                            {requirements.caregiverInfo.phone}
                                        </div>
                                    </div>
                                    <div>
                                        <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Education Status</div>
                                        <div className="flex items-center gap-2">
                                            <Icon 
                                                icon={requirements.caregiverInfo.educated ? 'heroicons:check-circle' : 'heroicons:x-circle'} 
                                                className={`w-5 h-5 ${
                                                    requirements.caregiverInfo.educated
                                                        ? 'text-healthcare-success dark:text-healthcare-success-dark'
                                                        : 'text-healthcare-critical dark:text-healthcare-critical-dark'
                                                }`}
                                            />
                                            <span className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                {requirements.caregiverInfo.educated ? 'Completed' : 'Pending'}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}
                </div>
            </Card.Content>
        </Card>
    );
};

export default PostDischargeRequirements;
