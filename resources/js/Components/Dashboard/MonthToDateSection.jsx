import React from 'react';
import Card from '@/Components/Dashboard/Card';
import { Icon } from '@iconify/react';
import infoIcon from '@iconify/icons-solar/info-circle-line-duotone';
import ServiceBarChart from './Charts/ServiceBarChart';
import DualBarChart from './Charts/DualBarChart';
import StackedBarChart from './Charts/StackedBarChart';
import LineChart from './Charts/LineChart';
import WorkbenchReports from './WorkbenchReports';
import { syntheticData } from '../../mock-data/dashboard';

const MonthToDateSection = () => {
    const data = syntheticData.monthToDate;

    const renderChartSection = (title, info, children) => (
        <Card className="p-6">
            <div className="flex items-center justify-between mb-4">
                <h3 className="text-lg font-semibold">{title}</h3>
                {info && (
                    <Icon 
                        icon={infoIcon} 
                        className="w-5 h-5 text-gray-400 hover:text-gray-600 cursor-help"
                        title={info}
                    />
                )}
            </div>
            {children}
        </Card>
    );

    return (
        <section className="space-y-6">
            <h2 className="text-xl font-bold">Month to Date</h2>
            
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {/* On Time Starts */}
                {renderChartSection(
                    "On Time Starts",
                    "Displays the percentage of cases that were on time and took place within the current calendar month.",
                    <ServiceBarChart data={data.onTimeStarts.byService} />
                )}

                {/* Average Turnover */}
                {renderChartSection(
                    "Average Turnover",
                    "Displays the average room and procedure turnover for cases that took place within the current calendar month. If the service is different for the case preceding the turnover and the case following the turnover, then the turnover will fall under the 'No Value' category.",
                    <DualBarChart data={data.avgTurnover.byService} />
                )}

                {/* Case Length Accuracy */}
                {renderChartSection(
                    "Case Length Accuracy",
                    "Displays the percentage of cases performed within the current calendar month that were accurately scheduled.",
                    <StackedBarChart data={data.caseLengthAccuracy.byService} />
                )}

                {/* Block Utilization */}
                {renderChartSection(
                    "Block Utilization",
                    "Displays a high-level summary of OR block utilization data for your OR locations. The data is only refreshed when utilization batch jobs are run.",
                    <div className="overflow-x-auto">
                        <table className="min-w-full">
                            <thead>
                                <tr className="border-b">
                                    <th className="text-left py-2">Location</th>
                                    <th className="text-right py-2">MTD</th>
                                    <th className="text-right py-2">Last Month</th>
                                    <th className="text-right py-2">Last Three Months Combined</th>
                                    <th className="text-right py-2">Projected End of Month</th>
                                    <th className="text-right py-2">Sched. 1/15 - 1/26</th>
                                </tr>
                            </thead>
                            <tbody>
                                {data.blockUtilization.locations.map((location, index) => (
                                    <tr key={index} className="border-b">
                                        <td className="py-2">{location.name}</td>
                                        <td className="text-right py-2">{location.mtd}%</td>
                                        <td className="text-right py-2">{location.lastMonth}%</td>
                                        <td className="text-right py-2">{location.lastThreeMonths}%</td>
                                        <td className="text-right py-2">{location.projected}%</td>
                                        <td className="text-right py-2">{location.sched}%</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {/* Primetime Utilization */}
                {renderChartSection(
                    "Primetime Utilization",
                    "Displays the total in-room and setup/cleanup minutes over the available primetime minutes from the last year. The Primetime Room Utilization data includes rooms closed due to staff being unavailable, and cases only count against open available time for the room they are performed in. The Primetime Staffed Room Utilization data does not include rooms closed due to staff being unavailable in the denominator, but cases will 'float' across rooms to be in the numerator of the percentage. Filter to a single location to drill into line-level details.",
                    <LineChart data={data.primetimeUtilization.trend} />
                )}

                {/* Performed Cases */}
                {renderChartSection(
                    "Performed Cases",
                    "Displays the volume of cases that were performed within the current calendar month.",
                    <ServiceBarChart data={data.performedCases.byService} />
                )}

                {/* DoS Cancellations */}
                {renderChartSection(
                    "DoS Cancellations",
                    "Displays the number of cases from the current calendar month that were canceled, rescheduled, or for which no procedure was performed on the day of surgery.",
                    <ServiceBarChart data={data.doSCancellations.byService} />
                )}

                {/* Workbench Reports */}
                <div className="lg:col-span-2">
                    <WorkbenchReports reports={syntheticData.workbenchReports} />
                </div>
            </div>
        </section>
    );
};

export default MonthToDateSection;
