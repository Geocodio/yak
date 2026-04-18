import { test, beforeEach } from 'node:test';
import assert from 'node:assert';
import { mkdtempSync, writeFileSync, chmodSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { captureRect } from '../../src/lib/domRect.ts';

let dir: string;
beforeEach(() => {
  dir = mkdtempSync(join(tmpdir(), 'yb-rect-'));
});

test('captureRect parses a valid rect from agent-browser stdout', () => {
  const shim = join(dir, 'agent-browser');
  writeFileSync(shim, '#!/bin/sh\necho \'{"left":120,"top":80,"width":200,"height":40}\'\nexit 0\n');
  chmodSync(shim, 0o755);
  const r = captureRect('#btn', shim);
  assert.deepStrictEqual(r, { left: 120, top: 80, width: 200, height: 40 });
});

test('captureRect returns null when agent-browser returns null JSON', () => {
  const shim = join(dir, 'agent-browser');
  writeFileSync(shim, '#!/bin/sh\necho null\nexit 0\n');
  chmodSync(shim, 0o755);
  assert.strictEqual(captureRect('#missing', shim), null);
});

test('captureRect returns null when agent-browser errors', () => {
  const shim = join(dir, 'agent-browser');
  writeFileSync(shim, '#!/bin/sh\necho "oops" >&2\nexit 5\n');
  chmodSync(shim, 0o755);
  assert.strictEqual(captureRect('#btn', shim), null);
});

test('captureRect returns null on malformed JSON', () => {
  const shim = join(dir, 'agent-browser');
  writeFileSync(shim, '#!/bin/sh\necho "not json"\nexit 0\n');
  chmodSync(shim, 0o755);
  assert.strictEqual(captureRect('#btn', shim), null);
});
