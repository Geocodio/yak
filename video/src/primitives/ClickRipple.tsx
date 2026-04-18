import { interpolate, useCurrentFrame, useVideoConfig, Easing } from 'remotion';
import { colors } from '../lib/styling';

export const ClickRipple = ({ x, y }: { x: number; y: number }) => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();
  const lifetimeFrames = fps * 0.4;
  const scale = interpolate(frame, [0, lifetimeFrames], [0.3, 2.5], {
    easing: Easing.out(Easing.ease),
    extrapolateRight: 'clamp',
  });
  const opacity = interpolate(frame, [0, lifetimeFrames], [0.9, 0], { extrapolateRight: 'clamp' });
  const size = 48;
  return (
    <div
      style={{
        position: 'absolute',
        left: x - size / 2,
        top: y - size / 2,
        width: size,
        height: size,
        borderRadius: '50%',
        background: colors.ripple,
        transform: `scale(${scale})`,
        opacity,
        boxShadow: `0 0 24px ${colors.ripple}`,
      }}
    />
  );
};
