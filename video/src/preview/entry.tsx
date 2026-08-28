import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { Player, type PlayerRef } from '@remotion/player';
import { WalkthroughV3, type WalkthroughV3Props } from '../compositions/WalkthroughV3';
import { buildBlocks } from '../lib/v3/blocks';
import { DEFAULT_FPS } from '../lib/v3/types';
import type { PartialTheme } from '../lib/v3/types';
import { SAMPLE_PROPS } from './sample';
import { blockOffsets, type BlockKind } from './blockOffsets';

export type PreviewApi = {
  mount(el: HTMLElement, props?: Partial<WalkthroughV3Props>): void;
  update(theme: PartialTheme | null): void;
  seekToBlock(kind: BlockKind): void;
};

/**
 * Where the page serves the composition's `public/` assets from. The sample
 * manifest points its clip at `v3/preview-still.jpg`, which the composition
 * resolves through Remotion's `staticFile()`; inside `<Player>` that reads
 * `window.remotion_staticBase` and otherwise resolves against the page root.
 * The Dockerfile copies the still to `public/vendor/v3/preview-still.jpg`, so
 * the base is `/vendor`. Set `window.YakVideoPreviewStaticBase` before this
 * script loads to serve the assets from somewhere else.
 */
export const DEFAULT_STATIC_BASE = '/vendor';

function applyStaticBase(): void {
  if (typeof window === 'undefined') {
    return;
  }
  const configured = (window as { YakVideoPreviewStaticBase?: string }).YakVideoPreviewStaticBase;
  window.remotion_staticBase = (configured ?? DEFAULT_STATIC_BASE).replace(/\/+$/, '');
}

export function createPreviewApi(): PreviewApi {
  let root: Root | null = null;
  let element: HTMLElement | null = null;
  let props: WalkthroughV3Props = { ...SAMPLE_PROPS };
  const playerRef = React.createRef<PlayerRef>();

  const dimensions = (): {
    durationInFrames: number;
    compositionWidth: number;
    compositionHeight: number;
  } => {
    const timeline = buildBlocks({
      script: props.script,
      manifest: props.manifest,
      voiceover: props.voiceover,
      fps: DEFAULT_FPS,
    });
    return {
      durationInFrames: timeline.durationInFrames,
      compositionWidth: timeline.width,
      compositionHeight: timeline.height,
    };
  };

  const paint = (): void => {
    if (root === null || element === null) {
      return;
    }
    const { durationInFrames, compositionWidth, compositionHeight } = dimensions();
    root.render(
      <Player
        ref={playerRef}
        component={WalkthroughV3}
        inputProps={props}
        durationInFrames={durationInFrames}
        compositionWidth={compositionWidth}
        compositionHeight={compositionHeight}
        fps={DEFAULT_FPS}
        controls
        loop
        style={{ width: '100%', borderRadius: 18, overflow: 'hidden' }}
        acknowledgeRemotionLicense
      />,
    );
  };

  return {
    mount(el, overrides) {
      applyStaticBase();
      if (root !== null && element !== el) {
        root.unmount();
        root = null;
      }
      element = el;
      props = { ...SAMPLE_PROPS, ...(overrides ?? {}) };
      root ??= createRoot(el);
      paint();
    },
    update(theme) {
      props = { ...props, theme };
      paint();
    },
    seekToBlock(kind) {
      playerRef.current?.pause();
      playerRef.current?.seekTo(blockOffsets()[kind]);
    },
  };
}

declare global {
  interface Window {
    YakVideoPreview?: PreviewApi;
  }
}

if (typeof window !== 'undefined') {
  applyStaticBase();
  window.YakVideoPreview = createPreviewApi();
}
