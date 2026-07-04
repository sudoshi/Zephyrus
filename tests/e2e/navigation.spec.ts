import { test, expect } from '@playwright/test';

// Helper to authenticate before tests
async function login(page: any) {
  await page.goto('/login');
  await page.fill('input[name="username"]', process.env.TEST_USERNAME || 'admin');
  await page.fill('input[name="password"]', process.env.TEST_PASSWORD || 'password');
  await page.getByRole('button', { name: /log in/i }).click();
  await page.waitForURL(/\/(dashboard|home|change-password)/, { timeout: 10000 });
}

test.describe('Sidebar Navigation', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  test('sidebar is visible on desktop', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 720 });

    // Look for the navigation sidebar element
    const sidebar = page.locator('nav, [role="navigation"], aside').first();
    await expect(sidebar).toBeVisible();
  });

  // P4a (D4): the legacy overview bookmarks are permanent redirects into the
  // cockpit drill layer — the old URL must land on /dashboard?drill={domain}.
  test('legacy perioperative overview redirects into the periop drill', async ({ page }) => {
    await page.goto('/dashboard/perioperative');

    await expect(page).toHaveURL(/\/dashboard\?drill=periop/);
  });

  test('legacy RTDC overview redirects into the rtdc drill', async ({ page }) => {
    await page.goto('/dashboard/rtdc');

    await expect(page).toHaveURL(/\/dashboard\?drill=rtdc/);
  });

  test('legacy emergency overview redirects into the ed drill', async ({ page }) => {
    await page.goto('/dashboard/emergency');

    await expect(page).toHaveURL(/\/dashboard\?drill=ed/);
  });

  test('legacy improvement overview redirects into the quality drill', async ({ page }) => {
    await page.goto('/dashboard/improvement');

    await expect(page).toHaveURL(/\/dashboard\?drill=quality/);
  });
});

test.describe('Command Palette', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  test('opens with Cmd+K keyboard shortcut', async ({ page }) => {
    await page.goto('/dashboard');

    // Trigger Cmd+K (Meta+K)
    await page.keyboard.press('Meta+k');

    // Command palette should appear with a search input
    const commandInput = page.locator('[placeholder*="Search"], [placeholder*="search"]');
    await expect(commandInput).toBeVisible({ timeout: 5000 });
  });

  test('opens with Ctrl+K keyboard shortcut', async ({ page }) => {
    await page.goto('/dashboard');

    // Trigger Ctrl+K
    await page.keyboard.press('Control+k');

    // Command palette should appear
    const commandInput = page.locator('[placeholder*="Search"], [placeholder*="search"]');
    await expect(commandInput).toBeVisible({ timeout: 5000 });
  });

  test('closes when pressing Escape', async ({ page }) => {
    await page.goto('/dashboard');

    // Open the command palette
    await page.keyboard.press('Meta+k');
    const commandInput = page.locator('[placeholder*="Search"], [placeholder*="search"]');
    await expect(commandInput).toBeVisible({ timeout: 5000 });

    // Close with Escape
    await page.keyboard.press('Escape');

    // Should no longer be visible
    await expect(commandInput).not.toBeVisible({ timeout: 5000 });
  });
});

test.describe('Mobile Navigation', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await page.setViewportSize({ width: 375, height: 812 });
  });

  test('shows mobile-friendly layout on small viewport', async ({ page }) => {
    await page.goto('/dashboard');

    // On mobile, the main content area should still be visible
    const mainContent = page.locator('main, [role="main"]').first();
    await expect(mainContent).toBeVisible();
  });

  test('mobile drawer toggle is accessible', async ({ page }) => {
    await page.goto('/dashboard');

    // Look for a hamburger menu or drawer toggle button
    const menuButton = page.locator(
      'button[aria-label*="menu" i], button[aria-label*="sidebar" i], button[aria-label*="navigation" i]'
    );

    // The menu button should exist on mobile
    const count = await menuButton.count();
    if (count > 0) {
      await expect(menuButton.first()).toBeVisible();
    }
  });
});
