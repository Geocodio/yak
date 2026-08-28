import { describe, expect, it } from 'vitest';
import {
  CAPTION_FONT_SIZE,
  CAPTION_INNER_WIDTH,
  CAPTION_MAX_LINES,
  captionOverflow,
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
