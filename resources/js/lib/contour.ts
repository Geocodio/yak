/**
 * The terrain behind the start screen: concentric, hand-wobbled contour rings
 * around the composer, drawn once as SVG path data. Everything here is pure so
 * the rings can be memoised per field size and tested without a DOM.
 */

export const CONTOUR = {
    lines: 36,
    spacing: 44,
    rough: 1.2,
    hills: 2,
    clearing: 128,
    stretch: 1.3,
    weight: 1,
    indexEvery: 5,
    indexWeight: 1.8,
    opacity: 0.32,
    depth: 0.6,
    breatheSpeed: 32,
    breatheDepth: 1.8,
    drift: 10,
    phase: 1,
    waveTime: 2.1,
    waveWidth: 20,
    waveIntensity: 0.7,
    waveBands: 1,
    force: 11,
    eruptTime: 1.4,
    spring: 0.45,
    peakSize: 150,
    peakRings: 5,
    peakX: 18,
    peakY: 90,
    typeEvery: 14,
} as const;

/** Points per ring; enough that the wobble reads as a smooth curve. */
const POINTS_PER_RING = 140;

export type ContourRing = { d: string; opacity: number; width: number };

export type Contour = {
    /** Rings at even indices and at odd indices; the two sets breathe out of phase. */
    even: ContourRing[];
    odd: ContourRing[];
    /** Every ring as one path, stroked by the focus wave. */
    all: string;
};

/** One wobbling ring. `scaleX` and `scaleY` stretch it into an ellipse; `center` is the ring's centre in both axes. */
export function ringPath(radius: number, scaleX: number, scaleY: number, center: number, hills: number = CONTOUR.hills, rough: number = CONTOUR.rough): string {
    let path = '';

    for (let index = 0; index < POINTS_PER_RING; index++) {
        const angle = (index / POINTS_PER_RING) * Math.PI * 2;
        const wobble = 1 + rough * (0.07 * Math.sin(hills * angle + 1.3) + 0.045 * Math.sin((hills + 2) * angle + 0.4) + 0.03 * Math.sin(Math.max(2, hills - 1) * angle + radius * 0.006));
        const x = center + radius * scaleX * wobble * Math.cos(angle);
        const y = center + radius * scaleY * wobble * Math.sin(angle);
        path += `${index === 0 ? 'M' : 'L'}${x.toFixed(1)} ${y.toFixed(1)}`;
    }

    return `${path}Z`;
}

/** The horizontal and vertical stretch of the whole field. */
export function stretchFactors(stretch: number = CONTOUR.stretch): { scaleX: number; scaleY: number } {
    return { scaleX: Math.sqrt(stretch) * 1.03, scaleY: 1.03 / Math.sqrt(stretch) };
}

/**
 * Build the rings for a field of the given radius (the SVG is `2 * radius`
 * square, centred on the composer). Rings that start beyond the field's
 * corners are skipped, because nothing of them is ever visible.
 */
export function buildContour(radius: number): Contour {
    const { scaleX, scaleY } = stretchFactors();
    const smallestWobble = 1 - CONTOUR.rough * (0.07 + 0.045 + 0.03);
    const cornerDistance = radius * Math.SQRT2;
    const even: ContourRing[] = [];
    const odd: ContourRing[] = [];
    let all = '';

    for (let index = 0; index < CONTOUR.lines; index++) {
        const ringRadius = CONTOUR.clearing + index * CONTOUR.spacing;

        if (ringRadius * Math.min(scaleX, scaleY) * smallestWobble > cornerDistance) {
            break;
        }

        const depth = CONTOUR.lines > 1 ? index / (CONTOUR.lines - 1) : 0;
        const isHeavy = CONTOUR.indexEvery > 0 && index % CONTOUR.indexEvery === 0;
        const d = ringPath(ringRadius, scaleX, scaleY, radius);
        const ring: ContourRing = {
            d,
            opacity: Number(Math.max(0.03, CONTOUR.opacity * (1 - CONTOUR.depth * depth)).toFixed(3)),
            width: Number((CONTOUR.weight * (isHeavy ? CONTOUR.indexWeight : 1)).toFixed(2)),
        };

        (index % 2 === 1 ? odd : even).push(ring);
        all += d;
    }

    return { even, odd, all };
}

/** The concentric rings of the "Thread started" peak, as path data inside a `size` square. */
export function buildPeakRings(): string[] {
    const size = CONTOUR.peakSize;
    const rings: string[] = [];

    for (let index = 0; index < CONTOUR.peakRings; index++) {
        rings.push(ringPath(size * (0.1 + (0.36 * index) / (CONTOUR.peakRings - 1)), 1.1, 0.95, size / 2));
    }

    return rings;
}

/** A radial mask that lets one band of the wave layer show as it grows. */
export function waveMask(): string {
    const width = CONTOUR.waveWidth;
    const stops = ['transparent 0%'];

    for (let band = CONTOUR.waveBands - 1; band >= 0; band--) {
        const end = 100 - band * width * 1.8;
        const start = end - width;

        if (start < 0) {
            continue;
        }

        stops.push(`transparent ${start.toFixed(1)}%`, `#000 ${(start + width * 0.7).toFixed(1)}%`, `transparent ${end.toFixed(1)}%`);
    }

    return `radial-gradient(circle closest-side,${stops.join(',')})`;
}

/**
 * A damped spring sampled into scale keyframes: `force` sets the first kick,
 * `spring` the number of rebounds. Normalised so the highest sample is exactly
 * `1 + force`.
 */
export function springScales(samples: number = 48): number[] {
    const decay = 6 - CONTOUR.spring * 4.5;
    const turns = Math.PI * 2 * (0.6 + CONTOUR.spring * 2.4);
    const values: number[] = [];
    let highest = 0;

    for (let index = 0; index <= samples; index++) {
        const t = index / samples;
        const value = Math.exp(-decay * t) * Math.sin(turns * t) * (1 - t);
        values.push(value);
        highest = Math.max(highest, value);
    }

    return values.map((value) => Number((1 + ((CONTOUR.force / 100) * value) / (highest || 1)).toFixed(4)));
}

/** Round a measured radius up to a coarse step, so small layout shifts reuse the same rings. */
export function quantizeRadius(radius: number, step: number = 40): number {
    return Math.ceil(radius / step) * step;
}
