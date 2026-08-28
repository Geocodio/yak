import { describe, expect, it } from 'vitest';
import { blockOffsets, previewInitialFrame } from '../blockOffsets';
import { buildBlocks } from '../../lib/v3/blocks';
import { DEFAULT_FPS } from '../../lib/v3/types';
import { SAMPLE_MANIFEST, SAMPLE_SCRIPT } from '../sample';
import { createPreviewApi } from '../entry';

const timeline = () =>
  buildBlocks({
    script: SAMPLE_SCRIPT,
    manifest: SAMPLE_MANIFEST,
    voiceover: null,
    fps: DEFAULT_FPS,
  });

describe('preview entry', () => {
  it('exposes mount, update, seekToBlock and mountCard', () => {
    const api = createPreviewApi();
    expect(typeof api.mount).toBe('function');
    expect(typeof api.update).toBe('function');
    expect(typeof api.seekToBlock).toBe('function');
    expect(typeof api.mountCard).toBe('function');
  });

  it('reports a frame for each of the four block kinds', () => {
    const offsets = blockOffsets();
    for (const kind of ['title', 'chapter', 'shot', 'summary'] as const) {
      expect(Number.isInteger(offsets[kind])).toBe(true);
      expect(offsets[kind]).toBeGreaterThanOrEqual(0);
    }
    expect(offsets.summary).toBeGreaterThan(offsets.title);
  });

  it('lands past each block lead-in fade, so the card is fully opaque', () => {
    const offsets = blockOffsets();
    const { blocks } = timeline();

    for (const kind of ['title', 'chapter', 'shot', 'summary'] as const) {
      const block = blocks.find((candidate) => candidate.kind === kind);
      expect(block, `sample cut has a ${kind} block`).toBeDefined();

      const lead = Math.round(block!.transitionInSeconds * DEFAULT_FPS);
      // Fade() reaches opacity 1 at `inFrames`, so anything below that is
      // partially transparent and frame 0 of a fade-in is pure backdrop.
      expect(offsets[kind]).toBeGreaterThanOrEqual(block!.startFrame + lead);
      // ...and still inside the block's own readable time.
      expect(offsets[kind]).toBeLessThan(block!.startFrame + block!.durationInFrames);
    }
  });

  it('starts the player on the settled title card rather than frame 0', () => {
    expect(previewInitialFrame()).toBe(blockOffsets().title);
    expect(previewInitialFrame()).toBeGreaterThan(0);
  });
});
