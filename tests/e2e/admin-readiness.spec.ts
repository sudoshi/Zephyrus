import { test, expect } from '@playwright/test';
import { loginAsTestUser } from './support/auth';

test.describe('Admin readiness and policy surfaces', () => {
  test('renders truthful section states, bounded health evidence, and the canonical role catalog', async ({ page }) => {
    await loginAsTestUser(page);

    await page.goto('/admin', { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('heading', { level: 1, name: 'Zephyrus Administration' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Effective operating boundary' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Action queue' })).toBeVisible();
    await expect(page.getByText('System Health', { exact: true }).first()).toBeVisible();
    await expect(page.getByText('Roles / Capabilities', { exact: true })).toBeVisible();
    await expect(page.getByText('ready', { exact: true }).first()).toBeVisible();
    await expect(page.getByText('degraded', { exact: true })).toBeVisible();

    await page.goto('/admin/system-health?status=attention', { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('heading', { level: 1, name: 'System Health' })).toBeVisible();
    await expect(page.getByText(/unknown is never presented as healthy/i)).toBeVisible();
    await expect(page.getByRole('button', { name: 'attention', exact: true })).toHaveAttribute('aria-pressed', 'true');

    const diagnostic = page.getByRole('button', { name: 'Run bounded diagnostics' });
    await diagnostic.click();
    await expect(diagnostic).toBeEnabled({ timeout: 15_000 });
    await page.getByRole('button', { name: 'all', exact: true }).click();
    await expect(page.getByText('Primary database', { exact: true })).toBeVisible();
    await expect(page.getByText('Backup evidence', { exact: true })).toBeVisible();

    await page.goto('/admin/roles-capabilities', { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('heading', { level: 1, name: 'Roles & Capabilities' })).toBeVisible();
    await expect(page.getByText(/projection, not a grant store/i)).toBeVisible();
    await expect(page.getByText('viewSystemHealth', { exact: true }).first()).toBeVisible();
    await expect(page.getByText('runDiagnostics', { exact: true }).first()).toBeVisible();
    await expect(page.getByRole('button', { name: /save|edit|grant/i })).toHaveCount(0);
  });

  test('requires explicit enterprise scope and preserves it across Admin deep links', async ({ page }) => {
    await loginAsTestUser(page);

    await page.goto('/admin', { waitUntil: 'domcontentloaded' });
    await expect(page.getByLabel('Active organization')).toHaveValue('');
    await expect(page.getByRole('button', { name: 'Apply' })).toBeDisabled();

    await page.getByLabel('Active organization').selectOption({ label: 'E2E Health System' });
    await page.getByLabel('Active facility').selectOption({ label: 'E2E Medical Center' });
    await page.getByLabel('Active integration source').selectOption({ label: 'E2E FHIR Source' });
    await page.getByRole('button', { name: 'Apply' }).click();

    await expect(page).toHaveURL(/\/admin\?/);
    const adminUrl = new URL(page.url());
    expect(adminUrl.pathname).toBe('/admin');
    expect(adminUrl.searchParams.get('organization_id')).toMatch(/^\d+$/);
    expect(adminUrl.searchParams.get('facility_id')).toMatch(/^\d+$/);
    expect(adminUrl.searchParams.get('source_id')).toMatch(/^\d+$/);
    await expect(page.getByLabel('Active organization')).toHaveValue(/\d+/);
    await expect(page.getByLabel('Active facility')).toHaveValue(/\d+/);
    await expect(page.getByLabel('Active integration source')).toHaveValue(/\d+/);
    await expect(page.locator('p', { hasText: /^E2E Health System$/ })).toBeVisible();
    await expect(page.locator('p', { hasText: /^E2E Medical Center$/ })).toBeVisible();
    await expect(page.locator('p', { hasText: /^E2E FHIR Source$/ })).toBeVisible();

    await page.getByRole('link', { name: /^Integrations Manage the/ }).click();
    await expect(page).toHaveURL(/\/integrations\?/);
    const integrationsUrl = new URL(page.url());
    expect(integrationsUrl.pathname).toBe('/integrations');
    expect(integrationsUrl.searchParams.get('organization_id')).toBe(adminUrl.searchParams.get('organization_id'));
    expect(integrationsUrl.searchParams.get('facility_id')).toBe(adminUrl.searchParams.get('facility_id'));
    expect(integrationsUrl.searchParams.get('source_id')).toBe(adminUrl.searchParams.get('source_id'));
    await expect(page.getByRole('heading', { level: 1, name: 'Integrations' })).toBeVisible();
    await expect(page.getByText(/Mutations are constrained to the explicitly selected organization, facility, and source/i)).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Production onboarding and activation readiness' })).toBeVisible();
    await expect(page.getByText(/Immutable profile v1/)).toBeVisible();
    await expect(page.getByText(/not ready/i).first()).toBeVisible();
    await page.getByText('4. Future-dated governed activation', { exact: true }).click();
    await expect(page.getByRole('button', { name: 'Request independently approved activation window' })).toBeDisabled();

    await page.getByRole('tab', { name: 'FHIR R4 / SMART' }).click();
    await expect(page).toHaveURL(/tab=fhir/);
    await expect(page.getByRole('heading', { name: 'FHIR R4 Connections' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Discovered FHIR + SMART Conformance' })).toBeVisible();
    await expect(page.getByText(/No successful CapabilityStatement and SMART discovery has been observed/i)).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Governed Resource Profiles' })).toBeVisible();
    await expect(page.getByLabel('Resource type')).toBeVisible();
    await expect(page.getByLabel('Change reason')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Save profile' })).toBeDisabled();
    await expect(page.getByText(/enabled only after live capability discovery and SMART scope confirmation/i)).toBeVisible();

    await page.getByRole('button', { name: 'Clear' }).click();
    await expect(page).toHaveURL(/\/integrations\?tab=fhir$/);
    const clearedUrl = new URL(page.url());
    expect(clearedUrl.searchParams.get('tab')).toBe('fhir');
    expect(clearedUrl.searchParams.get('organization_id')).toBeNull();
    expect(clearedUrl.searchParams.get('facility_id')).toBeNull();
    expect(clearedUrl.searchParams.get('source_id')).toBeNull();
    await expect(page.getByLabel('Active organization')).toHaveValue('');
    await expect(page.getByText(/global roles do not bypass this selection/i)).toBeVisible();
  });

  test('renders the minimum-necessary Data Protection control plane without clinical content', async ({ page }) => {
    await loginAsTestUser(page);
    await page.goto('/admin/data-protection', { waitUntil: 'domcontentloaded' });

    await expect(page.getByRole('heading', { level: 1, name: 'Data Protection' })).toBeVisible();
    await expect(page.getByText(/Minimum-necessary boundary/i)).toBeVisible();
    await expect(page.getByText(/never decrypted bodies/i)).toBeVisible();
    await expect(page.getByText('ClinicalPayloadStore', { exact: true })).toBeVisible();
    await expect(page.getByText('xchacha20-poly1305-ietf', { exact: true })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Encryption and backfill coverage' })).toBeVisible();
    await expect(page.getByText('Outbound writeback drafts', { exact: true })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Operator action queue' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Governed payload lifecycle and recovery' })).toBeVisible();
    await expect(page.getByText(/Select one exact integration source/i)).toBeVisible();
    await expect(page.getByText(/Clinical content inspection remains prohibited/i)).toBeVisible();
    await expect(page.getByRole('button', { name: /release|purge|backfill|delete payload/i })).toHaveCount(0);
    await expect(page.getByText(/RECOGNIZABLE-/)).toHaveCount(0);

    await page.getByLabel('Active organization').selectOption({ label: 'E2E Health System' });
    await page.getByLabel('Active facility').selectOption({ label: 'E2E Medical Center' });
    await page.getByLabel('Active integration source').selectOption({ label: 'E2E FHIR Source' });
    await page.getByRole('button', { name: 'Apply' }).click();
    await expect(page).toHaveURL(/\/admin\/data-protection\?.*source_id=\d+/);
    await expect(page.getByText(/No non-deleted payload authorities in this source/i)).toBeVisible();
    await expect(page.getByText(/No open quarantine authority in this source/i)).toBeVisible();
    await expect(page.getByText(/No clinical-payload governed change is recorded/i)).toBeVisible();
    await expect(page.getByText(/RECOGNIZABLE-/)).toHaveCount(0);
  });
});
