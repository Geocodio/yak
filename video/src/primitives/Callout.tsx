import { interpolate, useCurrentFrame, useVideoConfig } from 'remotion';
import { colors, fonts } from '../lib/styling';

type Anchor = 'top' | 'bottom' | 'left' | 'right';

export const Callout = ({
  text,
  x,
  y,
  anchor = 'top',
}: {
  text: string;
  x: number;
  y: number;
  anchor?: Anchor;
}) => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();
  const lifetimeFrames = fps * 2.0;
  const fadeIn = interpolate(frame, [0, fps * 0.2], [0, 1], { extrapolateRight: 'clamp' });
  const fadeOut = interpolate(frame, [lifetimeFrames - fps * 0.3, lifetimeFrames], [1, 0], { extrapolateLeft: 'clamp' });
  const opacity = Math.min(fadeIn, fadeOut);
  const offsets: Record<Anchor, { dx: number; dy: number }> = {
    top: { dx: 0, dy: -120 },
    bottom: { dx: 0, dy: 120 },
    left: { dx: -200, dy: 0 },
    right: { dx: 200, dy: 0 },
  };
  const { dx, dy } = offsets[anchor];
  return (
    <svg style={{ position: 'absolute', inset: 0, width: '100%', height: '100%', opacity, pointerEvents: 'none' }}>
      <defs>
        <marker
          id="yak-arrowhead"
          viewBox="0 0 10 10"
          refX="9"
          refY="5"
          markerWidth="6"
          markerHeight="6"
          orient="auto-start-reverse"
        >
          <path d="M 0 0 L 10 5 L 0 10 z" fill={colors.accent} />
        </marker>
      </defs>
      <line
        x1={x + dx}
        y1={y + dy}
        x2={x}
        y2={y}
        stroke={colors.accent}
        strokeWidth="3"
        markerEnd="url(#yak-arrowhead)"
      />
      <foreignObject x={x + dx - 120} y={y + dy - 24} width="240" height="48">
        <div
          style={{
            background: colors.captionBg,
            color: colors.fg,
            fontFamily: fonts.primary,
            fontSize: 20,
            padding: '10px 16px',
            borderRadius: 8,
            textAlign: 'center',
            border: `1px solid ${colors.accentDim}`,
          }}
        >
          {text}
        </div>
      </foreignObject>
    </svg>
  );
};
