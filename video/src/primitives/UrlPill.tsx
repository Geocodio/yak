import { interpolate, useCurrentFrame, useVideoConfig } from 'remotion';
import { colors, fonts } from '../lib/styling';

export const UrlPill = ({ url }: { url: string }) => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();
  const lifetimeFrames = fps * 1.5;
  const fadeIn = interpolate(frame, [0, fps * 0.15], [0, 1], { extrapolateRight: 'clamp' });
  const fadeOut = interpolate(frame, [lifetimeFrames - fps * 0.25, lifetimeFrames], [1, 0], { extrapolateLeft: 'clamp' });
  const opacity = Math.min(fadeIn, fadeOut);
  return (
    <div
      style={{
        position: 'absolute',
        top: 28,
        right: 28,
        padding: '8px 16px',
        background: colors.captionBg,
        color: colors.fg,
        fontFamily: fonts.mono,
        fontSize: 20,
        borderRadius: 999,
        opacity,
      }}
    >
      {url}
    </div>
  );
};
