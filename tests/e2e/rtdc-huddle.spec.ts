import { test, expect } from '@playwright/test';

// Assumes the app + Reverb are running and TEST_USERNAME/TEST_PASSWORD seed a user.
// Run `php artisan rtdc:demo-reset` before this suite (see CI step).

test.describe('RTDC Unit Huddle (live)', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/login');
    await page.fill('input[name="username"]', process.env.TEST_USERNAME || 'admin');
    await page.fill('input[name="password"]', process.env.TEST_PASSWORD || 'password');
    await page.getByRole('button', { name: /log in/i }).click();
    await expect(page).not.toHaveURL(/\/login/, { timeout: 10000 });
  });

  test('runs a four-step cycle and shows bed-need', async ({ page }) => {
    await page.goto('/rtdc/unit-huddle');

    // Live census visible.
    await expect(page.getByText(/Live Census/i)).toBeVisible();

    // Step 1 — discharges.
    await page.getByLabel('definite').fill('2');
    await page.getByRole('button', { name: /save capacity/i }).click();

    // Step 2 — demand.
    await page.getByLabel('ED').fill('10');
    await page.getByRole('button', { name: /save demand/i }).click();

    // Step 3 — compute bed-need.
    await page.getByRole('button', { name: /compute bed-need/i }).click();

    await expect(page.getByText(/Bed Need/i)).toBeVisible();
  });
});
