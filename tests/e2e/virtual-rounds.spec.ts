import { expect, test } from '@playwright/test';
import { loginAsTestUser } from './support/auth';

/**
 * Virtual Rounds board (Phase 1 gate: the unit board is usable without the
 * 3D view). Requires VIRTUAL_ROUNDS_ENABLED=true on the target server; when
 * the flag is off the route 404s and the suite skips rather than fails, so
 * CI environments without the flag stay green.
 */

test.describe('Virtual Rounds board', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsTestUser(page);
  });

  test('renders the board shell and command bar', async ({ page }) => {
    const response = await page.goto('/rtdc/virtual-rounds');

    test.skip(response?.status() === 404, 'VIRTUAL_ROUNDS_ENABLED is off in this environment');

    await expect(page.getByText('Virtual Rounds')).toBeVisible();
    await expect(page.getByTestId('rounds-scope-select')).toBeVisible();
    await expect(page.getByTestId('rounds-template-select')).toBeVisible();
    await expect(page.getByTestId('rounds-create-run')).toBeVisible();
  });

  test('starting a run builds the queue and opens the workspace', async ({ page }) => {
    const response = await page.goto('/rtdc/virtual-rounds');

    test.skip(response?.status() === 404, 'VIRTUAL_ROUNDS_ENABLED is off in this environment');

    // Reuse an existing run if the selector already has one; otherwise start one.
    const runSelect = page.getByTestId('rounds-run-select');
    const hasRun = (await runSelect.locator('option').count()) > 1;

    if (!hasRun) {
      await page.getByTestId('rounds-create-run').click();
    }

    // The dense queue renders with at least one row, and selecting it opens
    // the right-side workspace with the explainable priority panel.
    const firstRow = page.getByTestId('rounds-row-1');
    await expect(firstRow).toBeVisible({ timeout: 15_000 });
    await firstRow.click();

    await expect(page.getByTestId('rounds-workspace')).toBeVisible();
    await expect(page.getByText('Why this position')).toBeVisible();
    await expect(page.getByText('Completion requirements')).toBeVisible();
  });
});
