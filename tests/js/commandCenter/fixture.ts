// tests/js/commandCenter/fixture.ts
import type { CommandCenterData, KpiMetric } from '@/types/commandCenter';

const m = (key: string, label: string, drillHref: string | null = null): KpiMetric => ({
  key, label, value: 1, unit: '', display: '1', target: null, targetDisplay: null,
  status: 'success', trajectory: { points: [1, 2, 3], direction: 'up', goodWhenDown: false },
  drillHref, definition: `${label} def`,
});

export const commandCenterFixture: CommandCenterData = {
  generatedAtIso: '2026-06-22T12:00:00Z',
  strain: { level: 2, label: 'Surge Level 2', status: 'warning', previousLevel: 1,
    drivers: [{ label: 'Occupancy', value: '88%', status: 'warning' }], updatedAtIso: '2026-06-22T12:00:00Z' },
  heroMetrics: [m('occupancy', 'Occupancy', '/rtdc/bed-tracking'), m('net_beds', 'Net Bed Position')],
  capacity: { key: 'capacity', title: 'Capacity', summary: 's', drillHref: '/rtdc/bed-tracking',
    drillLabel: 'open RTDC', metrics: [m('available_beds', 'Available')] },
  flow: { key: 'flow', title: 'Flow', summary: 's', drillHref: '/dashboard/emergency', drillLabel: 'open ED',
    metrics: [], subgroups: [{ key: 'ed', label: 'Emergency', metrics: [m('ed_d2p', 'Door-to-Provider')] }] },
  outcomes: { key: 'outcomes', title: 'Outcomes', summary: 's', drillHref: '/dashboard/improvement',
    drillLabel: 'open Improvement', metrics: [m('readmission', '30-Day Readmission')] },
  forecast: { key: 'forecast', title: 'Forecast', summary: 's', drillHref: '/rtdc/predictions/demand',
    drillLabel: 'open Predictions', metrics: [m('pred_discharges', 'Discharges 24h')] },
  forecastDetail: { predictedDischarges24h: 22, predictedDischarges48h: 41, predictedEdArrivals: 60,
    predictedAdmissions: 18, netBedPosition: -3, surgeProbabilityPct: 38,
    occupancyCurve: [{ hourOffset: 0, occupancyPct: 88, lowerPct: 85, upperPct: 91 }],
    netBedByUnit: [{ unitId: 1, name: '5 East', net: -2 }] },
  unitCensus: [{ unitId: 1, name: '5 East', type: 'Med-Surg', staffed: 30, occupied: 27, blocked: 1,
    available: 2, occupancyPct: 90, acuityAdjustedPct: 92, status: 'warning' }],
  objectives: [{ key: 'flow', title: 'Improve access & flow',
    keyResults: [{ label: 'ED boarding', current: 168, target: 120, baseline: 192, progressPct: 33,
      status: 'warning', display: '168→<120 min' }] }],
};
