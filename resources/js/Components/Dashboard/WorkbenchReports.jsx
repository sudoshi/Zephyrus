import React from 'react';
import { Icon } from '@iconify/react';

const WorkbenchReports = ({ reports }) => {
    return (
        <div className="bg-white p-6 rounded-lg shadow-sm border border-gray-100">
            <div className="flex items-center justify-between mb-4">
                <div className="flex items-center space-x-2">
                    <div className="bg-indigo-50 p-2 rounded-lg">
                        <Icon icon="heroicons:document-text" className="w-6 h-6 text-indigo-600" />
                    </div>
                    <div>
                        <h2 className="text-lg font-semibold text-gray-900">Recent Activity Reports</h2>
                        <p className="text-sm text-gray-500">Last updated {new Date().toLocaleTimeString()}</p>
                    </div>
                </div>
                <div className="relative group">
                    <Icon 
                        icon="heroicons:information-circle" 
                        className="w-5 h-5 text-gray-400 hover:text-gray-600 cursor-help"
                    />
                    <div className="absolute z-10 w-64 p-2 mt-2 text-sm bg-white rounded-lg shadow-lg border border-gray-200 opacity-0 group-hover:opacity-100 transition-opacity duration-200 pointer-events-none right-0">
                        Track and monitor recent OR activity reports and their current status
                    </div>
                </div>
            </div>
            <div className="divide-y divide-gray-100">
                {reports.map((report, index) => (
                    <div 
                        key={index}
                        className="flex items-center justify-between py-3 px-3 hover:bg-gray-50 cursor-pointer transition-all duration-200 rounded-lg group"
                    >
                        <div className="flex items-center space-x-3">
                            <div className={`p-1.5 rounded-lg transition-colors duration-200 ${
                                report.status === 'Completed' ? 'bg-green-50 group-hover:bg-green-100' :
                                report.status === 'In Progress' ? 'bg-yellow-50 group-hover:bg-yellow-100' :
                                'bg-gray-50 group-hover:bg-gray-100'
                            }`}>
                                <Icon 
                                    icon={
                                        report.status === 'Completed' ? 'heroicons:check-circle' :
                                        report.status === 'In Progress' ? 'heroicons:clock' :
                                        'heroicons:document'
                                    } 
                                    className={`w-5 h-5 ${
                                        report.status === 'Completed' ? 'text-green-600' :
                                        report.status === 'In Progress' ? 'text-yellow-600' :
                                        'text-gray-500'
                                    }`}
                                />
                            </div>
                            <div>
                                <p className="text-sm font-medium text-gray-900">{report.name}</p>
                                <p className="text-xs text-gray-500">{report.date}</p>
                            </div>
                        </div>
                        <div className="flex items-center">
                            <span className={`text-xs font-medium px-2 py-1 rounded-full ${
                                report.status === 'Completed' ? 'bg-green-100 text-green-800' :
                                report.status === 'In Progress' ? 'bg-yellow-100 text-yellow-800' :
                                'bg-gray-100 text-gray-800'
                            }`}>
                                {report.status}
                            </span>
                            <Icon 
                                icon="heroicons:chevron-right" 
                                className="w-4 h-4 text-gray-400 ml-2 transition-transform duration-200 group-hover:translate-x-1" 
                            />
                        </div>
                    </div>
                ))}
            </div>
            <div className="mt-6 flex justify-end">
                <button className="inline-flex items-center px-4 py-2 text-sm font-medium text-indigo-600 bg-indigo-50 rounded-lg hover:bg-indigo-100 transition-colors duration-200">
                    View All Reports
                    <Icon 
                        icon="heroicons:arrow-right" 
                        className="w-4 h-4 ml-1.5 transition-transform duration-200 group-hover:translate-x-1" 
                    />
                </button>
            </div>
        </div>
    );
};

export default WorkbenchReports;
