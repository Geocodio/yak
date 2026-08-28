import type { Page } from 'playwright-core';
import type { Action } from './types.ts';
import { ensureCursor, moveCursorTo, smoothScrollTo, clickPulse } from './cursor.ts';

const SETTLE_MS = 500;
const SELECTOR_TIMEOUT_MS = 10_000;

const sleep = (ms: number): Promise<void> => new Promise((resolve) => setTimeout(resolve, ms));

function resolveUrl(target: string, base: string): string {
  return /^https?:\/\//i.test(target) ? target : new URL(target, base).toString();
}

async function requireVisible(page: Page, selector: string): Promise<void> {
  try {
    await page.locator(selector).first().waitFor({ state: 'visible', timeout: SELECTOR_TIMEOUT_MS });
  } catch {
    throw new Error(`selector did not resolve: ${selector}`);
  }
}

/**
 * Execute one `do` entry with the pacing the cut needs: eased scrolling, a
 * cursor glide before every click, a click pulse, and a settle after
 * navigation. Throws an Error naming the selector when a target is missing.
 */
export async function runAction(page: Page, action: Action, base: string): Promise<void> {
  if (action.navigate !== undefined) {
    await page.goto(resolveUrl(action.navigate, base), { waitUntil: 'networkidle', timeout: 30_000 });
    await ensureCursor(page);
    await sleep(SETTLE_MS);
    return;
  }
  if (action.scroll_to !== undefined) {
    await requireVisible(page, action.scroll_to);
    await smoothScrollTo(page, action.scroll_to);
    await sleep(SETTLE_MS);
    return;
  }
  if (action.click !== undefined) {
    await requireVisible(page, action.click);
    await smoothScrollTo(page, action.click);
    await moveCursorTo(page, action.click);
    await clickPulse(page);
    await page.locator(action.click).first().click({ timeout: SELECTOR_TIMEOUT_MS });
    await sleep(SETTLE_MS);
    return;
  }
  if (action.hover !== undefined) {
    await requireVisible(page, action.hover);
    await smoothScrollTo(page, action.hover);
    await moveCursorTo(page, action.hover);
    await page.locator(action.hover).first().hover({ timeout: SELECTOR_TIMEOUT_MS });
    await sleep(SETTLE_MS);
    return;
  }
  if (action.fill !== undefined) {
    await requireVisible(page, action.fill);
    await smoothScrollTo(page, action.fill);
    await moveCursorTo(page, action.fill);
    await page.locator(action.fill).first().fill(action.value ?? '', { timeout: SELECTOR_TIMEOUT_MS });
    await sleep(SETTLE_MS);
    return;
  }
  if (action.type !== undefined) {
    await page.keyboard.type(action.type, { delay: 45 });
    await sleep(SETTLE_MS);
    return;
  }
  if (action.press !== undefined) {
    await page.keyboard.press(action.press);
    await sleep(SETTLE_MS);
    return;
  }
  if (action.wait !== undefined) {
    if (typeof action.wait === 'number') {
      await sleep(Math.min(5000, action.wait));
    } else {
      await requireVisible(page, action.wait);
    }
    return;
  }
  throw new Error(`unknown action: ${JSON.stringify(action)}`);
}
