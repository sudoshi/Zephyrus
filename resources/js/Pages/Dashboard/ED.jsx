import React from 'react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { Head } from '@inertiajs/react';
import { Section, MetricGrid, Panel, metric } from '@/Components/system';
import { Icon } from '@iconify/react';
import TrendChart from '@/Components/Analytics/Common/TrendChart';
import AlertsAndPredictions from '@/Components/ED/AlertsAndPredictions';
import ResourceManagement from '@/Components/ED/ResourceManagement';
import { edMetrics, performanceMetrics, patientStatusBoard, alertsData } from '@/mock-data/ed';
import { formatDurationMinutes } from '@/lib/duration';

// Emergency Department workflow dashboard, rebuilt on the gold-standard design
// system. The top-line department + performance numbers form one MetricGrid hero
// wall (status paired with tone, never colour alone); triage mix, wait-time
// trend, and the patient status board live in Panels under Section headers. The
// Resource Management and Alerts/Predictions composites keep their own surfaces.
// All values flow from live props (edMetrics / performanceMetrics / board /
// alerts); mock-data is only the dev fallback — nothing is fabricated here.

const formatMinutes = (value) => {
    if (value === null || value === undefined || value === '') return formatDurationMinutes(null);
    const minutes = Number(value);

    return formatDurationMinutes(Number.isFinite(minutes) ? minutes : null);
};
const formatTargetTime = (value) => {
    const minutes = Number.parseFloat(value);

    return Number.isFinite(minutes) ? formatDurationMinutes(minutes) : value;
};

const EDDashboard = ({
    edMetrics: edMetricsProp = edMetrics,
    performanceMetrics: performanceMetricsProp = performanceMetrics,
    patientStatusBoard: patientStatusBoardProp = patientStatusBoard,
    alertsData: alertsDataProp = alertsData,
}) => {
    const ed = edMetricsProp;
    const perf = performanceMetricsProp;
    const board = patientStatusBoardProp;
    const alerts = alertsDataProp;

    const cs = ed.currentStatus;
    const occupancyStatus = cs.occupancy >= 90 ? 'critical' : cs.occupancy >= 80 ? 'warning' : 'success';
    const waitingStatus = cs.waitingRoom > 15 ? 'critical' : cs.waitingRoom > 10 ? 'warning' : 'success';
    const d2pStatus =
        perf.doorToProvider.current > perf.doorToProvider.target * 1.5 ? 'critical'
            : perf.doorToProvider.current > perf.doorToProvider.target ? 'warning' : 'success';
    const lwbsStatus =
        perf.leftWithoutBeingSeen.current > perf.leftWithoutBeingSeen.target * 1.5 ? 'critical'
            : perf.leftWithoutBeingSeen.current > perf.leftWithoutBeingSeen.target ? 'warning' : 'success';
    const losStatus = (gate) =>
        gate.current > gate.target * 1.5 ? 'critical' : gate.current > gate.target ? 'warning' : 'success';

    // Real hourly wait-time series → trajectory on the wait-time hero tile.
    const waitTrajectory = (ed.waitTimes?.trends ?? []).map((t) => t.waitTime);

    // Hero KPI wall — top-line department + performance numbers. Status pairs the
    // breach logic with tone; the label + value carry meaning, never colour alone.
    const heroMetrics = [
        metric({
            key: 'total-patients', label: 'Total patients', value: Number(cs.totalPatients),
            status: occupancyStatus, target: Number(cs.capacity), targetDisplay: `${cs.capacity} capacity`,
            caption: `${cs.occupancy}% occupancy · ${cs.criticalCases} critical`,
            definition: 'Patients currently in the department against staffed capacity.',
        }),
        metric({
            key: 'waiting-room', label: 'Waiting room', value: Number(cs.waitingRoom),
            status: waitingStatus, goodWhenDown: true, trajectory: waitTrajectory,
            caption: `${formatMinutes(cs.averageWaitTime)} avg wait`,
            definition: 'Patients in the waiting room awaiting a treatment space.',
        }),
        metric({
            key: 'door-to-provider', label: 'Door to provider', value: Number(perf.doorToProvider.current),
            display: formatMinutes(perf.doorToProvider.current), status: d2pStatus, goodWhenDown: true,
            target: Number(perf.doorToProvider.target), targetDisplay: `${formatMinutes(perf.doorToProvider.target)} target`,
            caption: `${perf.doorToProvider.trend === 'up' ? '+' : '-'}${formatMinutes(Math.abs(Number(perf.doorToProvider.trendValue) || 0))} vs prior`,
            definition: 'Median time from arrival to first provider contact.',
        }),
        metric({
            key: 'lwbs', label: 'Left without being seen', value: Number(perf.leftWithoutBeingSeen.current), unit: '%',
            status: lwbsStatus, goodWhenDown: true, target: Number(perf.leftWithoutBeingSeen.target),
            caption: `${perf.leftWithoutBeingSeen.trend === 'up' ? '+' : '-'}${perf.leftWithoutBeingSeen.trendValue} pts vs prior`,
            definition: 'Share of arrivals who leave before being evaluated. Target ≤ 2%.',
        }),
        metric({
            key: 'los-admitted', label: 'LOS — admitted', value: Number(perf.lengthOfStay.admitted.current),
            display: formatMinutes(perf.lengthOfStay.admitted.current), status: losStatus(perf.lengthOfStay.admitted),
            goodWhenDown: true, target: Number(perf.lengthOfStay.admitted.target),
            targetDisplay: `${formatMinutes(perf.lengthOfStay.admitted.target)} target`,
            caption: `${perf.lengthOfStay.admitted.trend === 'up' ? '+' : '-'}${formatMinutes(perf.lengthOfStay.admitted.trendValue)} vs target`,
            definition: 'Median ED length of stay for patients admitted to an inpatient bed.',
        }),
        metric({
            key: 'los-discharged', label: 'LOS — discharged', value: Number(perf.lengthOfStay.discharged.current),
            display: formatMinutes(perf.lengthOfStay.discharged.current), status: losStatus(perf.lengthOfStay.discharged),
            goodWhenDown: true, target: Number(perf.lengthOfStay.discharged.target),
            targetDisplay: `${formatMinutes(perf.lengthOfStay.discharged.target)} target`,
            caption: `${perf.lengthOfStay.discharged.trend === 'up' ? '+' : '-'}${formatMinutes(perf.lengthOfStay.discharged.trendValue)} vs target`,
            definition: 'Median ED length of stay for patients discharged home.',
        }),
    ];

    // Throughput + staffing already ship in the payload — render, don't recompute.
    const throughputRows = [
        { key: 'arrivals', label: 'Arrivals' },
        { key: 'admissions', label: 'Admissions' },
        { key: 'discharges', label: 'Discharges' },
        { key: 'leftWithoutBeingSeen', label: 'LWBS' },
    ];
    const staffingRoles = [
        { key: 'physicians', label: 'Physicians' },
        { key: 'nurses', label: 'Nurses' },
        { key: 'techs', label: 'Techs' },
    ];

    const triageDot = (category) =>
        category === 'resuscitation' ? 'bg-healthcare-critical dark:bg-healthcare-critical-dark'
            : category === 'emergent' ? 'bg-healthcare-warning dark:bg-healthcare-warning-dark'
                : 'bg-healthcare-success dark:bg-healthcare-success-dark';

    return (
        <DashboardLayout>
            <Head title="ED Dashboard - ZephyrusOR" />
            <PageContentLayout
                title="Emergency Department"
                subtitle="Real-time ED operations and metrics"
            >
                <div className="flex flex-col gap-5">
                    {/* Hero KPI wall */}
                    <Section
                        title="Department status"
                        icon="heroicons:heart"
                        summary={`${cs.totalPatients}/${cs.capacity} beds · ${cs.occupancy}% occupancy`}
                    >
                        <MetricGrid metrics={heroMetrics} />
                    </Section>

                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
                        {/* Triage categories */}
                        <Section
                            title="Triage categories"
                            icon="heroicons:funnel"
                            summary="Patients by acuity tier and target time"
                        >
                            <Panel className="space-y-3 p-4">
                                {Object.entries(ed.triageCategories).map(([category, data]) => (
                                    <div key={category} className="flex items-center justify-between p-3 bg-healthcare-background dark:bg-healthcare-background-dark rounded-lg">
                                        <div className="flex items-center space-x-3">
                                            <div className={`w-2 h-2 rounded-full ${triageDot(category)}`} aria-hidden="true" />
                                            <span className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark capitalize">
                                                {category}
                                            </span>
                                        </div>
                                        <div className="flex items-center space-x-4">
                                            <span className="text-sm tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                {data.count} patients
                                            </span>
                                            <span className="text-xs text-healthcare-text-tertiary dark:text-healthcare-text-tertiary-dark">
                                                Target: {formatTargetTime(data.targetTime)}
                                            </span>
                                        </div>
                                    </div>
                                ))}
                            </Panel>
                        </Section>

                        {/* Wait time trends */}
                        <Section
                            title="Wait time trends"
                            icon="heroicons:chart-bar"
                            summary="Average wait time across the day"
                        >
                            <Panel className="p-4">
                                <div className="h-48">
                                    <TrendChart
                                        data={ed.waitTimes.trends}
                                        series={[
                                            {
                                                dataKey: 'waitTime',
                                                name: 'Wait Time',
                                            },
                                        ]}
                                        xAxis={{
                                            dataKey: 'hour',
                                            type: 'category',
                                        }}
                                        yAxis={{ formatter: formatMinutes }}
                                        tooltip={{ formatter: formatMinutes }}
                                    />
                                </div>
                            </Panel>
                        </Section>
                    </div>

                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
                        {/* Departmental flow — last hour vs today, straight from throughput */}
                        <Section
                            title="Departmental flow"
                            icon="heroicons:arrows-right-left"
                            summary={`${ed.throughput.today.arrivals} arrivals today · ${ed.throughput.lastHour.arrivals} last hour`}
                        >
                            <Panel className="p-4">
                                <table className="min-w-full">
                                    <thead>
                                        <tr className="border-b border-healthcare-border dark:border-healthcare-border-dark">
                                            <th className="py-2 text-left text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Measure</th>
                                            <th className="py-2 text-right text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Last hour</th>
                                            <th className="py-2 text-right text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Today</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                                        {throughputRows.map((row) => (
                                            <tr key={row.key}>
                                                <td className="py-2 text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{row.label}</td>
                                                <td className="py-2 text-right text-sm tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                    {ed.throughput.lastHour[row.key]}
                                                </td>
                                                <td className="py-2 text-right text-sm tabular-nums font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                    {ed.throughput.today[row.key]}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </Panel>
                        </Section>

                        {/* Staffing posture — current coverage plus the next-shift plan */}
                        <Section
                            title="Staffing posture"
                            icon="heroicons:user-group"
                            summary="Current shift coverage and next-shift plan"
                        >
                            <Panel className="space-y-3 p-4">
                                {staffingRoles.map((role) => {
                                    const current = ed.staffing.current[role.key];
                                    const next = ed.staffing.nextShift[role.key];
                                    const short = current.present < current.required;

                                    return (
                                        <div key={role.key} className="flex items-center justify-between p-3 bg-healthcare-background dark:bg-healthcare-background-dark rounded-lg">
                                            <span className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                {role.label}
                                            </span>
                                            <div className="flex items-center space-x-4">
                                                <span className={`text-sm tabular-nums ${short
                                                    ? 'font-semibold text-healthcare-warning dark:text-healthcare-warning-dark'
                                                    : 'text-healthcare-text-primary dark:text-healthcare-text-primary-dark'}`}
                                                >
                                                    {current.present} of {current.required} present{short ? ' — short' : ''}
                                                </span>
                                                <span className="text-xs tabular-nums text-healthcare-text-tertiary dark:text-healthcare-text-tertiary-dark">
                                                    Next shift: {next.scheduled} of {next.required}
                                                </span>
                                            </div>
                                        </div>
                                    );
                                })}
                            </Panel>
                        </Section>
                    </div>

                    {/* Patient Status Board */}
                    <Section
                        title="Patient status board"
                        icon="heroicons:clipboard-document-list"
                        summary={`${board.length} active patients tracked`}
                    >
                        <Panel className="p-4">
                            <div className="overflow-x-auto">
                                <table className="min-w-full divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                                    <thead>
                                        <tr>
                                            {['Location', 'Chief Complaint', 'Triage Level', 'Wait Time', 'Next Action', 'Provider'].map((h) => (
                                                <th key={h} className="px-4 py-3 text-left text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                    {h}
                                                </th>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                                        {board.map((patient) => (
                                            <tr key={patient.id} className="hover:bg-healthcare-background dark:hover:bg-healthcare-background-dark transition-colors duration-200">
                                                <td className="px-4 py-3 text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                    {patient.location}
                                                </td>
                                                <td className="px-4 py-3 text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                    {patient.chiefComplaint}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${
                                                        patient.triageLevel <= 2 ? 'bg-healthcare-critical/20 text-healthcare-critical dark:text-healthcare-critical-dark' :
                                                        patient.triageLevel === 3 ? 'bg-healthcare-warning/20 text-healthcare-warning dark:text-healthcare-warning-dark' :
                                                        'bg-healthcare-success/20 text-healthcare-success dark:text-healthcare-success-dark'
                                                    }`}>
                                                        Level {patient.triageLevel}
                                                    </span>
                                                </td>
                                                <td className="px-4 py-3 text-sm tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                    {formatMinutes(patient.waitTime)}
                                                </td>
                                                <td className="px-4 py-3 text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                    {patient.nextAction}
                                                </td>
                                                <td className="px-4 py-3 text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                    {patient.provider}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </Panel>
                    </Section>

                    {/* Resource Management — composite keeps its own surface */}
                    <Section
                        title="Resource management"
                        icon="heroicons:cube"
                        summary="Current bed and equipment availability"
                    >
                        <ResourceManagement resources={ed.resources} />
                    </Section>

                    {/* Alerts and Predictions — composite keeps its own surfaces */}
                    <Section
                        title="Alerts & predictions"
                        icon="heroicons:bell-alert"
                        summary="Active notifications and forecasted events"
                    >
                        <AlertsAndPredictions
                            alerts={alerts.alerts}
                            predictions={ed.predictions}
                        />
                    </Section>
                </div>
            </PageContentLayout>
        </DashboardLayout>
    );
};

export default EDDashboard;
