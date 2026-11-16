import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { existsSync, mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const hook = new URL('../hooks/environment-note.mjs', import.meta.url).pathname;

function run(input, note) {
  const directory = mkdtempSync(join(tmpdir(), 'yak-note-'));
  const notePath = join(directory, 'environment-note.md');
  if (note !== undefined) writeFileSync(notePath, note);
  const result = spawnSync('node', [hook], { input, env: { ...process.env, YAK_ENVIRONMENT_NOTE_DIRECTORY: directory }, encoding: 'utf8' });
  return { ...result, notePath };
}

test('it prints additionalContext JSON for source resume when a note exists', () => {
  const result = run(JSON.stringify({ source: 'resume' }), 'The repo moved.');
  assert.equal(result.status, 0);
  assert.deepEqual(JSON.parse(result.stdout), { hookSpecificOutput: { hookEventName: 'SessionStart', additionalContext: 'The repo moved.' } });
});

test('it removes the note after printing so the next resume prints nothing', () => {
  const result = run(JSON.stringify({ source: 'resume' }), 'once');
  assert.equal(existsSync(result.notePath), false);
  const second = spawnSync('node', [hook], { input: JSON.stringify({ source: 'resume' }), env: { ...process.env, YAK_ENVIRONMENT_NOTE_DIRECTORY: join(result.notePath, '..') }, encoding: 'utf8' });
  assert.equal(second.stdout, '');
});

test('it prints nothing for source startup, clear and compact and keeps the note', () => {
  for (const source of ['startup', 'clear', 'compact']) {
    const result = run(JSON.stringify({ source }), 'keep me');
    assert.equal(result.status, 0);
    assert.equal(result.stdout, '');
    assert.equal(existsSync(result.notePath), true);
  }
});

test('it prints nothing and exits 0 when the note is missing or empty', () => {
  for (const note of [undefined, '', '  \n']) {
    const result = run(JSON.stringify({ source: 'resume' }), note);
    assert.equal(result.status, 0);
    assert.equal(result.stdout, '');
  }
});

test('it exits 0 on invalid stdin JSON', () => {
  const result = run('not json', 'note');
  assert.equal(result.status, 0);
  assert.equal(result.stdout, '');
});
