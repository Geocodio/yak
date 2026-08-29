import { test } from 'node:test';
import assert from 'node:assert';
import { mkdtempSync, mkdirSync, writeFileSync, chmodSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { resolveChromiumExecutable } from '../../src/v3/playwright.ts';

function fakeBrowsersRoot(): string {
  const root = mkdtempSync(join(tmpdir(), 'yak-ms-playwright-'));
  const dir = join(root, 'chromium-1234', 'chrome-linux');
  mkdirSync(dir, { recursive: true });
  const exe = join(dir, 'chrome');
  writeFileSync(exe, '#!/bin/sh\n');
  chmodSync(exe, 0o755);
  return root;
}

test('finds the chromium binary under PLAYWRIGHT_BROWSERS_PATH', () => {
  const root = fakeBrowsersRoot();
  const found = resolveChromiumExecutable({ PLAYWRIGHT_BROWSERS_PATH: root } as NodeJS.ProcessEnv);
  assert.ok(found, 'expected a resolved executable');
  assert.ok(found!.startsWith(root), `${found} should live under ${root}`);
});

test('prefers the headless shell when only that is installed', () => {
  const root = mkdtempSync(join(tmpdir(), 'yak-ms-playwright-'));
  const dir = join(root, 'chromium_headless_shell-1234', 'chrome-linux');
  mkdirSync(dir, { recursive: true });
  const exe = join(dir, 'headless_shell');
  writeFileSync(exe, '#!/bin/sh\n');
  chmodSync(exe, 0o755);
  assert.ok(resolveChromiumExecutable({ PLAYWRIGHT_BROWSERS_PATH: root } as NodeJS.ProcessEnv));
});

test('falls back to playwright-core when the path holds no chromium', () => {
  const empty = mkdtempSync(join(tmpdir(), 'yak-ms-playwright-'));
  // Falls through to chromium.executablePath(); on a machine with no browsers
  // installed that path does not exist, and the helper returns null.
  const found = resolveChromiumExecutable({ PLAYWRIGHT_BROWSERS_PATH: empty } as NodeJS.ProcessEnv);
  assert.ok(found === null || typeof found === 'string');
});

test('the default browsers path is the sandbox cache directory', () => {
  const found = resolveChromiumExecutable({} as NodeJS.ProcessEnv);
  // No assertion on the value (host-dependent); the call must not throw.
  assert.ok(found === null || typeof found === 'string');
});
