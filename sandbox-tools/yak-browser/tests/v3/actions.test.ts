import { test } from 'node:test';
import assert from 'node:assert';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { startStaticServer } from '../support/server.ts';
import { skipWithoutChromium } from '../support/browser.ts';
import { launchChromium } from '../../src/v3/playwright.ts';
import { ensureCursor, moveCursorTo, smoothScrollTo, hideCursor, showCursor } from '../../src/v3/cursor.ts';
import { runAction } from '../../src/v3/actions.ts';

const here = dirname(fileURLToPath(import.meta.url));
const siteRoot = join(here, '..', 'fixtures', 'site');

async function withPage(fn: (page: any, base: string) => Promise<void>): Promise<void> {
  const server = await startStaticServer(siteRoot);
  const browser = await launchChromium();
  const context = await browser.newContext({ viewport: { width: 900, height: 700 } });
  const page = await context.newPage();
  try {
    await fn(page, server.url);
  } finally {
    await context.close();
    await browser.close();
    await server.close();
  }
}

test('navigate loads a path relative to the base', { skip: skipWithoutChromium }, async () => {
  await withPage(async (page, base) => {
    await runAction(page, { navigate: '/second.html' }, base);
    assert.match(page.url(), /second\.html$/);
  });
});

test('navigate accepts an absolute URL', { skip: skipWithoutChromium }, async () => {
  await withPage(async (page, base) => {
    await runAction(page, { navigate: `${base}/second.html` }, base);
    assert.match(page.url(), /second\.html$/);
  });
});

test('scroll_to moves the viewport toward the target', { skip: skipWithoutChromium }, async () => {
  await withPage(async (page, base) => {
    await runAction(page, { navigate: '/' }, base);
    assert.strictEqual(await page.evaluate(() => window.scrollY), 0);
    await runAction(page, { scroll_to: '#third' }, base);
    assert.ok((await page.evaluate(() => window.scrollY)) > 100);
  });
});

test('click follows a link', { skip: skipWithoutChromium }, async () => {
  await withPage(async (page, base) => {
    await runAction(page, { navigate: '/' }, base);
    await runAction(page, { click: '#go' }, base);
    await page.waitForURL(/second\.html/);
    assert.match(page.url(), /second\.html$/);
  });
});

test('fill types into an input', { skip: skipWithoutChromium }, async () => {
  await withPage(async (page, base) => {
    await runAction(page, { navigate: '/' }, base);
    await runAction(page, { fill: '#q', value: 'hello' }, base);
    assert.strictEqual(await page.inputValue('#q'), 'hello');
  });
});

test('wait accepts a number of milliseconds', { skip: skipWithoutChromium }, async () => {
  await withPage(async (page, base) => {
    await runAction(page, { navigate: '/' }, base);
    const before = Date.now();
    await runAction(page, { wait: 300 }, base);
    assert.ok(Date.now() - before >= 250);
  });
});

test('a missing selector throws an error naming it', { skip: skipWithoutChromium }, async () => {
  await withPage(async (page, base) => {
    await runAction(page, { navigate: '/' }, base);
    await assert.rejects(
      () => runAction(page, { click: '#nope' }, base),
      (error: Error) => /#nope/.test(error.message),
    );
  });
});

test('the cursor is injected, movable and hideable', { skip: skipWithoutChromium }, async () => {
  await withPage(async (page, base) => {
    await runAction(page, { navigate: '/' }, base);
    await ensureCursor(page);
    assert.strictEqual(await page.evaluate(() => document.getElementById('__yak_cursor') !== null), true);
    await moveCursorTo(page, '#target');
    await hideCursor(page);
    assert.strictEqual(
      await page.evaluate(() => document.getElementById('__yak_cursor')!.style.display),
      'none',
    );
    await showCursor(page);
    assert.notStrictEqual(
      await page.evaluate(() => document.getElementById('__yak_cursor')!.style.display),
      'none',
    );
  });
});

test('smoothScrollTo settles at the target', { skip: skipWithoutChromium }, async () => {
  await withPage(async (page, base) => {
    await runAction(page, { navigate: '/' }, base);
    await smoothScrollTo(page, '#third');
    const y = await page.evaluate(() => window.scrollY);
    await new Promise((r) => setTimeout(r, 200));
    assert.strictEqual(await page.evaluate(() => window.scrollY), y, 'scroll should have finished before returning');
  });
});
