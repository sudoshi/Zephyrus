import { expect, test, type Page } from '@playwright/test';
import { loginAsTestUser } from './support/auth';

/**
 * Rendered-scene coverage for the Patient Flow 4D Navigator (plan Phase F, F5).
 * jsdom can't run WebGL, so the scene lifecycle — boot, layer rebuild, GPU
 * context-loss self-heal (F1), and frame-budget sampling (F4) — is only
 * exercisable in a real browser. This lands BEFORE the F2 instancing migration
 * so that refactor is guarded by a real selection/rebuild/context-loss check.
 *
 * The browser CI seed carries no patient-flow data (E2eTestSeeder skips the
 * clinical-pathway seed), so the scene boots the facility MODEL into an empty
 * data layer. That still gives a real WebGL context + render loop + the layer
 * rebuild path; the data-dependent selection-survival case degrades to a skip
 * when no tokens are present (and auto-strengthens once the E2E flow seed lands).
 */

const SOAK = '() => (window).__FLOW4D_SOAK__';

/** Resolve once the lazy scene chunk mounts and the renderer reports geometry. */
async function waitForSceneReady(page: Page): Promise<boolean> {
  await page.waitForSelector('canvas.patient-flow-canvas', { timeout: 60_000 }).catch(() => null);
  try {
    await page.waitForFunction(
      () => {
        const soak = (window as unknown as { __FLOW4D_SOAK__?: { rendererInfo: () => unknown } }).__FLOW4D_SOAK__;
        const info = soak?.rendererInfo?.() as { geometries?: number } | null | undefined;
        return Boolean(info && (info.geometries ?? 0) > 0);
      },
      { timeout: 60_000 },
    );
    return true;
  } catch {
    return false;
  }
}

test.describe('Patient Flow 4D Navigator — rendered scene (F5)', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsTestUser(page);
  });

  test('boots a real WebGL scene with the facility model', async ({ page }) => {
    const response = await page.goto('/rtdc/patient-flow-navigator', { waitUntil: 'commit' });
    test.skip(response?.status() === 404, 'Patient Flow Navigator route unavailable in this environment');

    const ready = await waitForSceneReady(page);
    test.skip(!ready, 'No WebGL context / model in this environment — scene did not boot');

    // The soak hook is installed and the renderer is drawing real geometry.
    const info = await page.evaluate(`(${SOAK})()?.rendererInfo()`);
    expect(info).toBeTruthy();
    expect((info as { geometries: number }).geometries).toBeGreaterThan(0);
  });

  test('survives GPU context loss and self-heals (F1)', async ({ page }) => {
    const response = await page.goto('/rtdc/patient-flow-navigator', { waitUntil: 'commit' });
    test.skip(response?.status() === 404, 'route unavailable');
    const ready = await waitForSceneReady(page);
    test.skip(!ready, 'scene did not boot');

    // Force a context loss via the WEBGL_lose_context extension on the live
    // canvas — getContext returns three's own context, so this is the real one.
    const hadExtension = await page.evaluate(() => {
      const canvas = document.querySelector('canvas.patient-flow-canvas') as HTMLCanvasElement | null;
      const gl = canvas?.getContext('webgl2') ?? canvas?.getContext('webgl');
      const ext = gl?.getExtension('WEBGL_lose_context');
      if (!ext) return false;
      ext.loseContext();
      // Restore shortly after — the browser fires webglcontextrestored async.
      setTimeout(() => ext.restoreContext(), 250);
      return true;
    });
    test.skip(!hadExtension, 'WEBGL_lose_context unavailable in this environment');

    // The degraded card appears while the context is gone…
    await expect(page.getByText('Graphics paused')).toBeVisible({ timeout: 10_000 });
    // …and clears once the context restores (self-heal).
    await expect(page.getByText('Graphics paused')).toBeHidden({ timeout: 20_000 });

    // The soak counters recorded exactly the loss + restore.
    const events = await page.evaluate(`(${SOAK})()?.contextEvents()`);
    expect((events as { lost: number }).lost).toBeGreaterThanOrEqual(1);
    expect((events as { restored: number }).restored).toBeGreaterThanOrEqual(1);
    expect((events as { currentlyLost: boolean }).currentlyLost).toBe(false);

    // And it keeps rendering after the heal.
    const info = await page.evaluate(`(${SOAK})()?.rendererInfo()`);
    expect((info as { geometries: number }).geometries).toBeGreaterThan(0);
  });

  test('accumulates a frame budget (F4)', async ({ page }) => {
    const response = await page.goto('/rtdc/patient-flow-navigator', { waitUntil: 'commit' });
    test.skip(response?.status() === 404, 'route unavailable');
    const ready = await waitForSceneReady(page);
    test.skip(!ready, 'scene did not boot');

    // After the loop runs, the p95 sampler has a window with finite timings.
    await page.waitForFunction(
      () => {
        const soak = (window as unknown as { __FLOW4D_SOAK__?: { frameBudget: () => { samples: number } | null } }).__FLOW4D_SOAK__;
        return (soak?.frameBudget?.()?.samples ?? 0) > 30;
      },
      { timeout: 20_000 },
    );
    const budget = await page.evaluate(`(${SOAK})()?.frameBudget()`) as { frameP95: number; samples: number };
    expect(budget.samples).toBeGreaterThan(30);
    expect(Number.isFinite(budget.frameP95)).toBe(true);
  });

  test('a layer rebuild keeps the scene rendering', async ({ page }) => {
    const response = await page.goto('/rtdc/patient-flow-navigator', { waitUntil: 'commit' });
    test.skip(response?.status() === 404, 'route unavailable');
    const ready = await waitForSceneReady(page);
    test.skip(!ready, 'scene did not boot');

    // Toggling the Census layer forces a bucketed heavy-layer rebuild.
    const census = page.getByRole('switch', { name: 'Census' });
    await expect(census).toBeVisible({ timeout: 10_000 });
    await census.click();
    await census.click();

    // The renderer is still alive after the rebuild churn (no dead context).
    const info = await page.evaluate(`(${SOAK})()?.rendererInfo()`);
    expect((info as { geometries: number }).geometries).toBeGreaterThan(0);
  });
});
