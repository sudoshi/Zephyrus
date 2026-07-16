import { test, expect } from '@playwright/test';
import { loginAsTestUser } from './support/auth';

// Assumes the app + Reverb are running and TEST_USERNAME/TEST_PASSWORD seed a user.
// Run `php artisan rtdc:demo-reset` before this suite (see CI step).

test.describe('RTDC Unit Huddle (live)', () => {
  test.beforeEach(async ({ page }) => {
    await page.route('**/api/cockpit/stream', async (route) => {
      await route.fulfill({ status: 204, body: '' });
    });

    await loginAsTestUser(page);
    await page.goto('/rtdc/unit-huddle', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/rtdc\/unit-huddle/);
  });

  test('runs a four-step cycle and shows bed-need', async ({ page }) => {
    await expect(page.getByRole('region', { name: /step 1/i })).toBeVisible();

    // Step 1 — discharges.
    await page.getByRole('spinbutton', { name: 'definite' }).fill('2');
    const capacityResponse = page.waitForResponse((response) =>
      response.url().includes('/api/rtdc/units/1/capacity') &&
      response.request().method() === 'POST' &&
      response.ok()
    );
    await page.getByRole('button', { name: /save capacity/i }).click();
    await capacityResponse;

    // Step 2 — demand.
    await page.getByRole('spinbutton', { name: 'ED' }).fill('10');
    const demandResponse = page.waitForResponse((response) =>
      response.url().includes('/api/rtdc/units/1/demand') &&
      response.request().method() === 'POST' &&
      response.ok()
    );
    await page.getByRole('button', { name: /save demand/i }).click();
    await demandResponse;

    // Step 3 — compute bed-need.
    const planResponse = page.waitForResponse((response) =>
      response.url().includes('/api/rtdc/units/1/plan') &&
      response.request().method() === 'POST' &&
      response.ok()
    );
    await page.getByRole('button', { name: /compute bed-need/i }).click();
    const plan = await (await planResponse).json();
    const prediction = plan.data;

    await expect(page.getByText(/Bed Need/i)).toBeVisible();
    await expect(page.getByText(`Demand ${prediction.demand_expected} · Effective capacity ${prediction.capacity_now}`)).toBeVisible();
    await expect(page.getByText(prediction.bed_need > 0 ? `+${prediction.bed_need}` : `${prediction.bed_need}`)).toBeVisible();
  });
});
