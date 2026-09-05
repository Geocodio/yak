import type { BrandMark } from '@geocodio/console-ui';

/**
 * Yak's identity mark: a shaggy yak head, drawn once as data so the same
 * silhouette renders in the sidebar lockup, paints the tab favicon and
 * serialises to the static touch icons under `public/`. One flat colour,
 * one closed silhouette; the eyes and nostrils are counter-wound subpaths
 * so they knock out under the nonzero fill rule.
 */
export const YAK_MARK: BrandMark = {
    viewBox: '0 0 64 64',
    faviconViewBox: '2 4 60 60',
    shapes: [
        {
            tag: 'path',
            attrs: {
                'fill-rule': 'nonzero',
                d: [
                    'M16 20 C17 13 23 11 27 15 C29 10 35 10 37 15 C41 11 47 13 48 20 C53 23 55 30 54 37 C53 45 48 53 42 57 C38 60 26 60 22 57 C16 53 11 45 10 37 C9 30 11 23 16 20 Z',
                    'M14 33 C6 31 2 22 3 11 C3 8 6 6 8 8 C8 20 11 26 19 27 Z',
                    'M45 27 C53 26 56 20 56 8 C58 6 61 8 61 11 C62 22 58 31 50 33 Z',
                    'M19.8 36 a3.2 3.2 0 1 0 6.4 0 a3.2 3.2 0 1 0 -6.4 0 Z',
                    'M37.8 36 a3.2 3.2 0 1 0 6.4 0 a3.2 3.2 0 1 0 -6.4 0 Z',
                    'M26.2 51.5 a1.8 1.8 0 1 0 3.6 0 a1.8 1.8 0 1 0 -3.6 0 Z',
                    'M34.2 51.5 a1.8 1.8 0 1 0 3.6 0 a1.8 1.8 0 1 0 -3.6 0 Z',
                ].join(' '),
            },
        },
    ],
};

/**
 * The brand colour pair, mirrored by hand from `--brand` in `app.css`: a
 * favicon cannot read CSS custom properties. Yak brown in light mode, the
 * landing page's tan in dark mode, both from the mascot's own coat.
 */
export const YAK_BRAND_COLOR = { light: '#5c4a3a', dark: '#c8b89a' } as const;

/** The status pip drawn on the favicon while tasks are running (`--info`). */
export const YAK_ACTIVITY_PIP = '#3d7cb8';
