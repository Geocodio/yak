import { test } from 'node:test';
import assert from 'node:assert';
import { mkdtempSync, mkdirSync, writeFileSync, utimesSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { checkAssetFreshness } from '../../src/v3/assetsFs.ts';

const OLD = new Date('2026-01-01T00:00:00Z');
const NEW = new Date('2026-06-01T00:00:00Z');

function write(root: string, relative: string, body: string, when: Date): void {
  const path = join(root, relative);
  mkdirSync(dirname(path), { recursive: true });
  writeFileSync(path, body);
  utimesSync(path, when, when);
}

function tree(): string {
  return mkdtempSync(join(tmpdir(), 'yak-assets-'));
}

test('a tree with no build output is skipped', () => {
  const root = tree();
  write(root, 'resources/css/app.css', 'body{}', NEW);
  write(root, 'package.json', '{}', NEW);
  const result = checkAssetFreshness(root);
  assert.strictEqual(result.status, 'skipped');
});

test('a fresh tree passes', () => {
  const root = tree();
  write(root, 'resources/css/app.css', 'body{}', OLD);
  write(root, 'package.json', '{}', OLD);
  write(root, 'public/build/manifest.json', JSON.stringify({ 'resources/css/app.css': { file: 'assets/app.css' } }), NEW);
  write(root, 'public/build/assets/app.css', 'body{}', NEW);
  const result = checkAssetFreshness(root);
  assert.strictEqual(result.status, 'fresh', result.reason);
});

test('a source newer than the build output is stale and names the file', () => {
  const root = tree();
  write(root, 'public/build/manifest.json', JSON.stringify({ 'resources/css/app.css': { file: 'assets/app.css' } }), OLD);
  write(root, 'public/build/assets/app.css', 'body{}', OLD);
  write(root, 'resources/css/app.css', 'body{color:red}', NEW);
  const result = checkAssetFreshness(root);
  assert.strictEqual(result.status, 'stale', result.reason);
  assert.ok(result.staleSources.some((f) => f.endsWith('resources/css/app.css')), result.staleSources.join(','));
});

test('the oldest output wins the comparison', () => {
  const root = tree();
  write(root, 'public/build/manifest.json', JSON.stringify({ 'resources/js/app.js': { file: 'assets/app.js' } }), NEW);
  write(root, 'public/build/assets/app.js', 'x', OLD);
  write(root, 'resources/js/app.js', 'x', new Date('2026-03-01T00:00:00Z'));
  const result = checkAssetFreshness(root);
  assert.strictEqual(result.status, 'stale', 'a source newer than the OLDEST output is stale');
});

test('node_modules and vendor are ignored', () => {
  const root = tree();
  write(root, 'public/build/manifest.json', '{}', OLD);
  write(root, 'public/build/assets/app.css', 'x', OLD);
  write(root, 'node_modules/pkg/index.js', 'x', NEW);
  write(root, 'vendor/lib/thing.js', 'x', NEW);
  write(root, 'resources/css/app.css', 'x', OLD);
  assert.strictEqual(checkAssetFreshness(root).status, 'fresh');
});

test('the build output itself is not treated as a source', () => {
  const root = tree();
  write(root, 'public/build/manifest.json', '{}', NEW);
  write(root, 'public/build/assets/app.js', 'x', NEW);
  write(root, 'resources/js/app.js', 'x', OLD);
  assert.strictEqual(checkAssetFreshness(root).status, 'fresh');
});

test('tailwind and vite config count as sources', () => {
  const root = tree();
  write(root, 'public/build/manifest.json', '{}', OLD);
  write(root, 'public/build/assets/app.css', 'x', OLD);
  write(root, 'tailwind.config.js', 'module.exports={}', NEW);
  const result = checkAssetFreshness(root);
  assert.strictEqual(result.status, 'stale');
  assert.ok(result.staleSources.some((f) => f.endsWith('tailwind.config.js')));
});

test('a dist/ tree with no manifest still works', () => {
  const root = tree();
  write(root, 'dist/bundle.js', 'x', OLD);
  write(root, 'src/main.ts', 'x', NEW);
  assert.strictEqual(checkAssetFreshness(root).status, 'stale');
});
