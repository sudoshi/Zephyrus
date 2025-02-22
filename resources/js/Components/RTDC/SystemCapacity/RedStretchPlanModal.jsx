import React from 'react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from "@/Components/ui/dialog";
import { Button } from "@/Components/ui/button";
import { Icon } from '@iconify/react';
import Textarea from "@/Components/ui/textarea";

const RedStretchPlanModal = ({ 
    isOpen, 
    onOpenChange, 
    unit, 
    onSave 
}) => {
    const AUTO_SAVE_DELAY = 3000; // 3 seconds
    const tabs = [
        { id: 'patientMovementPlan', label: 'Patient Movement', icon: 'lucide:users' },
        { id: 'temporaryMeasures', label: 'Support Measures', icon: 'lucide:clipboard-list' }
    ];

    const [plan, setPlan] = React.useState(() => ({
        title: unit?.redStretchPlan?.title || `${unit?.name} Patient Movement Plan`,
        patientMovementPlan: unit?.redStretchPlan?.patientMovementPlan || '',
        temporaryMeasures: unit?.redStretchPlan?.temporaryMeasures || '',
        lastUpdated: unit?.redStretchPlan?.lastUpdated || new Date().toISOString(),
        updatedBy: unit?.redStretchPlan?.updatedBy || ''
    }));
    
    const [activeTab, setActiveTab] = React.useState('patientMovementPlan');
    const [isSaving, setIsSaving] = React.useState(false);
    const [validationErrors, setValidationErrors] = React.useState({});
    const autoSaveTimeout = React.useRef(null);

    const calculateProgress = () => {
        const requiredFields = ['title', 'patientMovementPlan'];
        const completed = requiredFields.filter(field => {
            if (field === 'title') return plan.title?.trim().length > 0;
            return plan[field]?.trim().length >= 20;
        }).length;
        return (completed / requiredFields.length) * 100;
    };

    const validatePlan = () => {
        const errors = {};
        if (!plan.title?.trim()) {
            errors.title = 'Plan title is required';
        }
        if (!plan.patientMovementPlan?.trim() || plan.patientMovementPlan.trim().length < 20) {
            errors.patientMovementPlan = 'Detailed patient movement plan required (minimum 20 characters)';
        }
        setValidationErrors(errors);
        return Object.keys(errors).length === 0;
    };

    const handleSave = async () => {
        if (!unit?.id || !validatePlan()) return;
        
        setIsSaving(true);
        try {
            await onSave(unit.id, plan);
            onOpenChange(false);
        } catch (error) {
            console.error('Failed to save plan:', error);
        } finally {
            setIsSaving(false);
        }
    };

    const autoSave = React.useCallback(() => {
        if (unit?.id && validatePlan()) {
            onSave(unit.id, plan);
        }
    }, [plan, unit?.id, onSave]);

    const handleInputChange = (field) => (e) => {
        setPlan(prevPlan => ({...prevPlan, [field]: e.target.value}));
    };

    const getPlaceholderText = (field) => {
        switch(field) {
            case 'patientMovementPlan':
                return 'Specify patient movement details including:\n• Eligibility criteria\n• Receiving units\n• Transportation\n• Timeline\n• Responsible staff';
            case 'temporaryMeasures':
                return 'Optional supporting measures:\n• Staffing adjustments\n• Equipment allocation\n• Service priorities\n• Communication protocols';
            default:
                return '';
        }
    };

    React.useEffect(() => {
        if (autoSaveTimeout.current) {
            clearTimeout(autoSaveTimeout.current);
        }
        autoSaveTimeout.current = setTimeout(autoSave, AUTO_SAVE_DELAY);
        return () => {
            if (autoSaveTimeout.current) {
                clearTimeout(autoSaveTimeout.current);
            }
        };
    }, [plan, autoSave]);

    const progress = calculateProgress();

    return (
        <Dialog open={isOpen} onOpenChange={onOpenChange}>
            <DialogContent 
                className="max-w-4xl bg-healthcare-background dark:bg-healthcare-background-dark"
                onCloseAutoFocus={(e) => e.preventDefault()}
            >
                <DialogHeader className="border-b border-healthcare-border dark:border-healthcare-border-dark pb-4">
                    <DialogTitle className="text-xl font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark flex flex-wrap items-center justify-between gap-4">
                        <div className="flex items-center gap-2 min-w-[200px] flex-1">
                            <Icon icon="lucide:alert-circle" className="w-6 h-6 text-healthcare-critical dark:text-healthcare-critical-dark" />
                            <span className="truncate">{plan.title}</span>
                        </div>
                        <div className="flex items-center gap-4">
                            <div className="text-sm min-w-[150px]">
                                <div className="whitespace-nowrap">
                                    Completion: {Math.round(progress)}%
                                    {progress === 100 && ' ✓'}
                                </div>
                                <div className="w-32 h-2 bg-gray-200 rounded-full mt-1">
                                    <div 
                                        className="h-full bg-healthcare-primary rounded-full transition-all duration-300"
                                        style={{ width: `${progress}%` }}
                                    />
                                </div>
                            </div>
                        </div>
                    </DialogTitle>
                    <DialogDescription className="sr-only">
                        Red Stretch Plan configuration dialog for {unit?.name}
                    </DialogDescription>
                </DialogHeader>

                {/* Add Title Input */}
                <div className="mb-4">
                    <input
                        type="text"
                        value={plan.title}
                        onChange={(e) => setPlan(p => ({...p, title: e.target.value}))}
                        className="w-full text-xl font-semibold bg-transparent border-b border-healthcare-border focus:outline-none focus:border-healthcare-primary dark:focus:border-healthcare-primary-dark"
                        placeholder="Plan Title"
                    />
                    {validationErrors.title && (
                        <div className="text-sm text-healthcare-critical mt-1">
                            {validationErrors.title}
                        </div>
                    )}
                </div>

                {/* Tabs Navigation */}
                <div className="flex gap-2 border-b border-healthcare-border dark:border-healthcare-border-dark pb-2">
                    {tabs.map(tab => (
                        <button
                            key={tab.id}
                            onClick={() => setActiveTab(tab.id)}
                            className={`flex items-center gap-2 px-4 py-2 rounded-t-lg text-sm
                                ${activeTab === tab.id 
                                    ? 'bg-healthcare-primary/10 text-healthcare-primary dark:text-healthcare-primary-dark'
                                    : 'text-healthcare-text-secondary hover:bg-healthcare-background-dark/5'
                                }`}
                        >
                            <Icon icon={tab.icon} className="w-4 h-4" />
                            {tab.label}
                        </button>
                    ))}
                </div>

                {/* Active Tab Content */}
                <div className="mt-4 space-y-4">
                    <Textarea
                        value={plan[activeTab]}
                        onChange={handleInputChange(activeTab)}
                        placeholder={getPlaceholderText(activeTab)}
                        className="min-h-[300px] w-full text-healthcare-text-primary dark:text-healthcare-text-primary-dark"
                    />
                    {validationErrors[activeTab] && (
                        <div className="text-sm text-healthcare-critical dark:text-healthcare-critical-dark">
                            {activeTab === 'patientMovementPlan' ? (
                                <div>
                                    Please provide a detailed patient movement plan with:
                                    <ul className="list-disc pl-4 mt-1">
                                        <li>Eligibility criteria</li>
                                        <li>Transfer destinations</li>
                                        <li>Transportation plan</li>
                                        <li>Execution timeline</li>
                                    </ul>
                                </div>
                            ) : (
                                validationErrors[activeTab]
                            )}
                        </div>
                    )}
                </div>

                {/* Footer */}
                <div className="mt-6 pt-4 border-t border-healthcare-border dark:border-healthcare-border-dark">
                    <div className="flex justify-between items-center">
                        <span className="text-sm text-healthcare-text-secondary">
                            Last updated: {new Date(plan.lastUpdated).toLocaleDateString()}
                        </span>
                        <div className="flex gap-3">
                            <Button variant="outline" onClick={() => onOpenChange(false)}>
                                Cancel
                            </Button>
                            <Button 
                                className="bg-healthcare-primary hover:bg-healthcare-primary-dark text-white"
                                onClick={handleSave}
                                disabled={isSaving}
                            >
                                {isSaving ? 'Saving...' : 'Save Plan'}
                            </Button>
                        </div>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
};

export default RedStretchPlanModal;
