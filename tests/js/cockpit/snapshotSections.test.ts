// Zephyrus 2.0 P1 — the §3.2 snapshot-sections contract. Mirrors what
// SnapshotBuilder emits; P1 acceptance requires the payload to parse
// without fallback.
import { describe, expect, it } from 'vitest';

import { cockpitSnapshotSectionsSchema } from '@/types/cockpit';

const metricValue = (key: string, status = 'normal', metadata?: Record<string, unknown>) => ({
  key,
  label: key,
  value: 42,
  display: '42',
  unit: '%',
  sub: null,
  status,
  target: 85,
  direction: 'down',
  trend: [40, 41, 42],
  trendLabel: null,
  updatedAt: '2026-07-04T12:00:00+00:00',
  ...(metadata ? { metadata } : {}),
});

const payload = {
  // Legacy contract keys coexist on the same payload — the schema ignores them.
  generatedAtIso: '2026-07-04T12:00:00+00:00',
  heroMetrics: [],

  asOf: '2026-07-04T12:00:00+00:00',
  facility: { name: 'Summit Regional Medical Center', licensedBeds: 500, level: 'Academic Medical Center' },
  capacityStatus: { level: 'Surge Level 2', code: 'yellow', status: 'warn' },
  census: [metricValue('rtdc.occupancy', 'warn'), metricValue('rtdc.boarders', 'warn')],
  alerts: [
    { key: 'ed.nedocs', status: 'crit', text: 'ED OVERCROWDED — NEDOCS 142', provenance: 'demo' },
    { key: 'rtdc.occupancy', status: 'warn', text: 'House occupancy 87% — above the safe zone' },
  ],
  okrs: [
    {
      ...metricValue('okr.dc_before_noon', 'warn'),
      objective: 'Smooth Throughput',
      keyResult: 'Discharge before noon',
      owner: 'CNO',
    },
  ],
  domains: {
    rtdc: { provenance: 'live', gaugeKey: 'rtdc.occupancy', tiles: [metricValue('rtdc.occupancy', 'warn')] },
    quality: {
      provenance: 'demo',
      gaugeKey: null,
      tiles: [metricValue('quality.hand_hygiene', 'ok', { provenance: 'demo' })],
    },
  },
};

describe('cockpitSnapshotSectionsSchema', () => {
  it('parses the SnapshotBuilder §3.2 sections without fallback', () => {
    const parsed = cockpitSnapshotSectionsSchema.parse(payload);

    expect(parsed.capacityStatus.code).toBe('yellow');
    expect(parsed.alerts[0].status).toBe('crit');
    expect(parsed.okrs[0].owner).toBe('CNO');
    expect(parsed.domains.quality.provenance).toBe('demo');
  });

  it('rejects a non-logical status in a tile', () => {
    const bad = {
      ...payload,
      census: [metricValue('rtdc.occupancy', 'critical')], // canon token, not logical state
    };

    expect(() => cockpitSnapshotSectionsSchema.parse(bad)).toThrow();
  });

  it('rejects an alert outside warn/crit — normal tiles never enter the ticker', () => {
    const bad = {
      ...payload,
      alerts: [{ key: 'rtdc.census', status: 'normal', text: 'House census 412' }],
    };

    expect(() => cockpitSnapshotSectionsSchema.parse(bad)).toThrow();
  });
});
