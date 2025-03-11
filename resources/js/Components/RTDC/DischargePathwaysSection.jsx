import React from 'react';
import { Icon } from '@iconify/react';
import Card from '@/Components/Dashboard/Card';
import { useDarkMode } from '@/hooks/useDarkMode';

const PathwayCard = ({ 
    title, 
    icon, 
    description, 
    isEligible, 
    hasConsented, 
    eligibilityNotes,
    assessedBy,
    assessedAt,
    onUpdateEligibility,
    onUpdateConsent,
    onUpdateNotes,
    additionalFields = null
}) => {
    const [isDarkMode] = useDarkMode();

    return (
        <div className={`p-4 rounded-lg border ${
            isDarkMode 
                ? 'bg-gray-800 border-gray-700' 
                : 'bg-white border-gray-200'
        }`}>
            <div className="flex items-start gap-4">
                <div className={`p-2 rounded-lg ${
                    isEligible 
                        ? 'bg-green-100 dark:bg-green-900 text-green-600 dark:text-green-400'
                        : 'bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400'
                }`}>
                    <Icon icon={icon} className="w-6 h-6" />
                </div>
                <div className="flex-1 min-w-0">
                    <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-100">
                        {title}
                    </h3>
                    <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        {description}
                    </p>
                </div>
            </div>

            <div className="mt-4 space-y-4">
                <div className="flex flex-wrap gap-4">
                    <div className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            id={`${title}-eligible`}
                            checked={isEligible}
                            onChange={(e) => onUpdateEligibility(e.target.checked)}
                            className="h-4 w-4 rounded border-gray-300 dark:border-gray-600 text-indigo-600 focus:ring-indigo-500"
                        />
                        <label 
                            htmlFor={`${title}-eligible`}
                            className="text-sm font-medium text-gray-700 dark:text-gray-300"
                        >
                            Eligible
                        </label>
                    </div>
                    {isEligible && (
                        <div className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                id={`${title}-consent`}
                                checked={hasConsented}
                                onChange={(e) => onUpdateConsent(e.target.checked)}
                                className="h-4 w-4 rounded border-gray-300 dark:border-gray-600 text-indigo-600 focus:ring-indigo-500"
                            />
                            <label 
                                htmlFor={`${title}-consent`}
                                className="text-sm font-medium text-gray-700 dark:text-gray-300"
                            >
                                Patient Consented
                            </label>
                        </div>
                    )}
                </div>

                {additionalFields}

                <div>
                    <label 
                        htmlFor={`${title}-notes`}
                        className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"
                    >
                        Assessment Notes
                    </label>
                    <textarea
                        id={`${title}-notes`}
                        value={eligibilityNotes}
                        onChange={(e) => onUpdateNotes(e.target.value)}
                        rows={3}
                        className="w-full rounded-md border-gray-300 dark:border-gray-600 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:bg-gray-700 dark:text-gray-100"
                        placeholder="Enter assessment details..."
                    />
                </div>

                {(assessedBy || assessedAt) && (
                    <div className="text-sm text-gray-500 dark:text-gray-400">
                        Last assessed by {assessedBy} at {new Date(assessedAt).toLocaleString()}
                    </div>
                )}
            </div>
        </div>
    );
};

const DischargePathwaysSection = ({ 
    alternativePathways,
    onUpdatePathways,
    availableUnits = []
}) => {
    const handleUpdateHospitalAtHome = (updates) => {
        onUpdatePathways({
            ...alternativePathways,
            hospitalAtHome: {
                ...alternativePathways.hospitalAtHome,
                ...updates,
                assessedAt: new Date().toISOString()
            }
        });
    };

    const handleUpdateCAD = (updates) => {
        onUpdatePathways({
            ...alternativePathways,
            cadArena: {
                ...alternativePathways.cadArena,
                ...updates,
                assessedAt: new Date().toISOString()
            }
        });
    };

    return (
        <Card>
            <Card.Header>
                <Card.Title>Alternative Care Pathways</Card.Title>
            </Card.Header>
            <Card.Content>
                <div className="space-y-6">
                    <PathwayCard
                        title="Hospital at Home"
                        icon="heroicons:home"
                        description="Eligible patients can receive hospital-level care in their home environment with remote monitoring and regular clinical visits."
                        isEligible={alternativePathways.hospitalAtHome.isEligible}
                        hasConsented={alternativePathways.hospitalAtHome.hasConsented}
                        eligibilityNotes={alternativePathways.hospitalAtHome.eligibilityNotes}
                        assessedBy={alternativePathways.hospitalAtHome.assessedBy}
                        assessedAt={alternativePathways.hospitalAtHome.assessedAt}
                        onUpdateEligibility={(isEligible) => handleUpdateHospitalAtHome({ isEligible })}
                        onUpdateConsent={(hasConsented) => handleUpdateHospitalAtHome({ hasConsented })}
                        onUpdateNotes={(eligibilityNotes) => handleUpdateHospitalAtHome({ eligibilityNotes })}
                    />

                    <PathwayCard
                        title="Care After Discharge (CAD) Arena"
                        icon="heroicons:building-office-2"
                        description="Patients awaiting final discharge arrangements can be transferred to a dedicated CAD unit to optimize bed utilization."
                        isEligible={alternativePathways.cadArena.isEligible}
                        hasConsented={alternativePathways.cadArena.hasConsented}
                        eligibilityNotes={alternativePathways.cadArena.eligibilityNotes}
                        assessedBy={alternativePathways.cadArena.assessedBy}
                        assessedAt={alternativePathways.cadArena.assessedAt}
                        onUpdateEligibility={(isEligible) => handleUpdateCAD({ isEligible })}
                        onUpdateConsent={(hasConsented) => handleUpdateCAD({ hasConsented })}
                        onUpdateNotes={(eligibilityNotes) => handleUpdateCAD({ eligibilityNotes })}
                        additionalFields={
                            alternativePathways.cadArena.isEligible && (
                                <div>
                                    <label 
                                        htmlFor="preferred-unit"
                                        className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"
                                    >
                                        Preferred CAD Unit
                                    </label>
                                    <select
                                        id="preferred-unit"
                                        value={alternativePathways.cadArena.preferredUnit || ''}
                                        onChange={(e) => handleUpdateCAD({ preferredUnit: e.target.value })}
                                        className="w-full rounded-md border-gray-300 dark:border-gray-600 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:bg-gray-700 dark:text-gray-100"
                                    >
                                        <option value="">Select a unit</option>
                                        {availableUnits.map((unit) => (
                                            <option key={unit} value={unit}>
                                                {unit}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                            )
                        }
                    />
                </div>
            </Card.Content>
        </Card>
    );
};

export default DischargePathwaysSection;
