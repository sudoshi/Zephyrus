import React from 'react';
import { Icon } from '@iconify/react';
import Card from '@/Components/Dashboard/Card';

const WorkbenchReports = ({ reports }) => {
    return (
        <Card>
            <Card.Content>
                <div className="flex items-center justify-between mb-4">
                    <div className="flex items-center space-x-2">
                        <div className="bg-healthcare-info bg-opacity-10 dark:bg-opacity-20 p-2 rounded-lg transition-colors duration-300">
                            <Icon 
                                icon="heroicons:document-text" 
                                className="w-6 h-6 text-healthcare-info dark:text-healthcare-info-dark transition-colors duration-300" 
                            />
                        </div>
                        <div>
                            <h2 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
                                Recent Activity Reports
                            </h2>
                            <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark transition-colors duration-300">
                                Last updated {new Date().toLocaleTimeString()}
                            </p>
                        </div>
                    </div>
                    <div className="relative group">
                        <Icon 
                            icon="heroicons:information-circle" 
                            className="w-5 h-5 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark hover:text-healthcare-text-primary dark:hover:text-healthcare-text-primary-dark transition-colors duration-300 cursor-help"
                        />
                        <div className="absolute z-10 w-64 p-2 mt-2 text-sm rounded-lg shadow-lg bg-healthcare-surface dark:bg-healthcare-surface-dark border border-healthcare-border dark:border-healthcare-border-dark text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark opacity-0 group-hover:opacity-100 transition-all duration-300 pointer-events-none right-0">
                            Track and monitor recent OR activity reports and their current status
                        </div>
                    </div>
                </div>
                <div className="divide-y divide-healthcare-border dark:divide-healthcare-border-dark transition-colors duration-300">
                    {reports.map((report, index) => (
                        <div 
                            key={index}
                            className="flex items-center justify-between py-3 px-3 hover:bg-healthcare-background dark:hover:bg-healthcare-background-dark transition-all duration-300 rounded-lg group cursor-pointer"
                        >
                            <div className="flex items-center space-x-3">
                                <div className={`p-1.5 rounded-lg transition-colors duration-300 ${
                                    report.status === 'Completed' ? 'bg-healthcare-success bg-opacity-10 dark:bg-opacity-20 group-hover:bg-opacity-20 dark:group-hover:bg-opacity-30' :
                                    report.status === 'In Progress' ? 'bg-healthcare-warning bg-opacity-10 dark:bg-opacity-20 group-hover:bg-opacity-20 dark:group-hover:bg-opacity-30' :
                                    'bg-healthcare-background dark:bg-healthcare-background-dark group-hover:bg-opacity-75 dark:group-hover:bg-opacity-75'
                                }`}>
                                    <Icon 
                                        icon={
                                            report.status === 'Completed' ? 'heroicons:check-circle' :
                                            report.status === 'In Progress' ? 'heroicons:clock' :
                                            'heroicons:document'
                                        } 
                                        className={`w-5 h-5 transition-colors duration-300 ${
                                            report.status === 'Completed' ? 'text-healthcare-success dark:text-healthcare-success-dark' :
                                            report.status === 'In Progress' ? 'text-healthcare-warning dark:text-healthcare-warning-dark' :
                                            'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark'
                                        }`}
                                    />
                                </div>
                                <div>
                                    <p className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
                                        {report.name}
                                    </p>
                                    <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark transition-colors duration-300">
                                        {report.date}
                                    </p>
                                </div>
                            </div>
                            <div className="flex items-center">
                                <span className={`text-xs font-medium px-2 py-1 rounded-full transition-colors duration-300 ${
                                    report.status === 'Completed' ? 'bg-healthcare-success bg-opacity-10 dark:bg-opacity-20 text-healthcare-success dark:text-healthcare-success-dark' :
                                    report.status === 'In Progress' ? 'bg-healthcare-warning bg-opacity-10 dark:bg-opacity-20 text-healthcare-warning dark:text-healthcare-warning-dark' :
                                    'bg-healthcare-background dark:bg-healthcare-background-dark text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark'
                                }`}>
                                    {report.status}
                                </span>
                                <Icon 
                                    icon="heroicons:chevron-right" 
                                    className="w-4 h-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark ml-2 transition-all duration-300 group-hover:translate-x-1" 
                                />
                            </div>
                        </div>
                    ))}
                </div>
                <div className="mt-6 flex justify-end">
                    <button className="inline-flex items-center px-4 py-2 text-sm font-medium text-healthcare-info dark:text-healthcare-info-dark bg-healthcare-info bg-opacity-10 dark:bg-opacity-20 rounded-lg hover:bg-opacity-20 dark:hover:bg-opacity-30 transition-all duration-300">
                        View All Reports
                        <Icon 
                            icon="heroicons:arrow-right" 
                            className="w-4 h-4 ml-1.5 transition-transform duration-300 group-hover:translate-x-1" 
                        />
                    </button>
                </div>
            </Card.Content>
        </Card>
    );
};

export default WorkbenchReports;
