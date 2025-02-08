import React from 'react';
import { Icon } from '@iconify/react';
import Card from '@/Components/Dashboard/Card';

const TaskCompletionHistory = ({ tasks }) => {
    if (!tasks?.length) return null;

    const completedTasks = tasks
        .filter(task => task.completed)
        .sort((a, b) => new Date(b.completedAt || b.dueDate) - new Date(a.completedAt || a.dueDate));

    const getCategoryIcon = (category) => {
        switch (category?.toLowerCase()) {
            case 'clinical':
                return 'heroicons:heart';
            case 'therapy':
                return 'heroicons:hand-raised';
            case 'administrative':
                return 'heroicons:document-text';
            case 'ancillary':
                return 'heroicons:beaker';
            default:
                return 'heroicons:clipboard-document-list';
        }
    };

    const getPriorityColor = (priority) => {
        switch (priority?.toLowerCase()) {
            case 'high':
                return 'text-red-600 dark:text-red-400';
            case 'medium':
                return 'text-yellow-600 dark:text-yellow-400';
            default:
                return 'text-green-600 dark:text-green-400';
        }
    };

    const TaskItem = ({ task }) => (
        <div className="flex items-start gap-4 p-4 bg-gray-50 dark:bg-gray-800 rounded-lg">
            <div className="p-2 bg-green-100 dark:bg-green-900 rounded-full">
                <Icon 
                    icon={getCategoryIcon(task.category)} 
                    className="w-5 h-5 text-green-600 dark:text-green-400" 
                />
            </div>
            <div className="flex-1 min-w-0">
                <div className="flex justify-between">
                    <div>
                        <h4 className="font-medium text-gray-900 dark:text-gray-100">
                            {task.text}
                        </h4>
                        <p className="text-sm text-gray-500 dark:text-gray-400">
                            Completed by: {task.assignedTo}
                        </p>
                    </div>
                    <span className={`text-sm font-medium ${getPriorityColor(task.priority)}`}>
                        {task.priority}
                    </span>
                </div>
                <div className="mt-2 flex flex-wrap gap-2">
                    <span className="inline-flex items-center text-xs text-gray-500 dark:text-gray-400">
                        <Icon icon="heroicons:clock" className="w-4 h-4 mr-1" />
                        Completed: {new Date(task.completedAt || task.dueDate).toLocaleString()}
                    </span>
                    <span className="inline-flex items-center text-xs text-gray-500 dark:text-gray-400">
                        <Icon icon="heroicons:tag" className="w-4 h-4 mr-1" />
                        {task.category}
                    </span>
                </div>
            </div>
        </div>
    );

    const CompletionStats = () => {
        const totalTasks = tasks.length;
        const completedCount = completedTasks.length;
        const completionRate = (completedCount / totalTasks) * 100;

        return (
            <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <div className="p-4 bg-white dark:bg-gray-800 rounded-lg shadow-sm">
                    <div className="text-sm text-gray-500 dark:text-gray-400">Total Tasks</div>
                    <div className="text-2xl font-semibold text-gray-900 dark:text-gray-100">
                        {totalTasks}
                    </div>
                </div>
                <div className="p-4 bg-white dark:bg-gray-800 rounded-lg shadow-sm">
                    <div className="text-sm text-gray-500 dark:text-gray-400">Completed</div>
                    <div className="text-2xl font-semibold text-gray-900 dark:text-gray-100">
                        {completedCount}
                    </div>
                </div>
                <div className="p-4 bg-white dark:bg-gray-800 rounded-lg shadow-sm">
                    <div className="text-sm text-gray-500 dark:text-gray-400">Completion Rate</div>
                    <div className="text-2xl font-semibold text-gray-900 dark:text-gray-100">
                        {completionRate.toFixed(1)}%
                    </div>
                </div>
            </div>
        );
    };

    return (
        <Card>
            <Card.Header>
                <Card.Title>Task Completion History</Card.Title>
            </Card.Header>
            <Card.Content>
                <CompletionStats />
                <div className="space-y-4">
                    {completedTasks.map((task, index) => (
                        <TaskItem key={task.id || index} task={task} />
                    ))}
                    {completedTasks.length === 0 && (
                        <div className="text-center py-6 text-gray-500 dark:text-gray-400">
                            No completed tasks yet
                        </div>
                    )}
                </div>
            </Card.Content>
        </Card>
    );
};

export default TaskCompletionHistory;
