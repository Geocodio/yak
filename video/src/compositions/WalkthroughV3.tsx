import React from 'react';
import { AbsoluteFill, Audio, Sequence, staticFile, useVideoConfig } from 'remotion';
import type { CalculateMetadataFunction } from 'remotion';
import { classifySrc } from '../lib/v3/assets';
import { TIMING, buildBlocks } from '../lib/v3/blocks';
import { resolveTheme } from '../lib/v3/theme';
import { DEFAULT_FPS } from '../lib/v3/types';
import type { Manifest, PartialTheme, Script, Voiceover } from '../lib/v3/types';
import { ChapterCard } from '../primitives/v3/ChapterCard';
import { Fade } from '../primitives/v3/Fade';
import { ShotScene } from '../primitives/v3/ShotScene';
import { SummaryCard } from '../primitives/v3/SummaryCard';
import { TitleCard } from '../primitives/v3/TitleCard';
import { useThemeFonts } from '../primitives/v3/useThemeFonts';

export type WalkthroughV3Props = {
  script: Script;
  manifest: Manifest;
  /** `{ [id]: { file, durationSeconds } }`, or null when no voiceover was generated. */
  voiceover: Voiceover;
  /** Theme JSON from the installation's settings; null uses the spec defaults. */
  theme: PartialTheme | null;
  /** Public origin substituted into the browser bar; null shows the path alone. */
  publicOrigin: string | null;
};

/**
 * Duration and dimensions come from the same block engine the component
 * renders with, so `scripts/timeline.ts` can predict them exactly.
 */
export const walkthroughV3Metadata: CalculateMetadataFunction<WalkthroughV3Props> = ({ props }) => {
  const timeline = buildBlocks({
    script: props.script,
    manifest: props.manifest,
    voiceover: props.voiceover,
    fps: DEFAULT_FPS,
  });
  return {
    durationInFrames: timeline.durationInFrames,
    width: timeline.width,
    height: timeline.height,
    fps: DEFAULT_FPS,
  };
};

const BlockAudio: React.FC<{ file: string; fromFrame: number }> = ({ file, fromFrame }) => {
  const source = classifySrc(file);
  return (
    <Sequence from={fromFrame} layout="none">
      <Audio src={source.kind === 'static' ? staticFile(source.value) : source.value} />
    </Sequence>
  );
};

export const WalkthroughV3: React.FC<WalkthroughV3Props> = (props) => {
  const { fps } = useVideoConfig();
  const theme = resolveTheme(props.theme);
  const fonts = useThemeFonts(theme);
  const { blocks } = buildBlocks({
    script: props.script,
    manifest: props.manifest,
    voiceover: props.voiceover,
    fps,
  });

  return (
    <AbsoluteFill style={{ background: theme.colors.ink }}>
      {blocks.map((block, index) => {
        const next = blocks[index + 1];
        const tail = Math.round((next ? next.transitionInSeconds : TIMING.fadeOutSeconds) * fps);
        const total = block.durationInFrames + tail;
        const lead = Math.round(block.transitionInSeconds * fps);

        return (
          <Sequence
            key={block.id}
            from={block.startFrame}
            durationInFrames={total}
            premountFor={fps}
          >
            <Fade inFrames={lead} outFrames={tail} totalFrames={total}>
              {block.kind === 'title' ? <TitleCard script={props.script} theme={theme} fonts={fonts} /> : null}
              {block.kind === 'chapter' ? <ChapterCard block={block} theme={theme} fonts={fonts} /> : null}
              {block.kind === 'shot' ? (
                <ShotScene
                  block={block}
                  manifest={props.manifest}
                  theme={theme}
                  fonts={fonts}
                  publicOrigin={props.publicOrigin}
                />
              ) : null}
              {block.kind === 'summary' ? (
                <SummaryCard
                  script={props.script}
                  theme={theme}
                  fonts={fonts}
                  bulletStaggerFrames={Math.round(TIMING.summary.bulletStagger * fps)}
                />
              ) : null}
            </Fade>
            {block.voiceover ? (
              <BlockAudio
                file={block.voiceover.file}
                fromFrame={Math.round(block.voiceover.startSeconds * fps)}
              />
            ) : null}
          </Sequence>
        );
      })}
    </AbsoluteFill>
  );
};
