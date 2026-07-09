import { test, expect } from '@playwright/test';

// Assumes the app + Reverb are running and TEST_USERNAME/TEST_PASSWORD seed a user.
// Run `php artisan rtdc:demo-reset` before this suite (see CI step).

test.describe('RTDC Unit Huddle (live)', () => {
  test.beforeEach(async ({ page }) => {
    // Zephyrus demo web routes use SessionAuthMiddleware and auto-authenticate as admin.
    await page.route('**/api/cockpit/stream', async (route) => {
      await route.fulfill({ status: 204, body: '' });
    });

    await page.goto('/rtdc/unit-huddle', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/rtdc\/unit-huddle/);
  });

  test('runs a four-step cycle and shows bed-need', async ({ page }) => {
    await expect(page.getByRole('region', { name: /step 1/i })).toBeVisible();

    // Step 1 — discharges.
    await page.getByRole('spinbutton', { name: 'definite' }).fill('2');
    await page.getByRole('button', { name: /save capacity/i }).click();

    // Step 2 — demand.
    await page.getByRole('spinbutton', { name: 'ED' }).fill('10');
    await page.getByRole('button', { name: /save demand/i }).click();

    // Step 3 — compute bed-need.
    await page.getByRole('button', { name: /compute bed-need/i }).click();

    await expect(page.getByText(/Bed Need/i)).toBeVisible();
  });
});
