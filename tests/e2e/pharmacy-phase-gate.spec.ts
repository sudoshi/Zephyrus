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

const screenshotRoot = process.env.X14_SCREENSHOT_DIR
  ? path.resolve(process.env.X14_SCREENSHOT_DIR)
  : null;

// Raw source-side identifiers that must never reach the browser. The pharmacy
// contracts expose only pseudonymous `patientRef`/`orderUuid`; these vendor and
// individual-actor keys stay server-side (X-14 phase-wide safety boundary, §13).
const forbiddenBrowserKeys = [
  'source_order_key',
  'source_dispense_key',
  'source_transaction_key',
  'verifier_ref',
  'verifier_id',
  'user_id',
  'staff_id',
  'performed_by',
  'diversion_score',
  'diversion_risk',
  'risk_score',
];

const surfaces: Surface[] = [
  {
    key: 'flow-board',
    route: '/pharmacy',
    heading: 'Medication Flow Board',
    lightViewport: { width: 1024, height: 900, label: 'tablet' },
  },
  {
    key: 'discharge-meds',
    route: '/pharmacy/discharge-meds',
    heading: 'Discharge Medication Readiness',
    lightViewport: { width: 390, height: 844, label: 'mobile' },
  },
  {
    key: 'iv-room',
    route: '/pharmacy/iv-room',
    heading: 'IV Room and Batches',
    lightViewport: { width: 1024, height: 900, label: 'tablet' },
  },
  {
    key: 'dispense',
    route: '/pharmacy/dispense',
    heading: 'Dispense and Delivery',
    lightViewport: { width: 768, height: 1024, label: 'tablet' },
  },
  {
    key: 'controlled',
    route: '/pharmacy/controlled',
    heading: 'Controlled Substances',
    lightViewport: { width: 390, height: 844, label: 'mobile' },
  },
  {
    key: 'tat-study',
    route: '/analytics/pharmacy-tat',
    heading: 'Pharmacy TAT Study',
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

  await expect(page.getByRole('heading', { name: surface.heading, level: 1 })).toBeVisible({ timeout: 60_000 });
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

test.describe('Pharmacy X-14 visual matrix', () => {
  for (const surface of surfaces) {
    test(`${surface.heading} renders in dark desktop`, async ({ page }) => {
      await openSurface(page, surface, true, { width: 1440, height: 1000 });
    });

    test(`${surface.heading} renders in light ${surface.lightViewport.label}`, async ({ page }) => {
      await openSurface(page, surface, false, surface.lightViewport);
    });
  }
});

test.describe('Pharmacy X-14 operational state evidence', () => {
  test('canonical demo exposes surge, shortage, discharge-blocked, and degraded evidence', async ({ page }) => {
    test.skip(process.env.X14_CAPTURE_STATES !== 'canonical', 'Runs only against the canonical X-14 demo fixture.');
    const errors = await prepare(page, false);
    await page.setViewportSize({ width: 1024, height: 900 });

    // Verification-queue morning surge on the Flow Board.
    await page.goto('/pharmacy?lens=stat', { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('heading', { name: 'Medication Flow Board', level: 1 })).toBeVisible();
    await expect(page.getByRole('status').filter({ hasText: /coverage is partial|degraded/i }).first()).toBeVisible();
    if (screenshotRoot) await page.screenshot({ path: path.join(screenshotRoot, 'flow-board-surge-light-tablet.png'), fullPage: true });

    // Station shortage/stockout evidence on Dispense.
    await page.goto('/pharmacy/dispense', { waitUntil: 'domcontentloaded' });
    await expect(page.getByText(/stockout/i).first()).toBeVisible();
    if (screenshotRoot) await page.screenshot({ path: path.join(screenshotRoot, 'dispense-shortage-light-tablet.png'), fullPage: true });

    // Discharge-blocked medication readiness.
    await page.goto('/pharmacy/discharge-meds', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('article').first()).toBeVisible();
    if (screenshotRoot) await page.screenshot({ path: path.join(screenshotRoot, 'discharge-meds-blocked-light-tablet.png'), fullPage: true });

    await expectContainedAndPrivate(page, 'canonical-state-evidence');
    expect(errors.consoleErrors).toEqual([]);
    expect(errors.pageErrors).toEqual([]);
  });

  test('bounded historical filter renders the honest empty Study state', async ({ page }) => {
    const errors = await prepare(page, false);
    await page.setViewportSize({ width: 1024, height: 900 });
    await page.goto('/analytics/pharmacy-tat?dateFrom=2026-05-01&dateTo=2026-05-02', { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('heading', { name: 'Pharmacy TAT Study', level: 1 })).toBeVisible({ timeout: 60_000 });
    if (screenshotRoot) await page.screenshot({ path: path.join(screenshotRoot, 'tat-study-empty-light-tablet.png'), fullPage: true });
    await expectContainedAndPrivate(page, 'tat-study-empty');
    expect(errors.consoleErrors).toEqual([]);
    expect(errors.pageErrors).toEqual([]);
  });

  test('registered stale source renders a qualified Flow Board', async ({ page }) => {
    test.skip(process.env.X14_CAPTURE_STATES !== 'stale', 'Requires the isolated X-14 stale-source fixture.');
    const errors = await prepare(page, false);
    await page.setViewportSize({ width: 768, height: 1024 });
    await page.goto('/pharmacy', { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('status').filter({ hasText: /stale/i }).first()).toBeVisible();
    if (screenshotRoot) await page.screenshot({ path: path.join(screenshotRoot, 'flow-board-stale-light-tablet.png'), fullPage: true });
    await expectContainedAndPrivate(page, 'flow-board-stale');
    expect(errors.consoleErrors).toEqual([]);
    expect(errors.pageErrors).toEqual([]);
  });
});

test('theme control and page controls retain keyboard focus semantics', async ({ page }) => {
  await page.setViewportSize({ width: 1024, height: 900 });
  await page.goto('/pharmacy', { waitUntil: 'domcontentloaded' });

  const themeToggle = page.getByRole('button', { name: /Switch to light mode/i });
  await themeToggle.focus();
  await expect(themeToggle).toBeFocused();
  await page.keyboard.press('Space');
  await expect(page.locator('html')).not.toHaveClass(/\bdark\b/);
});

test('ED, RTDC full vector, and Cockpit compact joins reconcile to owned Pharmacy workspaces', async ({ page }) => {
  const errors = await prepare(page, false);
  await page.setViewportSize({ width: 1440, height: 1000 });

  // ED boarder medication lens (X-11): boarded ED patients surface a medication
  // readiness axis alongside the existing imaging and lab axes.
  await page.goto('/ed/operations/treatment', { waitUntil: 'domcontentloaded' });
  await expect(page.getByText(/Medication/i).first()).toBeVisible({ timeout: 60_000 });
  await expectContainedAndPrivate(page, 'ed-medication-lens');

  // RTDC full readiness vector: imaging + lab + medication all present.
  await page.goto('/rtdc/ancillary-services', { waitUntil: 'domcontentloaded' });
  await page.getByRole('button', { name: 'Matrix' }).click();
  await expect(page.getByText('Imaging', { exact: false }).first()).toBeVisible();
  await expect(page.getByText('Laboratory', { exact: false }).first()).toBeVisible();
  await expect(page.getByText('Medication', { exact: false }).first()).toBeVisible();
  await expectContainedAndPrivate(page, 'rtdc-full-vector');

  // Cockpit Flow drill reconciles Pharmacy to its owned destinations.
  await page.goto('/dashboard?drill=flow', { waitUntil: 'domcontentloaded' });
  const table = page.getByRole('table', { name: 'Ancillary operational health' });
  await expect(table.getByText('Pharmacy', { exact: true })).toBeVisible();
  await expect(table.locator('a[href="/pharmacy?lens=stat&source=cockpit"]')).toBeVisible();
  await expect(table.locator('a[href="/pharmacy?lens=sepsis&source=cockpit"]')).toBeVisible();
  await expect(table.locator('a[href="/pharmacy?lens=shortage&source=cockpit"]')).toBeVisible();
  await expectContainedAndPrivate(page, 'cockpit-compact-join');

  expect(errors.consoleErrors).toEqual([]);
  expect(errors.pageErrors).toEqual([]);
});
