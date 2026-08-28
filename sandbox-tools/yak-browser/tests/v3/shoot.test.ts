import { test } from 'node:test';
import assert from 'node:assert';
import { existsSync, mkdtempSync, readFileSync, statSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { startStaticServer } from '../support/server.ts';
import { skipWithoutChromium } from '../support/browser.ts';
import { shoot, ShotFailedError } from '../../src/v3/shoot.ts';
import type { Script } from '../../src/v3/types.ts';

const here = dirname(fileURLToPath(import.meta.url));
const siteRoot = join(here, '..', 'fixtures', 'site');

function twoShotScript(): Script {
  return {
    version: 3,
    title: 'Fixture walkthrough',
    intro: 'The fixture site now has a target paragraph and a second page worth showing.',
    summary: ['A target paragraph', 'A second page'],
    outro: 'Ready for review.',
    shots: [
      {
        id: 'target',
        chapter: 'The first page',
        say: 'The first page now highlights the target paragraph in the second section.',
        do: [{ navigate: '/' }, { scroll_to: '#target' }],
        focus: '#target',
      },
      {
        id: 'second',
        chapter: 'The second page',
        say: 'Following the link opens the second page with its detail block.',
        do: [{ click: '#go' }, { scroll_to: '#detail-target' }],
        focus: '#detail-target',
      },
    ],
    screenshots: [{ id: 'target-shot', caption: 'The target paragraph', after_shot: 'target' }],
  };
}

test('shoots a two-shot script into clips, stills and a manifest', { skip: skipWithoutChromium }, async () => {
  const server = await startStaticServer(siteRoot);
  const artifactsDir = mkdtempSync(join(tmpdir(), 'yak-shoot-'));
  try {
    const manifest = await shoot({
      script: twoShotScript(),
      base: server.url,
      artifactsDir,
      width: 900,
      height: 700,
      skipPreflight: true,
    });

    assert.strictEqual(manifest.version, 3);
    assert.strictEqual(manifest.width, 900);
    assert.strictEqual(manifest.height, 700);
    assert.strictEqual(manifest.base, server.url);
    assert.strictEqual(manifest.shots.length, 2);

    for (const shotEntry of manifest.shots) {
      assert.strictEqual(shotEntry.clip, `shots/${shotEntry.id}.webm`);
      assert.strictEqual(shotEntry.still, `stills/${shotEntry.id}.png`);
      assert.ok(existsSync(join(artifactsDir, shotEntry.clip)), `${shotEntry.clip} should exist`);
      assert.ok(statSync(join(artifactsDir, shotEntry.clip)).size > 0);
      assert.ok(existsSync(join(artifactsDir, shotEntry.still)), `${shotEntry.still} should exist`);
      assert.ok(shotEntry.end > shotEntry.start, 'end must follow start');
      assert.ok(shotEntry.start >= 0);
      assert.ok(shotEntry.rect !== null, 'focus should produce a rect');
      assert.ok(shotEntry.rect!.w > 0 && shotEntry.rect!.h > 0);
      assert.match(shotEntry.url, /^http:\/\/127\.0\.0\.1:\d+\//);
    }

    // Each shot's action time is at least the sum of its settles + hold.
    assert.ok(manifest.shots[0].end - manifest.shots[0].start >= 1.0);

    const written = JSON.parse(readFileSync(join(artifactsDir, 'manifest.json'), 'utf8'));
    assert.deepStrictEqual(written.shots.map((s: { id: string }) => s.id), ['target', 'second']);
    assert.ok(existsSync(join(artifactsDir, 'script.json')), 'the linted script is copied into the artifacts dir');
  } finally {
    await server.close();
  }
});

test('a shot whose selector never resolves fails after one retry', { skip: skipWithoutChromium }, async () => {
  const server = await startStaticServer(siteRoot);
  const artifactsDir = mkdtempSync(join(tmpdir(), 'yak-shoot-'));
  const script = twoShotScript();
  script.shots[1].do = [{ click: '#does-not-exist' }];
  script.shots[1].focus = undefined;
  try {
    await assert.rejects(
      () => shoot({ script, base: server.url, artifactsDir, width: 900, height: 700, skipPreflight: true }),
      (error: Error) => error instanceof ShotFailedError && error.shotId === 'second' && /does-not-exist/.test(error.message),
    );
  } finally {
    await server.close();
  }
});

test('--only re-shoots one shot and updates its manifest entry in place', { skip: skipWithoutChromium }, async () => {
  const server = await startStaticServer(siteRoot);
  const artifactsDir = mkdtempSync(join(tmpdir(), 'yak-shoot-'));
  try {
    const first = await shoot({
      script: twoShotScript(), base: server.url, artifactsDir, width: 900, height: 700, skipPreflight: true,
    });
    const before = JSON.stringify(first.shots[0]);

    const updated = await shoot({
      script: twoShotScript(), base: server.url, artifactsDir, width: 900, height: 700, only: 'second', skipPreflight: true,
    });

    assert.strictEqual(updated.shots.length, 2, 'the other shot survives');
    assert.strictEqual(JSON.stringify(updated.shots[0]), before, 'the untouched shot keeps its entry');
    assert.ok(existsSync(join(artifactsDir, 'shots/second.webm')));
  } finally {
    await server.close();
  }
});
