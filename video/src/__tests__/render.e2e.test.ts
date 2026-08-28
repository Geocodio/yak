import { execFileSync } from 'node:child_process';
import { existsSync, mkdirSync, rmSync } from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';
import { buildBlocks } from '../lib/v3/blocks';
import manifest from '../../fixtures/v3/manifest.json';
import script from '../../fixtures/v3/script.json';
import voiceover from '../../fixtures/v3/voiceover.json';
import type { Manifest, Script, Voiceover } from '../lib/v3/types';

const enabled = process.env.YAK_E2E_RENDER === '1';
const root = path.resolve(__dirname, '../..');

function probeDurationSeconds(file: string): number {
  const output = execFileSync(
    'ffprobe',
    ['-v', 'error', '-show_entries', 'format=duration', '-of', 'default=nw=1:nk=1', file],
    { encoding: 'utf8' },
  );
  return Number(output.trim());
}

describe.skipIf(!enabled)('WalkthroughV3 render smoke test', () => {
  it('renders an mp4 whose duration matches the block timeline', () => {
    const outDir = path.join(root, 'out');
    const outFile = path.join(outDir, 'smoke.mp4');
    mkdirSync(outDir, { recursive: true });
    rmSync(outFile, { force: true });

    const props = {
      script: script as Script,
      manifest: manifest as Manifest,
      voiceover: voiceover as NonNullable<Voiceover>,
      theme: null,
      publicOrigin: 'https://www.example.com',
    };

    execFileSync(
      'npx',
      ['remotion', 'render', 'src/index.ts', 'WalkthroughV3', outFile, `--props=${JSON.stringify(props)}`],
      { cwd: root, stdio: 'inherit' },
    );

    expect(existsSync(outFile)).toBe(true);

    const expected = buildBlocks({
      script: props.script,
      manifest: props.manifest,
      voiceover: props.voiceover,
      fps: 30,
    });
    expect(probeDurationSeconds(outFile)).toBeCloseTo(expected.durationSeconds, 0);
    expect(Math.abs(probeDurationSeconds(outFile) - expected.durationSeconds)).toBeLessThanOrEqual(0.5);
  }, 900_000);

  it('renders the footage-free preview composition', () => {
    const outFile = path.join(root, 'out', 'preview.mp4');
    rmSync(outFile, { force: true });
    execFileSync('npx', ['remotion', 'render', 'src/index.ts', 'PreviewWalkthrough', outFile], {
      cwd: root,
      stdio: 'inherit',
    });
    expect(existsSync(outFile)).toBe(true);
  }, 900_000);
});
