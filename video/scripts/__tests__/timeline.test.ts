import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { describe, expect, it } from 'vitest';
import { buildBlocks } from '../../src/lib/v3/blocks';
import { supportedFontFamilies } from '../../src/lib/v3/fonts';
import { DEFAULT_THEME } from '../../src/lib/v3/theme';
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

    const shots = output.blocks.filter((b: { kind: string }) => b.kind === 'shot');
    expect(shots.length).toBeGreaterThan(0);
    for (const shot of shots as { freezeSeconds: number; clipTruncatedSeconds: number }[]) {
      expect(typeof shot.clipTruncatedSeconds).toBe('number');
      expect(Math.min(shot.freezeSeconds, shot.clipTruncatedSeconds)).toBe(0);
    }
  }, 60_000);

  it('honours a custom --fps and the --flag=value form', () => {
    const result = run([
      `--script=${fixtures}/script.json`,
      `--manifest=${fixtures}/manifest.json`,
      '--fps=60',
    ]);
    expect(result.status).toBe(0);

    const output = JSON.parse(result.stdout);
    const expected = buildBlocks({
      script: script as Script,
      manifest: manifest as Manifest,
      voiceover: null,
      fps: 60,
    });
    expect(output.fps).toBe(60);
    expect(output.durationInFrames).toBe(expected.durationInFrames);
    expect(output.durationSeconds).toBeCloseTo(expected.durationSeconds, 6);
  }, 60_000);

  it('prints the default theme and font list for --theme-defaults', () => {
    const result = run(['--theme-defaults']);
    expect(result.status).toBe(0);

    const output = JSON.parse(result.stdout);
    expect(Object.keys(output).sort()).toEqual(['fonts', 'theme']);
    expect(output.theme).toEqual(DEFAULT_THEME);
    expect(output.fonts.length).toBeGreaterThan(0);
    expect(output.fonts).toEqual(supportedFontFamilies());
    expect(output.fonts).toContain(DEFAULT_THEME.fonts.display);
    expect(output.fonts).toContain(DEFAULT_THEME.fonts.body);
    expect(output.fonts).toContain(DEFAULT_THEME.fonts.mono);
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
