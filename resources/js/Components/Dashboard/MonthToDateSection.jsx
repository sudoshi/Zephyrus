import React, { useState } from 'react';
import Card from '@/Components/Dashboard/Card';
import { Icon } from '@iconify/react';
import ServiceBarChart from './Charts/ServiceBarChart';
import DualBarChart from './Charts/DualBarChart';
import StackedBarChart from './Charts/StackedBarChart';
import LineChart from './Charts/LineChart';
import WorkbenchReports from './WorkbenchReports';
import { syntheticData } from '../../mock-data/dashboard';

const MonthToDateSection = () => {
    const data = syntheticData.monthToDate;
    const [expandedSections, setExpandedSections] = useState({
        performance: true,
        utilization: true,
        cases: true
    });

    const toggleSection = (section) => {
        setExpandedSections(prev => ({
            ...prev,
            [section]: !prev[section]
        }));
    };

    const renderChartSection = (title, info, children, className = '') => (
        <Card className={`p-8 ${className}`}>
            <div className="flex items-center justify-between mb-6">
                <h3 className="text-lg font-semibold">{title}</h3>
                <div className="flex items-center space-x-2">
                    {info && (
                        <div className="relative group">
                            <Icon 
                                icon="heroicons:information-circle" 
                                className="w-5 h-5 text-gray-400 hover:text-gray-600 cursor-help"
                            />
                            <div className="absolute z-10 w-64 p-2 mt-2 text-sm bg-white rounded-lg shadow-lg border border-gray-200 opacity-0 group-hover:opacity-100 transition-opacity duration-200 pointer-events-none right-0">
                                {info}
                            </div>
                        </div>
                    )}
                </div>
            </div>
            <div className="overflow-hidden transition-all duration-200">
                {children}
            </div>
        </Card>
    );

    const renderCollapsibleSection = (title, content, section) => (
        <div className="bg-white rounded-lg shadow-sm overflow-hidden">
            <button
                className="w-full flex items-center justify-between p-4 text-left hover:bg-gray-50"
                onClick={() => toggleSection(section)}
            >
                <h3 className="text-lg font-semibold text-gray-900">{title}</h3>
                <Icon 
                    icon={expandedSections[section] ? 'heroicons:chevron-down' : 'heroicons:chevron-right'}
                    className={`w-5 h-5 text-gray-500 transform transition-transform duration-200 ${
                        expandedSections[section] ? 'rotate-0' : '-rotate-90'
                    }`}
                />
            </button>
            <div className={`transition-all duration-200 ${
                expandedSections[section] ? 'block' : 'hidden'
            }`}>
                {content}
            </div>
        </div>
    );

    return (
        <section className="space-y-8">
            <div className="flex items-center justify-between">
                <h2 className="text-xl font-bold">Month to Date</h2>
                <div className="flex items-center space-x-4">
                    <button className="inline-flex items-center text-sm text-blue-600 hover:text-blue-800 font-medium">
                        <Icon icon="heroicons:document-arrow-down" className="w-4 h-4 mr-1" />
                        Export Data
                    </button>
                    <div className="relative">
                        <select className="text-sm border-gray-300 rounded-md pl-8 pr-4 py-2 appearance-none bg-white">
                            <option>All Services</option>
                            <option>Orthopedics</option>
                            <option>Cardiology</option>
                        </select>
                        <Icon 
                            icon="heroicons:funnel" 
                            className="absolute left-2 top-1/2 transform -translate-y-1/2 w-4 h-4 text-gray-400"
                        />
                    </div>
                </div>
            </div>
            
            <div className="space-y-8">
                {/* Performance Metrics Section */}
                {renderCollapsibleSection(
                    "Performance Metrics",
                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 p-6">
                        {renderChartSection(
                            "On Time Starts",
                            "Displays the percentage of cases that were on time and took place within the current calendar month.",
                            <div className="space-y-6">
                                <ServiceBarChart data={data.onTimeStarts.byService} height={500} />
                                <div className="grid grid-cols-3 gap-4 text-sm">
                                    <div className="bg-gray-50 p-4 rounded-lg">
                                        <div className="text-gray-600">First Case</div>
                                        <div className="font-semibold text-lg">85%</div>
                                    </div>
                                    <div className="bg-gray-50 p-4 rounded-lg">
                                        <div className="text-gray-600">Subsequent</div>
                                        <div className="font-semibold text-lg">78%</div>
                                    </div>
                                    <div className="bg-gray-50 p-4 rounded-lg">
                                        <div className="text-gray-600">Trend</div>
                                        <div className="font-semibold text-lg text-green-600">↑ 3%</div>
                                    </div>
                                </div>
                            </div>
                        )}

                        {renderChartSection(
                            "Case Length Accuracy",
                            "Displays the percentage of cases performed within the current calendar month that were accurately scheduled.",
                            <div className="space-y-6">
                                <StackedBarChart data={data.caseLengthAccuracy.byService} height={500} />
                                <div className="grid grid-cols-3 gap-4 text-sm">
                                    <div className="bg-gray-50 p-4 rounded-lg">
                                        <div className="text-gray-600">Under</div>
                                        <div className="font-semibold text-lg">15%</div>
                                    </div>
                                    <div className="bg-gray-50 p-4 rounded-lg">
                                        <div className="text-gray-600">Accurate</div>
                                        <div className="font-semibold text-lg">70%</div>
                                    </div>
                                    <div className="bg-gray-50 p-4 rounded-lg">
                                        <div className="text-gray-600">Over</div>
                                        <div className="font-semibold text-lg">15%</div>
                                    </div>
                                </div>
                            </div>
                        )}
                    </div>,
                    "performance"
                )}

                {/* Utilization Metrics Section */}
                {renderCollapsibleSection(
                    "Utilization Metrics",
                    <div className="space-y-6 p-6">
                        {renderChartSection(
                            "Block Utilization",
                            "Displays a high-level summary of OR block utilization data for your OR locations.",
                            <div className="space-y-6">
                                <div className="overflow-x-auto">
                                    <table className="min-w-full">
                                        <thead>
                                            <tr className="border-b bg-gray-50">
                                                <th className="text-left py-3 px-4 text-sm font-medium text-gray-500 uppercase tracking-wider">Location</th>
                                                <th className="text-right py-3 px-4 text-sm font-medium text-gray-500 uppercase tracking-wider">MTD</th>
                                                <th className="text-right py-3 px-4 text-sm font-medium text-gray-500 uppercase tracking-wider">Trend</th>
                                                <th className="text-right py-3 px-4 text-sm font-medium text-gray-500 uppercase tracking-wider">Projected</th>
                                                <th className="text-right py-3 px-4 text-sm font-medium text-gray-500 uppercase tracking-wider">Target</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {data.blockUtilization.locations.map((location, index) => (
                                                <tr key={index} className="border-b hover:bg-gray-50">
                                                    <td className="py-4 px-4">{location.name}</td>
                                                    <td className="text-right py-4 px-4">
                                                        <span className={
                                                            location.mtd >= 80 ? 'text-green-600' :
                                                            location.mtd >= 70 ? 'text-yellow-600' :
                                                            'text-red-600'
                                                        }>
                                                            {location.mtd}%
                                                        </span>
                                                    </td>
                                                    <td className="text-right py-4 px-4">
                                                        <span className={`flex items-center justify-end ${
                                                            location.mtd > location.lastMonth ? 'text-green-600' : 'text-red-600'
                                                        }`}>
                                                            <Icon 
                                                                icon={location.mtd > location.lastMonth ? 'heroicons:arrow-up' : 'heroicons:arrow-down'} 
                                                                className="w-4 h-4 mr-1"
                                                            />
                                                            {Math.abs(location.mtd - location.lastMonth)}%
                                                        </span>
                                                    </td>
                                                    <td className="text-right py-4 px-4">{location.projected}%</td>
                                                    <td className="text-right py-4 px-4">80%</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        )}

                        {renderChartSection(
                            "Primetime Utilization",
                            "Displays the total in-room and setup/cleanup minutes over the available primetime minutes.",
                            <div className="space-y-6">
                                <LineChart data={data.primetimeUtilization.trend} height={500} />
                                <div className="grid grid-cols-3 gap-4 text-sm">
                                    <div className="bg-gray-50 p-4 rounded-lg">
                                        <div className="text-gray-600">In-Room Time</div>
                                        <div className="font-semibold text-lg">82%</div>
                                    </div>
                                    <div className="bg-gray-50 p-4 rounded-lg">
                                        <div className="text-gray-600">Setup/Cleanup</div>
                                        <div className="font-semibold text-lg">12%</div>
                                    </div>
                                    <div className="bg-gray-50 p-4 rounded-lg">
                                        <div className="text-gray-600">Unused</div>
                                        <div className="font-semibold text-lg">6%</div>
                                    </div>
                                </div>
                            </div>
                        )}
                    </div>,
                    "utilization"
                )}

                {/* Case Metrics Section */}
                {renderCollapsibleSection(
                    "Case Metrics",
                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 p-6">
                        {renderChartSection(
                            "Performed Cases",
                            "Displays the volume of cases that were performed within the current calendar month.",
                            <div className="space-y-6">
                                <ServiceBarChart data={data.performedCases.byService} height={500} />
                                <div className="grid grid-cols-3 gap-4 text-sm">
                                    <div className="bg-gray-50 p-4 rounded-lg">
                                        <div className="text-gray-600">Total</div>
                                        <div className="font-semibold text-lg">324</div>
                                    </div>
                                    <div className="bg-gray-50 p-4 rounded-lg">
                                        <div className="text-gray-600">Emergency</div>
                                        <div className="font-semibold text-lg">45</div>
                                    </div>
                                    <div className="bg-gray-50 p-4 rounded-lg">
                                        <div className="text-gray-600">Elective</div>
                                        <div className="font-semibold text-lg">279</div>
                                    </div>
                                </div>
                            </div>
                        )}

                        {renderChartSection(
                            "Average Turnover",
                            "Displays the average room and procedure turnover for cases.",
                            <div className="space-y-6">
                                <DualBarChart data={data.avgTurnover.byService} height={500} />
                                <div className="grid grid-cols-3 gap-4 text-sm">
                                    <div className="bg-gray-50 p-4 rounded-lg">
                                        <div className="text-gray-600">Average</div>
                                        <div className="font-semibold text-lg">32m</div>
                                    </div>
                                    <div className="bg-gray-50 p-4 rounded-lg">
                                        <div className="text-gray-600">Min</div>
                                        <div className="font-semibold text-lg">22m</div>
                                    </div>
                                    <div className="bg-gray-50 p-4 rounded-lg">
                                        <div className="text-gray-600">Max</div>
                                        <div className="font-semibold text-lg">68m</div>
                                    </div>
                                </div>
                            </div>
                        )}
                    </div>,
                    "cases"
                )}

                {/* Workbench Reports */}
                <WorkbenchReports reports={syntheticData.workbenchReports} />
            </div>
        </section>
    );
};

export default MonthToDateSection;
