import React from 'react';
import { interpolate, useCurrentFrame, useVideoConfig, Easing } from 'remotion';
import { timing } from '../lib/styling';

export type ZoomSpec = {
  fromFrame: number;
  durationFrames: number;
  x: number;
  y: number;
};

export const ZoomLayer: React.FC<{
  zooms: ZoomSpec[];
  children: React.ReactNode;
}> = ({ zooms, children }) => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();
  const zoomInFrames = (timing.zoomInMs / 1000) * fps;
  const holdFrames = (timing.zoomHoldMs / 1000) * fps;
  const zoomOutFrames = (timing.zoomOutMs / 1000) * fps;

  const active = zooms.find(
    (z) => frame >= z.fromFrame && frame < z.fromFrame + z.durationFrames,
  );

  let scale = 1;
  let originX = 0;
  let originY = 0;
  if (active) {
    const local = frame - active.fromFrame;
    const total = zoomInFrames + holdFrames + zoomOutFrames;
    scale = interpolate(
      local,
      [0, zoomInFrames, zoomInFrames + holdFrames, total],
      [1, timing.zoomScale, timing.zoomScale, 1],
      { easing: Easing.inOut(Easing.ease), extrapolateRight: 'clamp' },
    );
    originX = active.x;
    originY = active.y;
  }

  return (
    <div
      style={{
        width: '100%',
        height: '100%',
        position: 'absolute',
        inset: 0,
        overflow: 'hidden',
        transformOrigin: `${originX}px ${originY}px`,
        transform: `scale(${scale})`,
      }}
    >
      {children}
    </div>
  );
};
