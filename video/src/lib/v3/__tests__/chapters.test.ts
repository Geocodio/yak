import { describe, expect, it } from 'vitest';
import { buildBlocks } from '../blocks';
import { buildChapters } from '../chapters';
import type { Manifest, Script } from '../types';

const script: Script = {
  version: 3,
  title: 'T',
  intro: 'I.',
  summary: ['a'],
  outro: 'O.',
  shots: [
    { id: 'a', chapter: 'First', say: 'Shot a.' },
    { id: 'b', chapter: 'First', say: 'Shot b.' },
    { id: 'c', chapter: 'Second', say: 'Shot c.' },
  ],
};

const manifest: Manifest = {
  version: 3,
  width: 1440,
  height: 900,
  shots: [
    { id: 'a', clip: 'shots/a.webm', start: 0, end: 2, rect: null, url: 'http://local/a' },
    { id: 'b', clip: 'shots/b.webm', start: 0, end: 2, rect: null, url: 'http://local/b' },
    { id: 'c', clip: 'shots/c.webm', start: 0, end: 2, rect: null, url: 'http://local/c' },
  ],
};

describe('buildChapters', () => {
  it('groups shots under their chapter with block start times', () => {
    const timeline = buildBlocks({ script, manifest, voiceover: null, fps: 30 });
    const chapters = buildChapters(timeline);

    expect(chapters.map((c) => c.title)).toEqual(['First', 'Second']);
    expect(chapters[0].shots.map((s) => s.id)).toEqual(['a', 'b']);
    expect(chapters[1].shots.map((s) => s.id)).toEqual(['c']);
    expect(chapters[0].shots[0].say).toBe('Shot a.');
  });

  it('uses the chapter card start and each shot block start, in order', () => {
    const timeline = buildBlocks({ script, manifest, voiceover: null, fps: 30 });
    const chapters = buildChapters(timeline);
    const blockStart = (id: string) => timeline.blocks.find((b) => b.id === id)!.startSeconds;

    expect(chapters[0].startSeconds).toBeCloseTo(blockStart('chapter-1'), 3);
    expect(chapters[1].startSeconds).toBeCloseTo(blockStart('chapter-2'), 3);
    expect(chapters[0].shots[0].startSeconds).toBeCloseTo(blockStart('a'), 3);
    expect(chapters[0].startSeconds).toBeLessThan(chapters[0].shots[0].startSeconds);
    expect(chapters[0].shots[1].startSeconds).toBeLessThan(chapters[1].startSeconds);
  });

  it('returns an empty list when there are no shots', () => {
    const timeline = buildBlocks({
      script: { ...script, shots: [] },
      manifest: { ...manifest, shots: [] },
      voiceover: null,
      fps: 30,
    });
    expect(buildChapters(timeline)).toEqual([]);
  });
});
