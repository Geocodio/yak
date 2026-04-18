import { interpolate, useCurrentFrame, useVideoConfig } from 'remotion';
import { colors, fonts } from '../lib/styling';

export const KeypressBadge = ({ keys }: { keys: string }) => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();
  const lifetimeFrames = fps * 1.0;
  const fadeIn = interpolate(frame, [0, fps * 0.1], [0, 1], { extrapolateRight: 'clamp' });
  const fadeOut = interpolate(frame, [lifetimeFrames - fps * 0.2, lifetimeFrames], [1, 0], { extrapolateLeft: 'clamp' });
  const opacity = Math.min(fadeIn, fadeOut);
  return (
    <div
      style={{
        position: 'absolute',
        bottom: 80,
        left: '50%',
        transform: 'translateX(-50%)',
        padding: '12px 24px',
        background: colors.captionBg,
        color: colors.fg,
        fontFamily: fonts.mono,
        fontSize: 28,
        borderRadius: 12,
        opacity,
        border: `1px solid ${colors.accentDim}`,
      }}
    >
      {keys}
    </div>
  );
};
