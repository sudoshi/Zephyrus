import React from 'react';
import { Icon } from '@iconify/react';
import Modal from '@/Components/Common/Modal';
import { Sparkline } from '@/Components/cockpit/Sparkline';

// Zephyrus 2.0 P3 rewrite. The old component fabricated its entire body —
// a Math.random() hourly chart and a synthetic service table — and ignored
// the `title`/`children` its RTDC consumers were passing (rendering an
// "undefined Details" shell). Two real modes now:
//   children mode (RTDC compact widgets): title + caller-provided detail.
//   metric mode (periop Last Month tiles): a system KpiMetric — real value,
//   real previous→current trend, the metric's own definition text.
// The A0 cockpit drills are cockpit/DrillModal; this remains only for the
// legacy overview pages until P4a retires them.

const MetricDetail = ({ metric }) => {
    const points = metric.trajectory?.points ?? [];
    const previous = points.length >= 2 ? points[0] : null;
    const delta = previous !== null ? Math.round((metric.value - previous) * 10) / 10 : null;
    const suffix = metric.unit === '%' ? '%' : metric.unit ? ` ${metric.unit}` : '';
    const stats = [
        { title: 'Current', icon: 'heroicons:clock', value: metric.display, label: metric.caption ?? '' },
        previous !== null
            && { title: 'Previous period', icon: 'heroicons:chart-bar', value: `${previous}${suffix}`, label: 'prior calendar month' },
        delta !== null
            && {
                title: 'Change',
                icon: delta >= 0 ? 'heroicons:arrow-trending-up' : 'heroicons:arrow-trending-down',
                value: `${delta >= 0 ? '+' : ''}${delta}${suffix}`,
                label: 'vs. previous period',
            },
    ].filter(Boolean);

    return (
        <div className="space-y-6">
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                {stats.map((stat) => (
                    <div
                        key={stat.title}
                        className="bg-healthcare-surface dark:bg-healthcare-surface-dark p-4 rounded-lg border border-healthcare-border dark:border-healthcare-border-dark"
                    >
                        <div className="flex items-center justify-between">
                            <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                {stat.title}
                            </div>
                            <Icon
                                icon={stat.icon}
                                className="w-5 h-5 text-healthcare-info dark:text-healthcare-info-dark"
                            />
                        </div>
                        <div className="mt-2">
                            <div className="text-2xl font-semibold tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                {stat.value}
                            </div>
                            {stat.label && (
                                <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                    {stat.label}
                                </div>
                            )}
                        </div>
                    </div>
                ))}
            </div>

            {points.length >= 2 && (
                <div>
                    <h3 className="text-sm font-medium mb-2 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                        Trend
                    </h3>
                    <Sparkline
                        data={points}
                        status={metric.status}
                        target={metric.target}
                        id={`drilldown-${metric.key}`}
                    />
                </div>
            )}

            {metric.definition && (
                <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    {metric.definition}
                </p>
            )}
        </div>
    );
};

const DrillDownModal = ({ isOpen, onClose, title, metric, children }) => {
    const heading = title ?? (metric ? `${metric.label} Details` : 'Details');

    return (
        <Modal open={isOpen} onClose={onClose} title={heading} maxWidth="4xl">
            {children ?? (metric ? <MetricDetail metric={metric} /> : null)}
        </Modal>
    );
};

export default DrillDownModal;
