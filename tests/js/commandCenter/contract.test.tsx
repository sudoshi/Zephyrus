// tests/js/commandCenter/contract.test.tsx
import { describe, it, expect } from 'vitest';
import {
  commandCenterDataSchema,
  commandCenterDrilldownSchema,
  parseCommandCenterData,
  parseCommandCenterDrilldown,
} from '@/types/commandCenter';

const minimalMetric = {
  key: 'occupancy', label: 'Occupancy', value: 88, unit: '%', display: '88%',
  target: 85, targetDisplay: '≤85%', status: 'warning',
  trajectory: { points: [80, 84, 88], direction: 'up', goodWhenDown: true },
  drillHref: '/rtdc/bed-tracking', definition: 'Staffed occupancy.',
};
const band = {
  key: 'capacity', title: 'Capacity', summary: '88% occupied', drillHref: '/rtdc/bed-tracking',
  drillLabel: 'open RTDC', metrics: [minimalMetric],
};
const valid = {
  generatedAtIso: '2026-06-22T12:00:00Z',
  strain: { level: 2, label: 'Surge Level 2', status: 'warning', previousLevel: 1,
    drivers: [{ label: 'Occupancy', value: '88%', status: 'warning' }], updatedAtIso: '2026-06-22T12:00:00Z' },
  heroMetrics: [minimalMetric],
  capacity: band, flow: { ...band, key: 'flow', title: 'Flow' },
  outcomes: { ...band, key: 'outcomes', title: 'Outcomes' },
  forecast: { ...band, key: 'forecast', title: 'Forecast' },
  forecastDetail: { predictedDischarges24h: 22, predictedDischarges48h: 40, predictedEdArrivals: 60,
    predictedAdmissions: 18, netBedPosition: -3, surgeProbabilityPct: 38,
    occupancyCurve: [{ hourOffset: 0, occupancyPct: 88, lowerPct: 85, upperPct: 91 }],
    netBedByUnit: [{ unitId: 1, name: '5 East', net: -2 }] },
  unitCensus: [{ unitId: 1, name: '5 East', type: 'Med-Surg', staffed: 30, occupied: 27, blocked: 1,
    available: 2, occupancyPct: 90, acuityAdjustedPct: 92, status: 'warning' }],
  objectives: [{ key: 'flow', title: 'Improve access & flow',
    keyResults: [{ label: 'ED boarding', current: 168, target: 120, baseline: 192, progressPct: 33,
      status: 'warning', display: '168→<120 min' }] }],
};

describe('command center contract', () => {
  it('parses a valid payload', () => {
    const parsed = parseCommandCenterData(valid);
    expect(parsed.strain.level).toBe(2);
    expect(parsed.flow.title).toBe('Flow');
  });

  it('rejects an invalid payload (bad status enum)', () => {
    const bad = { ...valid, strain: { ...valid.strain, status: 'purple' } };
    expect(() => parseCommandCenterData(bad)).toThrow();
  });

  it('rejects a missing band', () => {
    const { forecast, ...rest } = valid;
    expect(commandCenterDataSchema.safeParse(rest).success).toBe(false);
  });

  it('accepts optional metric detail for explainable KPI visualizations', () => {
    const detailedMetric = {
      ...minimalMetric,
      key: 'ed_lwbs',
      label: 'LWBS',
      detail: {
        caption: 'Last 24h ED arrival cohort',
        segments: [
          { label: 'Seen / active', value: 96, display: '96', status: 'success' },
          { label: 'LWBS', value: 4, display: '4', status: 'warning' },
        ],
        rows: [
          { label: 'Total arrivals', value: '100', status: 'neutral' },
          { label: 'LWBS patients', value: '4', status: 'warning' },
        ],
      },
    };

    expect(commandCenterDataSchema.safeParse({
      ...valid,
      flow: {
        ...valid.flow,
        subgroups: [{ key: 'ed', label: 'Emergency', metrics: [detailedMetric] }],
      },
    }).success).toBe(true);
  });

  it('accepts optional metric lineage and source trust fields', () => {
    const lineageMetric = {
      ...minimalMetric,
      lineageHref: '/api/analytics/metrics/occupancy/lineage',
      lineageSummary: '100% trust from 1 source: Capacity census.',
      sourceTrust: {
        score: 100,
        status: 'success',
        freshSourceCount: 1,
        staleSourceCount: 0,
        missingSourceCount: 0,
      },
    };

    const parsed = parseCommandCenterData({
      ...valid,
      heroMetrics: [lineageMetric],
    });

    expect(parsed.heroMetrics[0].sourceTrust?.score).toBe(100);
    expect(parsed.heroMetrics[0].lineageHref).toBe('/api/analytics/metrics/occupancy/lineage');
  });

  it('parses a valid 90-day drilldown payload', () => {
    const dailyMetric = {
      metricKey: 'occupancy',
      label: 'Occupancy',
      panelKey: 'capacity',
      groupKey: null,
      value: 88,
      display: '88%',
      target: 85,
      targetDisplay: '≤85%',
      status: 'warning',
      varianceToTarget: 3,
    };
    const historyPoint = {
      date: '2026-03-27',
      value: 88,
      display: '88%',
      status: 'warning',
      varianceToTarget: 3,
      detailHref: '/api/command-center/drilldown?focus=metric:occupancy&date=2026-03-27',
    };
    const payload = {
      generatedAtIso: '2026-06-25T12:00:00Z',
      window: {
        startDate: '2026-03-27',
        endDate: '2026-06-25',
        days: 90,
        grain: 'daily',
        minimumDrillDays: 90,
        synthetic: true,
      },
      focus: { type: 'global', key: 'all', label: 'All Command Center detail', matched: true },
      panels: [{
        key: 'capacity',
        title: 'Capacity',
        summary: '88% occupied',
        drillHref: '/rtdc/bed-tracking',
        apiDrillHref: '/api/command-center/drilldown?focus=panel:capacity',
        recommendedInteractions: ['Click a unit for detail.'],
        daily: [{
          date: '2026-03-27',
          panelKey: 'capacity',
          status: 'warning',
          metricCount: 1,
          driverCount: 1,
          metrics: { occupancy: dailyMetric },
          detailHref: '/api/command-center/drilldown?focus=panel:capacity&date=2026-03-27',
        }],
        metrics: [{
          key: 'occupancy',
          label: 'Occupancy',
          panelKey: 'capacity',
          panelTitle: 'Capacity',
          groupKey: null,
          groupLabel: null,
          definition: 'Staffed occupancy.',
          target: 85,
          targetDisplay: '≤85%',
          current: { value: 88, display: '88%', status: 'warning' },
          distribution: { min: 80, p10: 82, median: 86, p90: 91, max: 94 },
          history: [historyPoint],
          recommendedInteractions: ['Open 90-day occupancy history.'],
        }],
      }],
      timeline: [{
        date: '2026-03-27',
        detailHref: '/api/command-center/drilldown?date=2026-03-27',
        status: 'warning',
        driverCount: 1,
        metrics: { occupancy: dailyMetric },
        drivers: [{ metricKey: 'occupancy', panelKey: 'capacity', label: 'Occupancy', display: '88%', status: 'warning' }],
        safetyOpportunityCount: 1,
      }],
      units: [{
        unitId: 1,
        name: '5 East',
        type: 'Med-Surg',
        current: { unitId: 1, name: '5 East' },
        history: [{
          date: '2026-03-27',
          staffed: 30,
          occupied: 27,
          available: 2,
          blocked: 1,
          occupancyPct: 90,
          acuityAdjustedPct: 92,
          status: 'warning',
          detailHref: '/api/command-center/drilldown?focus=unit:1&date=2026-03-27',
        }],
      }],
      events: [{
        eventId: 'sim-2026-03-27-occupancy',
        date: '2026-03-27',
        timestampIso: '2026-03-27T08:00:00Z',
        panelKey: 'capacity',
        metricKey: 'occupancy',
        unitId: 1,
        unitName: '5 East',
        severity: 'warning',
        title: 'Occupancy variance requires review',
        description: 'Synthetic detail.',
        recommendedAction: 'Run a capacity huddle.',
        timeAtRiskMinutes: 90,
        avoidableBedDays: 0.06,
        patientSafetyDomains: ['timely care'],
        synthetic: true,
      }],
      opportunities: [{
        opportunityId: 'opp-occupancy',
        panelKey: 'capacity',
        metricKey: 'occupancy',
        title: 'Improve Occupancy reliability',
        currentSignal: '88% vs ≤85%',
        patientSafetySignal: 'timely care',
        operationalLever: 'Demand-capacity balancing',
        expectedImpact: 'Earlier detection of bed deficits.',
        confidencePct: 70,
        firstActions: ['Run a capacity huddle.'],
        evidenceHref: '/api/command-center/drilldown?focus=metric:occupancy',
      }],
      playbooks: [{
        key: 'capacity-strain',
        title: 'Capacity strain huddle',
        trigger: 'Occupancy above target.',
        cadenceMinutes: 120,
        actions: ['Validate staffed beds.'],
      }],
      dataQuality: {
        mode: 'synthetic_operational_detail',
        clinicalUseNotice: 'Synthetic only.',
        lineage: { headlineMetrics: 'CommandCenterDataService live aggregates' },
      },
    };

    const parsed = parseCommandCenterDrilldown(payload);
    expect(parsed.window.days).toBe(90);
    expect(commandCenterDrilldownSchema.safeParse(payload).success).toBe(true);
  });
});
