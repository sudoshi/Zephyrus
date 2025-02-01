import React from 'react';
import { Icon } from '@iconify/react';
import fileIcon from '@iconify/icons-solar/file-text-line-duotone';

const WorkbenchReports = ({ reports }) => {
    return (
        <div className="bg-white p-6 rounded-lg shadow-sm">
            <div className="flex items-center justify-between mb-4">
                <h2 className="text-lg font-semibold text-gray-900">Recent Activity Reports</h2>
                <Icon icon={fileIcon} className="w-6 h-6 text-gray-400" />
            </div>
            <div className="space-y-2">
                {reports.map((report, index) => (
                    <div 
                        key={index}
                        className="flex items-center justify-between py-2 hover:bg-gray-50 cursor-pointer"
                    >
                        <span className="text-sm text-gray-600">{report.name}</span>
                        <span className="text-xs text-blue-600">{report.status}</span>
                    </div>
                ))}
            </div>
        </div>
    );
};

export default WorkbenchReports;
