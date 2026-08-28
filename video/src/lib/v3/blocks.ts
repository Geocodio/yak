import type { Manifest, ManifestShot, Script, ScriptShot, Voiceover } from './types';
import { BROWSER_BAR_HEIGHT, DEFAULT_FPS, voiceoverSeconds } from './types';

export const WPM = 165;
export const READING_MINIMUM_SECONDS = 2.4;
export const READING_PAD_SECONDS = 0.9;

/** Every duration in spec §7's timing table, in seconds. */
export const TIMING = {
  title: { floor: 4.0, ceiling: 8.0, voiceoverPad: 0.7 },
  chapter: { fixed: 2.0 },
  shot: { floor: 3.0, ceiling: 20.0, clipHold: 1.0, voiceoverPad: 0.5, voiceoverLead: 0.25 },
  summary: { floorBase: 5.0, floorPerBullet: 0.4, ceiling: 10.0, voiceoverPad: 1.0, bulletStagger: 0.4 },
  fadeInSeconds: 0.25,
  fadeOutSeconds: 0.3,
  shotCrossfadeSeconds: 0.25,
  chapterDipSeconds: 0.4,
  spotlightFadeSeconds: 0.6,
  captionFadeSeconds: 0.35,
} as const;

/** Reading time = words / 165 wpm * 60 s + 0.9 s, never below 2.4 s. */
export function readingSeconds(text: string): number {
  const words = text.trim().split(/\s+/).filter(Boolean).length;
  return Math.max(READING_MINIMUM_SECONDS, (words / WPM) * 60 + READING_PAD_SECONDS);
}

function clamp(value: number, floor: number, ceiling: number): number {
  return Math.min(ceiling, Math.max(floor, value));
}

export type BlockVoiceover = {
  file: string;
  /** Offset from the block start at which the line begins. */
  startSeconds: number;
};

type BlockCommon = {
  id: string;
  startFrame: number;
  startSeconds: number;
  durationInFrames: number;
  durationSeconds: number;
  /** Lead-in fade owned by this block; added on top of its readable time. */
  transitionInSeconds: number;
  voiceover: BlockVoiceover | null;
};

export type TitleBlock = BlockCommon & { kind: 'title' };

export type ChapterBlock = BlockCommon & {
  kind: 'chapter';
  title: string;
  index: number;
  total: number;
  /** The `say` of this chapter's first shot, rendered as a lead-in. */
  leadSay: string;
};

export type ShotBlock = BlockCommon & {
  kind: 'shot';
  shot: ScriptShot;
  manifestShot: ManifestShot;
  clip: string;
  clipStartSeconds: number;
  clipSeconds: number;
  /** How long the last clip frame is held after the clip runs out. */
  freezeSeconds: number;
  /**
   * How much of the clip the block has no room for: the shot renders only its
   * first `durationSeconds`, so the tail is never seen. 0 whenever the clip
   * fits, which is the common case. Exactly one of `freezeSeconds` and
   * `clipTruncatedSeconds` is non-zero.
   */
  clipTruncatedSeconds: number;
};

export type SummaryBlock = BlockCommon & { kind: 'summary' };

export type Block = TitleBlock | ChapterBlock | ShotBlock | SummaryBlock;

export type Timeline = {
  fps: number;
  width: number;
  height: number;
  blocks: Block[];
  durationSeconds: number;
  durationInFrames: number;
};

export type BuildBlocksInput = {
  script: Script;
  manifest: Manifest;
  voiceover?: Voiceover;
  fps?: number;
};

/**
 * The single source of truth for the cut. The composition renders from this
 * and `scripts/timeline.ts` prints it, so the host can derive chapters,
 * expected duration and caption fit before a render starts.
 */
export function buildBlocks(input: BuildBlocksInput): Timeline {
  const fps = input.fps ?? DEFAULT_FPS;
  const { script, manifest } = input;
  const voiceover = input.voiceover ?? null;

  const chapterTitles: string[] = [];
  for (const shot of script.shots) {
    if (!chapterTitles.includes(shot.chapter)) {
      chapterTitles.push(shot.chapter);
    }
  }

  const blocks: Block[] = [];
  let frame = 0;

  const add = <T extends Block>(
    partial: Omit<T, 'startFrame' | 'startSeconds' | 'durationInFrames' | 'durationSeconds'>,
    readable: number,
  ): T => {
    const durationInFrames = Math.max(1, Math.round((partial.transitionInSeconds + readable) * fps));
    const block = {
      ...partial,
      startFrame: frame,
      startSeconds: frame / fps,
      durationInFrames,
      durationSeconds: durationInFrames / fps,
    } as T;
    blocks.push(block);
    frame += durationInFrames;
    return block;
  };

  const introSeconds = voiceoverSeconds(voiceover, 'intro');
  add<TitleBlock>(
    {
      kind: 'title',
      id: 'title',
      transitionInSeconds: TIMING.fadeInSeconds,
      voiceover: voiceover?.intro
        ? { file: voiceover.intro.file, startSeconds: TIMING.fadeInSeconds }
        : null,
    },
    clamp(
      introSeconds !== null ? introSeconds + TIMING.title.voiceoverPad : readingSeconds(script.intro),
      TIMING.title.floor,
      TIMING.title.ceiling,
    ),
  );

  let currentChapter: string | null = null;
  let previousWasShot = false;

  for (const shot of script.shots) {
    const manifestShot = manifest.shots.find((candidate) => candidate.id === shot.id);
    if (!manifestShot) {
      continue;
    }

    if (shot.chapter !== currentChapter) {
      const index = chapterTitles.indexOf(shot.chapter) + 1;
      add<ChapterBlock>(
        {
          kind: 'chapter',
          id: `chapter-${index}`,
          title: shot.chapter,
          index,
          total: chapterTitles.length,
          leadSay: shot.say,
          transitionInSeconds: TIMING.chapterDipSeconds,
          voiceover: null,
        },
        TIMING.chapter.fixed,
      );
      currentChapter = shot.chapter;
      previousWasShot = false;
    }

    const clipSeconds = Math.max(0, manifestShot.end - manifestShot.start);
    const sayVoiceover = voiceoverSeconds(voiceover, shot.id);
    const drivers = [clipSeconds + TIMING.shot.clipHold, readingSeconds(shot.say)];
    if (sayVoiceover !== null) {
      drivers.push(sayVoiceover + TIMING.shot.voiceoverPad);
    }
    const transitionInSeconds = previousWasShot ? TIMING.shotCrossfadeSeconds : TIMING.chapterDipSeconds;
    const entry = voiceover?.[shot.id] ?? null;

    const block = add<ShotBlock>(
      {
        kind: 'shot',
        id: shot.id,
        shot,
        manifestShot,
        clip: manifestShot.clip ?? manifest.clip ?? `shots/${shot.id}.webm`,
        clipStartSeconds: manifestShot.start,
        clipSeconds,
        freezeSeconds: 0,
        clipTruncatedSeconds: 0,
        transitionInSeconds,
        voiceover: entry
          ? { file: entry.file, startSeconds: transitionInSeconds + TIMING.shot.voiceoverLead }
          : null,
      },
      clamp(Math.max(...drivers), TIMING.shot.floor, TIMING.shot.ceiling),
    );
    block.freezeSeconds = Math.max(0, block.durationSeconds - clipSeconds);
    block.clipTruncatedSeconds = Math.max(0, clipSeconds - block.durationSeconds);
    previousWasShot = true;
  }

  const outroSeconds = voiceoverSeconds(voiceover, 'outro');
  const summaryFloor = TIMING.summary.floorBase + TIMING.summary.floorPerBullet * script.summary.length;
  add<SummaryBlock>(
    {
      kind: 'summary',
      id: 'summary',
      transitionInSeconds: TIMING.chapterDipSeconds,
      voiceover: voiceover?.outro
        ? { file: voiceover.outro.file, startSeconds: TIMING.chapterDipSeconds }
        : null,
    },
    clamp(
      outroSeconds !== null ? outroSeconds + TIMING.summary.voiceoverPad : summaryFloor,
      summaryFloor,
      TIMING.summary.ceiling,
    ),
  );

  const durationInFrames = frame + Math.round(TIMING.fadeOutSeconds * fps);

  return {
    fps,
    width: manifest.width,
    height: manifest.height + BROWSER_BAR_HEIGHT,
    blocks,
    durationInFrames,
    durationSeconds: durationInFrames / fps,
  };
}
