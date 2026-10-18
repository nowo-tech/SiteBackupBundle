import { test, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import type { Page } from '@playwright/test';
import { PANEL, createBackup, login, row } from './helpers';

/**
 * REQ-DEMO-013 — the panel and the setup wizard in the demo frame (demo nav + UiKit page + Symfony toolbar).
 * Run on a fresh demo (setup not completed yet) to capture the wizard.
 */
const outDir = process.env.SCREENSHOT_DIR
  ? resolve(process.env.SCREENSHOT_DIR)
  : resolve(__dirname, '../../../../docs/images/demo');

const WIDTH = 1280;
const PAD = 16;
const CHROME = 'nav, form.demo-gate-escape, main';

async function fitViewport(page: Page): Promise<void> {
  const toolbar = page.locator('.sf-toolbar .sf-toolbarreset');
  await expect(toolbar).toBeVisible({ timeout: 15_000 });
  await expect(page.locator('.sf-toolbar').first()).not.toContainText('Loading', { timeout: 15_000 });
  const toolbarHeight = Math.ceil((await toolbar.boundingBox())?.height ?? 36);
  const contentBottom = await page.evaluate((sel) => Math.max(...[...document.querySelectorAll(sel)]
    .filter((el) => !el.closest('.sf-toolbar, dialog'))
    .map((el) => el.getBoundingClientRect().bottom + window.scrollY)), CHROME);
  await page.setViewportSize({ width: WIDTH, height: Math.max(720, Math.ceil(contentBottom) + 24 + toolbarHeight) });
  await page.evaluate(() => window.scrollTo(0, 0));
}

async function shoot(page: Page, file: string): Promise<void> {
  await expect(page.locator('.sf-toolbar').first()).not.toContainText('Loading');
  const box = await page.evaluate((sel) => {
    const rects = [...document.querySelectorAll(`${sel}, .sf-toolbar .sf-toolbarreset`)]
      .map((el) => el.getBoundingClientRect())
      .filter((r) => r.width > 0 && r.height > 0);
    return {
      x: Math.min(...rects.map((r) => r.left)),
      y: Math.min(...rects.map((r) => r.top)),
      r: Math.max(...rects.map((r) => r.right)),
      b: Math.max(...rects.map((r) => r.bottom)),
    };
  }, CHROME);
  const vp = page.viewportSize()!;
  const x = Math.max(0, box.x - PAD);
  const y = Math.max(0, box.y - PAD);
  await page.screenshot({
    path: resolve(outDir, `${file}.png`),
    clip: { x, y, width: Math.min(vp.width, box.r + PAD) - x, height: Math.min(vp.height, box.b + PAD) - y },
  });
}

test.use({ viewport: { width: WIDTH, height: 900 } });

test.beforeAll(() => {
  mkdirSync(outDir, { recursive: true });
});

test.describe.serial('screenshots', () => {
  test('setup wizard on the guest layout', async ({ page }) => {
    await page.goto('/en/_setup');
    test.skip(!(await page.getByTestId('nowo-sbb-setup-wizard').count()), 'Setup already completed: reset the demo first.');
    await expect(page.locator('[data-nowo-ui-region="steps"] [aria-current="step"]')).toBeVisible();
    await fitViewport(page);
    await shoot(page, 'setup-wizard');
  });

  test('archives list', async ({ page }) => {
    await login(page);
    await createBackup(page, 'before-upgrade');
    await createBackup(page, 'pre-deploy');
    await fitViewport(page);
    await shoot(page, 'panel-archives');
  });

  test('restore type-to-confirm dialog', async ({ page }) => {
    await login(page);
    const first = page.locator('tbody tr').first();
    const id = ((await first.locator('code').first().textContent()) ?? '').trim();
    await fitViewport(page);
    await row(page, id).getByRole('button', { name: 'Restore' }).click();
    const dialog = page.locator('dialog[open]');
    await expect(dialog).toBeVisible();
    await dialog.locator('input.nowo-ui-confirm__expect-input').fill(id.slice(0, 12));
    await shoot(page, 'panel-restore-confirm');
  });
});
