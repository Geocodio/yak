import { test } from 'node:test';
import assert from 'node:assert';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const pkgRoot = join(here, '..', '..');
const repoRoot = join(pkgRoot, '..', '..');

test('playwright-core is pinned to an exact version', () => {
  const pkg = JSON.parse(readFileSync(join(pkgRoot, 'package.json'), 'utf8'));
  const pinned = pkg.dependencies?.['playwright-core'];
  assert.ok(pinned, 'playwright-core must be a dependency');
  assert.match(pinned, /^\d+\.\d+\.\d+$/, `playwright-core must be pinned exactly, got ${pinned}`);
});

test('the ansible sandbox image installs the same playwright version', () => {
  const pkg = JSON.parse(readFileSync(join(pkgRoot, 'package.json'), 'utf8'));
  const pinned: string = pkg.dependencies['playwright-core'];
  const ansible = readFileSync(join(repoRoot, 'ansible', 'roles', 'incus', 'tasks', 'main.yml'), 'utf8');
  const match = ansible.match(/playwright@([\d.]+)/);
  assert.ok(match, 'ansible must install a pinned playwright@<version>');
  assert.strictEqual(
    match![1],
    pinned,
    `ansible installs playwright@${match![1]} but package.json pins playwright-core ${pinned}`,
  );
});

test('the sandbox environment exports PLAYWRIGHT_BROWSERS_PATH', () => {
  const ansible = readFileSync(join(repoRoot, 'ansible', 'roles', 'incus', 'tasks', 'main.yml'), 'utf8');
  assert.match(ansible, /export PLAYWRIGHT_BROWSERS_PATH=\/home\/yak\/\.cache\/ms-playwright/);
});
