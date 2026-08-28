import { describe, expect, it } from 'vitest';
import { blockOffsets } from '../blockOffsets';
import { createPreviewApi } from '../entry';

describe('preview entry', () => {
  it('exposes mount, update and seekToBlock', () => {
    const api = createPreviewApi();
    expect(typeof api.mount).toBe('function');
    expect(typeof api.update).toBe('function');
    expect(typeof api.seekToBlock).toBe('function');
  });

  it('reports a frame for each of the four block kinds', () => {
    const offsets = blockOffsets();
    expect(offsets.title).toBe(0);
    for (const kind of ['title', 'chapter', 'shot', 'summary'] as const) {
      expect(Number.isInteger(offsets[kind])).toBe(true);
      expect(offsets[kind]).toBeGreaterThanOrEqual(0);
    }
    expect(offsets.summary).toBeGreaterThan(offsets.title);
  });
});
