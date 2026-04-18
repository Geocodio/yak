import { useCurrentFrame, useVideoConfig } from 'remotion';
import { colors, fonts } from '../lib/styling';

export const FastForwardBadge = ({ factor }: { factor: number }) => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();
  const pulse = (Math.sin((frame / fps) * Math.PI * 2) + 1) / 2;
  const opacity = 0.75 + pulse * 0.25;
  return (
    <>
      <div
        style={{
          position: 'absolute',
          top: 28,
          right: 28,
          padding: '8px 14px',
          background: colors.accent,
          color: colors.fg,
          fontFamily: fonts.primary,
          fontWeight: 700,
          fontSize: 24,
          borderRadius: 8,
          opacity,
        }}
      >
        ▶▶ {factor}×
      </div>
      <div
        style={{
          position: 'absolute',
          left: 0,
          right: 0,
          bottom: 0,
          height: 6,
          background: `repeating-linear-gradient(45deg, ${colors.accent}, ${colors.accent} 8px, ${colors.accentDim} 8px, ${colors.accentDim} 16px)`,
          opacity: 0.7,
        }}
      />
    </>
  );
};
