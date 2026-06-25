import { mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';

const playwrightModule = process.env.PLAYWRIGHT_MODULE || 'playwright';
const playwright = await import(playwrightModule);
const chromium = playwright.chromium || playwright.default?.chromium;
const root = new URL('.', import.meta.url).pathname;
const outputDir = path.join(root, 'verification');
await mkdir(outputDir, { recursive: true });

const url = process.env.PATIENT_FLOW_VIEWER_URL || 'http://127.0.0.1:8776/viewer/';
const browser = await chromium.launch({ headless: true });

async function verifyViewport(name, viewport) {
  const page = await browser.newPage({ viewport });
  const errors = [];
  page.on('console', message => {
    if (message.type() === 'error') errors.push(message.text());
  });
  page.on('pageerror', error => errors.push(error.message));
  await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForSelector('canvas#viewport');
  await page.waitForFunction(() => {
    const status = document.querySelector('#statusText')?.textContent || '';
    const active = document.querySelector('#activeMetric')?.textContent || '';
    return status.includes('Model loaded') && Number(active) > 0;
  }, null, { timeout: 30000 });
  await page.waitForTimeout(900);
  const screenshotPath = path.join(outputDir, `${name}.png`);
  await page.screenshot({ path: screenshotPath, fullPage: true });
  const apiSummary = await page.evaluate(async () => {
    const summary = await fetch('/api/summary').then(r => r.json());
    const state = await fetch('/api/state').then(r => r.json());
    return {
      summary,
      state: {
        activePatients: state.activePatients,
        occupiedLocations: Object.keys(state.occupancy || {}).length,
      },
    };
  });
  const pixelStats = await page.evaluate(() => {
    const canvas = document.querySelector('canvas#viewport');
    const gl = canvas.getContext('webgl2') || canvas.getContext('webgl');
    const width = canvas.width;
    const height = canvas.height;
    const pixels = new Uint8Array(width * height * 4);
    gl.readPixels(0, 0, width, height, gl.RGBA, gl.UNSIGNED_BYTE, pixels);
    let nonBackground = 0;
    let minRgb = 255;
    let maxRgb = 0;
    for (let i = 0; i < pixels.length; i += 4) {
      const r = pixels[i];
      const g = pixels[i + 1];
      const b = pixels[i + 2];
      if (Math.abs(r - 18) > 4 || Math.abs(g - 21) > 4 || Math.abs(b - 20) > 4) nonBackground += 1;
      minRgb = Math.min(minRgb, r, g, b);
      maxRgb = Math.max(maxRgb, r, g, b);
    }
    return {
      width,
      height,
      totalPixels: width * height,
      nonBackground,
      colorRange: maxRgb - minRgb,
      nonBackgroundRatio: nonBackground / (width * height),
      activeMetric: document.querySelector('#activeMetric')?.textContent,
      eventMetric: document.querySelector('#eventMetric')?.textContent,
      status: document.querySelector('#statusText')?.textContent,
    };
  });
  await page.close();
  const meaningfulErrors = errors.filter(error => !error.includes('GL Driver Message'));
  if (meaningfulErrors.length) {
    throw new Error(`${name} console/page errors: ${meaningfulErrors.join(' | ')}`);
  }
  if (pixelStats.nonBackgroundRatio < 0.04 || pixelStats.colorRange < 20) {
    throw new Error(`${name} canvas appears blank: ${JSON.stringify(pixelStats)}`);
  }
  if (apiSummary.summary.normalized_events < 100 || apiSummary.state.activePatients < 1) {
    throw new Error(`${name} API checks failed: ${JSON.stringify(apiSummary)}`);
  }
  return { name, screenshotPath, pixelStats, apiSummary };
}

const results = [];
results.push(await verifyViewport('desktop-1440x900', { width: 1440, height: 900 }));
results.push(await verifyViewport('mobile-390x844', { width: 390, height: 844, isMobile: true }));
await browser.close();
const report = { url, generatedAt: new Date().toISOString(), results };
await writeFile(path.join(outputDir, 'results.json'), JSON.stringify(report, null, 2) + '\n');
console.log(JSON.stringify(report, null, 2));
