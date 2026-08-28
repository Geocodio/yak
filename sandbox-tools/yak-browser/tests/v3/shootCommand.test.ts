import { test } from 'node:test';
import assert from 'node:assert';
import { mkdtempSync, writeFileSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { runShoot } from '../../src/commands/shoot.ts';
import { validScript } from './lint.test.ts';
import { startStaticServer } from '../support/server.ts';
import { skipWithoutChromium } from '../support/browser.ts';

const here = dirname(fileURLToPath(import.meta.url));
const siteRoot = join(here, '..', 'fixtures', 'site');

function writeScript(body: unknown): string {
  const dir = mkdtempSync(join(tmpdir(), 'yak-shoot-cmd-'));
  const path = join(dir, 'script.json');
  writeFileSync(path, JSON.stringify(body, null, 2));
  return path;
}

function fixtureScript(): ReturnType<typeof validScript> {
  const s = validScript();
  s.shots = [
    {
      id: 'one',
      chapter: 'One',
      say: 'The first section of the fixture page is shown.',
      do: [{ navigate: '/' }, { scroll_to: '#first' }],
      focus: '#first',
    },
    {
      id: 'two',
      chapter: 'Two',
      say: 'The target paragraph in the second section is shown.',
      do: [{ scroll_to: '#target' }],
      focus: '#target',
    },
    {
      id: 'three',
      chapter: 'Three',
      say: 'Following the link opens the second page with its detail block.',
      do: [{ click: '#go' }],
      focus: '#detail-target',
    },
  ];
  s.screenshots = [{ id: 'one-shot', caption: 'The first section', after_shot: 'one' }];
  return s;
}

function captureStderr(): { lines: () => string; restore: () => void } {
  const original = process.stderr.write.bind(process.stderr);
  let buffer = '';
  (process.stderr as any).write = (chunk: any, ...rest: unknown[]) => {
    buffer += String(chunk);
    return (original as (...args: unknown[]) => boolean)(chunk, ...rest);
  };
  return { lines: () => buffer, restore: () => { (process.stderr as any).write = original; } };
}

// Finding 5: runShoot (the command layer) had no coverage at all — every
// shoot() call in the codebase went through the skipPreflight test escape
// hatch. These exercise the command layer end to end via its real exit codes.

test('runShoot exits 2 without --base', async () => {
  const err = captureStderr();
  let code: number;
  try {
    code = await runShoot({ scriptPath: 'irrelevant.json', artifactsDir: mkdtempSync(join(tmpdir(), 'yak-shoot-cmd-')) });
  } finally {
    err.restore();
  }
  assert.strictEqual(code, 2);
  assert.match(err.lines(), /--base/);
});

test('runShoot exits 2 on a script that fails static lint', async () => {
  const broken = validScript();
  broken.title = 'x'.repeat(200);
  const path = writeScript(broken);
  const err = captureStderr();
  let code: number;
  try {
    code = await runShoot({
      scriptPath: path,
      base: 'http://example.invalid',
      artifactsDir: mkdtempSync(join(tmpdir(), 'yak-shoot-cmd-')),
    });
  } finally {
    err.restore();
  }
  assert.strictEqual(code, 2);
});

test('runShoot exits 4 when the asset preflight fails', { skip: skipWithoutChromium }, async () => {
  const server = await startStaticServer(siteRoot);
  const deadUrl = server.url;
  await server.close();

  const path = writeScript(fixtureScript());
  const err = captureStderr();
  let code: number;
  try {
    code = await runShoot({
      scriptPath: path,
      base: deadUrl,
      artifactsDir: mkdtempSync(join(tmpdir(), 'yak-shoot-cmd-')),
      projectRoot: mkdtempSync(join(tmpdir(), 'empty-')),
    });
  } finally {
    err.restore();
  }
  assert.strictEqual(code, 4);
  assert.match(err.lines(), /asset preflight failed/i);
});

test('runShoot exits 0 and writes a manifest end to end', { skip: skipWithoutChromium }, async () => {
  const server = await startStaticServer(siteRoot);
  const artifactsDir = mkdtempSync(join(tmpdir(), 'yak-shoot-cmd-'));
  const path = writeScript(fixtureScript());
  try {
    const code = await runShoot({
      scriptPath: path,
      base: server.url,
      artifactsDir,
      projectRoot: mkdtempSync(join(tmpdir(), 'empty-')),
    });
    assert.strictEqual(code, 0);
    assert.ok(existsSync(join(artifactsDir, 'manifest.json')));
  } finally {
    await server.close();
  }
});
