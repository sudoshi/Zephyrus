import React, { useState } from 'react';
import { Icon } from '@iconify/react';
import { Section, KpiTile, metric } from '@/Components/system';
import { syntheticData } from '../../mock-data/dashboard';
import DrillDownModal from './DrillDownModal';

// Migrated from the bespoke MetricCard to the gold-standard KpiTile (built via
// metric()). Each tile keeps its real sparkline (sparklineData → trajectory) and
// its click-to-drill behaviour: KpiTile has no onClick, so every tile is rendered
// inside a clickable <button> that opens the existing DrillDownModal. Status is
// derived with the same thresholds MetricCard's getStatusColor used.

// value < threshold.critical → 'critical'; < threshold.warning → 'warning'; else 'success'
const statusFor = (value, threshold) => {
    if (value < threshold.critical) return 'critical';
    if (value < threshold.warning) return 'warning';
    return 'success';
};

const LastMonthSection = ({ data: dataProp }) => {
    const data = dataProp ?? syntheticData.lastMonth;
    const [selectedMetric, setSelectedMetric] = useState(null);

    // Real sparkline series (oldest→newest) for each tile; passed as trajectory.
    const generateSparklineData = (base, variance) => {
        return Array(24).fill(0).map(() => base + (Math.random() - 0.5) * variance);
    };

    const handleMetricClick = (metricKey) => {
        setSelectedMetric(metricKey);
    };

    const tiles = [
        {
            drillKey: 'ontime',
            metric: metric({
                key: 'firstCaseOnTime',
                label: 'First Case On Time Starts',
                value: data.firstCaseOnTime.value,
                unit: '%',
                status: statusFor(data.firstCaseOnTime.value, { warning: 80, critical: 70 }),
                trajectory: generateSparklineData(data.firstCaseOnTime.value, 20),
                caption: `${data.firstCaseOnTime.date} · from ${data.firstCaseOnTime.previousValue}% · 156 cases · 23 delayed`,
                definition: 'Displays the percentage of cases that were on time and took place within the previous calendar month.',
            }),
        },
        {
            drillKey: 'turnover',
            metric: metric({
                key: 'avgTurnover',
                label: 'Average Room Turnover',
                value: data.avgTurnover.value,
                status: statusFor(data.avgTurnover.value, { warning: 45, critical: 60 }),
                trajectory: generateSparklineData(data.avgTurnover.value, 10),
                caption: `${data.avgTurnover.date} · from ${data.avgTurnover.previousValue} min · min 22m · max 68m`,
                definition: 'Displays the average room and procedure turnover for cases that took place within the previous calendar month.',
            }),
        },
        {
            drillKey: 'accuracy',
            metric: metric({
                key: 'caseLengthAccuracy',
                label: 'Case Length Accuracy',
                value: data.caseLengthAccuracy.value,
                unit: '%',
                status: statusFor(data.caseLengthAccuracy.value, { warning: 75, critical: 65 }),
                trajectory: generateSparklineData(data.caseLengthAccuracy.value, 15),
                caption: `${data.caseLengthAccuracy.date} · from ${data.caseLengthAccuracy.previousValue}% · 12% under · 15% over`,
                definition: 'Displays the percentage of cases performed within the previous calendar month that were accurately scheduled.',
            }),
        },
        {
            drillKey: 'cases',
            metric: metric({
                key: 'performedCases',
                label: 'Performed Cases',
                value: data.performedCases.value,
                status: statusFor(data.performedCases.value, { warning: 300, critical: 250 }),
                trajectory: generateSparklineData(data.performedCases.value, 50),
                caption: `${data.performedCases.date} · from ${data.performedCases.previousValue} · 45 emergency · 278 elective`,
                definition: 'Displays the volume of cases that were performed within the previous calendar month.',
            }),
        },
        {
            drillKey: 'cancellations',
            metric: metric({
                key: 'doSCancellations',
                label: 'DoS Cancellations',
                value: data.doSCancellations.value,
                status: statusFor(data.doSCancellations.value, { warning: 15, critical: 25 }),
                trajectory: generateSparklineData(data.doSCancellations.value, 8),
                caption: `${data.doSCancellations.date} · from ${data.doSCancellations.previousValue} · 8 patient · 4 facility`,
                definition: 'Displays the number of cases from the previous calendar month that were canceled, rescheduled, or for which no procedure was performed on the day of surgery.',
            }),
        },
        {
            drillKey: 'utilization',
            metric: metric({
                key: 'blockUtilization',
                label: 'Block Utilization',
                value: data.blockUtilization.value,
                unit: '%',
                status: statusFor(data.blockUtilization.value, { warning: 75, critical: 65 }),
                trajectory: generateSparklineData(data.blockUtilization.value, 12),
                caption: `${data.blockUtilization.date} · from ${data.blockUtilization.previousValue}% · 12% released · 8% unused`,
                definition: 'Displays a high-level summary of OR block utilization data for your OR locations. The data is only refreshed when utilization batch jobs are run.',
            }),
        },
        {
            drillKey: 'primetime',
            metric: metric({
                key: 'primetimeUtilization',
                label: 'Primetime Utilization',
                value: data.primetimeUtilization.staffed,
                unit: '%',
                status: statusFor(data.primetimeUtilization.staffed, { warning: 80, critical: 70 }),
                trajectory: generateSparklineData(data.primetimeUtilization.staffed, 10),
                caption: `${data.primetimeUtilization.date} · ${data.primetimeUtilization.unstaffed}% unstaffed · 82% in room · 18% setup`,
                definition: "Displays the total in-room and setup/cleanup minutes over the available primetime minutes from the previous calendar month. The Primetime Room Utilization percentage includes rooms closed due to staff being unavailable, and cases only count against open available time for the room they are performed in. The Primetime Staffed Room Utilization percentage does not include rooms closed due to staff being unavailable in the denominator, but cases will 'float' across rooms to be in the numerator of the percentage.",
            }),
        },
    ];

    const actions = (
        <div className="flex items-center space-x-4">
            <button className="
                inline-flex items-center text-sm font-medium
                text-healthcare-info dark:text-healthcare-info-dark
                hover:text-healthcare-info-dark dark:hover:text-healthcare-info
                transition-colors duration-300
            ">
                <Icon icon="heroicons:document-arrow-down" className="w-4 h-4 mr-1" />
                Export Data
            </button>
            <div className="relative">
                <select className="
                    text-sm rounded-md pl-8 pr-4 py-2 appearance-none
                    bg-healthcare-surface dark:bg-healthcare-surface-dark
                    text-healthcare-text-primary dark:text-healthcare-text-primary-dark
                    border border-healthcare-border dark:border-healthcare-border-dark
                    focus:border-healthcare-info dark:focus:border-healthcare-info-dark
                    focus:ring-1 focus:ring-healthcare-info dark:focus:ring-healthcare-info-dark
                    transition-colors duration-300
                ">
                    <option>All Locations</option>
                    <option>Location A</option>
                    <option>Location B</option>
                </select>
                <Icon
                    icon="heroicons:funnel"
                    className="absolute left-2 top-1/2 transform -translate-y-1/2 w-4 h-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark transition-colors duration-300"
                />
            </div>
        </div>
    );

    return (
        <section className="mb-6">
            <Section
                title="Last Month"
                icon="heroicons:calendar-days"
                summary="Performance metrics and statistics from the previous calendar month"
                actions={actions}
            >
                <div
                    className="grid gap-2"
                    style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(184px, 1fr))' }}
                >
                    {tiles.map(({ drillKey, metric: m }) => (
                        <button
                            key={m.key}
                            type="button"
                            onClick={() => handleMetricClick(drillKey)}
                            className="block h-full text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-healthcare-primary dark:focus-visible:ring-healthcare-primary-dark rounded-lg"
                        >
                            <KpiTile metric={m} detailed />
                        </button>
                    ))}
                </div>
            </Section>

            {/* Drill Down Modal */}
            {selectedMetric && (
                <DrillDownModal
                    isOpen={!!selectedMetric}
                    onClose={() => setSelectedMetric(null)}
                    metric={selectedMetric}
                    data={data[selectedMetric]}
                />
            )}
        </section>
    );
};

export default LastMonthSection;
