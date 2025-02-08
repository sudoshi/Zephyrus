import React from 'react';
import { Icon } from '@iconify/react';
import Card from '@/Components/Dashboard/Card';

const TaskPriorityMatrix = ({ tasks }) => {
    if (!tasks?.length) return null;

    const getTasksByQuadrant = () => {
        const matrix = {
            urgent_important: [],
            not_urgent_important: [],
            urgent_not_important: [],
            not_urgent_not_important: []
        };

        tasks.forEach(task => {
            const isUrgent = new Date(task.dueDate) - new Date() < 24 * 60 * 60 * 1000; // Due within 24 hours
            const isImportant = task.priority === 'High';

            if (isUrgent && isImportant) {
                matrix.urgent_important.push(task);
            } else if (!isUrgent && isImportant) {
                matrix.not_urgent_important.push(task);
            } else if (isUrgent && !isImportant) {
                matrix.urgent_not_important.push(task);
            } else {
                matrix.not_urgent_not_important.push(task);
            }
        });

        return matrix;
    };

    const matrix = getTasksByQuadrant();

    const QuadrantCard = ({ title, tasks, bgColor, icon }) => (
        <div className={`${bgColor} p-4 rounded-lg`}>
            <div className="flex items-center gap-2 mb-3">
                <Icon icon={icon} className="w-5 h-5" />
                <h3 className="font-medium text-gray-900 dark:text-gray-100">{title}</h3>
                <span className="ml-auto text-sm text-gray-500 dark:text-gray-400">
                    {tasks.length} tasks
                </span>
            </div>
            <div className="space-y-2">
                {tasks.map((task, index) => (
                    <div 
                        key={task.id || index}
                        className="p-3 bg-white dark:bg-gray-800 rounded-lg shadow-sm"
                    >
                        <div className="flex items-start justify-between">
                            <div className="flex-1">
                                <p className="text-sm font-medium text-gray-900 dark:text-gray-100">
                                    {task.text}
                                </p>
                                <p className="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                    Assigned to: {task.assignedTo}
                                </p>
                            </div>
                            <input
                                type="checkbox"
                                checked={task.completed}
                                onChange={() => {}}
                                className="rounded border-gray-300 dark:border-gray-600 text-indigo-600 focus:ring-indigo-500 h-4 w-4"
                            />
                        </div>
                        <div className="mt-2 flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                            <Icon icon="heroicons:clock" className="w-4 h-4" />
                            Due: {new Date(task.dueDate).toLocaleString()}
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );

    return (
        <Card>
            <Card.Header>
                <Card.Title>Task Priority Matrix</Card.Title>
            </Card.Header>
            <Card.Content>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <QuadrantCard
                        title="Urgent & Important"
                        tasks={matrix.urgent_important}
                        bgColor="bg-red-50 dark:bg-red-900/20"
                        icon="heroicons:exclamation-circle"
                    />
                    <QuadrantCard
                        title="Important, Not Urgent"
                        tasks={matrix.not_urgent_important}
                        bgColor="bg-yellow-50 dark:bg-yellow-900/20"
                        icon="heroicons:clock"
                    />
                    <QuadrantCard
                        title="Urgent, Not Important"
                        tasks={matrix.urgent_not_important}
                        bgColor="bg-blue-50 dark:bg-blue-900/20"
                        icon="heroicons:bell-alert"
                    />
                    <QuadrantCard
                        title="Not Urgent or Important"
                        tasks={matrix.not_urgent_not_important}
                        bgColor="bg-green-50 dark:bg-green-900/20"
                        icon="heroicons:calendar"
                    />
                </div>
            </Card.Content>
        </Card>
    );
};

export default TaskPriorityMatrix;
