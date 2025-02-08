import React from 'react';
import { Icon } from '@iconify/react';
import Card from '@/Components/Dashboard/Card';

const TaskTemplates = ({ onApplyTemplate }) => {
    const templates = [
        {
            category: 'Clinical',
            items: [
                { title: 'Vital Signs Q4H', priority: 'High', assignee: 'Primary Nurse' },
                { title: 'Daily Weight', priority: 'Medium', assignee: 'Primary Nurse' },
                { title: 'Pain Assessment', priority: 'High', assignee: 'Primary Nurse' },
                { title: 'Medication Review', priority: 'High', assignee: 'Clinical Pharmacist' }
            ]
        },
        {
            category: 'Therapy',
            items: [
                { title: 'PT Evaluation', priority: 'Medium', assignee: 'Physical Therapy' },
                { title: 'OT Evaluation', priority: 'Medium', assignee: 'Occupational Therapy' },
                { title: 'Speech Therapy Consult', priority: 'Medium', assignee: 'Speech Therapy' }
            ]
        },
        {
            category: 'Care Coordination',
            items: [
                { title: 'Family Meeting', priority: 'Medium', assignee: 'Care Coordinator' },
                { title: 'Discharge Planning', priority: 'High', assignee: 'Care Coordinator' },
                { title: 'Insurance Authorization', priority: 'High', assignee: 'Case Manager' }
            ]
        }
    ];

    const getPriorityColor = (priority) => {
        switch (priority.toLowerCase()) {
            case 'high':
                return 'text-red-600 dark:text-red-400 bg-red-100 dark:bg-red-900';
            case 'medium':
                return 'text-yellow-600 dark:text-yellow-400 bg-yellow-100 dark:bg-yellow-900';
            default:
                return 'text-green-600 dark:text-green-400 bg-green-100 dark:bg-green-900';
        }
    };

    const TemplateItem = ({ template }) => (
        <div className="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-800 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors cursor-pointer">
            <div className="flex items-center gap-3">
                <div className="flex-1">
                    <h4 className="font-medium text-gray-900 dark:text-gray-100">
                        {template.title}
                    </h4>
                    <p className="text-sm text-gray-500 dark:text-gray-400">
                        Assigned to: {template.assignee}
                    </p>
                </div>
            </div>
            <div className="flex items-center gap-3">
                <span className={`px-2 py-1 rounded-full text-xs font-medium ${getPriorityColor(template.priority)}`}>
                    {template.priority}
                </span>
                <button 
                    onClick={() => onApplyTemplate?.(template)}
                    className="p-1 text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300"
                >
                    <Icon icon="heroicons:plus-circle" className="w-5 h-5" />
                </button>
            </div>
        </div>
    );

    return (
        <Card>
            <Card.Header>
                <div className="flex justify-between items-center">
                    <Card.Title>Task Templates</Card.Title>
                    <button className="text-sm text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300 flex items-center gap-1">
                        <Icon icon="heroicons:plus" className="w-4 h-4" />
                        Create Template
                    </button>
                </div>
            </Card.Header>
            <Card.Content>
                <div className="space-y-6">
                    {templates.map((category, idx) => (
                        <div key={idx}>
                            <h3 className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-3">
                                {category.category}
                            </h3>
                            <div className="space-y-2">
                                {category.items.map((template, index) => (
                                    <TemplateItem key={index} template={template} />
                                ))}
                            </div>
                        </div>
                    ))}
                </div>
            </Card.Content>
        </Card>
    );
};

export default TaskTemplates;
