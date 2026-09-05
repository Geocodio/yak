import type { BrandMark } from '@geocodio/console-ui';

/**
 * Yak's identity mark: the mascot's pose as a silhouette, a shaggy body seen
 * from the side with the head turned to the viewer. Drawn once as data so the
 * same shape renders in the sidebar lockup, paints the tab favicon and
 * serialises to the static touch icons under `public/`. One flat colour under
 * the nonzero fill rule: the outline, legs, hem tufts and horns are clockwise
 * and union; the face is a counter-wound cut-out; the fringe strands and the
 * muzzle are clockwise again so they refill inside it, and the nostrils are
 * counter-wound once more. Meant for small sizes, where the fur and horns
 * carry the read; the illustrated mascot stays the hero at large sizes.
 */
export const YAK_MARK: BrandMark = {
    viewBox: '2 2 64 58',
    shapes: [
        {
            tag: 'path',
            attrs: {
                'fill-rule': 'nonzero',
                d: [
                    'M38.00 15.00 L39.14 15.00 L40.29 14.98 L41.47 14.97 L42.66 14.97 L43.86 14.98 L45.07 15.00 L46.28 15.05 L47.50 15.12 L48.72 15.24 L49.93 15.39 L51.14 15.59 L52.34 15.84 L53.53 16.16 L54.71 16.53 L55.86 16.98 L57.00 17.50 L57.98 18.22 L58.87 19.06 L59.67 20.01 L60.36 21.05 L60.95 22.19 L61.45 23.42 L61.84 24.71 L62.12 26.06 L62.31 27.47 L62.38 28.92 L62.35 30.41 L62.20 31.91 L61.95 33.44 L61.58 34.97 L61.10 36.49 L60.50 38.00 L59.98 39.24 L59.36 40.35 L58.65 41.32 L57.85 42.17 L56.98 42.90 L56.03 43.50 L55.01 43.99 L53.94 44.38 L52.81 44.65 L51.64 44.83 L50.43 44.90 L49.18 44.89 L47.91 44.79 L46.62 44.60 L45.31 44.34 L44.00 44.00 L8.00 44.00 L7.00 42.00 L6.48 40.48 L6.06 38.92 L5.74 37.33 L5.52 35.72 L5.39 34.10 L5.37 32.47 L5.44 30.85 L5.62 29.25 L5.91 27.67 L6.31 26.12 L6.81 24.61 L7.42 23.16 L8.15 21.76 L8.98 20.43 L9.93 19.17 L11.00 18.00 L12.37 16.41 L13.84 15.00 L15.41 13.78 L17.05 12.75 L18.75 11.91 L20.51 11.25 L22.30 10.78 L24.12 10.50 L25.96 10.41 L27.79 10.50 L29.60 10.78 L31.39 11.25 L33.14 11.91 L34.83 12.75 L36.46 13.78 L38.00 15.00 Z',
                    'M15.5 47 h1 a2.5 2.5 0 0 1 2.5 2.5 v6 a2.5 2.5 0 0 1 -2.5 2.5 h-1 a2.5 2.5 0 0 1 -2.5 -2.5 v-6 a2.5 2.5 0 0 1 2.5 -2.5 Z',
                    'M23.5 47 h1 a2.5 2.5 0 0 1 2.5 2.5 v6 a2.5 2.5 0 0 1 -2.5 2.5 h-1 a2.5 2.5 0 0 1 -2.5 -2.5 v-6 a2.5 2.5 0 0 1 2.5 -2.5 Z',
                    'M35.5 47 h1 a2.5 2.5 0 0 1 2.5 2.5 v6 a2.5 2.5 0 0 1 -2.5 2.5 h-1 a2.5 2.5 0 0 1 -2.5 -2.5 v-6 a2.5 2.5 0 0 1 2.5 -2.5 Z',
                    'M43.5 47 h1 a2.5 2.5 0 0 1 2.5 2.5 v6 a2.5 2.5 0 0 1 -2.5 2.5 h-1 a2.5 2.5 0 0 1 -2.5 -2.5 v-6 a2.5 2.5 0 0 1 2.5 -2.5 Z',
                    'M8 44.5 a3 6.5 0 1 1 6 0 a3 6.5 0 1 1 -6 0 Z',
                    'M14 44.5 a3 4.7 0 1 1 6 0 a3 4.7 0 1 1 -6 0 Z',
                    'M20 44.5 a3 6.5 0 1 1 6 0 a3 6.5 0 1 1 -6 0 Z',
                    'M26 44.5 a3 4.7 0 1 1 6 0 a3 4.7 0 1 1 -6 0 Z',
                    'M32 44.5 a3 6.5 0 1 1 6 0 a3 6.5 0 1 1 -6 0 Z',
                    'M38 44.5 a3 4.7 0 1 1 6 0 a3 4.7 0 1 1 -6 0 Z',
                    'M38 19.5 C33 18 29 13 30 7 C30.5 4 34.5 3.5 35 6.5 C34.5 11 38 14 45 15.5 Z',
                    'M51 15.5 C58 14 61.5 11 61 6.5 C61.5 3.5 65.5 4 64 7 C63 13 62 18 58 20 Z',
                    'M39 32.5 a9 8.5 0 1 0 18 0 a9 8.5 0 1 0 -18 0 Z',
                    'M37.7 26.5 a2.3 7 0 1 1 4.6 0 a2.3 7 0 1 1 -4.6 0 Z',
                    'M41.7 26.5 a2.3 5.5 0 1 1 4.6 0 a2.3 5.5 0 1 1 -4.6 0 Z',
                    'M45.7 26.5 a2.3 7 0 1 1 4.6 0 a2.3 7 0 1 1 -4.6 0 Z',
                    'M49.7 26.5 a2.3 5.5 0 1 1 4.6 0 a2.3 5.5 0 1 1 -4.6 0 Z',
                    'M53.7 26.5 a2.3 7 0 1 1 4.6 0 a2.3 7 0 1 1 -4.6 0 Z',
                    'M46 35 h4 a3.5 3.5 0 0 1 3.5 3.5 v0 a3.5 3.5 0 0 1 -3.5 3.5 h-4 a3.5 3.5 0 0 1 -3.5 -3.5 v-0 a3.5 3.5 0 0 1 3.5 -3.5 Z',
                    'M44.8 38.5 a1.2 1.2 0 1 0 2.4 0 a1.2 1.2 0 1 0 -2.4 0 Z',
                    'M48.8 38.5 a1.2 1.2 0 1 0 2.4 0 a1.2 1.2 0 1 0 -2.4 0 Z',
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
