import { interpolate, useCurrentFrame, useVideoConfig } from 'remotion';
import { colors, fonts } from '../lib/styling';
import { clampCentered } from '../lib/viewport';

const BADGE_WIDTH = 220;
const BADGE_HEIGHT = 56;

export const KeypressBadge = ({ keys }: { keys: string }) => {
  const frame = useCurrentFrame();
  const { fps, width, height } = useVideoConfig();
  const lifetimeFrames = fps * 1.0;
  const fadeIn = interpolate(frame, [0, fps * 0.1], [0, 1], { extrapolateRight: 'clamp' });
  const fadeOut = interpolate(frame, [lifetimeFrames - fps * 0.2, lifetimeFrames], [1, 0], { extrapolateLeft: 'clamp' });
  const opacity = Math.min(fadeIn, fadeOut);
  const { left, top } = clampCentered(width / 2, 80, BADGE_WIDTH, BADGE_HEIGHT, width, height);
  return (
    <div
      style={{
        position: 'absolute',
        left,
        top,
        width: BADGE_WIDTH,
        padding: '12px 24px',
        background: colors.captionBg,
        color: colors.fg,
        fontFamily: fonts.mono,
        fontSize: 28,
        borderRadius: 12,
        opacity,
        border: `1px solid ${colors.accentDim}`,
        boxSizing: 'border-box',
        textAlign: 'center',
      }}
    >
      {keys}
    </div>
  );
};
