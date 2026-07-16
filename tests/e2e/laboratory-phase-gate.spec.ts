import { mkdirSync } from 'node:fs';
import path from 'node:path';
import { expect, test, type Page } from '@playwright/test';
import { loginAsTestUser } from './support/auth';

test.beforeEach(async ({ page }) => {
  await loginAsTestUser(page);
});

test.setTimeout(120_000);

type Surface = {
  key: string;
  route: string;
  heading: string;
  lightViewport: { width: number; height: number; label: string };
};

const screenshotRoot = process.env.L14_SCREENSHOT_DIR
  ? path.resolve(process.env.L14_SCREENSHOT_DIR)
  : null;

const forbiddenBrowserKeys = [
  'patient_ref',
  'encounter_ref',
  'specimen_uuid',
  'source_specimen_key',
  'result_uuid',
  'source_result_key',
  'source_critical_key',
  'pathologist_ref',
];

const surfaces: Surface[] = [
  {
    key: 'flow-board',
    route: '/lab',
    heading: 'Laboratory Flow Board',
    lightViewport: { width: 1024, height: 900, label: 'tablet' },
  },
  {
    key: 'specimens',
    route: '/lab/specimens',
    heading: 'Specimen Tracker',
    lightViewport: { width: 390, height: 844, label: 'mobile' },
  },
  {
    key: 'decision-pending',
    route: '/lab/pending-decisions',
    heading: 'Decision-Pending Results',
    lightViewport: { width: 1024, height: 900, label: 'tablet' },
  },
  {
    key: 'blood-bank',
    route: '/lab/blood-bank',
    heading: 'Blood Bank Readiness',
    lightViewport: { width: 768, height: 1024, label: 'tablet' },
  },
  {
    key: 'anatomic-pathology',
    route: '/lab/anatomic-path',
    heading: 'Anatomic Pathology Case Aging',
    lightViewport: { width: 390, height: 844, label: 'mobile' },
  },
  {
    key: 'tat-study',
    route: '/analytics/lab-tat',
    heading: 'Laboratory TAT Study',
    lightViewport: { width: 1024, height: 900, label: 'tablet' },
  },
];

async function prepare(page: Page, dark: boolean) {
  const consoleErrors: string[] = [];
  const pageErrors: string[] = [];
  page.on('console', (message) => {
    if (message.type() === 'error') consoleErrors.push(message.text());
  });
  page.on('pageerror', (error) => pageErrors.push(error.message));
  await page.route('**/api/cockpit/stream**', async (route) => route.fulfill({ status: 204, body: '' }));
  await page.addInitScript((isDark) => localStorage.setItem('darkMode', String(isDark)), dark);

  return { consoleErrors, pageErrors };
}

async function expectContainedAndPrivate(page: Page, key: string) {
  const body = await page.locator('body').innerText();
  for (const forbidden of forbiddenBrowserKeys) expect(body, `${key} exposes ${forbidden}`).not.toContain(forbidden);

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
    `${key} must not create document-level horizontal overflow: ${JSON.stringify(overflow)}`,
  ).toBe(true);
}

async function openSurface(
  page: Page,
  surface: Surface,
  dark: boolean,
  viewport: { width: number; height: number },
) {
  const errors = await prepare(page, dark);
  await page.setViewportSize(viewport);
  await page.goto(surface.route, { waitUntil: 'domcontentloaded' });

  try {
    await expect(page.getByRole('heading', { name: surface.heading, level: 1 })).toBeVisible({ timeout: 60_000 });
  } catch (error) {
    throw new Error([
      error instanceof Error ? error.message : String(error),
      `URL: ${page.url()}`,
      `Console errors: ${JSON.stringify(errors.consoleErrors)}`,
      `Page errors: ${JSON.stringify(errors.pageErrors)}`,
    ].join('\n'));
  }
  await expect(page.getByRole('main')).toBeVisible();
  await expect(page.locator('html')).toHaveClass(dark ? /\bdark\b/ : /^(?!.*\bdark\b)/);
  await expect(page.getByRole('status').first()).toBeVisible();
  await expectContainedAndPrivate(page, surface.key);

  if (screenshotRoot) {
    mkdirSync(screenshotRoot, { recursive: true });
    const theme = dark ? 'dark' : 'light';
    const viewportLabel = dark ? 'desktop' : surface.lightViewport.label;
    await page.screenshot({
      path: path.join(screenshotRoot, `${surface.key}-${theme}-${viewportLabel}.png`),
      fullPage: true,
    });
  }

  expect(errors.consoleErrors, `${surface.key} browser console errors`).toEqual([]);
  expect(errors.pageErrors, `${surface.key} uncaught page errors`).toEqual([]);
}

test.describe('Laboratory L-14 visual matrix', () => {
  for (const surface of surfaces) {
    test(`${surface.heading} renders in dark desktop`, async ({ page }) => {
      await openSurface(page, surface, true, { width: 1440, height: 1000 });
    });

    test(`${surface.heading} renders in light ${surface.lightViewport.label}`, async ({ page }) => {
      await openSurface(page, surface, false, surface.lightViewport);
    });
  }
});

test.describe('Laboratory L-14 operational state evidence', () => {
  test('canonical demo exposes rework, breach, degraded, and normal evidence', async ({ page }) => {
    test.skip(process.env.L14_CAPTURE_STATES !== 'canonical', 'Runs only against the canonical L-14 demo fixture.');
    const errors = await prepare(page, false);
    await page.setViewportSize({ width: 1024, height: 900 });

    await page.goto('/lab/specimens?rejection=recollect', { waitUntil: 'domcontentloaded' });
    await expect(page.getByText('Recollect ordered').first()).toBeVisible();
    await expect(page.getByText(/Chain \d+ of [2-9]/).first()).toBeVisible();
    if (screenshotRoot) await page.screenshot({ path: path.join(screenshotRoot, 'specimens-rework-light-tablet.png'), fullPage: true });

    await page.goto('/lab/pending-decisions?urgency=breach', { waitUntil: 'domcontentloaded' });
    const breachedDecision = page.locator('article').first();
    await expect(breachedDecision).toBeVisible();
    await expect(breachedDecision.getByText('breach', { exact: true })).toBeVisible();
    if (screenshotRoot) await page.screenshot({ path: path.join(screenshotRoot, 'decision-pending-breach-light-tablet.png'), fullPage: true });

    await page.goto('/lab', { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('status').filter({ hasText: 'Laboratory coverage is partial' })).toBeVisible();

    await page.goto('/lab/anatomic-path', { waitUntil: 'domcontentloaded' });
    await expect(page.getByText('Established benchmark reference lines')).toBeVisible();
    await expect(page.locator('article').first()).toBeVisible();

    await expectContainedAndPrivate(page, 'canonical-state-evidence');
    expect(errors.consoleErrors).toEqual([]);
    expect(errors.pageErrors).toEqual([]);
  });

  test('bounded historical filter renders the honest empty Study state', async ({ page }) => {
    const errors = await prepare(page, false);
    await page.setViewportSize({ width: 1024, height: 900 });
    await page.goto('/analytics/lab-tat?dateFrom=2026-05-01&dateTo=2026-05-02', { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('status').filter({ hasText: 'No current clinical-Laboratory orders match' })).toBeVisible();
    if (screenshotRoot) await page.screenshot({ path: path.join(screenshotRoot, 'tat-study-empty-light-tablet.png'), fullPage: true });
    await expectContainedAndPrivate(page, 'tat-study-empty');
    expect(errors.consoleErrors).toEqual([]);
    expect(errors.pageErrors).toEqual([]);
  });

  test('registered stale source renders a qualified Flow Board', async ({ page }) => {
    test.skip(process.env.L14_CAPTURE_STATES !== 'stale', 'Requires the isolated L-14 stale-source fixture.');
    const errors = await prepare(page, false);
    await page.setViewportSize({ width: 768, height: 1024 });
    await page.goto('/lab', { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('status').filter({ hasText: 'Laboratory facts are stale' })).toBeVisible();
    if (screenshotRoot) await page.screenshot({ path: path.join(screenshotRoot, 'flow-board-stale-light-tablet.png'), fullPage: true });
    await expectContainedAndPrivate(page, 'flow-board-stale');
    expect(errors.consoleErrors).toEqual([]);
    expect(errors.pageErrors).toEqual([]);
  });
});

test('decision details and theme control retain keyboard focus semantics', async ({ page }) => {
  await page.setViewportSize({ width: 1024, height: 900 });
  await page.goto('/lab/pending-decisions', { waitUntil: 'domcontentloaded' });

  const details = page.getByText('Why this rank and gate?', { exact: true }).first();
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

test('Perioperative, RTDC, and Cockpit compact joins reconcile to owned Laboratory workspaces', async ({ page }) => {
  const errors = await prepare(page, false);
  await page.setViewportSize({ width: 1440, height: 1000 });

  await page.goto('/operations/cases', { waitUntil: 'domcontentloaded' });
  await expect(page.getByRole('heading', { name: 'Case Management', level: 1 })).toBeVisible({ timeout: 60_000 });
  await expect(page.getByText(/Blood Bank (ready|gated|readiness unknown|· no requirement)/).first()).toBeVisible();
  await expect(page.getByText('Frozen section', { exact: false }).first()).toBeVisible();
  await expectContainedAndPrivate(page, 'perioperative-compact-joins');

  await page.goto('/rtdc/ancillary-services', { waitUntil: 'domcontentloaded' });
  await page.getByRole('button', { name: 'Matrix' }).click();
  const rtdcDrill = page.getByRole('link', { name: /Open Lab Laboratory Flow Board for/i }).first();
  await expect(rtdcDrill).toHaveAttribute('href', /\/lab\?unitId=\d+&source=ancillary_services/);

  await page.goto('/dashboard?drill=flow', { waitUntil: 'domcontentloaded' });
  const table = page.getByRole('table', { name: 'Ancillary operational health' });
  await expect(table.getByText('Laboratory', { exact: true })).toBeVisible();
  await expect(table.locator('a[href="/lab?priority=stat&source=cockpit"]')).toBeVisible();
  await expect(table.locator('a[href="/lab/pending-decisions?source=cockpit"]')).toBeVisible();
  await expect(table.locator('a[href="/lab?lens=critical_callbacks&source=cockpit"]')).toBeVisible();
  await expectContainedAndPrivate(page, 'cockpit-compact-join');

  expect(errors.consoleErrors).toEqual([]);
  expect(errors.pageErrors).toEqual([]);
});
