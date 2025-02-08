import React from 'react';
import { Icon } from '@iconify/react';
import Card from '@/Components/Dashboard/Card';

const PostDischargeRequirements = ({ requirements }) => {
    if (!requirements) return null;

    const RequirementSection = ({ title, icon, items, scheduled }) => (
        <div className="space-y-3">
            <div className="flex items-center justify-between">
                <div className="flex items-center gap-2">
                    <div className="p-2 bg-indigo-100 dark:bg-indigo-900 rounded-full">
                        <Icon icon={icon} className="w-5 h-5 text-indigo-600 dark:text-indigo-400" />
                    </div>
                    <h3 className="font-medium text-gray-900 dark:text-gray-100">{title}</h3>
                </div>
                {scheduled !== undefined && (
                    <span className={`px-2 py-1 text-xs font-medium rounded-full ${
                        scheduled 
                            ? 'bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-200'
                            : 'bg-yellow-100 dark:bg-yellow-900 text-yellow-800 dark:text-yellow-200'
                    }`}>
                        {scheduled ? 'Scheduled' : 'Pending'}
                    </span>
                )}
            </div>
            <div className="ml-9 space-y-2">
                {items.map((item, index) => (
                    <div 
                        key={index}
                        className="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-800 rounded-lg"
                    >
                        <div className="flex-1">
                            <div className="font-medium text-gray-900 dark:text-gray-100">
                                {item.specialty || item}
                            </div>
                            {item.timeframe && (
                                <div className="text-sm text-gray-500 dark:text-gray-400">
                                    Within {item.timeframe}
                                </div>
                            )}
                        </div>
                        {item.scheduled !== undefined && (
                            <div className={`flex items-center gap-2 text-sm ${
                                item.scheduled
                                    ? 'text-green-600 dark:text-green-400'
                                    : 'text-yellow-600 dark:text-yellow-400'
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
                    <div className="text-center py-3 text-gray-500 dark:text-gray-400">
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
                    <button className="text-sm text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300 flex items-center gap-1">
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
                                <div className="p-2 bg-indigo-100 dark:bg-indigo-900 rounded-full">
                                    <Icon 
                                        icon="heroicons:users" 
                                        className="w-5 h-5 text-indigo-600 dark:text-indigo-400" 
                                    />
                                </div>
                                <h3 className="font-medium text-gray-900 dark:text-gray-100">
                                    Caregiver Information
                                </h3>
                            </div>
                            <div className="ml-9 p-4 bg-gray-50 dark:bg-gray-800 rounded-lg space-y-3">
                                <div className="grid grid-cols-2 gap-4">
                                    <div>
                                        <div className="text-sm text-gray-500 dark:text-gray-400">Name</div>
                                        <div className="font-medium text-gray-900 dark:text-gray-100">
                                            {requirements.caregiverInfo.name}
                                        </div>
                                    </div>
                                    <div>
                                        <div className="text-sm text-gray-500 dark:text-gray-400">Relationship</div>
                                        <div className="font-medium text-gray-900 dark:text-gray-100">
                                            {requirements.caregiverInfo.relationship}
                                        </div>
                                    </div>
                                    <div>
                                        <div className="text-sm text-gray-500 dark:text-gray-400">Phone</div>
                                        <div className="font-medium text-gray-900 dark:text-gray-100">
                                            {requirements.caregiverInfo.phone}
                                        </div>
                                    </div>
                                    <div>
                                        <div className="text-sm text-gray-500 dark:text-gray-400">Education Status</div>
                                        <div className="flex items-center gap-2">
                                            <Icon 
                                                icon={requirements.caregiverInfo.educated ? 'heroicons:check-circle' : 'heroicons:x-circle'} 
                                                className={`w-5 h-5 ${
                                                    requirements.caregiverInfo.educated 
                                                        ? 'text-green-600 dark:text-green-400' 
                                                        : 'text-red-600 dark:text-red-400'
                                                }`} 
                                            />
                                            <span className="font-medium text-gray-900 dark:text-gray-100">
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
