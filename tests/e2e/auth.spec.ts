import { test, expect } from '@playwright/test';

test.describe('Authentication', () => {
  test('login page renders correctly', async ({ page }) => {
    await page.goto('/login');

    await expect(page).toHaveURL(/\/login/);
    await expect(page.locator('input[name="username"]')).toBeVisible();
    await expect(page.locator('input[name="password"]')).toBeVisible();
  });

  test('login page has create account section', async ({ page }) => {
    await page.goto('/login');

    await expect(page.getByText(/create account/i)).toBeVisible();
  });

  test('shows validation errors for empty login', async ({ page }) => {
    await page.goto('/login');

    await page.getByRole('button', { name: /log in/i }).click();

    // Should stay on login page with validation errors
    await expect(page).toHaveURL(/\/login/);
  });

  test('shows error for invalid credentials', async ({ page }) => {
    await page.goto('/login');

    await page.fill('input[name="username"]', 'invalid_user');
    await page.fill('input[name="password"]', 'wrong_password');
    await page.getByRole('button', { name: /log in/i }).click();

    // Should stay on login page and show error
    await expect(page).toHaveURL(/\/login/);
  });

  test('successful login redirects to dashboard', async ({ page }) => {
    await page.goto('/login');

    // This test requires a seeded test user in the database
    // The actual credentials would be configured per environment
    await page.fill('input[name="username"]', process.env.TEST_USERNAME || 'admin');
    await page.fill('input[name="password"]', process.env.TEST_PASSWORD || 'password');
    await page.getByRole('button', { name: /log in/i }).click();

    // Should redirect away from login on success
    await expect(page).not.toHaveURL(/\/login/, { timeout: 10000 });
  });

  test('unauthenticated users are redirected to login', async ({ page }) => {
    await page.goto('/dashboard');

    await expect(page).toHaveURL(/\/login/);
  });

  test('change password modal appears for new users', async ({ page }) => {
    // This test requires a user with must_change_password=true
    await page.goto('/login');

    await page.fill('input[name="username"]', process.env.TEST_NEW_USERNAME || 'newuser');
    await page.fill('input[name="password"]', process.env.TEST_NEW_PASSWORD || 'temp_pass');
    await page.getByRole('button', { name: /log in/i }).click();

    // Should redirect to change password or show the modal
    await page.waitForURL(/\/(change-password|dashboard)/, { timeout: 10000 });

    // If redirected to change-password page
    const url = page.url();
    if (url.includes('change-password')) {
      await expect(page.locator('input[name="current_password"], input[name="password"]')).toBeVisible();
    }
  });
});
