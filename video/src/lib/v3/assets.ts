export type SrcKind = {
  /** `url` is ready to hand to Remotion as-is; `static` must go through `staticFile()`. */
  kind: 'url' | 'static';
  value: string;
};

/**
 * The host passes absolute local paths (or `file://` urls); the Studio and the
 * preview composition pass `public/` relative names. This module is pure so it
 * can be unit tested outside a Remotion context.
 */
export function classifySrc(src: string): SrcKind {
  if (/^(https?:|file:|data:|blob:)/i.test(src)) {
    return { kind: 'url', value: src };
  }
  if (src.startsWith('/')) {
    return { kind: 'url', value: `file://${encodeURI(src)}` };
  }
  return { kind: 'static', value: src };
}

export function isImageSrc(src: string): boolean {
  return /\.(png|jpe?g|webp|avif|gif)(\?|#|$)/i.test(src);
}
