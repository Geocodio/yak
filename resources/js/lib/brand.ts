import type { BrandMark } from '@geocodio/console-ui';

/**
 * Yak's identity mark: a fluffy yak face, drawn once as data so the same
 * silhouette renders in the sidebar lockup, paints the tab favicon and
 * serialises to the static touch icons under `public/`. One flat colour,
 * one closed silhouette: the fringe, cheeks and ears are overlapping
 * clockwise circles that union under the nonzero fill rule, while the eyes,
 * nose and smile are counter-wound subpaths that knock out, with a
 * clockwise pupil refilled inside each eye.
 */
export const YAK_MARK: BrandMark = {
    viewBox: '0 0 64 64',
    faviconViewBox: '2 3 60 60',
    shapes: [
        {
            tag: 'path',
            attrs: {
                'fill-rule': 'nonzero',
                d: [
                    'M11 37 a21 19 0 1 1 42 0 a21 19 0 1 1 -42 0 Z',
                    'M8.5 26 a6 6 0 1 1 12 0 a6 6 0 1 1 -12 0 Z',
                    'M14 19.5 a7 7 0 1 1 14 0 a7 7 0 1 1 -14 0 Z',
                    'M24.5 15.5 a6.5 6.5 0 1 1 13 0 a6.5 6.5 0 1 1 -13 0 Z',
                    'M32.5 18 a7.5 7.5 0 1 1 15 0 a7.5 7.5 0 1 1 -15 0 Z',
                    'M43 25 a6 6 0 1 1 12 0 a6 6 0 1 1 -12 0 Z',
                    'M6 36 a5.5 5.5 0 1 1 11 0 a5.5 5.5 0 1 1 -11 0 Z',
                    'M47 36 a5.5 5.5 0 1 1 11 0 a5.5 5.5 0 1 1 -11 0 Z',
                    'M8.5 47 a5.5 5.5 0 1 1 11 0 a5.5 5.5 0 1 1 -11 0 Z',
                    'M44.5 47 a5.5 5.5 0 1 1 11 0 a5.5 5.5 0 1 1 -11 0 Z',
                    'M9 30 a4 4 0 1 1 8 0 a4 4 0 1 1 -8 0 Z',
                    'M47 30 a4 4 0 1 1 8 0 a4 4 0 1 1 -8 0 Z',
                    'M3.3 30 a4.2 4.2 0 1 1 8.4 0 a4.2 4.2 0 1 1 -8.4 0 Z',
                    'M52.3 30 a4.2 4.2 0 1 1 8.4 0 a4.2 4.2 0 1 1 -8.4 0 Z',
                    'M20 22 C14 20 8 16 8 10 C8 7.5 10.5 6.5 12 8 C13 13 16.5 16 23 17 Z',
                    'M41 17 C47.5 16 51 13 52 8 C53.5 6.5 56 7.5 56 10 C56 16 50 20 44 22 Z',
                    'M18.5 37 a5 5 0 1 0 10 0 a5 5 0 1 0 -10 0 Z',
                    'M21.4 38 a2.6 2.6 0 1 1 5.2 0 a2.6 2.6 0 1 1 -5.2 0 Z',
                    'M35.5 37 a5 5 0 1 0 10 0 a5 5 0 1 0 -10 0 Z',
                    'M37.4 38 a2.6 2.6 0 1 1 5.2 0 a2.6 2.6 0 1 1 -5.2 0 Z',
                    'M28.4 47.5 a3.6 2.6 0 1 0 7.2 0 a3.6 2.6 0 1 0 -7.2 0 Z',
                    'M25.5 50 C28 56.5 36 56.5 38.5 50 C36 53 28 53 25.5 50 Z',
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
