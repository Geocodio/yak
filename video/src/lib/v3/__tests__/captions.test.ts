import { describe, expect, it } from 'vitest';
import {
  CAPTION_FONT_SIZE,
  CAPTION_INNER_WIDTH,
  CAPTION_MAX_LINES,
  captionOverflow,
  captionPlacement,
  estimateCaptionHeight,
  estimateTextWidth,
} from '../captions';
import type { Script } from '../types';

const base: Script = {
  version: 3,
  title: 'T',
  intro: 'I.',
  summary: ['a'],
  outro: 'O.',
  shots: [],
};

describe('estimateTextWidth', () => {
  it('is zero for the empty string', () => {
    expect(estimateTextWidth('')).toBe(0);
  });

  it('scales linearly with font size', () => {
    expect(estimateTextWidth('hello world', 60)).toBeCloseTo(estimateTextWidth('hello world', 30) * 2, 6);
  });

  it('gives wide glyphs more room than narrow ones', () => {
    expect(estimateTextWidth('mmmm')).toBeGreaterThan(estimateTextWidth('llll'));
    expect(estimateTextWidth('MMMM')).toBeGreaterThan(estimateTextWidth('mmmm') * 0.5);
  });

  it('is deterministic', () => {
    expect(estimateTextWidth('The quick brown fox.')).toBe(estimateTextWidth('The quick brown fox.'));
  });

  it('uses a 30 px caption default', () => {
    expect(CAPTION_FONT_SIZE).toBe(30);
    expect(estimateTextWidth('abc')).toBe(estimateTextWidth('abc', CAPTION_FONT_SIZE));
  });
});

describe('captionOverflow', () => {
  it('reports nothing for captions that fit', () => {
    const script: Script = {
      ...base,
      shots: [{ id: 'a', chapter: 'C', say: 'The guide now lists all eleven geography levels.' }],
    };
    expect(captionOverflow(script)).toEqual([]);
  });

  it('reports a caption that needs more than the allowed lines', () => {
    const say = Array.from({ length: 90 }, () => 'wordy').join(' ');
    const script: Script = { ...base, shots: [{ id: 'toolong', chapter: 'C', say }] };
    const overflow = captionOverflow(script);

    expect(overflow).toHaveLength(1);
    expect(overflow[0].shotId).toBe('toolong');
    expect(overflow[0].width).toBeCloseTo(estimateTextWidth(say), 6);
    expect(overflow[0].width).toBeGreaterThan(CAPTION_INNER_WIDTH * CAPTION_MAX_LINES);
  });

  it('checks every shot', () => {
    const long = Array.from({ length: 90 }, () => 'wordy').join(' ');
    const script: Script = {
      ...base,
      shots: [
        { id: 'ok', chapter: 'C', say: 'Short line.' },
        { id: 'bad', chapter: 'C', say: long },
      ],
    };
    expect(captionOverflow(script).map((o) => o.shotId)).toEqual(['bad']);
  });
});

describe('captionPlacement', () => {
  // 1440 x 900 footage under a 52 px browser bar.
  const layout = { width: 1440, height: 952, topInset: 52 };
  const say = 'Daily lookups now show at the bottom of the usage page.';

  it('stays at the bottom without a spotlight', () => {
    expect(captionPlacement(say, null, layout)).toBe('bottom');
    expect(captionPlacement(say, undefined, layout)).toBe('bottom');
  });

  it('stays at the bottom when the spotlight is high on the page', () => {
    expect(captionPlacement(say, { x: 200, y: 120, w: 1000, h: 200 }, layout)).toBe('bottom');
  });

  it('moves to the top when the spotlight sits under the lower third', () => {
    expect(captionPlacement(say, { x: 200, y: 700, w: 1000, h: 160 }, layout)).toBe('top');
  });

  it('stays at the bottom when the spotlight is beside the caption, not under it', () => {
    expect(captionPlacement(say, { x: 1300, y: 800, w: 120, h: 60 }, layout)).toBe('bottom');
  });

  it('picks the side that hides less of a spotlight that fills the page', () => {
    expect(captionPlacement(say, { x: 200, y: 0, w: 1000, h: 900 }, layout)).toBe('bottom');
    expect(captionPlacement(say, { x: 200, y: 120, w: 1000, h: 900 }, layout)).toBe('top');
  });

  it('accounts for taller multi-line captions', () => {
    const long = 'word '.repeat(80).trim();
    expect(estimateCaptionHeight(long)).toBeGreaterThan(estimateCaptionHeight(say));
    // Clear of a one-line caption, but inside a three-line one.
    const rect = { x: 200, y: 640, w: 1000, h: 40 };
    expect(captionPlacement(say, rect, layout)).toBe('bottom');
    expect(captionPlacement(long, rect, layout)).toBe('top');
  });
});
