import { test } from 'node:test';
import assert from 'node:assert';
import { existsSync, mkdtempSync, readFileSync, statSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { startStaticServer } from '../support/server.ts';
import { skipWithoutChromium } from '../support/browser.ts';
import { moveFile, shoot, ShotFailedError } from '../../src/v3/shoot.ts';
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

test('a shot opening with navigate starts its clock after the navigation settles', { skip: skipWithoutChromium }, async () => {
  // Every response is delayed, so a clock started before the navigation would
  // read well under the delay and one started after it cannot.
  const responseDelayMs = 1200;
  const server = await startStaticServer(siteRoot, { delayMs: responseDelayMs });
  const artifactsDir = mkdtempSync(join(tmpdir(), 'yak-shoot-'));
  const script = twoShotScript();
  script.shots = [script.shots[0]];
  script.screenshots = [];
  try {
    const manifest = await shoot({
      script, base: server.url, artifactsDir, width: 900, height: 700, skipPreflight: true,
    });

    assert.ok(
      manifest.shots[0].start >= responseDelayMs / 1000,
      `start (${manifest.shots[0].start}s) must fall after the navigation settled (>= ${responseDelayMs / 1000}s)`,
    );
    assert.ok(manifest.shots[0].end - manifest.shots[0].start >= 1.0);
    assert.match(manifest.shots[0].url, /^http:\/\/127\.0\.0\.1:\d+\/$/);
  } finally {
    await server.close();
  }
});

test('moveFile falls back to copy when rename cannot cross filesystems', () => {
  const dir = mkdtempSync(join(tmpdir(), 'yak-move-'));
  const source = join(dir, 'clip.webm');
  const destination = join(dir, 'moved.webm');
  writeFileSync(source, 'video-bytes');

  const exdev = Object.assign(new Error('EXDEV: cross-device link not permitted'), { code: 'EXDEV' });
  moveFile(source, destination, () => {
    throw exdev;
  });

  assert.ok(!existsSync(source), 'the source is removed after the fallback copy');
  assert.strictEqual(readFileSync(destination, 'utf8'), 'video-bytes');
});

test('moveFile rethrows a rename failure it cannot recover from', () => {
  const dir = mkdtempSync(join(tmpdir(), 'yak-move-'));
  const enoent = Object.assign(new Error('ENOENT: no such file'), { code: 'ENOENT' });
  assert.throws(
    () =>
      moveFile(join(dir, 'missing.webm'), join(dir, 'out.webm'), () => {
        throw enoent;
      }),
    /ENOENT/,
  );
});

test('captures a screenshot after the named shot', { skip: skipWithoutChromium }, async () => {
  const server = await startStaticServer(siteRoot);
  const artifactsDir = mkdtempSync(join(tmpdir(), 'yak-shoot-'));
  try {
    const manifest = await shoot({
      script: twoShotScript(), base: server.url, artifactsDir, width: 900, height: 700, skipPreflight: true,
    });
    assert.strictEqual(manifest.screenshots.length, 1);
    const entry = manifest.screenshots[0];
    assert.strictEqual(entry.id, 'target-shot');
    assert.strictEqual(entry.file, 'screenshots/target-shot.png');
    assert.strictEqual(entry.caption, 'The target paragraph');
    assert.ok(existsSync(join(artifactsDir, entry.file)));
    assert.ok(statSync(join(artifactsDir, entry.file)).size > 0);
  } finally {
    await server.close();
  }
});

test('a screenshot with its own do list is captured standalone', { skip: skipWithoutChromium }, async () => {
  const server = await startStaticServer(siteRoot);
  const artifactsDir = mkdtempSync(join(tmpdir(), 'yak-shoot-'));
  const script = twoShotScript();
  script.screenshots = [
    { id: 'standalone', caption: 'The second page on its own', do: [{ navigate: '/second.html' }, { scroll_to: '#detail' }] },
  ];
  try {
    const manifest = await shoot({
      script, base: server.url, artifactsDir, width: 900, height: 700, skipPreflight: true,
    });
    assert.strictEqual(manifest.screenshots.length, 1);
    assert.ok(existsSync(join(artifactsDir, 'screenshots/standalone.png')));
  } finally {
    await server.close();
  }
});

test('the screenshot hides the synthetic cursor', { skip: skipWithoutChromium }, async () => {
  const server = await startStaticServer(siteRoot);
  const artifactsDir = mkdtempSync(join(tmpdir(), 'yak-shoot-'));
  try {
    await shoot({ script: twoShotScript(), base: server.url, artifactsDir, width: 900, height: 700, skipPreflight: true });
    // The still (cursor visible) and the screenshot (cursor hidden) are taken
    // at the same moment, so any difference proves the cursor was hidden.
    const still = readFileSync(join(artifactsDir, 'stills/target.png'));
    const screenshot = readFileSync(join(artifactsDir, 'screenshots/target-shot.png'));
    assert.notStrictEqual(still.length, screenshot.length);
  } finally {
    await server.close();
  }
});

test('--only keeps screenshots belonging to shots it did not re-shoot', { skip: skipWithoutChromium }, async () => {
  const server = await startStaticServer(siteRoot);
  const artifactsDir = mkdtempSync(join(tmpdir(), 'yak-shoot-'));
  try {
    await shoot({ script: twoShotScript(), base: server.url, artifactsDir, width: 900, height: 700, skipPreflight: true });
    const updated = await shoot({
      script: twoShotScript(), base: server.url, artifactsDir, width: 900, height: 700, only: 'second', skipPreflight: true,
    });
    assert.strictEqual(updated.screenshots.length, 1);
    assert.strictEqual(updated.screenshots[0].id, 'target-shot');
    assert.ok(existsSync(join(artifactsDir, 'screenshots/target-shot.png')));
  } finally {
    await server.close();
  }
});

// Finding 5: skipPreflight is a test-only escape hatch — every other test in
// this file sets it, so shoot()'s own preflight path (the branch guarded by
// `if (opts.skipPreflight !== true)`) needs at least one test that runs it
// for real, against the passing fixture site.
test('shoot() runs its own asset preflight when skipPreflight is not set', { skip: skipWithoutChromium }, async () => {
  const server = await startStaticServer(siteRoot);
  const artifactsDir = mkdtempSync(join(tmpdir(), 'yak-shoot-'));
  const projectRoot = mkdtempSync(join(tmpdir(), 'yak-shoot-empty-root-'));
  const script = twoShotScript();
  script.shots = [script.shots[0]];
  try {
    const manifest = await shoot({
      script,
      base: server.url,
      artifactsDir,
      width: 900,
      height: 700,
      projectRoot,
      // skipPreflight intentionally omitted — this exercises shoot()'s real
      // asset preflight against a page that should pass it.
    });
    assert.strictEqual(manifest.shots.length, 1);
    assert.ok(existsSync(join(artifactsDir, manifest.shots[0].clip)));
  } finally {
    await server.close();
  }
});
