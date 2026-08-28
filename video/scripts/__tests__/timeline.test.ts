import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { describe, expect, it } from 'vitest';
import { buildBlocks } from '../../src/lib/v3/blocks';
import manifest from '../../fixtures/v3/manifest.json';
import script from '../../fixtures/v3/script.json';
import type { Manifest, Script } from '../../src/lib/v3/types';

const root = path.resolve(__dirname, '../..');
const cli = path.join(root, 'scripts/timeline.ts');
const fixtures = path.join(root, 'fixtures/v3');

function run(args: string[]): { status: number; stdout: string; stderr: string } {
  try {
    const stdout = execFileSync('npx', ['tsx', cli, ...args], { cwd: root, encoding: 'utf8' });
    return { status: 0, stdout, stderr: '' };
  } catch (error) {
    const failure = error as { status: number; stdout: string; stderr: string };
    return { status: failure.status, stdout: failure.stdout ?? '', stderr: failure.stderr ?? '' };
  }
}

describe('scripts/timeline.ts', () => {
  it('prints blocks, duration, chapters and caption overflow', () => {
    const result = run(['--script', `${fixtures}/script.json`, '--manifest', `${fixtures}/manifest.json`]);
    expect(result.status).toBe(0);

    const output = JSON.parse(result.stdout);
    const expected = buildBlocks({ script: script as Script, manifest: manifest as Manifest, voiceover: null, fps: 30 });

    expect(Object.keys(output).sort()).toEqual(
      ['blocks', 'captionOverflow', 'chapters', 'durationInFrames', 'durationSeconds', 'fps', 'height', 'width'].sort(),
    );
    expect(output.fps).toBe(30);
    expect(output.width).toBe(1440);
    expect(output.height).toBe(952);
    expect(output.durationInFrames).toBe(expected.durationInFrames);
    expect(output.durationSeconds).toBeCloseTo(expected.durationSeconds, 6);
    expect(output.blocks.map((b: { kind: string }) => b.kind)).toEqual(
      expected.blocks.map((b) => b.kind),
    );
    expect(output.chapters.map((c: { title: string }) => c.title)).toEqual(['Geography levels', 'Published']);
    expect(output.chapters[0].shots.map((s: { id: string }) => s.id)).toEqual(['levels', 'explicit']);
    expect(output.captionOverflow).toEqual([]);
  }, 60_000);

  it('lengthens the cut when a voiceover file is supplied', () => {
    const without = JSON.parse(
      run(['--script', `${fixtures}/script.json`, '--manifest', `${fixtures}/manifest.json`]).stdout,
    );
    const with_ = JSON.parse(
      run([
        '--script', `${fixtures}/script.json`,
        '--manifest', `${fixtures}/manifest.json`,
        '--voiceover', `${fixtures}/voiceover.json`,
      ]).stdout,
    );
    expect(with_.blocks[0].voiceover).toEqual({ file: 'v3/silence.mp3', startSeconds: 0.25 });
    expect(without.blocks[0].voiceover).toBeNull();
  }, 60_000);

  it('exits 2 with a message on stderr when required arguments are missing', () => {
    const result = run([]);
    expect(result.status).toBe(2);
    expect(result.stdout).toBe('');
    expect(result.stderr).toContain('--script');
  }, 60_000);

  it('exits 2 when a file does not exist', () => {
    const result = run(['--script', `${fixtures}/nope.json`, '--manifest', `${fixtures}/manifest.json`]);
    expect(result.status).toBe(2);
    expect(result.stderr).toContain('nope.json');
  }, 60_000);
});
