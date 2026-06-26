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
                <h3 className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{title}</h3>
                <span className="ml-auto text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    {tasks.length} tasks
                </span>
            </div>
            <div className="space-y-2">
                {tasks.map((task, index) => (
                    <div 
                        key={task.id || index}
                        className="p-3 bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-lg shadow-sm"
                    >
                        <div className="flex items-start justify-between">
                            <div className="flex-1">
                                <p className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                    {task.text}
                                </p>
                                <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mt-1">
                                    Assigned to: {task.assignedTo}
                                </p>
                            </div>
                            <input
                                type="checkbox"
                                checked={task.completed}
                                onChange={() => {}}
                                className="rounded border-healthcare-border dark:border-healthcare-border-dark text-indigo-600 focus:ring-indigo-500 h-4 w-4"
                            />
                        </div>
                        <div className="mt-2 flex items-center gap-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
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
                        bgColor="bg-healthcare-critical/10 dark:bg-healthcare-critical-dark/20"
                        icon="heroicons:exclamation-circle"
                    />
                    <QuadrantCard
                        title="Important, Not Urgent"
                        tasks={matrix.not_urgent_important}
                        bgColor="bg-healthcare-warning/10 dark:bg-healthcare-warning-dark/20"
                        icon="heroicons:clock"
                    />
                    <QuadrantCard
                        title="Urgent, Not Important"
                        tasks={matrix.urgent_not_important}
                        bgColor="bg-healthcare-info/10 dark:bg-healthcare-info-dark/20"
                        icon="heroicons:bell-alert"
                    />
                    <QuadrantCard
                        title="Not Urgent or Important"
                        tasks={matrix.not_urgent_not_important}
                        bgColor="bg-healthcare-success/10 dark:bg-healthcare-success-dark/20"
                        icon="heroicons:calendar"
                    />
                </div>
            </Card.Content>
        </Card>
    );
};

export default TaskPriorityMatrix;
