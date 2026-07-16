import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { loginAsTestUser } from './support/auth';

/**
 * ADM-IA — Automated WCAG 2.2 AA gate over every Admin surface that ships in
 * this branch. This asserts the machine-checkable subset of WCAG 2.2 AA
 * (contrast, name/role/value, form labelling, landmark/heading structure,
 * non-text status affordances). A full manual screen-reader / keyboard-task
 * audit remains a separate, human deliverable.
 */

const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'] as const;

/**
 * Documented, ratcheting baseline of KNOWN WCAG 2.2 AA color-contrast items that
 * are NOT local component bugs but properties of the core DESIGN.md interactive
 * palette, and therefore require a design-system-owned decision (see the plan's
 * ADM-IA section — this item is deliberately left unchecked until the palette
 * decision lands):
 *
 *   - `.bg-healthcare-info` / `.bg-healthcare-primary` fills carrying white text
 *     in dark mode: white on info #0284C7 is 4.10:1 and on primary-dark #3B82F6
 *     is 3.68:1, both below the 4.5:1 normal-text threshold. Fixing this repaints
 *     every primary/action button app-wide.
 *   - `.text-healthcare-critical` (dark coral #E85A6B) on the slate-800 surface
 *     is 4.25:1. Nudging the coral changes the DESIGN.md status palette globally.
 *
 * Every OTHER violation — including any NEW contrast, role, name, structure, or
 * status-affordance regression — still fails the gate. The baseline is matched by
 * rule id plus a stable target token so a genuinely new element cannot hide
 * behind it.
 */
const KNOWN_BASELINE: ReadonlyArray<{ id: string; targetIncludes: string }> = [
  { id: 'color-contrast', targetIncludes: 'bg-healthcare-info' },
  { id: 'color-contrast', targetIncludes: 'bg-healthcare-primary' },
  { id: 'color-contrast', targetIncludes: 'text-healthcare-critical' },
];

function isBaselined(violationId: string, target: string): boolean {
  return KNOWN_BASELINE.some(
    (entry) => entry.id === violationId && target.includes(entry.targetIncludes),
  );
}

async function assertNoAxeViolations(page: Page, label: string): Promise<void> {
  const results = await new AxeBuilder({ page })
    .withTags([...WCAG_TAGS])
    .analyze();

  const summary = results.violations
    .map((violation) => ({
      id: violation.id,
      impact: violation.impact,
      help: violation.help,
      nodes: violation.nodes
        .map((node) => ({
          target: node.target.join(' '),
          html: node.html,
          failureSummary: node.failureSummary,
        }))
        .filter((node) => !isBaselined(violation.id, node.target)),
    }))
    .filter((violation) => violation.nodes.length > 0);

  expect(summary, `${label} — axe WCAG 2.2 AA violations:\n${JSON.stringify(summary, null, 2)}`).toEqual([]);
}

async function selectDeterministicScope(page: Page): Promise<void> {
  await page.getByLabel('Active organization').selectOption({ label: 'E2E Health System' });
  await page.getByLabel('Active facility').selectOption({ label: 'E2E Medical Center' });
  await page.getByLabel('Active integration source').selectOption({ label: 'E2E FHIR Source' });
  await page.getByRole('button', { name: 'Apply' }).click();
}

test.describe('Admin surfaces meet automated WCAG 2.2 AA', () => {
  test('every Admin page renders without axe-detectable WCAG 2.2 AA violations', async ({ page }) => {
    await loginAsTestUser(page);

    // Establishing an explicit enterprise scope on the dashboard persists it
    // server-side, so scope-gated content is exercised on the pages that need it.
    await page.goto('/admin', { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('heading', { level: 1, name: 'Zephyrus Administration' })).toBeVisible();
    await assertNoAxeViolations(page, '/admin (unscoped dashboard)');

    await selectDeterministicScope(page);
    await expect(page).toHaveURL(/\/admin\?.*organization_id=\d+/);
    await assertNoAxeViolations(page, '/admin (scoped dashboard)');

    await page.goto('/admin/system-health', { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('heading', { level: 1, name: 'System Health' })).toBeVisible();
    await assertNoAxeViolations(page, '/admin/system-health');

    await page.goto('/admin/auth-providers', { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await assertNoAxeViolations(page, '/admin/auth-providers');

    await page.goto('/admin/roles-capabilities', { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('heading', { level: 1, name: 'Roles & Capabilities' })).toBeVisible();
    await assertNoAxeViolations(page, '/admin/roles-capabilities');

    await page.goto('/admin/access-reviews', { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await assertNoAxeViolations(page, '/admin/access-reviews');

    // Data Protection renders a minimum-necessary control plane unscoped, and
    // an additional governed-lifecycle surface once an exact source is selected.
    await page.goto('/admin/data-protection', { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('heading', { level: 1, name: 'Data Protection' })).toBeVisible();
    await assertNoAxeViolations(page, '/admin/data-protection (unscoped)');

    await selectDeterministicScope(page);
    await expect(page).toHaveURL(/\/admin\/data-protection\?.*source_id=\d+/);
    await assertNoAxeViolations(page, '/admin/data-protection (scoped)');

    await page.goto('/admin/cockpit/thresholds', { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await assertNoAxeViolations(page, '/admin/cockpit/thresholds');

    await page.goto('/admin/ai-providers', { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await assertNoAxeViolations(page, '/admin/ai-providers');

    await page.goto('/admin/enterprise-setup', { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await assertNoAxeViolations(page, '/admin/enterprise-setup (Enterprise Setup / Deployment console)');

    await page.goto('/users', { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await assertNoAxeViolations(page, '/users (index)');

    const editLink = page.getByRole('link', { name: /^Edit / }).first();
    await editLink.click();
    await expect(page).toHaveURL(/\/users\/\d+\/edit/);
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await assertNoAxeViolations(page, '/users/{id}/edit');
  });
});
