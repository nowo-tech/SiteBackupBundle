import { test, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import type { Page } from '@playwright/test';
import { PANEL, createBackup, login, row } from './helpers';

/**
 * REQ-DEMO-013 — the panel and the setup wizard in the demo frame (demo platform shell + UiKit page, no Symfony toolbar).
 * Run on a fresh demo (setup not completed yet) to capture the wizard.
 */
const outDir = process.env.SCREENSHOT_DIR
  ? resolve(process.env.SCREENSHOT_DIR)
  : resolve(__dirname, '../../../../docs/images/demo');

const WIDTH = 1280;
const PAD = 16;
const SHELL = '[data-testid="demo-platform"]';

/** Hides the Symfony Web Debug Toolbar: the README screenshots show the demo platform shell only. */
async function hideProfiler(page: Page): Promise<void> {
  await page.addStyleTag({ content: '.sf-toolbar,.sf-minitoolbar,[id^="sfwdt"]{display:none!important}' });
}

/** Grows the viewport so the whole page fits (the toolbar is hidden). */
async function fitViewport(page: Page): Promise<void> {
  await hideProfiler(page);
  await page.waitForLoadState('networkidle', { timeout: 20_000 }).catch(() => {});
  await page.setViewportSize({ width: WIDTH, height: 720 });
  const height = await page.evaluate(() => document.documentElement.scrollHeight);
  await page.setViewportSize({ width: WIDTH, height: Math.min(Math.max(720, height), 1800) });
  await page.evaluate(() => window.scrollTo(0, 0));
}

/** Padded clip of the demo platform shell. */
async function shoot(page: Page, file: string): Promise<void> {
  const box = (await page.locator(SHELL).boundingBox())!;
  const vp = page.viewportSize()!;
  const x = Math.max(0, box.x - PAD);
  const y = Math.max(0, box.y - PAD);
  await page.screenshot({
    path: resolve(outDir, `${file}.png`),
    clip: { x, y, width: Math.min(vp.width, box.x + box.width + PAD) - x, height: Math.min(vp.height, box.y + box.height + PAD) - y },
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
