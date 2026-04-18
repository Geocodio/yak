import { interpolate, useCurrentFrame, useVideoConfig } from 'remotion';
import { colors, fonts } from '../lib/styling';

export const CaptionStrip = ({ text, durationFrames }: { text: string; durationFrames: number }) => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();
  const fadeIn = interpolate(frame, [0, fps * 0.15], [0, 1], { extrapolateRight: 'clamp' });
  const fadeOut = interpolate(frame, [durationFrames - fps * 0.2, durationFrames], [1, 0], { extrapolateLeft: 'clamp' });
  const opacity = Math.min(fadeIn, fadeOut);
  return (
    <div
      style={{
        position: 'absolute',
        bottom: 40,
        left: '50%',
        transform: 'translateX(-50%)',
        maxWidth: '70%',
        padding: '14px 28px',
        background: colors.captionBg,
        color: colors.fg,
        fontFamily: fonts.primary,
        fontSize: 26,
        lineHeight: 1.35,
        borderRadius: 10,
        textAlign: 'center',
        opacity,
      }}
    >
      {text}
    </div>
  );
};
