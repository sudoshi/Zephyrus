import React from 'react';
import { Icon } from '@iconify/react';
import Card from '@/Components/Dashboard/Card';

const CareGoalsSection = ({ goals }) => {
    if (!goals?.length) return null;

    const getStatusColor = (status) => {
        switch (status) {
            case 'Achieved':
                return 'bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-200';
            case 'In Progress':
                return 'bg-blue-100 dark:bg-blue-900 text-blue-800 dark:text-blue-200';
            case 'Modified':
                return 'bg-yellow-100 dark:bg-yellow-900 text-yellow-800 dark:text-yellow-200';
            default:
                return 'bg-gray-100 dark:bg-gray-900 text-gray-800 dark:text-gray-200';
        }
    };

    const getProgressPercentage = (status) => {
        switch (status) {
            case 'Achieved':
                return 100;
            case 'In Progress':
                return 50;
            case 'Modified':
                return 75;
            default:
                return 0;
        }
    };

    const GoalItem = ({ goal }) => {
        const progress = getProgressPercentage(goal.status);
        
        return (
            <div className="p-4 border border-gray-200 dark:border-gray-700 rounded-lg">
                <div className="flex justify-between items-start mb-2">
                    <div className="flex-1">
                        <h4 className="font-medium text-gray-900 dark:text-gray-100">
                            {goal.description}
                        </h4>
                        <p className="text-sm text-gray-500 dark:text-gray-400 mt-1">
                            Target: {new Date(goal.target).toLocaleDateString()}
                        </p>
                    </div>
                    <span className={`px-2 py-1 rounded-full text-xs font-medium ${getStatusColor(goal.status)}`}>
                        {goal.status}
                    </span>
                </div>
                
                <div className="mt-4">
                    <div className="flex items-center">
                        <div className="flex-1">
                            <div className="bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                                <div
                                    className="bg-indigo-600 dark:bg-indigo-400 rounded-full h-2 transition-all duration-300"
                                    style={{ width: `${progress}%` }}
                                />
                            </div>
                        </div>
                        <span className="ml-2 text-sm text-gray-500 dark:text-gray-400">
                            {progress}%
                        </span>
                    </div>
                </div>

                <div className="mt-4 flex items-center gap-4">
                    <button className="text-sm text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300 flex items-center gap-1">
                        <Icon icon="heroicons:pencil-square" className="w-4 h-4" />
                        Update Progress
                    </button>
                    <button className="text-sm text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300 flex items-center gap-1">
                        <Icon icon="heroicons:chat-bubble-left-ellipsis" className="w-4 h-4" />
                        Add Note
                    </button>
                </div>
            </div>
        );
    };

    return (
        <Card>
            <Card.Header>
                <div className="flex justify-between items-center">
                    <Card.Title>Care Goals</Card.Title>
                    <button className="text-sm text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300 flex items-center gap-1">
                        <Icon icon="heroicons:plus" className="w-4 h-4" />
                        Add Goal
                    </button>
                </div>
            </Card.Header>
            <Card.Content>
                <div className="space-y-4">
                    {goals.map((goal, index) => (
                        <GoalItem key={goal.id || index} goal={goal} />
                    ))}
                </div>
            </Card.Content>
        </Card>
    );
};

export default CareGoalsSection;
