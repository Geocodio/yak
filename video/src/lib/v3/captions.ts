import type { Script } from './types';

export const CAPTION_MAX_WIDTH = 1040;
export const CAPTION_PADDING_X = 28;
export const CAPTION_RULE_WIDTH = 6;
export const CAPTION_FONT_SIZE = 30;
/** A caption taller than three lines covers too much of the page. */
export const CAPTION_MAX_LINES = 3;
export const CAPTION_INNER_WIDTH = CAPTION_MAX_WIDTH - CAPTION_PADDING_X * 2 - CAPTION_RULE_WIDTH;

const NARROW = new Set(['i', 'j', 'l', 'I', 't', 'f', 'r', '.', ',', ':', ';', "'", '!', '|', '(', ')', '[', ']', '`']);
const WIDE = new Set(['m', 'w', 'M', 'W', '@', '—']);

/**
 * Advance width of one character as a fraction of the font size. These ratios
 * approximate a humanist sans (the default body face, Instrument Sans) closely
 * enough to catch captions that will not fit, and are deliberately font
 * independent so the estimate is identical on the host and in the browser.
 */
export function characterWidthRatio(character: string): number {
  if (character === ' ') return 0.26;
  if (NARROW.has(character)) return 0.3;
  if (WIDE.has(character)) return 0.9;
  if (character >= '0' && character <= '9') return 0.56;
  if (character >= 'A' && character <= 'Z') return 0.66;
  return 0.52;
}

/** Estimated single-line width of `text` in pixels. */
export function estimateTextWidth(text: string, fontSize: number = CAPTION_FONT_SIZE): number {
  let width = 0;
  for (const character of text) {
    width += characterWidthRatio(character) * fontSize;
  }
  return Math.round(width * 1000) / 1000;
}

export type CaptionOverflow = {
  shotId: string;
  /** Estimated single-line width in pixels. */
  width: number;
};

/**
 * A caption is reported when its estimated single-line width exceeds what
 * `CAPTION_MAX_LINES` lines of the caption box can hold.
 *
 * This is a heuristic, not a proof. `estimateTextWidth` sums glyph advances,
 * which is a lower bound on the space greedy wrapping actually consumes:
 * wrapping wastes whatever is left on each ragged right edge. So a reported
 * caption certainly will not fit, while a caption that passes is likely but
 * not guaranteed to fit — it can still spill past `CAPTION_MAX_LINES`.
 */
export function captionOverflow(script: Script): CaptionOverflow[] {
  const budget = CAPTION_INNER_WIDTH * CAPTION_MAX_LINES;
  const overflow: CaptionOverflow[] = [];
  for (const shot of script.shots) {
    const width = estimateTextWidth(shot.say);
    if (width > budget) {
      overflow.push({ shotId: shot.id, width });
    }
  }
  return overflow;
}
