import { test, expect } from '@playwright/test';
import type { Page } from '@playwright/test';

// Zephyrus demo web routes use SessionAuthMiddleware and auto-authenticate as admin.
async function blockCockpitStream(page: Page) {
  await page.route('**/api/cockpit/stream', async (route) => {
    await route.fulfill({ status: 204, body: '' });
  });
}

async function openDashboard(page: Page) {
  await blockCockpitStream(page);
  await page.goto('/dashboard', { waitUntil: 'domcontentloaded' });
  await expect(page).toHaveURL(/\/dashboard/);
  await expect(page.getByRole('main')).toBeVisible({ timeout: 10000 });
}

test.describe('Top Navigation', () => {
  test.beforeEach(async ({ page }) => {
    await openDashboard(page);
  });

  test('top navigation is visible on desktop', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 720 });

    await expect(page.getByRole('navigation')).toBeVisible();
    await expect(page.getByRole('link', { name: /zephyrus/i })).toBeVisible();
    await expect(page.getByRole('button', { name: /search/i })).toBeVisible();
  });

  // P4a (D4): the legacy overview bookmarks are permanent redirects into the
  // cockpit drill layer — the old URL must land on /dashboard?drill={domain}.
  test('legacy perioperative overview redirects into the periop drill', async ({ page }) => {
    await blockCockpitStream(page);
    await page.goto('/dashboard/perioperative', { waitUntil: 'domcontentloaded' });

    await expect(page).toHaveURL(/\/dashboard\?drill=periop/);
  });

  test('legacy RTDC overview redirects into the rtdc drill', async ({ page }) => {
    await blockCockpitStream(page);
    await page.goto('/dashboard/rtdc', { waitUntil: 'domcontentloaded' });

    await expect(page).toHaveURL(/\/dashboard\?drill=rtdc/);
  });

  test('legacy emergency overview redirects into the ed drill', async ({ page }) => {
    await blockCockpitStream(page);
    await page.goto('/dashboard/emergency', { waitUntil: 'domcontentloaded' });

    await expect(page).toHaveURL(/\/dashboard\?drill=ed/);
  });

  test('legacy improvement overview redirects into the quality drill', async ({ page }) => {
    await blockCockpitStream(page);
    await page.goto('/dashboard/improvement', { waitUntil: 'domcontentloaded' });

    await expect(page).toHaveURL(/\/dashboard\?drill=quality/);
  });
});

test.describe('Command Palette', () => {
  test.beforeEach(async ({ page }) => {
    await openDashboard(page);
  });

  test('opens with Cmd+K keyboard shortcut', async ({ page }) => {
    await page.keyboard.press('Meta+k');

    const commandInput = page.locator('[placeholder*="Search"], [placeholder*="search"]');
    await expect(commandInput).toBeVisible({ timeout: 5000 });
  });

  test('opens with Ctrl+K keyboard shortcut', async ({ page }) => {
    await page.keyboard.press('Control+k');

    const commandInput = page.locator('[placeholder*="Search"], [placeholder*="search"]');
    await expect(commandInput).toBeVisible({ timeout: 5000 });
  });

  test('closes when pressing Escape', async ({ page }) => {
    await page.keyboard.press('Meta+k');
    const commandInput = page.locator('[placeholder*="Search"], [placeholder*="search"]');
    await expect(commandInput).toBeVisible({ timeout: 5000 });

    await page.keyboard.press('Escape');

    await expect(commandInput).not.toBeVisible({ timeout: 5000 });
  });
});

test.describe('Mobile Navigation', () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await openDashboard(page);
  });

  test('shows mobile-friendly layout on small viewport', async ({ page }) => {
    await expect(page.getByRole('main')).toBeVisible();
  });

  test('mobile command search is accessible', async ({ page }) => {
    await expect(page.getByRole('button', { name: /search/i })).toBeVisible();
  });
});
