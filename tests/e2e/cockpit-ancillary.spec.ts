import { expect, test } from '@playwright/test';

test('Flow drill renders the server-computed ancillary health table', async ({ page }) => {
  const consoleErrors: string[] = [];
  page.on('console', (message) => {
    if (message.type() === 'error') consoleErrors.push(message.text());
  });
  await page.route('**/api/cockpit/stream', async (route) => {
    await route.fulfill({ status: 204, body: '' });
  });

  await page.goto('/dashboard?drill=flow', { waitUntil: 'domcontentloaded' });

  await expect(page.getByTestId('cockpit-drill-modal')).toBeVisible({ timeout: 10000 });
  await expect(page.getByText('Patient Flow & Transport', { exact: true })).toBeVisible();
  const table = page.getByRole('table', { name: 'Ancillary operational health' });
  await expect(table).toBeVisible();
  await expect(table.getByText('Radiology', { exact: true })).toBeVisible();
  await expect(table.getByText('Laboratory', { exact: true })).toBeVisible();
  await expect(table.getByText('Pharmacy', { exact: true })).toBeVisible();
  await expect(table.getByRole('columnheader', { name: 'Source cutoff' })).toBeVisible();
  await expect(table.locator('a[href="/lab?priority=stat&source=cockpit"]')).toBeVisible();
  await expect(table.locator('a[href="/lab/pending-decisions?source=cockpit"]')).toBeVisible();
  await expect(table.locator('a[href="/lab?lens=critical_callbacks&source=cockpit"]')).toBeVisible();
  expect(consoleErrors).toEqual([]);
});
