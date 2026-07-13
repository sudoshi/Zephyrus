import { test, expect } from '@playwright/test';
import type { Page } from '@playwright/test';
import { loginAsTestUser } from './support/auth';

async function openDashboard(page: Page) {
  await loginAsTestUser(page);
  await page.goto('/dashboard', { waitUntil: 'domcontentloaded' });
  await expect(page).toHaveURL(/\/dashboard/);
  await expect(page.getByRole('main')).toBeVisible({ timeout: 10000 });
}

async function expectLegacyRedirect(
  page: Page,
  legacyPath: string,
  drill: string
) {
  const redirect = await page.request.get(legacyPath, { maxRedirects: 0 });
  expect(redirect.status()).toBe(302);
  expect(redirect.headers().location ?? '').toContain(`/dashboard?drill=${drill}`);

  await page.goto(legacyPath, { waitUntil: 'domcontentloaded' });
  await expect(page).toHaveURL(new RegExp(`/dashboard\\?drill=${drill}`));
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

  test('renders section controls instead of every domain', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 800 });

    await expect(page.getByRole('button', { name: 'Cockpit' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Workspaces' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Study' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'RTDC' })).toHaveCount(0);

    await page.getByRole('button', { name: 'Workspaces' }).click();
    await expect(page.getByRole('tab', { name: 'RTDC' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Patient Flow 4D' })).toHaveAttribute(
      'href',
      '/rtdc/patient-flow-navigator',
    );
  });

  // P4a (D4): the legacy overview bookmarks are permanent redirects into the
  // cockpit drill layer — the old URL must land on /dashboard?drill={domain}.
  test('legacy perioperative overview redirects into the periop drill', async ({ page }) => {
    await expectLegacyRedirect(page, '/dashboard/perioperative', 'periop');
  });

  test('legacy RTDC overview redirects into the rtdc drill', async ({ page }) => {
    await expectLegacyRedirect(page, '/dashboard/rtdc', 'rtdc');
  });

  test('legacy emergency overview redirects into the ed drill', async ({ page }) => {
    await expectLegacyRedirect(page, '/dashboard/emergency', 'ed');
  });

  test('legacy improvement overview redirects into the quality drill', async ({ page }) => {
    await expectLegacyRedirect(page, '/dashboard/improvement', 'quality');
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

  test('filters entries and navigates to the selected page', async ({ page }) => {
    await page.keyboard.press('Meta+k');
    const commandInput = page.locator('[placeholder*="Search"], [placeholder*="search"]');
    await expect(commandInput).toBeVisible({ timeout: 5000 });

    await commandInput.fill('bed placement');
    const result = page.getByRole('option', { name: /bed placement/i }).first();
    await expect(result).toBeVisible();
    await result.click();

    await expect(page).toHaveURL(/\/rtdc\/bed-placement/);
  });
});

test.describe('Mobile Navigation', () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await openDashboard(page);
  });

  test('shows mobile-friendly layout on small viewport', async ({ page }) => {
    await expect(page.getByRole('main')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Open main navigation' })).toBeVisible();
  });

  test('mobile drawer exposes workspaces and closes with Escape', async ({ page }) => {
    const trigger = page.getByRole('button', { name: 'Open main navigation' });
    await trigger.click();
    await expect(page.getByRole('dialog')).toBeVisible();
    await page.getByRole('button', { name: 'RTDC' }).click();
    await expect(page.getByRole('link', { name: 'Patient Flow 4D' })).toBeVisible();

    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog')).not.toBeVisible();
    await expect(trigger).toBeFocused();
  });

  test('mobile command search is accessible', async ({ page }) => {
    await page.getByRole('button', { name: /search/i }).click();

    const commandInput = page.locator('[placeholder*="Search"], [placeholder*="search"]');
    await expect(commandInput).toBeVisible({ timeout: 5000 });
    await commandInput.fill('rtdc');
    await expect(page.getByRole('option', { name: /rtdc/i }).first()).toBeVisible();
  });
});

test.describe('Responsive navigation bounds', () => {
  for (const width of [375, 390, 768, 1024, 1280, 1440, 1920]) {
    test(`has no hidden horizontal navigation at ${width}px`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      await openDashboard(page);

      const primary = page.getByRole('navigation', { name: 'Primary' });
      const bounds = await primary.evaluate((element) => ({
        clientWidth: element.clientWidth,
        scrollWidth: element.scrollWidth,
      }));
      expect(bounds.scrollWidth).toBeLessThanOrEqual(bounds.clientWidth);

      if (width < 1024) {
        await expect(page.getByRole('button', { name: 'Open main navigation' })).toBeVisible();
      } else {
        await expect(page.getByRole('button', { name: 'Workspaces' })).toBeVisible();
      }
    });
  }
});
