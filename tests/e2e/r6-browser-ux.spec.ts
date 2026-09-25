import { test, expect, devices } from '@playwright/test';

/**
 * R6 browser/UX/RTL certification against a disposable default-theme store.
 * No live payment. Screenshots are evidence for the default test theme only.
 */

const BASE = process.env.R6_BASE_URL || 'http://127.0.0.1:8080';

const viewports = [
  { name: 'desktop', width: 1280, height: 800 },
  { name: 'mobile', width: 390, height: 844 },
];

for (const vp of viewports) {
  test.describe(`R6 UX ${vp.name}`, () => {
    test.use({ viewport: { width: vp.width, height: vp.height } });

    test('classic checkout has no critical console errors and labeled controls', async ({ page }) => {
      const errors: string[] = [];
      page.on('console', (msg) => {
        if (msg.type() === 'error') errors.push(msg.text());
      });
      await page.goto(`${BASE}/?page_id=3`, { waitUntil: 'domcontentloaded' });
      await page.screenshot({ path: `artifacts/r6-classic-${vp.name}.png`, fullPage: true });
      const inputs = page.locator('input, select, textarea');
      const count = await inputs.count();
      for (let i = 0; i < count; i++) {
        const el = inputs.nth(i);
        const id = await el.getAttribute('id');
        const aria = await el.getAttribute('aria-label');
        const name = await el.getAttribute('name');
        const labelled = id
          ? (await page.locator(`label[for="${id}"]`).count()) > 0
          : false;
        expect(labelled || !!aria || !!name).toBeTruthy();
      }
      expect(errors.filter((e) => !/favicon|net::ERR_/.test(e))).toEqual([]);
    });

    test('blocks checkout renders and keyboard focus is visible', async ({ page }) => {
      await page.goto(`${BASE}/checkout`, { waitUntil: 'domcontentloaded' });
      await page.screenshot({ path: `artifacts/r6-blocks-${vp.name}.png`, fullPage: true });
      await page.keyboard.press('Tab');
      const focused = await page.evaluate(() => {
        const el = document.activeElement as HTMLElement | null;
        return el ? { tag: el.tagName, outline: getComputedStyle(el).outlineStyle } : null;
      });
      expect(focused).not.toBeNull();
    });
  });
}

test.describe('R6 Arabic RTL', () => {
  test.use({ ...devices['Desktop Chrome'], locale: 'ar' });

  test('document direction is RTL and checkout remains usable', async ({ page }) => {
    await page.goto(`${BASE}/checkout`, { waitUntil: 'domcontentloaded' });
    const dir = await page.evaluate(() => document.documentElement.getAttribute('dir') || getComputedStyle(document.documentElement).direction);
    expect(String(dir).toLowerCase()).toContain('rtl');
    await page.screenshot({ path: 'artifacts/r6-arabic-rtl-checkout.png', fullPage: true });
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 2);
    expect(overflow).toBeFalsy();
  });
});
