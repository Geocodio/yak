import type { Timeline } from './blocks';

export type ChapterShotEntry = {
  id: string;
  startSeconds: number;
  say: string;
};

export type ChapterEntry = {
  title: string;
  startSeconds: number;
  shots: ChapterShotEntry[];
};

function round(seconds: number): number {
  return Math.round(seconds * 1000) / 1000;
}

/** The `chapters.json` payload (spec §8), derived from the same blocks the cut renders. */
export function buildChapters(timeline: Timeline): ChapterEntry[] {
  const chapters: ChapterEntry[] = [];
  for (const block of timeline.blocks) {
    if (block.kind === 'chapter') {
      chapters.push({ title: block.title, startSeconds: round(block.startSeconds), shots: [] });
      continue;
    }
    if (block.kind === 'shot' && chapters.length > 0) {
      chapters[chapters.length - 1].shots.push({
        id: block.id,
        startSeconds: round(block.startSeconds),
        say: block.shot.say,
      });
    }
  }
  return chapters;
}
