import type { WalkthroughV3Props } from '../compositions/WalkthroughV3';
import type { Manifest, Script } from '../lib/v3/types';

/**
 * A self-contained sample cut for the theme page's live preview. The "footage"
 * is a bundled still, so the preview needs no artifacts and no render.
 */
export const SAMPLE_SCRIPT: Script = {
  version: 3,
  task: { id: 1, repo: 'acme/example-site', pr: 128 },
  title: 'Sample walkthrough for the video theme',
  intro:
    'This preview shows how the title card, chapter cards, captions and summary card look with the current theme.',
  summary: ['Title and chapter cards', 'Captions and spotlight', 'Summary card and accent colour'],
  outro: 'That is the whole cut. Save the theme to use it for every walkthrough.',
  shots: [
    {
      id: 'sample',
      chapter: 'Sample chapter',
      say: 'Captions sit in a lower third with the accent colour as the left rule, over the page being demonstrated.',
    },
  ],
};

export const SAMPLE_MANIFEST: Manifest = {
  version: 3,
  width: 1440,
  height: 900,
  shots: [
    {
      id: 'sample',
      clip: 'v3/preview-still.jpg',
      start: 0,
      end: 4,
      rect: { x: 160, y: 200, w: 760, h: 260 },
      url: 'http://127.0.0.1:8899/guides/example',
    },
  ],
};

export const SAMPLE_PROPS: WalkthroughV3Props = {
  script: SAMPLE_SCRIPT,
  manifest: SAMPLE_MANIFEST,
  voiceover: null,
  theme: null,
  publicOrigin: 'https://www.example.com',
};
