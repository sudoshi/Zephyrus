import { expect, type Page } from '@playwright/test';

export async function loginAsTestUser(page: Page) {
  const username = process.env.TEST_USERNAME;
  const password = process.env.TEST_PASSWORD;

  if (!username || !password) {
    throw new Error('TEST_USERNAME and TEST_PASSWORD are required for authenticated browser tests.');
  }

  // The local browser harness intentionally uses PHP's single-worker server.
  // Stub the long-lived cockpit SSE connection so it cannot monopolize that
  // worker and deadlock subsequent navigations in the same browser context.
  await page.route('**/api/cockpit/stream', async (route) => {
    await route.fulfill({ status: 204, body: '' });
  });

  await page.goto('/login', { waitUntil: 'domcontentloaded' });
  await page.getByLabel(/username/i).fill(username);
  await page.getByLabel(/^password$/i).fill(password);
  await page.getByRole('button', { name: /sign in/i }).click();
  await expect(page).toHaveURL(/\/dashboard/, { timeout: 15_000 });
}
