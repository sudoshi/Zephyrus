import React, { useState, useEffect } from 'react';
import { Icon } from '@iconify/react';
import { Card, CardHeader, CardTitle, CardContent } from '@/Components/ui/Card';
import LineChart from '@/Components/Dashboard/Charts/LineChart';
import VitalSignsChart from '@/Components/RTDC/VitalSignsChart';
import QuickStatsPanel from '@/Components/RTDC/QuickStatsPanel';
import CareTeamTimeline from '@/Components/RTDC/CareTeamTimeline';
import MedicationAdministrationRecord from '@/Components/RTDC/MedicationAdministrationRecord';
import CareGoalsSection from '@/Components/RTDC/CareGoalsSection';
import InterventionsChecklist from '@/Components/RTDC/InterventionsChecklist';
import PatientPreferences from '@/Components/RTDC/PatientPreferences';
import TaskTemplates from '@/Components/RTDC/TaskTemplates';
import TaskPriorityMatrix from '@/Components/RTDC/TaskPriorityMatrix';
import TaskCompletionHistory from '@/Components/RTDC/TaskCompletionHistory';
import DischargeReadinessScore from '@/Components/RTDC/DischargeReadinessScore';
import DischargeChecklistTimeline from '@/Components/RTDC/DischargeChecklistTimeline';
import PostDischargeRequirements from '@/Components/RTDC/PostDischargeRequirements';
import DischargePathwaysSection from '@/Components/RTDC/DischargePathwaysSection';
import { useDarkMode } from '@/hooks/useDarkMode';
import { UNITS } from '@/mock-data/rtdc-service-huddle-constants';

const StatusUpdateModal = ({ isOpen, onClose, patient, onSave }) => {
    const [isDarkMode] = useDarkMode();
    const [activeTab, setActiveTab] = useState('overview');
    const [tasks, setTasks] = useState([]);
    const [newTask, setNewTask] = useState('');
    const [barriers, setBarriers] = useState([]);
    const [newBarrier, setNewBarrier] = useState('');
    const [dischargePlan, setDischargePlan] = useState({
        responsiblePerson: '',
        targetTime: '',
        predictedBy2PM: 'No',
        estimatedDischargeDate: ''
    });
    const [clinicalStatus, setClinicalStatus] = useState({
        vitalTrend: '',
        painLevel: 0,
        lastAssessment: ''
    });
    const [dischargeRequirements, setDischargeRequirements] = useState({
        clinicalCriteria: {
            vitalsSatisfied: false,
            medicationReconciled: false,
            followUpScheduled: false
        },
        transportation: {
            arranged: false,
            type: '',
            notes: ''
        },
        instructions: {
            medicationReviewed: false,
            dietaryReviewed: false,
            followUpReviewed: false
        }
    });

    useEffect(() => {
        if (patient) {
            setTasks(patient.tasks || []);
            setBarriers(patient.dischargePlan.dischargeBarriers || []);
            setDischargePlan(patient.dischargePlan);
            setClinicalStatus(patient.clinicalStatus);
            setDischargeRequirements(patient.dischargeRequirements);
        }
    }, [patient]);

    const handleAddTask = () => {
        if (newTask.trim()) {
            setTasks([
                ...tasks,
                {
                    id: Date.now(),
                    text: newTask.trim(),
                    completed: false,
                    category: 'Clinical',
                    priority: 'Medium',
                    assignedTo: patient?.assignedNurse,
                    dueDate: new Date(Date.now() + 24 * 60 * 60 * 1000).toISOString()
                }
            ]);
            setNewTask('');
        }
    };

    const handleTaskToggle = (taskId) => {
        setTasks(tasks.map(task =>
            task.id === taskId ? { ...task, completed: !task.completed } : task
        ));
    };

    const handleAddBarrier = () => {
        if (newBarrier.trim()) {
            setBarriers([...barriers, newBarrier.trim()]);
            setNewBarrier('');
        }
    };

    const handleRemoveBarrier = (index) => {
        setBarriers(barriers.filter((_, i) => i !== index));
    };

    const handleSave = () => {
        onSave({
            tasks,
            dischargePlan: {
                ...dischargePlan,
                dischargeBarriers: barriers
            },
            clinicalStatus,
            dischargeRequirements,
            timestamp: new Date().toISOString()
        });
        onClose();
    };

    const TabButton = ({ id, label, icon }) => (
        <button
            className={`flex items-center gap-2 px-4 py-2 rounded-t-lg ${
                activeTab === id
                    ? 'bg-indigo-600 text-white'
                    : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700'
            }`}
            onClick={() => setActiveTab(id)}
        >
            <Icon icon={icon} className="w-5 h-5" />
            {label}
        </button>
    );

    const StatusPill = ({ label, status, color }) => (
        <span className={`px-3 py-1 rounded-full text-sm font-medium ${color}`}>
            {label}: {status}
        </span>
    );

    const Checkbox = ({ label, checked, onChange }) => (
        <label className="flex items-center gap-2 cursor-pointer">
            <input
                type="checkbox"
                checked={checked}
                onChange={onChange}
                className="rounded border-gray-300 dark:border-gray-600 text-indigo-600 focus:ring-indigo-500 h-4 w-4"
            />
            <span className="text-sm text-gray-700 dark:text-gray-200">{label}</span>
        </label>
    );

    if (!isOpen) return null;

    return (
        <div className="fixed inset-0 z-50 overflow-y-auto">
            {/* Backdrop */}
            <div className="fixed inset-0 backdrop-blur-sm bg-black/30 transition-opacity" />

            {/* Modal */}
            <div className="flex min-h-screen items-center justify-center p-4">
                <div className={`relative w-full max-w-6xl ${isDarkMode ? 'bg-gray-800' : 'bg-white'} rounded-xl shadow-2xl transform transition-all`}>
                    {/* Header */}
                    <div className="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                        <div className="flex items-center justify-between">
                            <div className="flex items-center space-x-3">
                                <Icon 
                                    icon="heroicons:document-text" 
                                    className="w-6 h-6 text-indigo-600 dark:text-indigo-400" 
                                />
                                <h2 className="text-xl font-semibold text-gray-900 dark:text-gray-100">
                                    Status Update
                                    {patient && (
                                        <span className="text-sm font-normal text-gray-500 dark:text-gray-400 ml-2">
                                            {patient.name} - Room {patient.room}
                                        </span>
                                    )}
                                </h2>
                            </div>
                            <button
                                onClick={onClose}
                                className="text-gray-400 hover:text-gray-500 dark:hover:text-gray-300 transition-colors"
                            >
                                <span className="sr-only">Close</span>
                                <Icon icon="heroicons:x-mark" className="w-6 h-6" />
                            </button>
                        </div>

                        <div className="mt-2 flex gap-2">
                            <StatusPill 
                                label="Mobility"
                                status={patient?.careJourney.mobility}
                                color="bg-healthcare-success/20 text-healthcare-success"
                            />
                            <StatusPill 
                                label="Care Level"
                                status={patient?.careJourney.phase}
                                color="bg-healthcare-primary/20 text-healthcare-primary"
                            />
                            <StatusPill 
                                label="Vital Trend"
                                status={clinicalStatus.vitalTrend}
                                color="bg-healthcare-warning/20 text-healthcare-warning"
                            />
                        </div>
                    </div>

                    {/* Content */}
                    <div className="p-6">
                        {/* Tabs */}
                        <div className="flex gap-2 mb-4 border-b border-gray-200 dark:border-gray-700">
                            <TabButton id="overview" label="Overview" icon="heroicons:chart-bar" />
                            <TabButton id="carePlan" label="Care Plan" icon="heroicons:clipboard-document-check" />
                            <TabButton id="tasks" label="Tasks & Services" icon="heroicons:clipboard-document-list" />
                            <TabButton id="discharge" label="Discharge Planning" icon="heroicons:arrow-right-on-rectangle" />
                            <TabButton id="barriers" label="Barriers & Notes" icon="heroicons:exclamation-triangle" />
                        </div>

                        {/* Tab Content */}
                        <div className="space-y-6">
                        {activeTab === 'discharge' && (
                            <div className="space-y-6">
                                <DischargePathwaysSection 
                                    alternativePathways={patient?.dischargePlan.alternativePathways}
                                    onUpdatePathways={(pathways) => {
                                        setDischargePlan(prev => ({
                                            ...prev,
                                            alternativePathways: pathways
                                        }));
                                    }}
                                    availableUnits={UNITS}
                                />
                                <DischargeReadinessScore 
                                    requirements={{
                                        ...patient?.dischargeRequirements,
                                        alternativePathways: patient?.dischargePlan.alternativePathways
                                    }} 
                                />
                                <PostDischargeRequirements requirements={patient?.dischargePlan.postDischargeNeeds} />
                                <DischargeChecklistTimeline 
                                    milestones={[
                                        ...patient?.dischargePlan.journeyMilestones,
                                        ...(patient?.dischargePlan.alternativePathways?.hospitalAtHome?.isEligible ? [{
                                            id: 'hah-assessment',
                                            type: 'milestone',
                                            title: 'Hospital at Home Assessment',
                                            time: new Date(patient?.dischargePlan.alternativePathways.hospitalAtHome.assessedAt).toLocaleTimeString(),
                                            date: new Date(patient?.dischargePlan.alternativePathways.hospitalAtHome.assessedAt).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }),
                                            description: `Assessed by ${patient?.dischargePlan.alternativePathways.hospitalAtHome.assessedBy}`,
                                            isAlert: !patient?.dischargePlan.alternativePathways.hospitalAtHome.hasConsented
                                        }] : []),
                                        ...(patient?.dischargePlan.alternativePathways?.cadArena?.isEligible ? [{
                                            id: 'cad-assessment',
                                            type: 'milestone',
                                            title: 'CAD Arena Assessment',
                                            time: new Date(patient?.dischargePlan.alternativePathways.cadArena.assessedAt).toLocaleTimeString(),
                                            date: new Date(patient?.dischargePlan.alternativePathways.cadArena.assessedAt).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }),
                                            description: `Assessed by ${patient?.dischargePlan.alternativePathways.cadArena.assessedBy}${
                                                patient?.dischargePlan.alternativePathways.cadArena.preferredUnit 
                                                    ? ` - Preferred Unit: ${patient?.dischargePlan.alternativePathways.cadArena.preferredUnit}`
                                                    : ''
                                            }`,
                                            isAlert: !patient?.dischargePlan.alternativePathways.cadArena.hasConsented
                                        }] : [])
                                    ].filter(Boolean)} 
                                />
                            </div>
                        )}

                        {activeTab === 'tasks' && (
                            <div className="space-y-6">
                                <TaskTemplates onApplyTemplate={(template) => {
                                    setNewTask(template.title);
                                    handleAddTask();
                                }} />
                                <TaskPriorityMatrix tasks={tasks} />
                                <TaskCompletionHistory tasks={tasks} />
                            </div>
                        )}

                        {activeTab === 'carePlan' && (
                            <div className="space-y-6">
                                <CareGoalsSection goals={patient?.carePlan.goals} />
                                <InterventionsChecklist interventions={patient?.carePlan.interventions} />
                                <PatientPreferences preferences={patient?.carePlan.preferences} />
                            </div>
                        )}

                        {activeTab === 'overview' && (
                            <div className="space-y-6">
                                <VitalSignsChart vitalsHistory={patient?.clinicalStatus.vitalsHistory} />
                                <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                                    <QuickStatsPanel patient={patient} />
                                    <MedicationAdministrationRecord medications={patient?.clinicalStatus.medicationAdministration} />
                                </div>
                                <CareTeamTimeline teamCommunication={patient?.teamCommunication} />
                            </div>
                        )}

                        {activeTab === 'barriers' && (
                                <div className="space-y-6">
                                    {/* Quick Add Barrier */}
                                    <Card>
                                        <CardHeader>
                                            <CardTitle>Add New Barrier</CardTitle>
                                        </CardHeader>
                                        <CardContent>
                                            <div className="flex gap-4">
                                                <input
                                                    type="text"
                                                    value={newBarrier}
                                                    onChange={(e) => setNewBarrier(e.target.value)}
                                                    onKeyDown={(e) => {
                                                        if (e.key === 'Enter') {
                                                            e.preventDefault();
                                                            handleAddBarrier();
                                                        }
                                                    }}
                                                    placeholder="Describe the barrier..."
                                                    className="flex-1 rounded-md border-gray-300 dark:border-gray-600 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                />
                                                <select className="w-40 rounded-md border-gray-300 dark:border-gray-600 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                    <option value="Clinical">Clinical</option>
                                                    <option value="Social">Social</option>
                                                    <option value="Administrative">Administrative</option>
                                                    <option value="Environmental">Environmental</option>
                                                </select>
                                                <button
                                                    onClick={handleAddBarrier}
                                                    className="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                                                >
                                                    Add Barrier
                                                </button>
                                            </div>
                                        </CardContent>
                                    </Card>

                                    {/* Active Barriers */}
                                    <Card>
                                        <CardHeader>
                                            <div className="flex justify-between items-center">
                                                <CardTitle>Active Barriers</CardTitle>
                                                <span className="text-sm text-gray-500">
                                                    {barriers.length} identified
                                                </span>
                                            </div>
                                        </CardHeader>
                                        <CardContent>
                                            <div className="space-y-4">
                                                {barriers.map((barrier, index) => (
                                                    <div key={index} className="flex items-start justify-between p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                                                        <div className="flex-1">
                                                            <div className="font-medium">{barrier}</div>
                                                            <div className="mt-1 flex flex-wrap gap-2">
                                                                <span className="px-2 py-1 bg-yellow-100 text-yellow-800 rounded-full text-xs">
                                                                    Requires Action
                                                                </span>
                                                                <span className="px-2 py-1 bg-gray-100 text-gray-800 rounded-full text-xs">
                                                                    Added {new Date().toLocaleDateString()}
                                                                </span>
                                                            </div>
                                                        </div>
                                                        <button
                                                            onClick={() => handleRemoveBarrier(index)}
                                                            className="ml-4 text-gray-400 hover:text-gray-500"
                                                        >
                                                            <Icon icon="heroicons:x-mark" className="w-5 h-5" />
                                                        </button>
                                                    </div>
                                                ))}
                                                {barriers.length === 0 && (
                                                    <div className="text-center py-6 text-gray-500">
                                                        No barriers identified
                                                    </div>
                                                )}
                                            </div>
                                        </CardContent>
                                    </Card>

                                    {/* Resolution History */}
                                    <Card>
                                        <CardHeader>
                                            <CardTitle>Resolution History</CardTitle>
                                        </CardHeader>
                                        <CardContent>
                                            <div className="space-y-4">
                                                {[
                                                    {
                                                        barrier: "Pending physical therapy evaluation",
                                                        resolvedBy: "Dr. Smith",
                                                        resolvedAt: "2024-02-07T14:30:00",
                                                        resolution: "PT evaluation completed and cleared for discharge"
                                                    },
                                                    {
                                                        barrier: "Insurance authorization pending",
                                                        resolvedBy: "Jane Wilson",
                                                        resolvedAt: "2024-02-07T11:15:00",
                                                        resolution: "Authorization received for skilled nursing facility"
                                                    }
                                                ].map((item, index) => (
                                                    <div key={index} className="p-3 bg-green-50 dark:bg-green-900/20 rounded-lg">
                                                        <div className="flex justify-between items-start">
                                                            <div>
                                                                <div className="font-medium text-green-900 dark:text-green-100">
                                                                    {item.barrier}
                                                                </div>
                                                                <div className="mt-1 text-sm text-green-800 dark:text-green-200">
                                                                    {item.resolution}
                                                                </div>
                                                            </div>
                                                            <span className="px-2 py-1 bg-green-100 dark:bg-green-800 text-green-800 dark:text-green-200 rounded-full text-xs">
                                                                Resolved
                                                            </span>
                                                        </div>
                                                        <div className="mt-2 text-xs text-green-700 dark:text-green-300">
                                                            Resolved by {item.resolvedBy} • {new Date(item.resolvedAt).toLocaleString()}
                                                        </div>
                                                    </div>
                                                ))}
                                            </div>
                                        </CardContent>
                                    </Card>

                                    {/* Team Communication */}
                                    <Card>
                                        <CardHeader>
                                            <CardTitle>Team Communication</CardTitle>
                                        </CardHeader>
                                        <CardContent>
                                            <div className="space-y-4">
                                                <textarea
                                                    rows="4"
                                                    placeholder="Add notes about barrier resolution efforts..."
                                                    className="w-full rounded-md border-gray-300 dark:border-gray-600 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                />
                                                <div className="space-y-3">
                                                    {patient?.teamCommunication
                                                        .filter(note => note.category === 'Barrier Resolution')
                                                        .map((note) => (
                                                            <div key={note.id} className="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                                                                <div className="flex justify-between text-sm">
                                                                    <span className="font-medium">{note.author}</span>
                                                                    <span className="text-gray-500">
                                                                        {new Date(note.timestamp).toLocaleString()}
                                                                    </span>
                                                                </div>
                                                                <div className="mt-2">{note.message}</div>
                                                            </div>
                                                        ))}
                                                </div>
                                            </div>
                                        </CardContent>
                                    </Card>
                                </div>
                            )}
                        </div>
                    </div>

                    {/* Footer */}
                    <div className="px-6 py-4 border-t border-gray-200 dark:border-gray-700 flex items-center justify-between">
                        <div className="text-sm text-gray-500 dark:text-gray-400">
                            Last updated: {new Date().toLocaleTimeString()}
                        </div>
                        <div className="flex items-center space-x-3">
                            <button
                                onClick={onClose}
                                className="inline-flex items-center px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm text-sm font-medium text-gray-700 dark:text-gray-200 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                            >
                                Cancel
                            </button>
                            <button
                                onClick={handleSave}
                                className="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                            >
                                Save Changes
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
};

export default StatusUpdateModal;
