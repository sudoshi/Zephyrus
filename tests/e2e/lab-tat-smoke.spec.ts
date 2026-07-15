import { expect, test, type Page } from '@playwright/test';

const forbidden = ['patient_ref', 'specimen_uuid', 'source_specimen_key', 'result_uuid', 'source_result_key', 'source_critical_key'];

async function prepare(page: Page, darkMode: boolean) {
  const consoleErrors: string[] = [];
  const pageErrors: string[] = [];
  page.on('console', (message) => {
    if (message.type() === 'error') consoleErrors.push(message.text());
  });
  page.on('pageerror', (error) => pageErrors.push(error.message));
  await page.route('**/api/cockpit/stream**', async (route) => route.fulfill({ status: 204, body: '' }));
  await page.addInitScript((dark) => window.localStorage.setItem('darkMode', dark ? 'true' : 'false'), darkMode);

  return { consoleErrors, pageErrors };
}

async function expectPrivateAndContained(page: Page) {
  const text = await page.locator('body').innerText();
  for (const key of forbidden) expect(text).not.toContain(key);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
}

test('desktop Laboratory TAT Study renders every governed evidence family', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 1000 });
  const errors = await prepare(page, false);

  await page.goto('/analytics/lab-tat', { waitUntil: 'domcontentloaded' });
  await expect(page.getByRole('heading', { name: 'Laboratory TAT Study' })).toBeVisible({ timeout: 15000 });
  await expect(page.getByRole('table', { name: 'Accessible Laboratory TAT segment waterfall summary' })).toBeVisible();
  await expect(page.getByRole('table', { name: 'Accessible daily Laboratory TAT trend summary' })).toBeVisible();
  await expect(page.getByRole('table', { name: 'Accessible AM Laboratory readiness curve summary' })).toContainText('83.3%');
  await expect(page.getByRole('table', { name: 'Accessible Laboratory auto-verification trend summary' })).toBeVisible();
  await expect(page.getByRole('table', { name: 'Accessible Laboratory specimen-quality rate summary' })).toContainText('Recollect');
  await expect(page.getByRole('img', { name: /critical callback state/i })).toBeVisible();
  await expect(page.getByRole('table', { name: 'Accessible Laboratory breach Pareto summary' })).toBeVisible();
  await expect(page.getByRole('table', { name: 'Microbiology result-stage progression' })).toContainText('Susceptibility');
  await expect(page.getByText(/Historical microbiology progression is outside the live operational window/i)).toBeVisible();
  await expect(page.getByText(/Historical AP sign-out examples are labeled outside the live operational window/i)).toBeVisible();
  await expect(page.getByRole('table', { name: 'Blood Bank readiness states' })).toContainText('Crossmatch Ready');
  await expect(page.getByRole('table', { name: 'Laboratory governed benchmark references' })).toContainText('No governed numeric line');
  await expectPrivateAndContained(page);

  expect(errors.consoleErrors).toEqual([]);
  expect(errors.pageErrors).toEqual([]);
});

test('mobile dark Laboratory TAT Study remains semantically usable and contained', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  const errors = await prepare(page, true);

  await page.goto('/analytics/lab-tat?testFamily=troponin&priority=stat&patientClass=emergency', { waitUntil: 'domcontentloaded' });
  await expect(page.getByRole('heading', { name: 'Laboratory TAT Study' })).toBeVisible({ timeout: 15000 });
  await expect(page.locator('select[name="testFamily"]')).toHaveValue('troponin');
  await expect(page.locator('select[name="priority"]')).toHaveValue('stat');
  await expect(page.getByRole('table', { name: 'Accessible Order-to-verification by Test family summary' })).toContainText('Troponin');
  await expect(page.getByRole('link', { name: 'Open Laboratory Flow Board' })).toHaveAttribute('href', '/lab');
  await expectPrivateAndContained(page);

  expect(errors.consoleErrors).toEqual([]);
  expect(errors.pageErrors).toEqual([]);
});
