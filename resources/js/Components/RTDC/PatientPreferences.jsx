import React from 'react';
import { Icon } from '@iconify/react';
import Card from '@/Components/Dashboard/Card';

const PatientPreferences = ({ preferences }) => {
    if (!preferences) return null;

    const PreferenceItem = ({ icon, label, value, editable = true }) => (
        <div className="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
            <div className="flex items-center gap-3">
                <div className="p-2 bg-indigo-100 dark:bg-indigo-900 rounded-full">
                    <Icon icon={icon} className="w-5 h-5 text-indigo-600 dark:text-indigo-400" />
                </div>
                <div>
                    <div className="text-sm text-gray-500 dark:text-gray-400">{label}</div>
                    <div className="font-medium text-gray-900 dark:text-gray-100">{value}</div>
                </div>
            </div>
            {editable && (
                <button className="p-1 text-gray-400 hover:text-gray-500 dark:hover:text-gray-300">
                    <Icon icon="heroicons:pencil-square" className="w-5 h-5" />
                </button>
            )}
        </div>
    );

    const RestrictionItem = ({ restriction }) => (
        <div className="flex items-center gap-2 px-3 py-2 bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-300 rounded-lg">
            <Icon icon="heroicons:exclamation-triangle" className="w-5 h-5" />
            <span className="text-sm font-medium">{restriction}</span>
        </div>
    );

    return (
        <div className="space-y-6">
            {/* Preferences */}
            <Card>
                <Card.Header>
                    <div className="flex justify-between items-center">
                        <Card.Title>Patient Preferences</Card.Title>
                        <button className="text-sm text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300 flex items-center gap-1">
                            <Icon icon="heroicons:plus" className="w-4 h-4" />
                            Add Preference
                        </button>
                    </div>
                </Card.Header>
                <Card.Content>
                    <div className="space-y-3">
                        <PreferenceItem
                            icon="heroicons:cake"
                            label="Dietary Preference"
                            value={preferences.diet}
                        />
                        <PreferenceItem
                            icon="heroicons:user-group"
                            label="Communication"
                            value={preferences.communication}
                        />
                        <PreferenceItem
                            icon="heroicons:arrow-path"
                            label="Mobility Preference"
                            value={preferences.mobility}
                        />
                    </div>
                </Card.Content>
            </Card>

            {/* Restrictions */}
            <Card>
                <Card.Header>
                    <div className="flex justify-between items-center">
                        <Card.Title>Restrictions & Precautions</Card.Title>
                        <button className="text-sm text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300 flex items-center gap-1">
                            <Icon icon="heroicons:plus" className="w-4 h-4" />
                            Add Restriction
                        </button>
                    </div>
                </Card.Header>
                <Card.Content>
                    <div className="space-y-2">
                        {[
                            "NPO after midnight",
                            "Fall precautions",
                            "No blood products"
                        ].map((restriction, index) => (
                            <RestrictionItem key={index} restriction={restriction} />
                        ))}
                    </div>
                </Card.Content>
            </Card>

            {/* Special Instructions */}
            <Card>
                <Card.Header>
                    <Card.Title>Special Instructions</Card.Title>
                </Card.Header>
                <Card.Content>
                    <textarea
                        rows="4"
                        className="w-full rounded-md border-gray-300 dark:border-gray-600 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        placeholder="Add any special instructions or notes..."
                    />
                    <div className="mt-4 text-sm text-gray-500 dark:text-gray-400">
                        Last updated: {new Date().toLocaleString()}
                    </div>
                </Card.Content>
            </Card>
        </div>
    );
};

export default PatientPreferences;
