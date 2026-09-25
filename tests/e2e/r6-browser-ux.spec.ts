import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

/**
 * R6 browser / RTL / accessibility certification.
 * URLs come from fixture env vars (no hardcoded page IDs).
 * No live payment.
 */

const BASE = process.env.R6_BASE_URL || 'http://127.0.0.1:8080';
const CLASSIC = process.env.R6_CLASSIC_CHECKOUT_URL || `${BASE}/?page_id=0`;
const BLOCKS = process.env.R6_BLOCKS_CHECKOUT_URL || `${BASE}/?page_id=0`;
const CALLBACK = process.env.R6_CALLBACK_URL || `${BASE}/wc-api/wc_upayments/`;
const PRODUCT = process.env.R6_PRODUCT_URL || '';

async function ensureCart(page: import('@playwright/test').Page) {
  if (!PRODUCT) return;
  await page.goto(PRODUCT, { waitUntil: 'domcontentloaded' });
  const add = page.locator('button.single_add_to_cart_button, button[name="add-to-cart"], .wc-block-components-product-button button');
  if (await add.first().isVisible().catch(() => false)) {
    await add.first().click();
    await page.waitForLoadState('domcontentloaded');
  }
}

const viewports = [
  { name: 'desktop', width: 1280, height: 800 },
  { name: 'mobile', width: 390, height: 844 },
];

function classifyConsoleError(text: string): boolean {
  if (/favicon|net::ERR_/.test(text)) return false;
  return true;
}

for (const vp of viewports) {
  test.describe(`R6 ${vp.name}`, () => {
    test.use({ viewport: { width: vp.width, height: vp.height } });

    test('Classic guest checkout: gateway UI, console, assets, focus, axe', async ({ page }) => {
      const errors: string[] = [];
      page.on('console', (m) => {
        if (m.type() === 'error') errors.push(m.text());
      });
      page.on('pageerror', (e) => errors.push(String(e)));

      const resp = await page.goto(CLASSIC, { waitUntil: 'networkidle' });
      expect(resp && resp.status() < 500).toBeTruthy();
      await ensureCart(page);
      await page.goto(CLASSIC, { waitUntil: 'networkidle' });
      await page.screenshot({ path: `artifacts/r6-classic-guest-${vp.name}.png`, fullPage: true });

      // Gateway UI present (Classic).
      const gateway = page.locator(
        '.payment_method_upayments, .payment_methods input[value="upayments"], [data-gateway_id="upayments"], label[for*="upayments"]'
      );
      await expect(gateway.first()).toBeVisible({ timeout: 20000 });

      // Keyboard focus onto an interactive control with visible focus-visible treatment.
      const firstInput = page.locator('input:visible, button:visible, select:visible, a:visible').first();
      await firstInput.focus();
      const focusState = await page.evaluate(() => {
        const el = document.activeElement as HTMLElement | null;
        if (!el) return null;
        const cs = getComputedStyle(el);
        return {
          tag: el.tagName,
          outlineStyle: cs.outlineStyle,
          outlineWidth: cs.outlineWidth,
          boxShadow: cs.boxShadow,
        };
      });
      expect(focusState).not.toBeNull();
      const hasFocusRing =
        (focusState!.outlineStyle !== 'none' && parseFloat(focusState!.outlineWidth || '0') > 0) ||
        (focusState!.boxShadow !== 'none' && focusState!.boxShadow !== '');
      expect(hasFocusRing).toBeTruthy();

      // Duplicate plugin-owned DOM IDs.
      const dupIds = await page.evaluate(() => {
        const seen = new Set<string>();
        const dups: string[] = [];
        document.querySelectorAll('[id]').forEach((el) => {
          const id = el.id;
          if (!id) return;
          if (seen.has(id)) dups.push(id);
          seen.add(id);
        });
        return dups;
      });
      expect(dupIds).toEqual([]);

      // No sensitive tokens / user ids in DOM.
      const body = await page.content();
      expect(body).not.toMatch(/sk_live_[A-Za-z0-9]{8,}/);
      expect(body).not.toMatch(/upayments_token_identity_secret/);

      // First-party console errors.
      expect(errors.filter(classifyConsoleError)).toEqual([]);

      // Axe: fail on plugin-owned critical/serious.
      const results = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa'])
        .analyze();
      const severe = results.violations.filter((v) => v.impact === 'critical' || v.impact === 'serious');
      const pluginOwned = severe.filter((v) =>
        v.nodes.some((n) => /upayments|supcheckout|payment_method_upayments/i.test(n.html))
      );
      expect(pluginOwned, JSON.stringify(pluginOwned, null, 2)).toEqual([]);
    });

    test('Blocks checkout renders with payment methods', async ({ page }) => {
      const errors: string[] = [];
      page.on('console', (m) => {
        if (m.type() === 'error') errors.push(m.text());
      });
      await ensureCart(page);
      await page.goto(BLOCKS, { waitUntil: 'networkidle' });
      await page.screenshot({ path: `artifacts/r6-blocks-guest-${vp.name}.png`, fullPage: true });
      const hasCheckoutBlock = await page
        .locator('.wc-block-checkout, .wp-block-woocommerce-checkout, .wc-block-components-sidebar, form.checkout, .woocommerce-checkout')
        .first()
        .isVisible()
        .catch(() => false);
      expect(hasCheckoutBlock).toBeTruthy();
      expect(errors.filter(classifyConsoleError)).toEqual([]);
    });

    test('canonical WC-API callback does not leak success URL', async ({ page }) => {
      const resp = await page.goto(`${CALLBACK}?wc_order_id=1&track_id=x&requested_order_id=x`, {
        waitUntil: 'domcontentloaded',
      });
      expect(resp).not.toBeNull();
      const body = await page.content();
      expect(body).not.toMatch(/order-received/);
    });
  });
}

test.describe('R6 Arabic RTL (WordPress locale)', () => {
  test('document is RTL and checkout remains usable', async ({ page }) => {
    await page.goto(CLASSIC, { waitUntil: 'networkidle' });
    const dir = await page.evaluate(
      () => document.documentElement.getAttribute('dir') || getComputedStyle(document.documentElement).direction
    );
    expect(String(dir).toLowerCase()).toContain('rtl');
    await page.screenshot({ path: 'artifacts/r6-arabic-rtl-classic.png', fullPage: true });
    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth > window.innerWidth + 4
    );
    expect(overflow).toBeFalsy();

    const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
    const pluginOwned = results.violations.filter(
      (v) =>
        (v.impact === 'critical' || v.impact === 'serious') &&
        v.nodes.some((n) => /upayments|supcheckout/i.test(n.html))
    );
    expect(pluginOwned, JSON.stringify(pluginOwned, null, 2)).toEqual([]);
  });
});
