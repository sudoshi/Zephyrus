import { test, expect } from '@playwright/test';
import type { APIRequestContext, Page } from '@playwright/test';

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

async function expectLegacyRedirect(
  request: APIRequestContext,
  page: Page,
  legacyPath: string,
  drill: string
) {
  const redirect = await request.get(legacyPath, { maxRedirects: 0 });
  expect(redirect.status()).toBe(302);
  expect(redirect.headers().location ?? '').toContain(`/dashboard?drill=${drill}`);

  await blockCockpitStream(page);
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

    await expect(page.getByRole('link', { name: 'Cockpit' })).toBeVisible();
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

  test('Radiology workspace exposes each operational leaf from one desktop domain', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 800 });

    await page.getByRole('button', { name: 'Workspaces' }).click();
    await page.getByRole('tab', { name: 'Radiology' }).click();

    const expected = [
      ['Imaging Flow Board', '/radiology'],
      ['Order Worklist', '/radiology/worklist'],
      ['Modality Utilization', '/radiology/modality'],
      ['Reads & Results', '/radiology/reads'],
    ] as const;
    for (const [label, href] of expected) {
      await expect(page.getByRole('link', { name: label })).toHaveAttribute('href', href);
    }
    await expect(page.locator('a[href="/radiology"]')).toHaveCount(1);
  });

  test('Laboratory workspace exposes each operational leaf from one desktop domain', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 800 });
    await page.getByRole('button', { name: 'Workspaces' }).click();
    await page.getByRole('tab', { name: 'Laboratory' }).click();

    for (const [label, href] of [
      ['Laboratory Flow Board', '/lab'],
      ['Specimen Tracker', '/lab/specimens'],
      ['Decision-Pending Results', '/lab/pending-decisions'],
      ['Blood Bank Readiness', '/lab/blood-bank'],
      ['AP Case Aging', '/lab/anatomic-path'],
    ] as const) {
      await expect(page.getByRole('link', { name: label })).toHaveAttribute('href', href);
    }
    await expect(page.locator('a[href="/lab"]')).toHaveCount(1);
  });

  // P4a (D4): the legacy overview bookmarks are permanent redirects into the
  // cockpit drill layer — the old URL must land on /dashboard?drill={domain}.
  test('legacy perioperative overview redirects into the periop drill', async ({ page, request }) => {
    await expectLegacyRedirect(request, page, '/dashboard/perioperative', 'periop');
  });

  test('legacy RTDC overview redirects into the rtdc drill', async ({ page, request }) => {
    await expectLegacyRedirect(request, page, '/dashboard/rtdc', 'rtdc');
  });

  test('legacy emergency overview redirects into the ed drill', async ({ page, request }) => {
    await expectLegacyRedirect(request, page, '/dashboard/emergency', 'ed');
  });

  test('legacy improvement overview redirects into the quality drill', async ({ page, request }) => {
    await expectLegacyRedirect(request, page, '/dashboard/improvement', 'quality');
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

  test('finds and opens a Radiology workspace leaf', async ({ page }) => {
    await page.keyboard.press('Meta+k');
    const commandInput = page.locator('[placeholder*="Search"], [placeholder*="search"]');
    await commandInput.fill('reads results');

    const result = page.getByRole('option', { name: /reads & results/i }).first();
    await expect(result).toBeVisible();
    await result.click();

    await expect(page).toHaveURL(/\/radiology\/reads/);
  });

  test('finds and opens the Analytics-owned Laboratory TAT Study leaf', async ({ page }) => {
    await page.keyboard.press('Meta+k');
    const commandInput = page.locator('[placeholder*="Search"], [placeholder*="search"]');
    await commandInput.fill('laboratory tat');

    const result = page.getByRole('option', { name: /laboratory tat/i }).first();
    await expect(result).toBeVisible();
    await result.click();

    await expect(page).toHaveURL(/\/analytics\/lab-tat/);
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

  test('mobile drawer exposes the same Radiology workspace leaves', async ({ page }) => {
    await page.getByRole('button', { name: 'Open main navigation' }).click();
    await page.getByRole('button', { name: 'Radiology' }).click();

    await expect(page.getByRole('link', { name: 'Imaging Flow Board' })).toHaveAttribute('href', '/radiology');
    await expect(page.getByRole('link', { name: 'Order Worklist' })).toHaveAttribute('href', '/radiology/worklist');
    await expect(page.getByRole('link', { name: 'Modality Utilization' })).toHaveAttribute('href', '/radiology/modality');
    await expect(page.getByRole('link', { name: 'Reads & Results' })).toHaveAttribute('href', '/radiology/reads');
  });

  test('mobile drawer exposes the same Laboratory workspace leaves', async ({ page }) => {
    await page.getByRole('button', { name: 'Open main navigation' }).click();
    await page.getByRole('button', { name: 'Laboratory' }).click();

    await expect(page.getByRole('link', { name: 'Laboratory Flow Board' })).toHaveAttribute('href', '/lab');
    await expect(page.getByRole('link', { name: 'Specimen Tracker' })).toHaveAttribute('href', '/lab/specimens');
    await expect(page.getByRole('link', { name: 'Decision-Pending Results' })).toHaveAttribute('href', '/lab/pending-decisions');
    await expect(page.getByRole('link', { name: 'Blood Bank Readiness' })).toHaveAttribute('href', '/lab/blood-bank');
    await expect(page.getByRole('link', { name: 'AP Case Aging' })).toHaveAttribute('href', '/lab/anatomic-path');
  });

  test('mobile command search is accessible', async ({ page }) => {
    await page.getByRole('button', { name: /search/i }).click();

    const commandInput = page.locator('[placeholder*="Search"], [placeholder*="search"]');
    await expect(commandInput).toBeVisible({ timeout: 5000 });
    await commandInput.fill('rtdc');
    await expect(page.getByRole('option', { name: /rtdc/i }).first()).toBeVisible();
  });
});

test.describe('RTDC ancillary handoff', () => {
  test('imaging tile drills into the unit-scoped Radiology worklist', async ({ page }) => {
    await page.goto('/rtdc/ancillary-services', { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('main')).toBeVisible({ timeout: 10000 });
    await page.getByRole('button', { name: 'Matrix' }).click();

    const drill = page.getByRole('link', { name: /Open .* Radiology worklist for/i }).first();
    await expect(drill).toBeVisible();
    await expect(drill).toHaveAttribute(
      'href',
      /\/radiology\/worklist\?unitId=\d+&source=ancillary_services/,
    );
    const href = await drill.getAttribute('href');
    expect(href).not.toBeNull();
    await page.goto(href as string, { waitUntil: 'domcontentloaded' });

    await expect(page).toHaveURL(/\/radiology\/worklist\?unitId=\d+&source=ancillary_services/);
    await expect(page.getByRole('heading', { name: 'Radiology Order Worklist' })).toBeVisible();
  });

  test('Laboratory tile drills into the unit-scoped Flow Board with provenance', async ({ page }) => {
    await blockCockpitStream(page);
    await page.goto('/rtdc/ancillary-services', { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('main')).toBeVisible({ timeout: 10000 });
    await page.getByRole('button', { name: 'Matrix' }).click();

    const drill = page.getByRole('link', { name: /Open Lab Laboratory Flow Board for/i }).first();
    await expect(drill).toBeVisible();
    await expect(drill).toHaveAttribute('href', /\/lab\?unitId=\d+&source=ancillary_services/);
    await drill.click();

    await expect(page).toHaveURL(/\/lab\?unitId=\d+&source=ancillary_services/);
    await expect(page.getByRole('heading', { name: 'Laboratory Flow Board' })).toBeVisible({ timeout: 10000 });
    await expect(page.locator('input[name="source"]')).toHaveValue('ancillary_services');
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
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
