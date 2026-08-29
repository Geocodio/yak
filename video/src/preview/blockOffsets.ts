import { buildBlocks, type Block } from '../lib/v3/blocks';
import { DEFAULT_FPS } from '../lib/v3/types';
import { SAMPLE_MANIFEST, SAMPLE_SCRIPT } from './sample';

export type BlockKind = 'title' | 'chapter' | 'shot' | 'summary';

/**
 * How far into a block the preview settles, in seconds.
 *
 * A block's own lead-in fade is only the first of two entrances: every card
 * then staggers its contents in on top of it (the title card's eyebrow /
 * title / intro rise over ~26 frames, the summary card's checklist over ~40).
 * Landing at 1.5 s clears the slowest of them, so a chip — and the frame the
 * player opens on — shows a finished card rather than a half-drawn one.
 */
const SETTLE_SECONDS = 1.5;

/**
 * The frame at which `block` is fully painted: past its lead-in fade and past
 * its contents' entrance stagger, clamped so it never runs past the block's
 * own readable time into the next block.
 */
function settledFrame(block: Block, fps: number): number {
  const lead = Math.round(block.transitionInSeconds * fps);
  const last = block.startFrame + Math.max(block.durationInFrames - 1, 0);
  const settled = block.startFrame + Math.round(SETTLE_SECONDS * fps);

  // A block shorter than the settle window still has to land past its fade,
  // so clamp from both ends rather than trusting either bound alone.
  return Math.max(block.startFrame + lead, Math.min(settled, last));
}

/**
 * Frame of each kind's first block at which that card is fully painted, so the
 * settings page's Title / Chapter / Shot / Summary chips seek somewhere that
 * actually shows the card. Seeking to `startFrame` would land on frame 0 of
 * the block's fade-in, which is 100% transparent — the bug that made the
 * preview look black on load.
 *
 * Falls back to frame 0 for a kind the sample happens not to contain.
 */
export function blockOffsets(): Record<BlockKind, number> {
  const { blocks } = buildBlocks({
    script: SAMPLE_SCRIPT,
    manifest: SAMPLE_MANIFEST,
    voiceover: null,
    fps: DEFAULT_FPS,
  });

  const first = (kind: BlockKind): number => {
    const block = blocks.find((candidate) => candidate.kind === kind);

    return block ? settledFrame(block, DEFAULT_FPS) : 0;
  };

  return {
    title: first('title'),
    chapter: first('chapter'),
    shot: first('shot'),
    summary: first('summary'),
  };
}

/** The frame `<Player>` opens on: the title card, already faded in. */
export function previewInitialFrame(): number {
  return blockOffsets().title;
}
