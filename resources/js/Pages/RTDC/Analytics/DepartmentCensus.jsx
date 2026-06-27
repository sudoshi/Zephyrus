import RTDCPageLayout from '../RTDCPageLayout';
import { Section, MetricGrid, Panel, metric } from '@/Components/system';
import TrendChart from '@/Components/Analytics/Common/TrendChart';
import DateRangeSelector from '@/Components/Common/DateRangeSelector';

// Department Census rebuilt on the gold-standard design system: the census,
// staffing, and capacity KPI walls are MetricGrids of KpiTiles (status + gauge
// + target + caption) under Section headers; the trend chart lives in a Panel.
// Values here are representative demo data until a live census feed is wired in.

// Current occupancy by department. Occupancy is "good when down" (higher =
// tighter capacity), so a rising occupancy reads as warning/critical.
const censusMetrics = [
    metric({
        key: 'medsurg', label: 'Medical/Surgical', value: 92, unit: '%', status: 'critical',
        target: 85, goodWhenDown: true, caption: '147/160 beds · +3% vs prior',
        definition: 'Med/Surg occupancy. Above 90% strains throughput and staffing.',
    }),
    metric({
        key: 'icu', label: 'ICU', value: 85, unit: '%', status: 'warning',
        target: 80, goodWhenDown: true, caption: '17/20 beds · -2% vs prior',
        definition: 'Critical-care occupancy. >85% limits surge capacity for admissions.',
    }),
    metric({
        key: 'emergency', label: 'Emergency', value: 78, unit: '%', status: 'success',
        target: 85, goodWhenDown: true, caption: '35/45 beds · +5% vs prior',
        definition: 'ED treatment-space occupancy. Headroom remains before flow degrades.',
    }),
];

// Census impact on staffing. Coverage is good when up; required headcount is a
// neutral count that rises with census.
const staffingMetrics = [
    metric({
        key: 'required-staff', label: 'Required Staff', value: 42, status: 'neutral',
        caption: 'Based on current census · +2 vs prior',
        definition: 'Headcount required to safely cover the current house census.',
    }),
    metric({
        key: 'staff-coverage', label: 'Staff Coverage', value: 95, unit: '%', status: 'warning',
        target: 100, caption: 'Current shift · -3% vs prior',
        definition: 'Present staff as a share of required headcount. Below 100% leaves gaps.',
    }),
];

// Projected capacity needs. Peak occupancy is good when down; additional beds
// is a count of capacity that must be opened to meet the projected peak.
const capacityMetrics = [
    metric({
        key: 'projected-peak', label: 'Projected Peak', value: 96, unit: '%', status: 'critical',
        target: 90, goodWhenDown: true, caption: 'Next 24 hours · +4% vs prior',
        definition: 'Forecast peak house occupancy over the next 24h. >95% signals overflow risk.',
    }),
    metric({
        key: 'additional-beds', label: 'Additional Beds', value: 5, status: 'warning',
        goodWhenDown: true, caption: 'Needed for peak · +2 vs prior',
        definition: 'Beds that must be opened beyond current staffed capacity to meet the peak.',
    }),
];

// Decorative historical trend (hardcoded until the live census feed is wired).
const censusTrendData = [
    { date: '2024-01-01', value: 85 },
    { date: '2024-01-02', value: 87 },
    { date: '2024-01-03', value: 89 },
    { date: '2024-01-04', value: 92 },
    { date: '2024-01-05', value: 88 },
];

const DepartmentCensus = () => {
    return (
        <RTDCPageLayout
            title="Department Census"
            subtitle="Real-time and historical census data by department"
        >
            <div className="flex flex-col gap-5">
                <div>
                    <DateRangeSelector
                        onChange={(range) => console.log('Date range changed:', range)}
                    />
                </div>

                <Section
                    title="Current Census Overview"
                    icon="heroicons:building-office-2"
                    summary="Real-time department occupancy metrics"
                >
                    <MetricGrid metrics={censusMetrics} />
                </Section>

                <Section
                    title="Census Trends"
                    icon="heroicons:chart-bar"
                    summary="Historical census patterns by department"
                >
                    <Panel className="p-4">
                        <div className="h-96">
                            <TrendChart
                                data={censusTrendData}
                                xKey="date"
                                yKey="value"
                                yAxisLabel="Occupancy %"
                            />
                        </div>
                    </Panel>
                </Section>

                <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    <Section
                        title="Staffing Impact"
                        icon="heroicons:user-group"
                        summary="Census impact on staffing requirements"
                    >
                        <MetricGrid metrics={staffingMetrics} />
                    </Section>

                    <Section
                        title="Capacity Planning"
                        icon="heroicons:arrow-trending-up"
                        summary="Projected census and capacity needs"
                    >
                        <MetricGrid metrics={capacityMetrics} />
                    </Section>
                </div>
            </div>
        </RTDCPageLayout>
    );
};

export default DepartmentCensus;
