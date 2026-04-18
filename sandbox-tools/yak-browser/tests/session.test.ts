import { test, beforeEach } from 'node:test';
import assert from 'node:assert';
import { mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { startSession, readSession, clearSession, elapsedSeconds } from '../src/lib/session.ts';

let dir: string;
beforeEach(() => {
  dir = mkdtempSync(join(tmpdir(), 'yb-session-'));
});

test('startSession writes a session file with default budgets', () => {
  startSession(dir, { storyboardPath: join(dir, 'storyboard.json') });
  const s = readSession(dir)!;
  assert.ok(s.startedAtMs > 0);
  assert.strictEqual(s.storyboardPath, join(dir, 'storyboard.json'));
  assert.strictEqual(s.emphasizeBudget, 0);
  assert.strictEqual(s.calloutBudget, 0);
  assert.strictEqual(s.openFastforward, null);
  assert.deepStrictEqual(s.chapters, []);
});

test('readSession returns null when no session file exists', () => {
  assert.strictEqual(readSession(dir), null);
});

test('clearSession removes the file', () => {
  startSession(dir, { storyboardPath: 'x' });
  clearSession(dir);
  assert.strictEqual(readSession(dir), null);
});

test('elapsedSeconds returns seconds since start', async () => {
  startSession(dir, { storyboardPath: 'x' });
  await new Promise((r) => setTimeout(r, 50));
  const e = elapsedSeconds(dir);
  assert.ok(e !== null && e >= 0.04 && e < 1.0, `got ${e}`);
});
