import { test } from 'node:test';
import assert from 'node:assert';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { mkdtempSync, mkdirSync, writeFileSync, utimesSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { startStaticServer } from '../support/server.ts';
import { skipWithoutChromium } from '../support/browser.ts';
import { runAssetPreflight, formatPreflightFailures } from '../../src/v3/assets.ts';
import { runAssets } from '../../src/commands/assets.ts';

const here = dirname(fileURLToPath(import.meta.url));
const fixtures = join(here, '..', 'fixtures');

test('a styled page with no build output passes', { skip: skipWithoutChromium }, async () => {
  const server = await startStaticServer(join(fixtures, 'site'));
  try {
    const failures = await runAssetPreflight({ base: server.url, projectRoot: mkdtempSync(join(tmpdir(), 'empty-')) });
    assert.deepStrictEqual(failures, [], JSON.stringify(failures));
  } finally {
    await server.close();
  }
});

test('a 404 stylesheet fails the preflight', { skip: skipWithoutChromium }, async () => {
  const server = await startStaticServer(join(fixtures, 'broken-css'));
  try {
    const failures = await runAssetPreflight({ base: server.url, projectRoot: mkdtempSync(join(tmpdir(), 'empty-')) });
    assert.ok(failures.some((f) => f.kind === 'request'), JSON.stringify(failures));
    assert.ok(failures.some((f) => f.offenders.some((o) => o.includes('missing.css'))));
  } finally {
    await server.close();
  }
});

test('a Vite manifest error page fails the preflight', { skip: skipWithoutChromium }, async () => {
  const server = await startStaticServer(join(fixtures, 'vite-error'));
  try {
    const failures = await runAssetPreflight({ base: server.url, projectRoot: mkdtempSync(join(tmpdir(), 'empty-')) });
    assert.ok(failures.some((f) => f.kind === 'bundler'), JSON.stringify(failures));
  } finally {
    await server.close();
  }
});

test('a stale project tree fails the preflight even when the page is fine', { skip: skipWithoutChromium }, async () => {
  const root = mkdtempSync(join(tmpdir(), 'yak-stale-'));
  const old = new Date('2026-01-01T00:00:00Z');
  const fresh = new Date('2026-06-01T00:00:00Z');
  mkdirSync(join(root, 'public', 'build'), { recursive: true });
  writeFileSync(join(root, 'public', 'build', 'manifest.json'), '{}');
  utimesSync(join(root, 'public', 'build', 'manifest.json'), old, old);
  mkdirSync(join(root, 'resources', 'css'), { recursive: true });
  writeFileSync(join(root, 'resources', 'css', 'app.css'), 'body{}');
  utimesSync(join(root, 'resources', 'css', 'app.css'), fresh, fresh);

  const server = await startStaticServer(join(fixtures, 'site'));
  try {
    const failures = await runAssetPreflight({ base: server.url, projectRoot: root });
    assert.ok(failures.some((f) => f.kind === 'stale'), JSON.stringify(failures));
  } finally {
    await server.close();
  }
});

test('an unreachable base is reported as a preflight failure instead of throwing', { skip: skipWithoutChromium }, async () => {
  // A closed local port: nothing is listening, so the connection is refused
  // immediately rather than timing out.
  const server = await startStaticServer(join(fixtures, 'site'));
  const deadUrl = server.url;
  await server.close();

  let failures: Awaited<ReturnType<typeof runAssetPreflight>> | undefined;
  let threw: unknown;
  try {
    failures = await runAssetPreflight({ base: deadUrl, projectRoot: mkdtempSync(join(tmpdir(), 'empty-')) });
  } catch (error) {
    threw = error;
  }
  assert.strictEqual(threw, undefined, `runAssetPreflight threw: ${String(threw)}`);
  assert.ok(failures);
  assert.ok(failures!.some((f) => f.kind === 'unreachable'), JSON.stringify(failures));
  assert.ok(failures!.some((f) => f.detail.includes(deadUrl)), JSON.stringify(failures));
});

test('the failure message ends with the rebuild hint', () => {
  const message = formatPreflightFailures([
    { kind: 'request', detail: 'stylesheet request failed', offenders: ['http://x/app.css (404)'] },
  ]);
  assert.match(message, /app\.css/);
  assert.match(message, /rebuild the frontend assets/i);
});

function captureStderr(): { text: () => string; restore: () => void } {
  const original = process.stderr.write.bind(process.stderr);
  let buffer = '';
  (process.stderr as unknown as { write: (chunk: unknown) => boolean }).write = (chunk) => {
    buffer += String(chunk);
    return true;
  };
  return {
    text: () => buffer,
    restore: () => {
      (process.stderr as unknown as { write: typeof original }).write = original;
    },
  };
}

test('assets without the check subcommand exits 2 with usage', async () => {
  const err = captureStderr();
  try {
    assert.strictEqual(await runAssets({ argv: [] }), 2);
    assert.match(err.text(), /assets check --base/);
  } finally {
    err.restore();
  }
});

test('assets check without --base exits 2', async () => {
  const err = captureStderr();
  try {
    assert.strictEqual(await runAssets({ argv: ['check'] }), 2);
    assert.match(err.text(), /requires --base/);
  } finally {
    err.restore();
  }
});
