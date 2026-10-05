import type { Rect, Script } from './types';

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

/** Gap between the caption pill and the edge of the frame it is anchored to. */
export const CAPTION_EDGE_MARGIN = 56;
export const CAPTION_LINE_HEIGHT = 1.35;
export const CAPTION_PADDING_Y = 18;
/** How far the spotlight's outline reaches beyond its rect (padding + outline). */
const SPOTLIGHT_BLEED = 17;

export type CaptionPlacement = 'top' | 'bottom';

export type CaptionLayout = {
  /** Composition width in pixels. */
  width: number;
  /** Composition height in pixels, browser bar included. */
  height: number;
  /** Space reserved above the footage (the browser bar). */
  topInset: number;
};

/** Estimated rendered height of the caption pill for `text`. */
export function estimateCaptionHeight(text: string): number {
  const lines = Math.min(CAPTION_MAX_LINES, Math.max(1, Math.ceil(estimateTextWidth(text) / CAPTION_INNER_WIDTH)));
  return Math.ceil(lines * CAPTION_FONT_SIZE * CAPTION_LINE_HEIGHT + CAPTION_PADDING_Y * 2);
}

function overlap(startA: number, endA: number, startB: number, endB: number): number {
  return Math.max(0, Math.min(endA, endB) - Math.max(startA, startB));
}

/**
 * The caption sits in the lower third unless that would cover the spotlit
 * element, in which case it moves to the top of the footage. When the focus
 * is tall enough to collide with both, the side that hides less of it wins.
 *
 * `rect` is in footage coordinates, so it is shifted down by `topInset`.
 */
export function captionPlacement(text: string, rect: Rect | null | undefined, layout: CaptionLayout): CaptionPlacement {
  if (!rect) {
    return 'bottom';
  }

  const captionHeight = estimateCaptionHeight(text);
  const captionLeft = (layout.width - CAPTION_MAX_WIDTH) / 2;
  const captionRight = captionLeft + CAPTION_MAX_WIDTH;
  const focusLeft = rect.x - SPOTLIGHT_BLEED;
  const focusRight = rect.x + rect.w + SPOTLIGHT_BLEED;
  if (overlap(captionLeft, captionRight, focusLeft, focusRight) === 0) {
    return 'bottom';
  }

  const focusTop = rect.y + layout.topInset - SPOTLIGHT_BLEED;
  const focusBottom = rect.y + rect.h + layout.topInset + SPOTLIGHT_BLEED;

  const bottomEnd = layout.height - CAPTION_EDGE_MARGIN;
  const bottomOverlap = overlap(bottomEnd - captionHeight, bottomEnd, focusTop, focusBottom);
  if (bottomOverlap === 0) {
    return 'bottom';
  }

  const topStart = layout.topInset + CAPTION_EDGE_MARGIN;
  const topOverlap = overlap(topStart, topStart + captionHeight, focusTop, focusBottom);

  return topOverlap < bottomOverlap ? 'top' : 'bottom';
}
