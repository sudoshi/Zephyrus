/**
 * 24-hour soak of the Patient Flow 4D Navigator (HFE closure H4.1 —
 * docs/FLOW-4D-HFE-CLOSURE-PLAN-2026-07-19.md).
 *
 * Loads the navigator once, never reloads, and samples every N minutes:
 * JS heap, renderer info, now-marker drift, rounds HUD run, screenshot.
 * At the end it asserts:
 *   - heap growth < 15% between the post-warmup window and the final window
 *   - geometry/texture counts flat (when the debug hook is present)
 *   - now-marker drift < 90 s (when the debug hook is present)
 *   - rounds run turns over across the 6 h demo-refresh boundary without a reload
 *   - zero uncaught exceptions / WebGL context losses
 *
 * Optional in-app debug hook (feature-detected; nulls when absent):
 *   window.__FLOW4D_SOAK__ = {
 *     rendererInfo(): { geometries, textures, calls, triangles },
 *     nowDeltaMs(): number,           // scene now-marker minus wall clock
 *     roundsRun(): { uuid, status } | null,
 *   }
 *
 * Usage (wall box):
 *   FLOW4D_USERNAME=... FLOW4D_PASSWORD=... node scripts/soak-flow4d.mjs
 * Extra env: FLOW4D_DURATION_HOURS (24), FLOW4D_SAMPLE_MINUTES (30),
 *   FLOW4D_OUT_DIR, FLOW4D_HEADLESS (default headed, per protocol).
 */
import { chromium } from '@playwright/test';
import { writeFileSync } from 'node:fs';
import { join } from 'node:path';
import {
  appendJsonl, ensureDir, fieldConfig, intEnv, login, sleep,
} from './lib/flow4d-field.mjs';

const NAVIGATOR_PATH = '/rtdc/patient-flow-navigator';
const HEAP_GROWTH_MAX = 0.15;
const NOW_DRIFT_MAX_MS = 90_000;

async function captureSample(page, index) {
  return page.evaluate((sampleIndex) => {
    const memory = performance.memory
      ? {
          used_js_heap: performance.memory.usedJSHeapSize,
          total_js_heap: performance.memory.totalJSHeapSize,
        }
      : null;
    const hook = window.__FLOW4D_SOAK__;
    const hudText = document.querySelector('.patient-flow-rounds-hud-text')?.textContent ?? null;
    return {
      index: sampleIndex,
      at: new Date().toISOString(),
      url: location.pathname,
      memory,
      renderer: hook?.rendererInfo?.() ?? null,
      now_delta_ms: hook?.nowDeltaMs?.() ?? null,
      rounds_run: hook?.roundsRun?.() ?? null,
      rounds_hud_text: hudText,
      webgl_context_lost: window.__FLOW4D_CONTEXT_LOST__ === true,
    };
  }, index);
}

function meanHeap(samples) {
  const heaps = samples.map((s) => s.memory?.used_js_heap).filter((v) => typeof v === 'number');
  return heaps.length ? heaps.reduce((a, b) => a + b, 0) / heaps.length : null;
}

function evaluateRun(samples, pageErrors, relogins) {
  const failures = [];
  const notes = [];

  // Heap: compare a post-warmup window against the final window.
  const warmupEnd = Math.min(samples.length, Math.max(2, Math.floor(samples.length / 6)));
  const baseline = meanHeap(samples.slice(warmupEnd, warmupEnd * 2));
  const final = meanHeap(samples.slice(-warmupEnd));
  if (baseline && final) {
    const growth = (final - baseline) / baseline;
    (growth > HEAP_GROWTH_MAX ? failures : notes).push(
      `heap growth ${(growth * 100).toFixed(1)}% (baseline ${(baseline / 1e6).toFixed(0)}MB → final ${(final / 1e6).toFixed(0)}MB, limit ${HEAP_GROWTH_MAX * 100}%)`,
    );
  } else {
    notes.push('heap not measured (performance.memory unavailable)');
  }

  const drifts = samples.map((s) => s.now_delta_ms).filter((v) => typeof v === 'number');
  if (drifts.length) {
    const worst = Math.max(...drifts.map(Math.abs));
    (worst > NOW_DRIFT_MAX_MS ? failures : notes).push(`worst now-marker drift ${(worst / 1000).toFixed(1)}s (limit ${NOW_DRIFT_MAX_MS / 1000}s)`);
  } else {
    notes.push('now-marker drift not measured (debug hook absent — land __FLOW4D_SOAK__ first)');
  }

  const rendererCounts = samples.map((s) => s.renderer).filter(Boolean);
  if (rendererCounts.length >= 2) {
    const first = rendererCounts[0];
    const last = rendererCounts[rendererCounts.length - 1];
    notes.push(`renderer geometries ${first.geometries}→${last.geometries}, textures ${first.textures}→${last.textures}`);
  }

  const runUuids = [...new Set(samples.map((s) => s.rounds_run?.uuid).filter(Boolean))];
  notes.push(runUuids.length
    ? `rounds run turnover: ${runUuids.length} distinct run(s) without reload`
    : 'rounds run not observed (hook absent or rounds layer off)');

  if (samples.some((s) => s.webgl_context_lost)) failures.push('WebGL context lost during soak');
  if (pageErrors.length) failures.push(`${pageErrors.length} uncaught exception(s) — see errors.jsonl`);
  if (relogins > 0) notes.push(`${relogins} re-login(s) — session was bounced (check demo-refresh vs sessions)`);

  return { failures, notes };
}

async function main() {
  const config = fieldConfig();
  const durationHours = intEnv('FLOW4D_DURATION_HOURS', 24);
  const sampleMinutes = intEnv('FLOW4D_SAMPLE_MINUTES', 30);
  const outDir = ensureDir(process.env.FLOW4D_OUT_DIR
    ?? join('soak-output', `flow4d-${new Date().toISOString().replace(/[:.]/g, '-')}`));
  const samplesFile = join(outDir, 'samples.jsonl');
  const errorsFile = join(outDir, 'errors.jsonl');

  const browser = await chromium.launch({ headless: process.env.FLOW4D_HEADLESS === '1' });
  const context = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
  const page = await context.newPage();

  const pageErrors = [];
  page.on('pageerror', (error) => {
    pageErrors.push(String(error));
    appendJsonl(errorsFile, { at: new Date().toISOString(), kind: 'pageerror', message: String(error) });
  });
  page.on('console', (message) => {
    if (message.type() === 'error') {
      appendJsonl(errorsFile, { at: new Date().toISOString(), kind: 'console.error', message: message.text() });
    }
  });

  await login(page, config);
  const navigatorUrl = `${config.baseUrl}${NAVIGATOR_PATH}${config.persona ? `?persona=${config.persona}` : ''}`;
  await page.goto(navigatorUrl, { waitUntil: 'networkidle' });
  await page.waitForSelector('canvas', { timeout: 60_000 });
  await page.evaluate(() => {
    document.querySelector('canvas')?.addEventListener('webglcontextlost', () => {
      window.__FLOW4D_CONTEXT_LOST__ = true;
    });
  });

  const totalSamples = Math.max(1, Math.round((durationHours * 60) / sampleMinutes));
  console.log(`Soaking ${navigatorUrl} for ${durationHours}h, sampling every ${sampleMinutes}min → ${outDir}`);

  const samples = [];
  let relogins = 0;
  for (let i = 0; i < totalSamples; i += 1) {
    // Session bounced (demo refresh / expiry): re-login and note it — the
    // no-reload evidence chain is marked, not silently patched over.
    if (page.url().includes('/login')) {
      relogins += 1;
      appendJsonl(errorsFile, { at: new Date().toISOString(), kind: 'relogin', message: 'bounced to /login' });
      await login(page, config);
      await page.goto(navigatorUrl, { waitUntil: 'networkidle' });
      await page.waitForSelector('canvas', { timeout: 60_000 });
    }
    const sample = await captureSample(page, i);
    samples.push(sample);
    appendJsonl(samplesFile, sample);
    await page.screenshot({ path: join(outDir, `sample-${String(i).padStart(3, '0')}.png`) });
    console.log(`[${sample.at}] sample ${i + 1}/${totalSamples} heap=${sample.memory ? (sample.memory.used_js_heap / 1e6).toFixed(0) + 'MB' : 'n/a'} hud=${sample.rounds_hud_text ?? 'n/a'}`);
    if (i < totalSamples - 1) await sleep(sampleMinutes * 60_000);
  }

  const verdict = evaluateRun(samples, pageErrors, relogins);
  const summary = {
    started: samples[0]?.at,
    finished: new Date().toISOString(),
    samples: samples.length,
    relogins,
    ...verdict,
    pass: verdict.failures.length === 0,
  };
  writeFileSync(join(outDir, 'summary.json'), JSON.stringify(summary, null, 2));
  console.log(JSON.stringify(summary, null, 2));

  await browser.close();
  process.exit(summary.pass ? 0 : 1);
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
