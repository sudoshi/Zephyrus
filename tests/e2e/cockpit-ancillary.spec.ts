import { expect, test } from '@playwright/test';
import { loginAsTestUser } from './support/auth';

test.beforeEach(async ({ page }) => {
  await loginAsTestUser(page);
});

test('Ancillary service sectors render as first-class cockpit panels', async ({ page }) => {
  const consoleErrors: string[] = [];
  page.on('console', (message) => {
    if (message.type() === 'error') consoleErrors.push(message.text());
  });
  await page.route('**/api/cockpit/stream', async (route) => {
    await route.fulfill({ status: 204, body: '' });
  });

  await page.goto('/dashboard', { waitUntil: 'domcontentloaded' });

  const grid = page.getByTestId('cockpit-domain-grid');
  await expect(grid).toBeVisible({ timeout: 10000 });
  // The three ancillary sectors, promoted out of Flow (2026-07-19), now render
  // as their own gauged panels wherever the ancillary feed has data. (The
  // Hospital@Home panel is a separately flag-gated module, HOME_HOSPITAL_ENABLED,
  // so it is asserted by the CockpitOverview unit fixture, not this feed-dependent
  // browser check.)
  await expect(grid.getByText('Radiology', { exact: true })).toBeVisible();
  await expect(grid.getByText('Laboratory', { exact: true })).toBeVisible();
  await expect(grid.getByText('Pharmacy', { exact: true })).toBeVisible();

  // The Radiology panel drills to its own aggregate measure ledger.
  await page.goto('/dashboard?drill=radiology', { waitUntil: 'domcontentloaded' });
  await expect(page.getByTestId('cockpit-drill-modal')).toBeVisible({ timeout: 10000 });
  await expect(page.getByText('Radiology — Imaging Operational Health', { exact: true })).toBeVisible();

  expect(consoleErrors).toEqual([]);
});
