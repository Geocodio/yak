import { interpolate, useCurrentFrame, useVideoConfig } from 'remotion';
import { colors, fonts } from '../lib/styling';
import { clampPoint } from '../lib/viewport';

const PILL_HEIGHT = 40;

export const UrlPill = ({ url }: { url: string }) => {
  const frame = useCurrentFrame();
  const { fps, width, height } = useVideoConfig();
  const lifetimeFrames = fps * 1.5;
  const fadeIn = interpolate(frame, [0, fps * 0.15], [0, 1], { extrapolateRight: 'clamp' });
  const fadeOut = interpolate(frame, [lifetimeFrames - fps * 0.25, lifetimeFrames], [1, 0], { extrapolateLeft: 'clamp' });
  const opacity = Math.min(fadeIn, fadeOut);
  const pillWidth = Math.min(url.length * 11 + 32, width - 60);
  const { left, top } = clampPoint(width - pillWidth - 28, 28, pillWidth, PILL_HEIGHT, width, height);
  return (
    <div
      style={{
        position: 'absolute',
        left,
        top,
        maxWidth: pillWidth,
        padding: '8px 16px',
        background: colors.captionBg,
        color: colors.fg,
        fontFamily: fonts.mono,
        fontSize: 20,
        borderRadius: 999,
        opacity,
        whiteSpace: 'nowrap',
        overflow: 'hidden',
        textOverflow: 'ellipsis',
      }}
    >
      {url}
    </div>
  );
};
