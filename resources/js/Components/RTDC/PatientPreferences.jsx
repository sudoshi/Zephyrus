import React from 'react';
import { Icon } from '@iconify/react';
import Card from '@/Components/Dashboard/Card';

const PatientPreferences = ({ preferences }) => {
    if (!preferences) return null;

    const PreferenceItem = ({ icon, label, value, editable = true }) => (
        <div className="flex items-center justify-between p-3 bg-healthcare-background dark:bg-healthcare-background-dark rounded-lg">
            <div className="flex items-center gap-3">
                <div className="p-2 bg-healthcare-primary/10 dark:bg-healthcare-primary-dark/20 rounded-full">
                    <Icon icon={icon} className="w-5 h-5 text-healthcare-primary dark:text-healthcare-primary-dark" />
                </div>
                <div>
                    <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{label}</div>
                    <div className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{value}</div>
                </div>
            </div>
            {editable && (
                <button className="p-1 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark hover:text-healthcare-text-primary dark:hover:text-healthcare-text-primary-dark">
                    <Icon icon="heroicons:pencil-square" className="w-5 h-5" />
                </button>
            )}
        </div>
    );

    const RestrictionItem = ({ restriction }) => (
        <div className="flex items-center gap-2 px-3 py-2 bg-healthcare-critical/10 dark:bg-healthcare-critical/20 text-healthcare-critical dark:text-healthcare-critical-dark rounded-lg">
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
                        <button className="text-sm text-healthcare-primary dark:text-healthcare-primary-dark hover:opacity-80 flex items-center gap-1">
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
                        <button className="text-sm text-healthcare-primary dark:text-healthcare-primary-dark hover:opacity-80 flex items-center gap-1">
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
                        className="w-full rounded-md border-healthcare-border dark:border-healthcare-border-dark shadow-sm focus:border-healthcare-primary focus:ring-healthcare-primary"
                        placeholder="Add any special instructions or notes..."
                    />
                    <div className="mt-4 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                        Last updated: {new Date().toLocaleString()}
                    </div>
                </Card.Content>
            </Card>
        </div>
    );
};

export default PatientPreferences;
