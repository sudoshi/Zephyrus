import { afterEach, describe, expect, it } from 'vitest';
import { installSoakHook } from '@/features/patientFlowNavigator/soakHook';
import type { Flow4dSoakHook } from '@/features/patientFlowNavigator/soakHook';

function stubHook(overrides: Partial<Flow4dSoakHook> = {}): Flow4dSoakHook {
  return {
    rendererInfo: () => null,
    nowDeltaMs: () => null,
    roundsRun: () => null,
    epoch: () => null,
    pathwayGlyphs: () => null,
    contextEvents: () => null,
    frameBudget: () => null,
    ...overrides,
  };
}

describe('installSoakHook (H4.1)', () => {
  afterEach(() => {
    delete window.__FLOW4D_SOAK__;
  });

  it('installs the hook and passes getters through', () => {
    const uninstall = installSoakHook(stubHook({
      rendererInfo: () => ({ geometries: 12, textures: 4, calls: 60, triangles: 20_000 }),
      nowDeltaMs: () => 1_500,
      roundsRun: () => ({ uuid: 'run-uuid', status: 'active' }),
      pathwayGlyphs: () => 3,
      contextEvents: () => ({ lost: 2, restored: 2, currentlyLost: false }),
      frameBudget: () => ({ frameP95: 8.2, renderP95: 5.1, labelsP95: 0.4, controlsP95: 0.6, samples: 240 }),
    }));

    expect(window.__FLOW4D_SOAK__?.rendererInfo()).toEqual({ geometries: 12, textures: 4, calls: 60, triangles: 20_000 });
    expect(window.__FLOW4D_SOAK__?.nowDeltaMs()).toBe(1_500);
    expect(window.__FLOW4D_SOAK__?.roundsRun()).toEqual({ uuid: 'run-uuid', status: 'active' });
    expect(window.__FLOW4D_SOAK__?.pathwayGlyphs()).toBe(3);
    expect(window.__FLOW4D_SOAK__?.contextEvents()).toEqual({ lost: 2, restored: 2, currentlyLost: false });
    expect(window.__FLOW4D_SOAK__?.frameBudget()).toEqual({ frameP95: 8.2, renderP95: 5.1, labelsP95: 0.4, controlsP95: 0.6, samples: 240 });
    uninstall();
  });

  it('uninstall removes its own installation', () => {
    const uninstall = installSoakHook(stubHook());
    expect(window.__FLOW4D_SOAK__).toBeDefined();
    uninstall();
    expect(window.__FLOW4D_SOAK__).toBeUndefined();
  });

  it('a stale uninstaller leaves a newer installation in place', () => {
    const first = installSoakHook(stubHook());
    const second = stubHook({ nowDeltaMs: () => 42 });
    installSoakHook(second);
    first();
    expect(window.__FLOW4D_SOAK__).toBe(second);
    expect(window.__FLOW4D_SOAK__?.nowDeltaMs()).toBe(42);
  });
});
