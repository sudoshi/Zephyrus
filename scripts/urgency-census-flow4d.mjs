/**
 * Urgency census — earned-urgency verification on real data (HFE closure
 * H4.2 — docs/FLOW-4D-HFE-CLOSURE-PLAN-2026-07-19.md).
 *
 * Polls /api/patient-flow/occupancy and /barriers on an interval and records
 * the distribution of ok/watch/delayed disks, timer statuses, and open
 * barriers. At the end it computes the time-weighted share of coral
 * (delayed) and amber (watch) elements against the earned-urgency
 * guidelines: coral < 10%, amber < 25% of visible status elements in steady
 * state. Exceedance prints an EXCEEDED verdict (exit 1 only with
 * FLOW4D_STRICT=1 — the first run is a baseline, per the plan).
 *
 * Usage:
 *   FLOW4D_USERNAME=... FLOW4D_PASSWORD=... node scripts/urgency-census-flow4d.mjs
 * Extra env: FLOW4D_DURATION_HOURS (24), FLOW4D_SAMPLE_MINUTES (30),
 *   FLOW4D_PERSONA, FLOW4D_CORAL_MAX (0.10), FLOW4D_AMBER_MAX (0.25),
 *   FLOW4D_OUT_DIR, FLOW4D_STRICT.
 */
import { chromium } from '@playwright/test';
import { writeFileSync } from 'node:fs';
import { join } from 'node:path';
import {
  appendJsonl, ensureDir, fieldConfig, floatEnv, intEnv, login, sleep,
} from './lib/flow4d-field.mjs';

function personaQuery(config) {
  return config.persona ? `?persona=${config.persona}` : '';
}

async function fetchJson(page, url) {
  const response = await page.request.get(url);
  if (response.status() === 401 || response.status() === 419) return { expired: true };
  if (!response.ok()) throw new Error(`${url} → HTTP ${response.status()}`);
  return { json: await response.json() };
}

function tally(items, key) {
  const counts = {};
  for (const item of items) {
    const value = item?.[key] ?? 'unknown';
    counts[value] = (counts[value] ?? 0) + 1;
  }
  return counts;
}

async function captureSample(page, config, index) {
  const base = `${config.baseUrl}/api/patient-flow`;
  const occupancy = await fetchJson(page, `${base}/occupancy${personaQuery(config)}`);
  const barriers = await fetchJson(page, `${base}/barriers`);
  if (occupancy.expired || barriers.expired) return { expired: true };

  const insights = occupancy.json.occupancy ?? [];
  const timers = insights.flatMap((insight) => insight.timers ?? []);
  return {
    sample: {
      index,
      at: new Date().toISOString(),
      as_of: occupancy.json.asOf,
      disks: insights.length,
      disk_status: tally(insights, 'primary_status'),
      timer_status: tally(timers, 'status'),
      open_barriers: barriers.json.count ?? 0,
      barrier_categories: tally(barriers.json.open_barriers ?? [], 'category'),
    },
  };
}

function share(samples, status) {
  // Uniform sampling interval ⇒ time-weighted share = mean of per-sample shares.
  const shares = samples
    .filter((s) => s.disks > 0)
    .map((s) => (s.disk_status[status] ?? 0) / s.disks);
  return shares.length ? shares.reduce((a, b) => a + b, 0) / shares.length : 0;
}

async function main() {
  const config = fieldConfig();
  const durationHours = intEnv('FLOW4D_DURATION_HOURS', 24);
  const sampleMinutes = intEnv('FLOW4D_SAMPLE_MINUTES', 30);
  const coralMax = floatEnv('FLOW4D_CORAL_MAX', 0.10);
  const amberMax = floatEnv('FLOW4D_AMBER_MAX', 0.25);
  const outDir = ensureDir(process.env.FLOW4D_OUT_DIR
    ?? join('soak-output', `flow4d-census-${new Date().toISOString().replace(/[:.]/g, '-')}`));
  const samplesFile = join(outDir, 'census.jsonl');

  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext();
  const page = await context.newPage();
  await login(page, config);

  const totalSamples = Math.max(1, Math.round((durationHours * 60) / sampleMinutes));
  console.log(`Census against ${config.baseUrl} for ${durationHours}h every ${sampleMinutes}min → ${outDir}`);

  const samples = [];
  let relogins = 0;
  for (let i = 0; i < totalSamples; i += 1) {
    let result = await captureSample(page, config, i);
    if (result.expired) {
      relogins += 1;
      await login(page, config);
      result = await captureSample(page, config, i);
      if (result.expired) throw new Error('Session still expired immediately after re-login.');
    }
    samples.push(result.sample);
    appendJsonl(samplesFile, result.sample);
    console.log(`[${result.sample.at}] ${i + 1}/${totalSamples} disks=${result.sample.disks} status=${JSON.stringify(result.sample.disk_status)} barriers=${result.sample.open_barriers}`);
    if (i < totalSamples - 1) await sleep(sampleMinutes * 60_000);
  }

  const coralShare = share(samples, 'delayed');
  const amberShare = share(samples, 'watch');
  const exceeded = [];
  if (coralShare > coralMax) exceeded.push(`coral ${(coralShare * 100).toFixed(1)}% > ${coralMax * 100}%`);
  if (amberShare > amberMax) exceeded.push(`amber ${(amberShare * 100).toFixed(1)}% > ${amberMax * 100}%`);

  const summary = {
    started: samples[0]?.at,
    finished: new Date().toISOString(),
    samples: samples.length,
    relogins,
    time_weighted_coral_share: Number(coralShare.toFixed(4)),
    time_weighted_amber_share: Number(amberShare.toFixed(4)),
    thresholds: { coral_max: coralMax, amber_max: amberMax },
    verdict: exceeded.length ? `EXCEEDED: ${exceeded.join('; ')}` : 'WITHIN GUIDELINES',
  };
  writeFileSync(join(outDir, 'summary.json'), JSON.stringify(summary, null, 2));
  console.log(JSON.stringify(summary, null, 2));

  await browser.close();
  process.exit(exceeded.length && process.env.FLOW4D_STRICT === '1' ? 1 : 0);
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
