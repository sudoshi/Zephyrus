import { expect, test, type Page } from '@playwright/test';
import { loginAsTestUser } from './support/auth';

test.beforeEach(async ({ page }) => {
  await loginAsTestUser(page);
});

test.setTimeout(120_000);

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

async function expectDocumentContained(page: Page) {
  const overflow = await page.evaluate(() => ({
    documentWidth: document.documentElement.scrollWidth,
    viewportWidth: window.innerWidth,
  }));
  expect(overflow.documentWidth).toBeLessThanOrEqual(overflow.viewportWidth + 1);
}

test('queue forecast is an opt-in synthetic planning table separate from observed state', async ({ page }) => {
  const errors = await prepare(page, false);
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.goto('/pharmacy?forecast=1', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1_000);
  expect(errors.consoleErrors, 'queue forecast browser console errors during bootstrap').toEqual([]);
  expect(errors.pageErrors, 'queue forecast uncaught page errors during bootstrap').toEqual([]);

  await expect(page.getByRole('heading', { name: 'Medication Flow Board', level: 1 })).toBeVisible({ timeout: 60_000 });
  await expect(page.getByRole('heading', { name: /Synthetic planning forecast · verification queue/ })).toBeVisible();
  await expect(page.getByRole('table', { name: 'Synthetic hourly verification queue-depth projection' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Verification queue', exact: true })).toBeVisible();
  await expect(page.getByText(/Both are lower than the hour-of-week and last-value baselines: yes/)).toBeVisible();
  await expect(page.getByText(/Synthetic demo calibration/).first()).toBeVisible();

  const toggle = page.getByRole('link', { name: 'Hide planning forecast' });
  await expect(toggle).toHaveAttribute('aria-pressed', 'true');
  await toggle.focus();
  await expect(toggle).toBeFocused();
  await expectDocumentContained(page);
  expect(errors.consoleErrors).toEqual([]);
  expect(errors.pageErrors).toEqual([]);
});

test('stockout forecast preserves observed, low-confidence, and velocity-only evidence on mobile dark', async ({ page }) => {
  const errors = await prepare(page, true);
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/pharmacy/dispense?forecast=1', { waitUntil: 'domcontentloaded' });

  await expect(page.getByRole('heading', { name: 'Dispense and Delivery', level: 1 })).toBeVisible({ timeout: 60_000 });
  await expect(page.getByRole('heading', { name: /Synthetic planning forecast · stockout pressure/ })).toBeVisible();
  await expect(page.getByRole('table', { name: 'Synthetic station-medication stockout-pressure forecast' })).toBeVisible();
  await expect(page.getByText('Observed stockout').first()).toBeVisible();
  await expect(page.getByText('Low confidence').first()).toBeVisible();
  await expect(page.getByText('Velocity only').first()).toBeVisible();
  await expect(page.getByText('Active stockout').first()).toBeVisible();
  await expect(page.getByText(/No probability band/).first()).toBeVisible();
  await expect(page.locator('html')).toHaveClass(/\bdark\b/);
  await expectDocumentContained(page);
  expect(errors.consoleErrors).toEqual([]);
  expect(errors.pageErrors).toEqual([]);
});
