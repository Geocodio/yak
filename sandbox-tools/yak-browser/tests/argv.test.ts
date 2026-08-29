import { test } from 'node:test';
import assert from 'node:assert';
import { getFlag, pickPositional } from '../src/lib/argv.ts';

// -------- pickPositional --------

test('pickPositional finds a trailing file path after a valued flag', () => {
  const path = pickPositional(['--base', 'http://example.com', 'file.json'], ['--base']);
  assert.strictEqual(path, 'file.json');
});

test('pickPositional finds a leading file path before any flags', () => {
  const path = pickPositional(['file.json', '--base', 'http://example.com'], ['--base']);
  assert.strictEqual(path, 'file.json');
});

test('pickPositional does not mistake a flag value for the positional (flag-first ordering)', () => {
  const path = pickPositional(['--base', 'http://example.com', 'file.json'], ['--base']);
  assert.notStrictEqual(path, 'http://example.com');
  assert.strictEqual(path, 'file.json');
});

test('pickPositional skips multiple valued flags and a boolean flag to find the path', () => {
  const path = pickPositional(
    ['--width', '1440', '--height', '900', '--only', 'first', '--base', 'http://example.com', 'file.json'],
    ['--width', '--height', '--only', '--base'],
  );
  assert.strictEqual(path, 'file.json');
});

test('pickPositional treats an unlisted --flag as boolean and keeps scanning', () => {
  const path = pickPositional(['--review', 'file.json'], ['--base']);
  assert.strictEqual(path, 'file.json');
});

test('pickPositional handles --flag=value form without consuming the next token', () => {
  const path = pickPositional(['--base=http://example.com', 'file.json'], ['--base']);
  assert.strictEqual(path, 'file.json');
});

test('pickPositional returns undefined when there is no positional token', () => {
  const path = pickPositional(['--base', 'http://example.com'], ['--base']);
  assert.strictEqual(path, undefined);
});

// -------- `assets --base <url> check` (src/index.ts's assets branch) --------
// src/index.ts resolves the `assets` subcommand with
// `pickPositional(rest, ['--base', '--project-root'])`, the same helper
// `script` and `shoot` use, so flag-first ordering doesn't misread the
// `--base` URL as the subcommand.

test('assets subcommand resolution finds "check" after a flag-first --base', () => {
  const subcommand = pickPositional(['--base', 'http://example.com', 'check'], ['--base', '--project-root']);
  assert.strictEqual(subcommand, 'check');
});

test('assets subcommand resolution finds "check" when it comes first', () => {
  const subcommand = pickPositional(['check', '--base', 'http://example.com'], ['--base', '--project-root']);
  assert.strictEqual(subcommand, 'check');
});

test('assets subcommand resolution is unaffected by --project-root', () => {
  const subcommand = pickPositional(
    ['--project-root', '/tmp/root', '--base', 'http://example.com', 'check'],
    ['--base', '--project-root'],
  );
  assert.strictEqual(subcommand, 'check');
});

// -------- getFlag --------

test('getFlag reads a space-separated flag value', () => {
  assert.strictEqual(getFlag(['--base', 'http://example.com'], '--base'), 'http://example.com');
});

test('getFlag reads an equals-separated flag value', () => {
  assert.strictEqual(getFlag(['--base=http://example.com'], '--base'), 'http://example.com');
});

test('getFlag returns undefined when the flag is absent', () => {
  assert.strictEqual(getFlag(['file.json'], '--base'), undefined);
});
