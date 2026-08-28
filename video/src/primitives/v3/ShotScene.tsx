import React from 'react';
import { AbsoluteFill, Freeze, Img, OffthreadVideo, interpolate, staticFile, useCurrentFrame, useVideoConfig } from 'remotion';
import { classifySrc, isImageSrc } from '../../lib/v3/assets';
import { TIMING } from '../../lib/v3/blocks';
import type { ShotBlock } from '../../lib/v3/blocks';
import type { Manifest, Theme } from '../../lib/v3/types';
import { BROWSER_BAR_HEIGHT } from '../../lib/v3/types';
import { publicUrl } from '../../lib/v3/urls';
import { BrowserBar } from './BrowserBar';
import { Caption } from './Caption';
import { Spotlight } from './Spotlight';
import type { ResolvedFonts } from './useThemeFonts';

export type ShotSceneProps = {
  block: ShotBlock;
  manifest: Manifest;
  theme: Theme;
  fonts: ResolvedFonts;
  publicOrigin: string | null;
};

/** One shot: browser bar, footage (or its frozen last frame), spotlight, caption. */
export const ShotScene: React.FC<ShotSceneProps> = ({ block, manifest, theme, fonts, publicOrigin }) => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();

  const source = classifySrc(block.clip);
  const src = source.kind === 'static' ? staticFile(source.value) : source.value;
  const still = isImageSrc(block.clip);

  const clipFrames = Math.max(1, Math.round(block.clipSeconds * fps));
  const trimBefore = Math.round(block.clipStartSeconds * fps);
  const blockFrames = block.durationInFrames;

  const captionIn = Math.round(block.transitionInSeconds * fps);
  const captionFade = Math.round(TIMING.captionFadeSeconds * fps);
  const captionOpacity = interpolate(
    frame,
    [captionIn, captionIn + captionFade, blockFrames - captionFade, blockFrames],
    [0, 1, 1, 0],
    { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' },
  );
  const captionOffset = interpolate(frame, [captionIn, captionIn + captionFade], [16, 0], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });

  const spotlightFade = Math.round(TIMING.spotlightFadeSeconds * fps);
  const spotlightStart = Math.max(0, clipFrames - spotlightFade);
  const spotlightProgress = block.manifestShot.rect
    ? interpolate(frame, [spotlightStart, clipFrames, blockFrames - captionFade, blockFrames], [0, 1, 1, 0], {
        extrapolateLeft: 'clamp',
        extrapolateRight: 'clamp',
      })
    : 0;

  const footage = still ? (
    <Img src={src} style={{ width: manifest.width, height: manifest.height, objectFit: 'cover' }} />
  ) : (
    <OffthreadVideo
      src={src}
      trimBefore={trimBefore}
      trimAfter={trimBefore + clipFrames}
      muted
      style={{ width: manifest.width, height: manifest.height, objectFit: 'cover' }}
    />
  );

  const playing = still || frame < clipFrames;

  return (
    <AbsoluteFill style={{ background: theme.colors.ink }}>
      <BrowserBar
        url={publicUrl(block.manifestShot.url, publicOrigin)}
        width={manifest.width}
        theme={theme}
        fonts={fonts}
      />
      <AbsoluteFill style={{ top: BROWSER_BAR_HEIGHT, height: manifest.height }}>
        {playing ? footage : <Freeze frame={clipFrames - 1}>{footage}</Freeze>}
      </AbsoluteFill>
      {block.manifestShot.rect ? (
        <Spotlight
          rect={block.manifestShot.rect}
          offsetY={BROWSER_BAR_HEIGHT}
          accent={theme.colors.accent}
          progress={spotlightProgress}
        />
      ) : null}
      <Caption
        text={block.shot.say}
        theme={theme}
        fonts={fonts}
        opacity={captionOpacity}
        translateY={captionOffset}
      />
    </AbsoluteFill>
  );
};
