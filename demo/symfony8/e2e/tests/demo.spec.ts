import { test, expect } from '@playwright/test';
import { PANEL, createBackup, login, row } from './helpers';

/**
 * REQ-DEMO-013 / REQ-UI-003 — the Site Backup panel and setup wizard render on the UiKit page contract inside the
 * demo host layouts (layouts/admin.html.twig, layouts/guest.html.twig).
 */
test.describe('setup wizard (guest layout)', () => {
  test('runs the guided setup to the end, or is closed once done', async ({ page }) => {
    await page.goto('/en/_setup');
    if (!(await page.getByTestId('nowo-sbb-setup-wizard').count())) {
      // setup already completed in this demo: the wizard redirects to the site
      await expect(page).toHaveURL(/\/$/);
      return;
    }
    await expect(page.locator('[data-nowo-ui-page="wizard"]')).toBeVisible();
    await expect(page.locator('[data-nowo-ui-region="steps"] [aria-current="step"]')).toBeVisible();

    for (let i = 0; i < 25; i++) {
      if (await page.getByTestId('nowo-sbb-setup-done').count()) {
        break;
      }
      const guided = page.getByRole('button', { name: 'Guided setup (create admin)' });
      const skip = page.getByRole('button', { name: 'Skip (use existing env)' });
      const email = page.locator('input[name="email"]');
      const next = page.getByRole('button', { name: 'Continue' });
      if (await guided.count()) {
        await guided.click();
      } else if (await skip.count()) {
        await skip.click();
      } else if (await email.count()) {
        await email.fill('admin@example.com');
        await page.locator('input[name="password"]').fill('Admin-Demo-2026!');
        await page.getByRole('button', { name: 'Create admin' }).click();
      } else if (await next.count()) {
        // automatic advance submits by itself; click if it did not yet
        await Promise.race([page.waitForLoadState('load'), next.click({ timeout: 2_000 }).catch(() => undefined)]);
      }
      await page.waitForLoadState('load');
    }

    await expect(page.getByTestId('nowo-sbb-setup-done')).toBeVisible();
    await expect(page.locator('[data-nowo-ui-region="steps"] [aria-current="step"]')).toHaveText('Done');
  });
});

test.describe('backup panel', () => {
  test('sign-in is a UiKit auth page on the guest layout', async ({ browser }) => {
    const page = await browser.newPage();
    await page.goto(PANEL);
    await expect(page.locator('[data-nowo-ui-page="auth"]')).toBeVisible();
    await page.locator('input[name="password"]').fill('wrong');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page.locator('.nowo-ui-flash--error')).toHaveText('Invalid password.');
    await page.close();
  });

  test('create, verify and list backups; history tab', async ({ page }) => {
    await login(page);
    const id = await createBackup(page, 'e2e-verify');
    await expect(row(page, id)).toContainText('e2e-verify');

    await row(page, id).getByRole('button', { name: 'Verify' }).click();
    await expect(page.locator('.nowo-ui-flash--success', { hasText: `Integrity OK for ${id}` })).toBeVisible();

    await page.getByTestId('nowo-sbb-nav-history').click();
    await expect(page.getByTestId('nowo-sbb-history')).toBeVisible();
    await expect(page.locator('tbody tr', { hasText: id }).first()).toBeVisible();
  });

  test('restore asks to type the backup id (UiKit confirm dialog)', async ({ page }) => {
    await login(page);
    const id = await createBackup(page, 'e2e-restore');

    await row(page, id).getByRole('button', { name: 'Restore' }).click();
    const dialog = page.locator('dialog[open]');
    await expect(dialog).toContainText(`Restore backup ${id}?`);
    const submit = dialog.getByRole('button', { name: 'Restore' });
    await expect(submit).toBeDisabled();
    await dialog.locator('input.nowo-ui-confirm__expect-input').fill('nope');
    await expect(submit).toBeDisabled();
    await dialog.locator('input.nowo-ui-confirm__expect-input').fill(id);
    await expect(submit).toBeEnabled();
    await dialog.getByRole('button', { name: 'Cancel' }).click();
    await expect(page.locator('dialog[open]')).toHaveCount(0);
  });

  test('delete through the dialog, and the no-JS confirm pages', async ({ page }) => {
    await login(page);
    const id = await createBackup(page, 'e2e-delete');

    await page.goto(`${PANEL}restore/${id}`);
    await expect(page.getByTestId('nowo-sbb-restore-confirm')).toBeVisible();
    await expect(page.locator('input[pattern]')).toHaveAttribute('pattern', id);
    await page.goto(`${PANEL}delete/${id}`);
    await expect(page.getByTestId('nowo-sbb-delete-confirm')).toBeVisible();

    await page.goto(PANEL);
    await row(page, id).getByRole('button', { name: 'Delete' }).click();
    await page.locator('dialog[open]').getByRole('button', { name: 'Delete' }).click();
    await expect(page.locator('.nowo-ui-flash--success', { hasText: `Backup deleted: ${id}` })).toBeVisible();
    await expect(row(page, id)).toHaveCount(0);
  });
});

test.describe('setup token gate (setup.token_gate, v1.22)', () => {
  test('renders the illustration as <picture> and the configured how-to steps', async ({ page }) => {
    await page.goto('/demo/setup-token-gate');
    await expect(page.getByTestId('nowo-sbb-token-gate')).toBeVisible();
    const art = page.getByTestId('nowo-sbb-token-illustration');
    await expect(art.locator('source[type="image/webp"]')).toHaveAttribute('srcset', /setup-locked\.webp$/);
    await expect(art.locator('img.nowo-sbb-token__img')).toHaveAttribute('width', '896');
    await expect(page.locator('form[method="get"] input[name="token"]')).toBeVisible();
    await expect(page.getByTestId('nowo-sbb-token-howto').locator('li')).toHaveCount(3);
  });
});
