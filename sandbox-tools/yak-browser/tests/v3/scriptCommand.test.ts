import { test } from 'node:test';
import assert from 'node:assert';
import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { runScript } from '../../src/commands/script.ts';
import { validScript } from './lint.test.ts';

function writeScript(body: unknown): string {
  const dir = mkdtempSync(join(tmpdir(), 'yak-script-'));
  const path = join(dir, 'script.json');
  writeFileSync(path, JSON.stringify(body, null, 2));
  return path;
}

function captureStderr(): { lines: () => string; restore: () => void } {
  const original = process.stderr.write.bind(process.stderr);
  let buffer = '';
  (process.stderr as any).write = (chunk: any) => {
    buffer += String(chunk);
    return true;
  };
  return { lines: () => buffer, restore: () => { (process.stderr as any).write = original; } };
}

function captureStdout(): { lines: () => string; restore: () => void } {
  const original = process.stdout.write.bind(process.stdout);
  let buffer = '';
  (process.stdout as any).write = (chunk: any) => {
    buffer += String(chunk);
    return true;
  };
  return { lines: () => buffer, restore: () => { (process.stdout as any).write = original; } };
}

test('a clean script exits 0', async () => {
  const path = writeScript(validScript());
  const out = captureStdout();
  try {
    assert.strictEqual(await runScript({ scriptPath: path }), 0);
  } finally {
    out.restore();
  }
});

test('without --base it says only the static rules ran', async () => {
  const path = writeScript(validScript());
  const out = captureStdout();
  try {
    await runScript({ scriptPath: path });
  } finally {
    out.restore();
  }
  assert.match(out.lines(), /static rules only/);
});

test('a broken script exits 2 with one line per error', async () => {
  const broken = validScript();
  broken.title = 'x'.repeat(200);
  broken.summary = ['only one'];
  const path = writeScript(broken);
  const err = captureStderr();
  let code: number;
  try {
    code = await runScript({ scriptPath: path });
  } finally {
    err.restore();
  }
  assert.strictEqual(code, 2);
  const lines = err.lines().trim().split('\n');
  assert.ok(lines.length >= 2, `expected at least two error lines, got ${lines.length}`);
  assert.ok(lines.every((l) => l.length > 0));
});

test('a missing file exits 2', async () => {
  const err = captureStderr();
  let code: number;
  try {
    code = await runScript({ scriptPath: '/nonexistent/script.json' });
  } finally {
    err.restore();
  }
  assert.strictEqual(code, 2);
  assert.match(err.lines(), /cannot read/);
});

test('invalid JSON exits 2', async () => {
  const dir = mkdtempSync(join(tmpdir(), 'yak-script-'));
  const path = join(dir, 'script.json');
  writeFileSync(path, '{ not json');
  const err = captureStderr();
  let code: number;
  try {
    code = await runScript({ scriptPath: path });
  } finally {
    err.restore();
  }
  assert.strictEqual(code, 2);
  assert.match(err.lines(), /not valid JSON/);
});

test('--review prints the script and the three-question checklist', async () => {
  const path = writeScript(validScript());
  const out = captureStdout();
  try {
    assert.strictEqual(await runScript({ scriptPath: path, review: true }), 0);
  } finally {
    out.restore();
  }
  const printed = out.lines();
  assert.match(printed, /Geography levels/);
  assert.match(printed, /does the intro say what changed/i);
  assert.match(printed, /does every shot show something the diff touched/i);
  assert.match(printed, /would a reviewer know where to look/i);
});
