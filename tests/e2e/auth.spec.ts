import { test, expect } from '@playwright/test';

test.describe('Authentication', () => {
  test('login page renders correctly', async ({ page }) => {
    await page.goto('/login', { waitUntil: 'domcontentloaded' });

    await expect(page).toHaveURL(/\/login/);
    await expect(page.getByRole('heading', { name: /welcome back/i })).toBeVisible();
    await expect(page.getByLabel(/username/i)).toBeVisible();
    await expect(page.getByLabel(/^password$/i)).toBeVisible();
    await expect(page.getByRole('button', { name: /sign in/i })).toBeVisible();
  });

  test('login page has create account section', async ({ page }) => {
    await page.goto('/login', { waitUntil: 'domcontentloaded' });

    await expect(page.getByText(/create an account/i)).toBeVisible();
  });

  test('shows validation errors for empty login', async ({ page }) => {
    await page.goto('/login', { waitUntil: 'domcontentloaded' });

    await page.getByRole('button', { name: /sign in/i }).click();

    await expect(page).toHaveURL(/\/login/);
    await expect(page.locator('#username:invalid')).toHaveCount(1);
    await expect(page.locator('#password:invalid')).toHaveCount(1);
  });

  test('shows error for invalid credentials', async ({ page }) => {
    await page.goto('/login', { waitUntil: 'domcontentloaded' });

    await page.getByLabel(/username/i).fill('invalid_user');
    await page.getByLabel(/^password$/i).fill('wrong_password');
    await page.getByRole('button', { name: /sign in/i }).click();

    await expect(page).toHaveURL(/\/login/);
    await expect(page.locator('.za-alert-err')).toContainText(/credentials|username|password|match/i, { timeout: 10000 });
  });

  test('seeded login redirects to dashboard', async ({ page }) => {
    test.skip(
      !process.env.TEST_USERNAME || !process.env.TEST_PASSWORD,
      'Set TEST_USERNAME and TEST_PASSWORD to run the seeded login E2E path.'
    );

    await page.route('**/api/cockpit/stream', async (route) => {
      await route.fulfill({ status: 204, body: '' });
    });

    await page.goto('/login', { waitUntil: 'domcontentloaded' });

    await page.getByLabel(/username/i).fill(process.env.TEST_USERNAME!);
    await page.getByLabel(/^password$/i).fill(process.env.TEST_PASSWORD!);
    await page.getByRole('button', { name: /sign in/i }).click();

    await expect(page).toHaveURL(/\/dashboard|\/change-password/, { timeout: 10000 });
  });

  test('demo web routes auto-authenticate dashboard viewers', async ({ page }) => {
    await page.route('**/api/cockpit/stream', async (route) => {
      await route.fulfill({ status: 204, body: '' });
    });

    await page.goto('/dashboard', { waitUntil: 'domcontentloaded' });

    await expect(page).toHaveURL(/\/dashboard/);
    await expect(page.getByRole('main')).toBeVisible({ timeout: 10000 });
  });

  test('change password modal appears for new users', async ({ page }) => {
    // This test requires a user with must_change_password=true
    test.skip(
      !process.env.TEST_NEW_USERNAME || !process.env.TEST_NEW_PASSWORD,
      'Set TEST_NEW_USERNAME and TEST_NEW_PASSWORD to run the temporary-password E2E path.'
    );

    await page.goto('/login', { waitUntil: 'domcontentloaded' });

    await page.getByLabel(/username/i).fill(process.env.TEST_NEW_USERNAME!);
    await page.getByLabel(/^password$/i).fill(process.env.TEST_NEW_PASSWORD!);
    await page.getByRole('button', { name: /sign in/i }).click();

    // Should redirect to change password or show the modal
    await page.waitForURL(/\/(change-password|dashboard)/, { timeout: 10000 });

    await expect(
      page.locator('#current_password, input[name="current_password"], text=/change your password/i').first()
    ).toBeVisible({ timeout: 10000 });
  });
});
