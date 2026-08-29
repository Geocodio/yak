import { describe, expect, it } from 'vitest';
import { TIMING, buildBlocks, readingSeconds } from '../blocks';
import type { Block, ChapterBlock, ShotBlock, TitleBlock } from '../blocks';
import type { Manifest, Script, Voiceover } from '../types';
import { BROWSER_BAR_HEIGHT } from '../types';

const FPS = 30;

/**
 * `tsc --noEmit` covers this file, so narrow the block union explicitly
 * instead of leaning on non-null assertions.
 */
function titleBlock(blocks: Block[]): TitleBlock {
  const block = blocks.find((candidate): candidate is TitleBlock => candidate.kind === 'title');
  if (!block) throw new Error('no title block');
  return block;
}

function chapterBlocks(blocks: Block[]): ChapterBlock[] {
  return blocks.filter((candidate): candidate is ChapterBlock => candidate.kind === 'chapter');
}

function shotBlocks(blocks: Block[]): ShotBlock[] {
  return blocks.filter((candidate): candidate is ShotBlock => candidate.kind === 'shot');
}

function firstShot(blocks: Block[]): ShotBlock {
  const [block] = shotBlocks(blocks);
  if (!block) throw new Error('no shot block');
  return block;
}

function script(overrides: Partial<Script> = {}): Script {
  return {
    version: 3,
    task: { id: 1, repo: 'acme/site', pr: 42 },
    title: 'A title',
    intro: 'A short intro.',
    summary: ['One', 'Two'],
    outro: 'Ready for review.',
    shots: [
      { id: 'a', chapter: 'First', say: 'Shot a.' },
      { id: 'b', chapter: 'First', say: 'Shot b.' },
      { id: 'c', chapter: 'Second', say: 'Shot c.' },
    ],
    ...overrides,
  };
}

function manifest(overrides: Partial<Manifest> = {}): Manifest {
  return {
    version: 3,
    width: 1440,
    height: 900,
    shots: [
      { id: 'a', clip: 'shots/a.webm', start: 0, end: 1, rect: { x: 1, y: 2, w: 3, h: 4 }, url: 'http://local/a' },
      { id: 'b', clip: 'shots/b.webm', start: 0, end: 1, rect: null, url: 'http://local/b' },
      { id: 'c', clip: 'shots/c.webm', start: 0, end: 1, rect: null, url: 'http://local/c' },
    ],
    ...overrides,
  };
}

function build(s: Script, m: Manifest, voiceover: Voiceover = null) {
  return buildBlocks({ script: s, manifest: m, voiceover, fps: FPS });
}

/**
 * `durationInFrames` is rounded once and `durationSeconds` derived back from
 * it, so an expectation written in seconds only holds after the same
 * quantisation (0.25 s + 4.0 s is 127.5 frames at 30 fps, never 4.25 s).
 */
function expectDuration(durationInFrames: number, expectedSeconds: number): void {
  expect(durationInFrames).toBe(Math.round(expectedSeconds * FPS));
}

describe('readingSeconds', () => {
  it('is words / 165 wpm * 60 + 0.9 s', () => {
    const text = Array.from({ length: 33 }, () => 'word').join(' ');
    expect(readingSeconds(text)).toBeCloseTo((33 / 165) * 60 + 0.9, 6);
  });

  it('never returns less than 2.4 s', () => {
    expect(readingSeconds('Hi.')).toBe(2.4);
    expect(readingSeconds('')).toBe(2.4);
  });
});

describe('buildBlocks structure', () => {
  it('emits title, chapter cards before their shots, and a summary', () => {
    const { blocks } = build(script(), manifest());
    expect(blocks.map((b) => b.kind)).toEqual([
      'title', 'chapter', 'shot', 'shot', 'chapter', 'shot', 'summary',
    ]);
  });

  it('numbers chapters and carries the first shot say as the lead-in', () => {
    const { blocks } = build(script(), manifest());
    const chapters = chapterBlocks(blocks);
    expect(chapters.map((c) => [c.index, c.total, c.title, c.leadSay])).toEqual([
      [1, 2, 'First', 'Shot a.'],
      [2, 2, 'Second', 'Shot c.'],
    ]);
  });

  it('lays blocks out back to back with no gaps', () => {
    const { blocks, durationInFrames } = build(script(), manifest());
    let cursor = 0;
    for (const block of blocks) {
      expect(block.startFrame).toBe(cursor);
      expect(block.startSeconds).toBeCloseTo(cursor / FPS, 6);
      cursor += block.durationInFrames;
    }
    expect(durationInFrames).toBe(cursor + Math.round(TIMING.fadeOutSeconds * FPS));
  });

  it('reports composition dimensions with the browser bar added', () => {
    const timeline = build(script(), manifest());
    expect(timeline.width).toBe(1440);
    expect(timeline.height).toBe(900 + BROWSER_BAR_HEIGHT);
    expect(BROWSER_BAR_HEIGHT).toBe(52);
  });

  it('skips script shots with no manifest entry', () => {
    const { blocks } = build(script(), manifest({ shots: manifest().shots.filter((s) => s.id !== 'b') }));
    expect(shotBlocks(blocks).map((b) => b.id)).toEqual(['a', 'c']);
  });
});

describe('title card timing', () => {
  it('uses the 4.0 s floor for a short intro', () => {
    const { blocks } = build(script({ intro: 'Short.' }), manifest());
    const title = titleBlock(blocks);
    expectDuration(title.durationInFrames, TIMING.fadeInSeconds + 4.0);
    expect(title.transitionInSeconds).toBe(TIMING.fadeInSeconds);
  });

  it('uses reading time of the intro when it is longer than the floor', () => {
    const intro = Array.from({ length: 15 }, () => 'word').join(' ');
    const { blocks } = build(script({ intro }), manifest());
    expectDuration(titleBlock(blocks).durationInFrames, TIMING.fadeInSeconds + readingSeconds(intro));
  });

  it('uses voiceover(intro) + 0.7 s when a voiceover is present', () => {
    const { blocks } = build(script(), manifest(), { intro: { file: 'vo/intro.mp3', durationSeconds: 6 } });
    expectDuration(titleBlock(blocks).durationInFrames, TIMING.fadeInSeconds + 6.7);
    expect(titleBlock(blocks).voiceover).toEqual({ file: 'vo/intro.mp3', startSeconds: TIMING.fadeInSeconds });
  });

  it('caps a reading-time title card at the 8 s ceiling', () => {
    const { blocks } = build(script({ intro: 'word '.repeat(400) }), manifest());
    expectDuration(titleBlock(blocks).durationInFrames, TIMING.fadeInSeconds + 8.0);
  });

  it('lets a voiceover longer than the ceiling play out instead of cutting it', () => {
    /**
     * Regression: the 8 s ceiling was applied to the voiceover-driven length
     * too, so a 13.9 s intro was truncated to 8 s and the narration was cut
     * off mid-sentence before the cut moved on to the next card.
     */
    const { blocks } = build(script(), manifest(), { intro: { file: 'vo/intro.mp3', durationSeconds: 13.87 } });
    expectDuration(titleBlock(blocks).durationInFrames, TIMING.fadeInSeconds + 13.87 + TIMING.title.voiceoverPad);
  });
});

describe('chapter card timing', () => {
  it('is a fixed 2.0 s after a 0.40 s dip', () => {
    const { blocks } = build(script(), manifest());
    const [chapter] = chapterBlocks(blocks);
    expect(chapter.transitionInSeconds).toBe(TIMING.chapterDipSeconds);
    expectDuration(chapter.durationInFrames, TIMING.chapterDipSeconds + 2.0);
    expect(chapter.voiceover).toBeNull();
  });
});

describe('shot timing', () => {
  it('uses the 3.0 s floor when clip and narration are tiny', () => {
    const { blocks } = build(
      script({ shots: [{ id: 'a', chapter: 'First', say: 'Hi.' }] }),
      manifest({ shots: [{ id: 'a', clip: 'shots/a.webm', start: 0, end: 0.5, rect: null, url: 'http://local/a' }] }),
    );
    const shot = firstShot(blocks);
    expectDuration(shot.durationInFrames, TIMING.chapterDipSeconds + 3.0);
  });

  it('is driven by clip length plus a 1.0 s hold', () => {
    const { blocks } = build(
      script({ shots: [{ id: 'a', chapter: 'First', say: 'Hi.' }] }),
      manifest({ shots: [{ id: 'a', clip: 'shots/a.webm', start: 2, end: 12, rect: null, url: 'http://local/a' }] }),
    );
    const shot = firstShot(blocks);
    expect(shot.clipSeconds).toBe(10);
    expect(shot.clipStartSeconds).toBe(2);
    expectDuration(shot.durationInFrames, TIMING.chapterDipSeconds + 11.0);
  });

  it('is driven by reading time when the say line is long', () => {
    const say = Array.from({ length: 30 }, () => 'word').join(' ');
    const { blocks } = build(
      script({ shots: [{ id: 'a', chapter: 'First', say }] }),
      manifest({ shots: [{ id: 'a', clip: 'shots/a.webm', start: 0, end: 1, rect: null, url: 'http://local/a' }] }),
    );
    const shot = firstShot(blocks);
    expectDuration(shot.durationInFrames, TIMING.chapterDipSeconds + readingSeconds(say));
  });

  it('is driven by voiceover + 0.5 s when that is the longest', () => {
    const { blocks } = build(
      script({ shots: [{ id: 'a', chapter: 'First', say: 'Hi.' }] }),
      manifest({ shots: [{ id: 'a', clip: 'shots/a.webm', start: 0, end: 1, rect: null, url: 'http://local/a' }] }),
      { a: { file: 'vo/a.mp3', durationSeconds: 9 } },
    );
    const shot = firstShot(blocks);
    expectDuration(shot.durationInFrames, TIMING.chapterDipSeconds + 9.5);
    expect(shot.voiceover).toEqual({
      file: 'vo/a.mp3',
      startSeconds: TIMING.chapterDipSeconds + TIMING.shot.voiceoverLead,
    });
  });

  it('caps a shot at the 20 s ceiling', () => {
    const { blocks } = build(
      script({ shots: [{ id: 'a', chapter: 'First', say: 'Hi.' }] }),
      manifest({ shots: [{ id: 'a', clip: 'shots/a.webm', start: 0, end: 60, rect: null, url: 'http://local/a' }] }),
    );
    const shot = firstShot(blocks);
    expectDuration(shot.durationInFrames, TIMING.chapterDipSeconds + 20.0);
  });

  it('freezes for the remainder when the clip is shorter than the block', () => {
    const { blocks } = build(
      script({ shots: [{ id: 'a', chapter: 'First', say: 'Hi.' }] }),
      manifest({ shots: [{ id: 'a', clip: 'shots/a.webm', start: 0, end: 0.5, rect: null, url: 'http://local/a' }] }),
    );
    const shot = firstShot(blocks);
    expect(shot.freezeSeconds).toBeCloseTo(shot.durationSeconds - 0.5, 6);
    expect(shot.freezeSeconds).toBeGreaterThan(0);
    expect(shot.clipTruncatedSeconds).toBe(0);
  });

  it('reports the unseen tail when the clip is longer than the block', () => {
    const { blocks } = build(
      script({ shots: [{ id: 'a', chapter: 'First', say: 'Hi.' }] }),
      manifest({ shots: [{ id: 'a', clip: 'shots/a.webm', start: 0, end: 60, rect: null, url: 'http://local/a' }] }),
    );
    const shot = firstShot(blocks);
    expect(shot.clipSeconds).toBe(60);
    expect(shot.clipTruncatedSeconds).toBeCloseTo(60 - shot.durationSeconds, 6);
    expect(shot.clipTruncatedSeconds).toBeGreaterThan(0);
    expect(shot.freezeSeconds).toBe(0);
  });

  it('never reports a freeze and a truncation on the same shot', () => {
    const { blocks } = build(
      script(),
      manifest({
        shots: [
          { id: 'a', clip: 'shots/a.webm', start: 0, end: 0.5, rect: null, url: 'http://local/a' },
          { id: 'b', clip: 'shots/b.webm', start: 0, end: 60, rect: null, url: 'http://local/b' },
          { id: 'c', clip: 'shots/c.webm', start: 0, end: 4, rect: null, url: 'http://local/c' },
        ],
      }),
    );
    for (const shot of shotBlocks(blocks)) {
      expect(Math.min(shot.freezeSeconds, shot.clipTruncatedSeconds)).toBe(0);
      expect(shot.freezeSeconds).toBeGreaterThanOrEqual(0);
      expect(shot.clipTruncatedSeconds).toBeGreaterThanOrEqual(0);
    }
  });

  it('crossfades 0.25 s shot to shot and dips 0.40 s after a chapter card', () => {
    const { blocks } = build(script(), manifest());
    const shots = shotBlocks(blocks);
    expect(shots.map((s) => s.transitionInSeconds)).toEqual([
      TIMING.chapterDipSeconds,
      TIMING.shotCrossfadeSeconds,
      TIMING.chapterDipSeconds,
    ]);
  });

  it('falls back to the manifest-level clip and then to a conventional path', () => {
    const one = build(
      script({ shots: [{ id: 'a', chapter: 'First', say: 'Hi.' }] }),
      manifest({ clip: 'footage.webm', shots: [{ id: 'a', start: 0, end: 1, rect: null, url: 'http://local/a' }] }),
    );
    expect(firstShot(one.blocks).clip).toBe('footage.webm');

    const two = build(
      script({ shots: [{ id: 'a', chapter: 'First', say: 'Hi.' }] }),
      manifest({ shots: [{ id: 'a', start: 0, end: 1, rect: null, url: 'http://local/a' }] }),
    );
    expect(firstShot(two.blocks).clip).toBe('shots/a.webm');
  });
});

describe('summary card timing', () => {
  it('is 5.0 s plus 0.4 s per bullet without a voiceover', () => {
    const { blocks } = build(script({ summary: ['a', 'b', 'c'] }), manifest());
    const summary = blocks[blocks.length - 1];
    expect(summary.transitionInSeconds).toBe(TIMING.chapterDipSeconds);
    expectDuration(summary.durationInFrames, TIMING.chapterDipSeconds + 5.0 + 1.2);
  });

  it('uses voiceover(outro) + 1.0 s when longer than the floor', () => {
    const { blocks } = build(script({ summary: ['a'] }), manifest(), {
      outro: { file: 'vo/outro.mp3', durationSeconds: 8 },
    });
    const summary = blocks[blocks.length - 1];
    expectDuration(summary.durationInFrames, TIMING.chapterDipSeconds + 9.0);
    expect(summary.voiceover).toEqual({ file: 'vo/outro.mp3', startSeconds: TIMING.chapterDipSeconds });
  });

  it('never drops below the bullet floor, and lets a long outro play out', () => {
    const short = build(script({ summary: ['a', 'b'] }), manifest(), {
      outro: { file: 'vo/outro.mp3', durationSeconds: 1 },
    });
    expectDuration(short.blocks[short.blocks.length - 1].durationInFrames, TIMING.chapterDipSeconds + 5.8);

    // The 10 s ceiling must not cut narration: same contract as the title card.
    const long = build(script({ summary: ['a', 'b'] }), manifest(), {
      outro: { file: 'vo/outro.mp3', durationSeconds: 40 },
    });
    expectDuration(
      long.blocks[long.blocks.length - 1].durationInFrames,
      TIMING.chapterDipSeconds + 40 + TIMING.summary.voiceoverPad,
    );
  });
});

describe('determinism', () => {
  it('produces identical output for identical input', () => {
    const a = build(script(), manifest(), { intro: { file: 'vo/intro.mp3', durationSeconds: 5 } });
    const b = build(script(), manifest(), { intro: { file: 'vo/intro.mp3', durationSeconds: 5 } });
    expect(JSON.stringify(a)).toBe(JSON.stringify(b));
  });
});
