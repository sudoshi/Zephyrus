import { mkdirSync } from 'node:fs';
import path from 'node:path';
import { expect, test, type Page } from '@playwright/test';

test.setTimeout(120_000);

type Surface = {
  key: string;
  route: string;
  heading: string;
  lightViewport: { width: number; height: number; label: string };
};

const screenshotRoot = process.env.R15_SCREENSHOT_DIR
  ? path.resolve(process.env.R15_SCREENSHOT_DIR)
  : null;

const surfaces: Surface[] = [
  {
    key: 'flow-board',
    route: '/radiology',
    heading: 'Imaging Flow Board',
    lightViewport: { width: 1024, height: 900, label: 'tablet' },
  },
  {
    key: 'worklist',
    route: '/radiology/worklist',
    heading: 'Radiology Order Worklist',
    lightViewport: { width: 390, height: 844, label: 'mobile' },
  },
  {
    key: 'modality-utilization',
    route: '/radiology/modality',
    heading: 'Modality Utilization',
    lightViewport: { width: 1024, height: 900, label: 'tablet' },
  },
  {
    key: 'reads-results',
    route: '/radiology/reads',
    heading: 'Reads and Results',
    lightViewport: { width: 768, height: 1024, label: 'tablet' },
  },
  {
    key: 'tat-study',
    route: '/analytics/radiology-tat',
    heading: 'Radiology TAT Study',
    lightViewport: { width: 1024, height: 900, label: 'tablet' },
  },
  {
    key: 'ir-suite-study',
    route: '/analytics/ir-utilization',
    heading: 'IR Suite Study',
    lightViewport: { width: 390, height: 844, label: 'mobile' },
  },
];

async function openSurface(
  page: Page,
  surface: Surface,
  dark: boolean,
  viewport: { width: number; height: number },
) {
  const consoleErrors: string[] = [];
  const pageErrors: string[] = [];
  page.on('console', (message) => {
    if (message.type() === 'error') consoleErrors.push(message.text());
  });
  page.on('pageerror', (error) => pageErrors.push(error.message));

  await page.setViewportSize(viewport);
  await page.addInitScript((isDark) => {
    localStorage.setItem('darkMode', String(isDark));
  }, dark);
  await page.goto(surface.route, { waitUntil: 'domcontentloaded' });

  await expect(page.getByRole('heading', { name: surface.heading, level: 1 })).toBeVisible({
    timeout: 60_000,
  });
  await expect(page.locator('html')).toHaveClass(dark ? /\bdark\b/ : /^(?!.*\bdark\b)/);
  await expect(page.getByRole('main')).toBeVisible();
  const overflow = await page.evaluate(() => ({
    documentWidth: document.documentElement.scrollWidth,
    viewportWidth: window.innerWidth,
    offenders: Array.from(document.querySelectorAll<HTMLElement>('body *'))
      .map((element) => ({ element, rect: element.getBoundingClientRect() }))
      .filter(({ rect }) => rect.right > window.innerWidth + 1 || rect.left < -1)
      .slice(0, 8)
      .map(({ element, rect }) => ({
        tag: element.tagName.toLowerCase(),
        className: element.className?.toString().slice(0, 180) ?? '',
        left: Math.round(rect.left),
        right: Math.round(rect.right),
        width: Math.round(rect.width),
      })),
  }));
  expect(
    overflow.documentWidth <= overflow.viewportWidth + 1,
    `${surface.key} must not create document-level horizontal overflow at ${viewport.width}px: ${JSON.stringify(overflow)}`,
  ).toBe(true);

  if (screenshotRoot) {
    mkdirSync(screenshotRoot, { recursive: true });
    const theme = dark ? 'dark' : 'light';
    const viewportLabel = dark ? 'desktop' : surface.lightViewport.label;
    await page.screenshot({
      path: path.join(screenshotRoot, `${surface.key}-${theme}-${viewportLabel}.png`),
      fullPage: true,
    });
  }

  expect(consoleErrors, `${surface.key} browser console errors`).toEqual([]);
  expect(pageErrors, `${surface.key} uncaught page errors`).toEqual([]);
}

test.describe('Radiology R-15 visual matrix', () => {
  for (const surface of surfaces) {
    test(`${surface.heading} renders in dark desktop`, async ({ page }) => {
      await openSurface(page, surface, true, { width: 1440, height: 1000 });
    });

    test(`${surface.heading} renders in light ${surface.lightViewport.label}`, async ({ page }) => {
      await openSurface(page, surface, false, surface.lightViewport);
    });
  }
});

test.describe('Radiology R-15 operational state evidence', () => {
  test('canonical demo exposes a real breach and an explicitly degraded read queue', async ({ page }) => {
    test.skip(process.env.R15_CAPTURE_STATES !== 'canonical', 'Runs only against the canonical R-15 demo fixture.');

    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.goto('/radiology', { waitUntil: 'domcontentloaded' });
    const breachCard = page.getByText('Open breaches', { exact: true }).locator('..').locator('..');
    await expect(breachCard).toBeVisible();
    const breachValue = Number(await breachCard.locator('p').nth(1).textContent());
    expect(breachValue).toBeGreaterThan(0);

    await page.goto('/radiology/reads', { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('status').filter({
      hasText: 'Some report-bearing exams lack timestamps required for comparable backlog calculations.',
    })).toBeVisible();
  });

  test('bounded historical filter renders the honest empty Study state', async ({ page }) => {
    await page.setViewportSize({ width: 1024, height: 900 });
    await page.addInitScript(() => localStorage.setItem('darkMode', 'false'));
    await page.goto('/analytics/radiology-tat?dateFrom=2026-05-01&dateTo=2026-05-02', {
      waitUntil: 'domcontentloaded',
    });
    await expect(page.getByRole('status').filter({
      hasText: 'No Radiology exams match the bounded study filters.',
    })).toBeVisible();
    if (screenshotRoot) {
      mkdirSync(screenshotRoot, { recursive: true });
      await page.screenshot({
        path: path.join(screenshotRoot, 'tat-study-empty-light-tablet.png'),
        fullPage: true,
      });
    }
  });

  test('registered stale source renders the qualified Reads state', async ({ page }) => {
    test.skip(process.env.R15_CAPTURE_STATES !== 'stale', 'Requires the isolated R-15 stale-source fixture.');

    await page.setViewportSize({ width: 768, height: 1024 });
    await page.addInitScript(() => localStorage.setItem('darkMode', 'false'));
    await page.goto('/radiology/reads', { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('status').filter({
      hasText: 'Radiology reporting facts are stale and remain anchored to the displayed source cutoff.',
    })).toBeVisible();
    if (screenshotRoot) {
      mkdirSync(screenshotRoot, { recursive: true });
      await page.screenshot({
        path: path.join(screenshotRoot, 'reads-results-stale-light-tablet.png'),
        fullPage: true,
      });
    }
  });
});

test('Radiology worklist supports keyboard detail and visible focus semantics', async ({ page }) => {
  await page.setViewportSize({ width: 1024, height: 900 });
  await page.goto('/radiology/worklist', { waitUntil: 'domcontentloaded' });

  const details = page.getByText('Expand milestone and source detail', { exact: true }).first();
  if (await details.count()) {
    await details.focus();
    await expect(details).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(details.locator('..')).toHaveJSProperty('open', true);
  }

  const themeToggle = page.getByRole('button', { name: /Switch to light mode/i });
  await themeToggle.focus();
  await expect(themeToggle).toBeFocused();
  await page.keyboard.press('Space');
  await expect(page.locator('html')).not.toHaveClass(/\bdark\b/);
});
