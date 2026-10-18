import { expect, type Page } from '@playwright/test';

export const PANEL = '/_site_backup/';
export const PASSWORD = 'password';

/** Signs in to the backup panel (password gate) unless the session is already authenticated. */
export async function login(page: Page): Promise<void> {
  await page.goto(PANEL);
  if (await page.getByTestId('nowo-sbb-login').count()) {
    await page.locator('input[name="password"]').fill(PASSWORD);
    await page.getByRole('button', { name: 'Sign in' }).click();
  }
  await expect(page.getByTestId('nowo-sbb-archives')).toBeVisible();
}

/** Creates a backup through the create form (page/form) and returns its id from the success flash. */
export async function createBackup(page: Page, label: string): Promise<string> {
  await page.goto(PANEL);
  await page.getByTestId('nowo-sbb-create').click();
  await expect(page.getByTestId('nowo-sbb-create-form')).toBeVisible();
  await page.locator('input[name="label"]').fill(label);
  await page.getByRole('button', { name: 'Create integral backup' }).click();
  const flash = page.locator('.nowo-ui-flash--success', { hasText: 'Backup created:' });
  await expect(flash).toBeVisible({ timeout: 60_000 });
  const id = ((await flash.textContent()) ?? '').replace('Backup created:', '').trim();
  expect(id).not.toBe('');

  return id;
}

/** Row of the archives table for a backup id. */
export function row(page: Page, id: string) {
  return page.locator('tbody tr', { hasText: id });
}
