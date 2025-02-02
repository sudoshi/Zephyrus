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
        <Card className={className}>
            <Card.Content>
                <div className="flex items-center justify-between mb-6">
                    <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
                        {title}
                    </h3>
                    <div className="flex items-center space-x-2">
                        {info && (
                            <div className="relative group">
                                <Icon 
                                    icon="heroicons:information-circle" 
                                    className="w-5 h-5 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark hover:text-healthcare-text-primary dark:hover:text-healthcare-text-primary-dark transition-colors duration-300 cursor-help"
                                />
                                <div className="absolute z-10 w-64 p-2 mt-2 text-sm rounded-lg shadow-lg bg-healthcare-surface dark:bg-healthcare-surface-dark border border-healthcare-border dark:border-healthcare-border-dark text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark opacity-0 group-hover:opacity-100 transition-all duration-300 pointer-events-none right-0">
                                    {info}
                                </div>
                            </div>
                        )}
                    </div>
                </div>
                <div className="overflow-hidden transition-all duration-300">
                    {children}
                </div>
            </Card.Content>
        </Card>
    );

    const renderCollapsibleSection = (title, content, section) => (
        <Card>
            <button
                className="w-full flex items-center justify-between p-4 text-left hover:bg-healthcare-background dark:hover:bg-healthcare-background-dark transition-colors duration-300"
                onClick={() => toggleSection(section)}
            >
                <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
                    {title}
                </h3>
                <Icon 
                    icon={expandedSections[section] ? 'heroicons:chevron-down' : 'heroicons:chevron-right'}
                    className={`w-5 h-5 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark transform transition-all duration-300 ${
                        expandedSections[section] ? 'rotate-0' : '-rotate-90'
                    }`}
                />
            </button>
            <div className={`transition-all duration-300 ${
                expandedSections[section] ? 'block' : 'hidden'
            }`}>
                {content}
            </div>
        </Card>
    );

    const renderMetricBox = (label, value, className = '') => (
        <div className="bg-healthcare-background dark:bg-healthcare-background-dark p-4 rounded-lg transition-colors duration-300">
            <div className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark transition-colors duration-300">
                {label}
            </div>
            <div className={`font-semibold text-lg ${className} transition-colors duration-300`}>
                {value}
            </div>
        </div>
    );

    return (
        <section className="space-y-8">
            <div className="flex items-center justify-between">
                <h2 className="text-xl font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
                    Month to Date
                </h2>
                <div className="flex items-center space-x-4">
                    <button className="inline-flex items-center text-sm text-healthcare-info dark:text-healthcare-info-dark hover:text-healthcare-info-dark dark:hover:text-healthcare-info font-medium transition-colors duration-300">
                        <Icon icon="heroicons:document-arrow-down" className="w-4 h-4 mr-1" />
                        Export Data
                    </button>
                    <div className="relative">
                        <select className="text-sm border-healthcare-border dark:border-healthcare-border-dark rounded-md pl-8 pr-4 py-2 appearance-none bg-healthcare-surface dark:bg-healthcare-surface-dark hover:border-healthcare-info dark:hover:border-healthcare-info-dark transition-colors duration-300">
                            <option>All Services</option>
                            <option>Orthopedics</option>
                            <option>Cardiology</option>
                        </select>
                        <Icon 
                            icon="heroicons:funnel" 
                            className="absolute left-2 top-1/2 transform -translate-y-1/2 w-4 h-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark transition-colors duration-300"
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
                                <ServiceBarChart data={data.onTimeStarts.byService} height={350} />
                                <div className="grid grid-cols-3 gap-4 text-sm">
                                    {renderMetricBox("First Case", "85%")}
                                    {renderMetricBox("Subsequent", "78%")}
                                    {renderMetricBox("Trend", "↑ 3%", "text-healthcare-success dark:text-healthcare-success-dark")}
                                </div>
                            </div>
                        )}

                        {renderChartSection(
                            "Case Length Accuracy",
                            "Displays the percentage of cases performed within the current calendar month that were accurately scheduled.",
                            <div className="space-y-6">
                                <StackedBarChart data={data.caseLengthAccuracy.byService} height={350} />
                                <div className="grid grid-cols-3 gap-4 text-sm">
                                    {renderMetricBox("Under", "15%")}
                                    {renderMetricBox("Accurate", "70%")}
                                    {renderMetricBox("Over", "15%")}
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
                                            <tr className="border-b border-healthcare-border dark:border-healthcare-border-dark">
                                                <th className="text-left py-3 px-4 text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider transition-colors duration-300">
                                                    Location
                                                </th>
                                                <th className="text-right py-3 px-4 text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider transition-colors duration-300">
                                                    MTD
                                                </th>
                                                <th className="text-right py-3 px-4 text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider transition-colors duration-300">
                                                    Trend
                                                </th>
                                                <th className="text-right py-3 px-4 text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider transition-colors duration-300">
                                                    Projected
                                                </th>
                                                <th className="text-right py-3 px-4 text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider transition-colors duration-300">
                                                    Target
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {data.blockUtilization.locations.map((location, index) => (
                                                <tr key={index} className="border-b border-healthcare-border dark:border-healthcare-border-dark hover:bg-healthcare-background dark:hover:bg-healthcare-background-dark transition-colors duration-300">
                                                    <td className="py-4 px-4 text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
                                                        {location.name}
                                                    </td>
                                                    <td className="text-right py-4 px-4">
                                                        <span className={
                                                            location.mtd >= 80 ? 'text-healthcare-success dark:text-healthcare-success-dark' :
                                                            location.mtd >= 70 ? 'text-healthcare-warning dark:text-healthcare-warning-dark' :
                                                            'text-healthcare-critical dark:text-healthcare-critical-dark'
                                                        }>
                                                            {location.mtd}%
                                                        </span>
                                                    </td>
                                                    <td className="text-right py-4 px-4">
                                                        <span className={`flex items-center justify-end ${
                                                            location.mtd > location.lastMonth 
                                                                ? 'text-healthcare-success dark:text-healthcare-success-dark' 
                                                                : 'text-healthcare-critical dark:text-healthcare-critical-dark'
                                                        }`}>
                                                            <Icon 
                                                                icon={location.mtd > location.lastMonth ? 'heroicons:arrow-up' : 'heroicons:arrow-down'} 
                                                                className="w-4 h-4 mr-1"
                                                            />
                                                            {Math.abs(location.mtd - location.lastMonth)}%
                                                        </span>
                                                    </td>
                                                    <td className="text-right py-4 px-4 text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
                                                        {location.projected}%
                                                    </td>
                                                    <td className="text-right py-4 px-4 text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
                                                        80%
                                                    </td>
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
                                <LineChart data={data.primetimeUtilization.trend} height={350} />
                                <div className="grid grid-cols-3 gap-4 text-sm">
                                    {renderMetricBox("In-Room Time", "82%")}
                                    {renderMetricBox("Setup/Cleanup", "12%")}
                                    {renderMetricBox("Unused", "6%")}
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
                                <ServiceBarChart data={data.performedCases.byService} height={350} />
                                <div className="grid grid-cols-3 gap-4 text-sm">
                                    {renderMetricBox("Total", "324")}
                                    {renderMetricBox("Emergency", "45")}
                                    {renderMetricBox("Elective", "279")}
                                </div>
                            </div>
                        )}

                        {renderChartSection(
                            "Average Turnover",
                            "Displays the average room and procedure turnover for cases.",
                            <div className="space-y-6">
                                <DualBarChart data={data.avgTurnover.byService} height={350} />
                                <div className="grid grid-cols-3 gap-4 text-sm">
                                    {renderMetricBox("Average", "32m")}
                                    {renderMetricBox("Min", "22m")}
                                    {renderMetricBox("Max", "68m")}
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
