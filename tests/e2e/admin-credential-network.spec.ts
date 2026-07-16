import { test, expect } from '@playwright/test';
import { loginAsTestUser } from './support/auth';

test.describe('Admin credential and network governance', () => {
  test('renders provider truth, immutable credential authority, and governed route controls', async ({ page }) => {
    await loginAsTestUser(page);
    await page.goto('/integrations?tab=credentials', { waitUntil: 'domcontentloaded' });

    await expect(page.getByRole('heading', { level: 1, name: 'Integrations' })).toBeVisible();
    await expect(page.getByRole('tab', { name: 'Credentials' })).toHaveAttribute('aria-selected', 'true');
    await expect(page.getByRole('heading', { name: 'Reference Administration' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Provider, Rotation, and Certificate Authority' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Outbound Network Authority' })).toBeVisible();
    await expect(page.getByText(/Secret values are resolved at validation or runtime and are never returned/i)).toBeVisible();

    for (const scheme of ['file://', 'vault://', 'aws-secretsmanager://', 'gcp-secretmanager://', 'azure-keyvault://']) {
      await expect(page.getByText(scheme, { exact: true })).toBeVisible();
    }

    await expect(page.getByText(/DNS is re-resolved and pinned at connection time/i)).toBeVisible();
    await expect(page.getByRole('button', { name: 'Add route' })).toBeDisabled();
    await expect(page.getByText('No governed network routes configured for the selected source.')).toBeVisible();
  });
});
