import { test, expect } from '@playwright/test';
import { loginAsTestUser } from './support/auth';

test.describe('Admin identity lifecycle', () => {
  test('renders the governed external-identity and purge controls without exposing the provider subject', async ({ page }) => {
    await loginAsTestUser(page);
    await page.goto('/users', { waitUntil: 'domcontentloaded' });

    const targetRow = page.getByRole('row').filter({ hasText: 'Browser Lifecycle Target' });
    await expect(targetRow).toBeVisible({ timeout: 10_000 });
    await targetRow.getByRole('link', { name: 'Edit Browser Lifecycle Target' }).click();

    await expect(page).toHaveURL(/\/users\/\d+\/edit/);
    await expect(page.getByRole('heading', { name: 'External identities' })).toBeVisible();
    await expect(page.getByText('Subject fingerprint')).toBeVisible();
    await expect(page.getByText('e2e-lifecycle-subject')).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Unlink identity' })).toBeVisible();

    await expect(page.getByRole('heading', { name: 'Exceptional identity purge' })).toBeVisible();
    await expect(page.getByLabel('Purge justification')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Request exceptional identity purge' })).toBeVisible();
  });
});
