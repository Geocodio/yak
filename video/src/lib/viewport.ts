export type Bounds = { left: number; top: number; width: number; height: number };

const MARGIN = 16;

export function clampPoint(
  x: number,
  y: number,
  elementWidth: number,
  elementHeight: number,
  frameWidth: number,
  frameHeight: number
): { left: number; top: number } {
  const maxLeft = Math.max(MARGIN, frameWidth - elementWidth - MARGIN);
  const maxTop = Math.max(MARGIN, frameHeight - elementHeight - MARGIN);
  return {
    left: Math.max(MARGIN, Math.min(x, maxLeft)),
    top: Math.max(MARGIN, Math.min(y, maxTop)),
  };
}

export function clampCentered(
  centerX: number,
  centerY: number,
  elementWidth: number,
  elementHeight: number,
  frameWidth: number,
  frameHeight: number
): { left: number; top: number } {
  return clampPoint(
    centerX - elementWidth / 2,
    centerY - elementHeight / 2,
    elementWidth,
    elementHeight,
    frameWidth,
    frameHeight
  );
}

export function clampRect(rect: Bounds, frameWidth: number, frameHeight: number): Bounds {
  const clamped = clampPoint(rect.left, rect.top, rect.width, rect.height, frameWidth, frameHeight);
  return { left: clamped.left, top: clamped.top, width: rect.width, height: rect.height };
}
