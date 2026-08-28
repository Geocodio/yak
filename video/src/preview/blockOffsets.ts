import { buildBlocks } from '../lib/v3/blocks';
import { DEFAULT_FPS } from '../lib/v3/types';
import { SAMPLE_MANIFEST, SAMPLE_SCRIPT } from './sample';

export type BlockKind = 'title' | 'chapter' | 'shot' | 'summary';

/**
 * Start frame of the first block of each kind in the sample cut, so the
 * settings page's Title / Chapter / Shot / Summary chips can seek the player.
 * Falls back to frame 0 for a kind the sample happens not to contain.
 */
export function blockOffsets(): Record<BlockKind, number> {
  const { blocks } = buildBlocks({
    script: SAMPLE_SCRIPT,
    manifest: SAMPLE_MANIFEST,
    voiceover: null,
    fps: DEFAULT_FPS,
  });

  const first = (kind: BlockKind): number =>
    blocks.find((block) => block.kind === kind)?.startFrame ?? 0;

  return {
    title: first('title'),
    chapter: first('chapter'),
    shot: first('shot'),
    summary: first('summary'),
  };
}
