import React from 'react';
import { Icon } from '@iconify/react';
import Card from '@/Components/Dashboard/Card';

const DischargeReadinessScore = ({ requirements }) => {
    if (!requirements) return null;

    const calculateScore = () => {
        let total = 0;
        let completed = 0;

        // Clinical Criteria
        const clinicalItems = Object.values(requirements.clinicalCriteria);
        total += clinicalItems.length;
        completed += clinicalItems.filter(Boolean).length;

        // Transportation
        total += 1;
        if (requirements.transportation.arranged) completed += 1;

        // Instructions
        const instructionItems = Object.values(requirements.instructions);
        total += instructionItems.length;
        completed += instructionItems.filter(Boolean).length;

        // Alternative Pathways
        if (requirements.alternativePathways) {
            // Hospital at Home
            if (requirements.alternativePathways.hospitalAtHome?.isEligible) {
                total += 1;
                if (requirements.alternativePathways.hospitalAtHome.hasConsented) completed += 1;
            }

            // CAD Arena
            if (requirements.alternativePathways.cadArena?.isEligible) {
                total += 2; // +1 for consent, +1 for unit selection
                if (requirements.alternativePathways.cadArena.hasConsented) completed += 1;
                if (requirements.alternativePathways.cadArena.preferredUnit) completed += 1;
            }
        }

        return {
            score: Math.round((completed / total) * 100),
            completed,
            total
        };
    };

    const score = calculateScore();

    const getScoreColor = (score) => {
        if (score >= 80) return 'text-green-600 dark:text-green-400';
        if (score >= 50) return 'text-yellow-600 dark:text-yellow-400';
        return 'text-red-600 dark:text-red-400';
    };

    const CriteriaGroup = ({ title, items, icon }) => (
        <div className="space-y-3">
            <div className="flex items-center gap-2">
                <Icon icon={icon} className="w-5 h-5 text-gray-500 dark:text-gray-400" />
                <h3 className="font-medium text-gray-900 dark:text-gray-100">{title}</h3>
            </div>
            <div className="space-y-2 ml-7">
                {Object.entries(items).map(([key, value]) => (
                    <div key={key} className="flex items-center gap-2">
                        <div className={`w-5 h-5 rounded-full flex items-center justify-center ${
                            value 
                                ? 'bg-green-100 dark:bg-green-900 text-green-600 dark:text-green-400'
                                : 'bg-gray-100 dark:bg-gray-900 text-gray-400 dark:text-gray-600'
                        }`}>
                            <Icon 
                                icon={value ? 'heroicons:check' : 'heroicons:minus-small'} 
                                className="w-4 h-4" 
                            />
                        </div>
                        <span className="text-sm text-gray-700 dark:text-gray-300">
                            {key.split(/(?=[A-Z])/).join(' ')}
                        </span>
                    </div>
                ))}
            </div>
        </div>
    );

    const AlternativePathwayStatus = ({ pathway, title, icon }) => {
        if (!pathway?.isEligible) return null;

        return (
            <div className="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                <div className="flex items-center gap-3">
                    <div className={`p-2 rounded-lg ${
                        pathway.hasConsented
                            ? 'bg-green-100 dark:bg-green-900 text-green-600 dark:text-green-400'
                            : 'bg-yellow-100 dark:bg-yellow-900 text-yellow-600 dark:text-yellow-400'
                    }`}>
                        <Icon icon={icon} className="w-5 h-5" />
                    </div>
                    <div>
                        <div className="font-medium text-gray-900 dark:text-gray-100">{title}</div>
                        <div className="text-sm text-gray-500 dark:text-gray-400">
                            {pathway.hasConsented ? 'Patient has consented' : 'Awaiting patient consent'}
                        </div>
                    </div>
                </div>
                {pathway.preferredUnit && (
                    <div className="text-sm text-gray-500 dark:text-gray-400">
                        Unit: {pathway.preferredUnit}
                    </div>
                )}
            </div>
        );
    };

    return (
        <Card>
            <Card.Header>
                <div className="flex justify-between items-center">
                    <Card.Title>Discharge Readiness</Card.Title>
                    <div className={`text-2xl font-bold ${getScoreColor(score.score)}`}>
                        {score.score}%
                    </div>
                </div>
            </Card.Header>
            <Card.Content>
                <div className="mb-6">
                    <div className="h-2 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
                        <div 
                            className={`h-full transition-all duration-500 ${
                                score.score >= 80 
                                    ? 'bg-green-500' 
                                    : score.score >= 50 
                                        ? 'bg-yellow-500' 
                                        : 'bg-red-500'
                            }`}
                            style={{ width: `${score.score}%` }}
                        />
                    </div>
                    <div className="mt-2 text-sm text-gray-500 dark:text-gray-400 text-center">
                        {score.completed} of {score.total} criteria met
                    </div>
                </div>

                <div className="space-y-6">
                    {requirements.alternativePathways && (
                        <div className="space-y-3">
                            <h3 className="font-medium text-gray-900 dark:text-gray-100">Alternative Pathways</h3>
                            <div className="space-y-2">
                                <AlternativePathwayStatus 
                                    pathway={requirements.alternativePathways.hospitalAtHome}
                                    title="Hospital at Home"
                                    icon="heroicons:home"
                                />
                                <AlternativePathwayStatus 
                                    pathway={requirements.alternativePathways.cadArena}
                                    title="CAD Arena"
                                    icon="heroicons:building-office-2"
                                />
                            </div>
                        </div>
                    )}

                    <CriteriaGroup 
                        title="Clinical Criteria"
                        items={requirements.clinicalCriteria}
                        icon="heroicons:heart"
                    />
                    <CriteriaGroup 
                        title="Patient Instructions"
                        items={requirements.instructions}
                        icon="heroicons:document-text"
                    />
                    <div className="space-y-3">
                        <div className="flex items-center gap-2">
                            <Icon icon="heroicons:truck" className="w-5 h-5 text-gray-500 dark:text-gray-400" />
                            <h3 className="font-medium text-gray-900 dark:text-gray-100">Transportation</h3>
                        </div>
                        <div className="ml-7 space-y-2">
                            <div className="flex items-center gap-2">
                                <div className={`w-5 h-5 rounded-full flex items-center justify-center ${
                                    requirements.transportation.arranged
                                        ? 'bg-green-100 dark:bg-green-900 text-green-600 dark:text-green-400'
                                        : 'bg-gray-100 dark:bg-gray-900 text-gray-400 dark:text-gray-600'
                                }`}>
                                    <Icon 
                                        icon={requirements.transportation.arranged ? 'heroicons:check' : 'heroicons:minus-small'} 
                                        className="w-4 h-4" 
                                    />
                                </div>
                                <span className="text-sm text-gray-700 dark:text-gray-300">
                                    {requirements.transportation.arranged ? 'Arranged' : 'Not Arranged'}
                                    {requirements.transportation.type && ` (${requirements.transportation.type})`}
                                </span>
                            </div>
                            {requirements.transportation.notes && (
                                <p className="text-sm text-gray-500 dark:text-gray-400 ml-7">
                                    {requirements.transportation.notes}
                                </p>
                            )}
                        </div>
                    </div>
                </div>
            </Card.Content>
        </Card>
    );
};

export default DischargeReadinessScore;
