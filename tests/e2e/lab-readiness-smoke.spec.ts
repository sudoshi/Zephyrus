import { expect, test, type Page } from '@playwright/test';
import { loginAsTestUser } from './support/auth';

test.beforeEach(async ({ page }) => {
  await loginAsTestUser(page);
});

const sensitiveKeys = ['resultUuid', 'specimenUuid', 'sourceResultKey'];

async function prepare(page: Page, darkMode: boolean) {
  const consoleErrors: string[] = [];
  const pageErrors: string[] = [];

  page.on('console', (message) => {
    if (message.type() === 'error') consoleErrors.push(message.text());
  });
  page.on('pageerror', (error) => pageErrors.push(error.message));
  await page.route('**/api/cockpit/stream**', async (route) => {
    await route.fulfill({ status: 204, body: '' });
  });
  await page.addInitScript((dark) => {
    window.localStorage.setItem('darkMode', dark ? 'true' : 'false');
  }, darkMode);

  return { consoleErrors, pageErrors };
}

async function expectPrivateAndContained(page: Page) {
  const text = await page.locator('body').innerText();
  for (const key of sensitiveKeys) expect(text).not.toContain(key);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
}

test('desktop readiness surfaces render together and exact Lab drill reconciles', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 1000 });
  const errors = await prepare(page, false);

  await page.goto('/rtdc/predictions/discharge', { waitUntil: 'domcontentloaded' });
  await expect(page.getByRole('heading', { name: 'Discharge Priorities' })).toBeVisible({ timeout: 15000 });
  const vectors = page.getByRole('region', { name: 'Ancillary readiness vector' });
  await expect(vectors.first()).toBeVisible();
  await expect(vectors.first().getByText('Imaging', { exact: true })).toBeVisible();
  await expect(vectors.first().getByText('Lab', { exact: true })).toBeVisible();
  await expect(page.getByRole('button', { name: /^Open Lab: Blocked/ }).first()).toBeVisible();
  await expectPrivateAndContained(page);

  await page.goto('/ed/operations/treatment', { waitUntil: 'domcontentloaded' });
  await expect(page.getByRole('heading', { name: 'ED Treatment Board' })).toBeVisible({ timeout: 15000 });
  await expect(page.getByRole('columnheader', { name: 'Imaging' })).toBeVisible();
  await expect(page.getByRole('columnheader', { name: 'Lab' })).toBeVisible();
  const drill = page.getByRole('link', { name: /^Open Lab: Blocked/ }).first();
  await expect(drill).toBeVisible();
  const href = await drill.getAttribute('href');
  expect(href).toMatch(/^\/lab\/pending-decisions\?decisionClass=ed_disposition&orderUuid=[0-9a-f-]{36}&source=ed$/);
  await drill.click();
  await expect(page.getByRole('heading', { name: 'Decision-Pending Results' })).toBeVisible({ timeout: 15000 });
  await expect(page).toHaveURL(/decisionClass=ed_disposition&orderUuid=[0-9a-f-]{36}&source=ed/);
  await expect(page.locator('article')).toHaveCount(1);
  await expect(page.locator('input[name="orderUuid"]')).toHaveValue(/[0-9a-f-]{36}/);
  await expect(page.locator('input[name="source"]')).toHaveValue('ed');
  await expectPrivateAndContained(page);

  expect(errors.consoleErrors).toEqual([]);
  expect(errors.pageErrors).toEqual([]);
});

test('mobile dark readiness surfaces remain document-contained', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  const errors = await prepare(page, true);

  await page.goto('/rtdc/predictions/discharge', { waitUntil: 'domcontentloaded' });
  await expect(page.getByRole('heading', { name: 'Discharge Priorities' })).toBeVisible({ timeout: 15000 });
  await expect(page.getByRole('region', { name: 'Ancillary readiness vector' }).first()).toBeVisible();
  await expectPrivateAndContained(page);

  await page.goto('/ed/operations/treatment', { waitUntil: 'domcontentloaded' });
  await expect(page.getByRole('heading', { name: 'ED Treatment Board' })).toBeVisible({ timeout: 15000 });
  await expect(page.getByRole('columnheader', { name: 'Lab' })).toBeVisible();
  await expectPrivateAndContained(page);

  expect(errors.consoleErrors).toEqual([]);
  expect(errors.pageErrors).toEqual([]);
});
