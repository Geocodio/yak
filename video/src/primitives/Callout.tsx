import { interpolate, useCurrentFrame, useVideoConfig } from 'remotion';
import { colors, fonts } from '../lib/styling';
import { clampPoint, Bounds } from '../lib/viewport';

type Anchor = 'top' | 'bottom' | 'left' | 'right';

const LABEL_WIDTH = 280;
const LABEL_HEIGHT = 52;

export const Callout = ({
  text,
  rect,
  anchor = 'top',
}: {
  text: string;
  rect?: Bounds;
  anchor?: Anchor;
}) => {
  const frame = useCurrentFrame();
  const { fps, width, height } = useVideoConfig();
  const lifetimeFrames = fps * 2.0;
  const fadeIn = interpolate(frame, [0, fps * 0.2], [0, 1], { extrapolateRight: 'clamp' });
  const fadeOut = interpolate(frame, [lifetimeFrames - fps * 0.3, lifetimeFrames], [1, 0], { extrapolateLeft: 'clamp' });
  const opacity = Math.min(fadeIn, fadeOut);

  if (!rect) {
    const fallbackTop = anchor === 'bottom' ? height - LABEL_HEIGHT - 120 : 120;
    const { left, top } = clampPoint(
      width / 2 - LABEL_WIDTH / 2,
      fallbackTop,
      LABEL_WIDTH,
      LABEL_HEIGHT,
      width,
      height,
    );
    return (
      <div
        style={{
          position: 'absolute',
          left,
          top,
          width: LABEL_WIDTH,
          minHeight: LABEL_HEIGHT,
          background: colors.captionBg,
          color: colors.fg,
          fontFamily: fonts.primary,
          fontSize: 20,
          padding: '12px 18px',
          borderRadius: 8,
          textAlign: 'center',
          border: `1px solid ${colors.accentDim}`,
          boxSizing: 'border-box',
          opacity,
        }}
      >
        {text}
      </div>
    );
  }

  const ax = rect.left + rect.width / 2;
  const ay = rect.top + rect.height / 2;

  const offsets: Record<Anchor, { dx: number; dy: number }> = {
    top: { dx: 0, dy: -120 },
    bottom: { dx: 0, dy: 120 },
    left: { dx: -220, dy: 0 },
    right: { dx: 220, dy: 0 },
  };
  const { dx, dy } = offsets[anchor];

  const labelCenterX = ax + dx;
  const labelCenterY = ay + dy;
  const { left, top } = clampPoint(
    labelCenterX - LABEL_WIDTH / 2,
    labelCenterY - LABEL_HEIGHT / 2,
    LABEL_WIDTH,
    LABEL_HEIGHT,
    width,
    height,
  );

  const labelCx = left + LABEL_WIDTH / 2;
  const labelCy = top + LABEL_HEIGHT / 2;

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
        x1={labelCx}
        y1={labelCy}
        x2={ax}
        y2={ay}
        stroke={colors.accent}
        strokeWidth="3"
        markerEnd="url(#yak-arrowhead)"
      />
      <rect
        x={rect.left}
        y={rect.top}
        width={rect.width}
        height={rect.height}
        fill="none"
        stroke={colors.accent}
        strokeWidth="2"
        strokeDasharray="6 4"
        rx="6"
      />
      <foreignObject x={left} y={top} width={LABEL_WIDTH} height={LABEL_HEIGHT}>
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
            boxSizing: 'border-box',
          }}
        >
          {text}
        </div>
      </foreignObject>
    </svg>
  );
};
