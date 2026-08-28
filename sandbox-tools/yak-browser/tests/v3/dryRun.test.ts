import { test } from 'node:test';
import assert from 'node:assert';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { startStaticServer } from '../support/server.ts';
import { skipWithoutChromium } from '../support/browser.ts';
import { dryRunSelectors } from '../../src/v3/dryRun.ts';
import type { Script } from '../../src/v3/types.ts';

const here = dirname(fileURLToPath(import.meta.url));
const siteRoot = join(here, '..', 'fixtures', 'site');

function script(overrides: Partial<Script> = {}): Script {
  return {
    version: 3,
    title: 'Fixture walkthrough',
    intro: 'The fixture site now has a target paragraph and a second page worth showing.',
    summary: ['A target paragraph', 'A second page'],
    outro: 'Ready for review.',
    shots: [
      { id: 'target', chapter: 'One', say: 'The target paragraph is highlighted.', do: [{ navigate: '/' }, { scroll_to: '#target' }], focus: '#target' },
      { id: 'second', chapter: 'Two', say: 'The second page opens with its detail block.', do: [{ click: '#go' }], focus: '#detail-target' },
    ],
    screenshots: [{ id: 'shot-one', caption: 'The target paragraph', after_shot: 'target' }],
    ...overrides,
  };
}

test('a script whose selectors all resolve produces no errors', { skip: skipWithoutChromium }, async () => {
  const server = await startStaticServer(siteRoot);
  try {
    assert.deepStrictEqual(await dryRunSelectors(script(), server.url), []);
  } finally {
    await server.close();
  }
});

test('a selector that does not resolve is reported with its shot and selector', { skip: skipWithoutChromium }, async () => {
  const server = await startStaticServer(siteRoot);
  const s = script();
  s.shots[0].do[1] = { scroll_to: '#not-there' };
  s.shots[0].focus = undefined;
  try {
    const errors = await dryRunSelectors(s, server.url);
    assert.ok(errors.some((e) => /target/.test(e) && /#not-there/.test(e)), errors.join('\n'));
  } finally {
    await server.close();
  }
});

test('a focus selector missing on the shot final page is reported', { skip: skipWithoutChromium }, async () => {
  const server = await startStaticServer(siteRoot);
  const s = script();
  s.shots[1].focus = '#target'; // exists on page one, not on the second page
  try {
    const errors = await dryRunSelectors(s, server.url);
    assert.ok(errors.some((e) => /second/.test(e) && /focus/.test(e)), errors.join('\n'));
  } finally {
    await server.close();
  }
});
