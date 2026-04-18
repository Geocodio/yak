import React from 'react';
import { interpolate, useCurrentFrame, useVideoConfig, Easing } from 'remotion';
import { timing } from '../lib/styling';

export const SmartZoom: React.FC<{
  x: number;
  y: number;
  width: number;
  height: number;
  children: React.ReactNode;
}> = ({ x, y, width, height, children }) => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();
  const zoomInFrames = (timing.zoomInMs / 1000) * fps;
  const holdFrames = (timing.zoomHoldMs / 1000) * fps;
  const zoomOutFrames = (timing.zoomOutMs / 1000) * fps;
  const totalFrames = zoomInFrames + holdFrames + zoomOutFrames;
  const scale = interpolate(
    frame,
    [0, zoomInFrames, zoomInFrames + holdFrames, totalFrames],
    [1, timing.zoomScale, timing.zoomScale, 1],
    { easing: Easing.inOut(Easing.ease), extrapolateRight: 'clamp' }
  );
  const tx = (width / 2 - x) * (scale - 1);
  const ty = (height / 2 - y) * (scale - 1);
  return (
    <div
      style={{
        width,
        height,
        overflow: 'hidden',
        position: 'absolute',
        inset: 0,
        transform: `translate(${tx}px, ${ty}px) scale(${scale})`,
        transformOrigin: '0 0',
      }}
    >
      {children}
    </div>
  );
};
