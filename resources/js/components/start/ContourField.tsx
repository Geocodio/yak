import { useEffect, useImperativeHandle, useLayoutEffect, useMemo, useRef, useState, type CSSProperties, type RefObject } from 'react';
import { CONTOUR, buildContour, buildPeakRings, quantizeRadius, springScales, waveMask } from '@/lib/contour';
import type { StartFieldHandle } from './StartFieldContext';

type Geometry = { radius: number; originX: number; originY: number; width: number; height: number };

/** How long the finished eruption is left on screen before a visit may leave the page. */
const ERUPTION_SETTLE_MS = CONTOUR.eruptTime * 1000 + 100;
const REDUCED_MOTION_SETTLE_MS = 700;

const prefersReducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/**
 * Contour rings behind the composer, centred on `anchorRef` and filling its
 * positioned parent. The rings are built once per field size, breathe and
 * drift in CSS, ripple outward on `wave()`, and `erupt()` kicks the whole
 * terrain and raises the "Thread started" peak. Under reduced motion the
 * rings stay still and the peak simply appears.
 */
export function ContourField({ anchorRef, handleRef }: { anchorRef: RefObject<HTMLElement | null>; handleRef: RefObject<StartFieldHandle | null> }) {
    const fieldRef = useRef<HTMLDivElement>(null);
    const burstRef = useRef<HTMLDivElement>(null);
    const eruptedAt = useRef<number | null>(null);
    const [geometry, setGeometry] = useState<Geometry | null>(null);

    useLayoutEffect(() => {
        const container = fieldRef.current?.parentElement;
        if (!container) {
            return;
        }
        let timer: number | undefined;

        const measure = () => {
            const anchor = anchorRef.current;
            if (!anchor) {
                return;
            }
            const box = container.getBoundingClientRect();
            const anchorBox = anchor.getBoundingClientRect();
            const centerX = anchorBox.left + anchorBox.width / 2 - box.left;
            const centerY = anchorBox.top + anchorBox.height / 2 - box.top;
            const radius = quantizeRadius(Math.hypot(Math.max(centerX, box.width - centerX), Math.max(centerY, box.height - centerY)) + 60);
            const next: Geometry = { radius, originX: Math.round(centerX - radius), originY: Math.round(centerY - radius), width: Math.round(box.width), height: Math.round(box.height) };

            setGeometry((current) => (current && current.radius === next.radius && current.originX === next.originX && current.originY === next.originY && current.width === next.width && current.height === next.height ? current : next));
        };

        measure();
        const observer = new ResizeObserver(() => {
            window.clearTimeout(timer);
            timer = window.setTimeout(measure, 120);
        });
        observer.observe(container);
        if (anchorRef.current) {
            observer.observe(anchorRef.current);
        }
        void document.fonts?.ready.then(measure);

        return () => {
            window.clearTimeout(timer);
            observer.disconnect();
        };
    }, [anchorRef]);

    // The rings only depend on the field's radius, so a moved or resized composer reuses them.
    const radius = geometry?.radius ?? 0;
    const contour = useMemo(() => (radius > 0 ? buildContour(radius) : null), [radius]);
    const peakRings = useMemo(() => buildPeakRings(), []);
    const mask = useMemo(() => waveMask(), []);

    useEffect(() => {
        const field = fieldRef.current;
        const pause = () => field?.classList.toggle('is-paused', document.hidden);
        pause();
        document.addEventListener('visibilitychange', pause);

        return () => document.removeEventListener('visibilitychange', pause);
    }, []);

    useImperativeHandle(handleRef, () => {
        const wave = () => {
            const field = fieldRef.current;
            if (!field || prefersReducedMotion()) {
                return;
            }
            field.classList.remove('is-waving');
            void field.offsetWidth;
            field.classList.add('is-waving');
        };

        return {
            wave,
            erupt: () => {
                const field = fieldRef.current;
                if (!field) {
                    return;
                }
                eruptedAt.current = performance.now();
                field.classList.remove('is-rising');
                void field.offsetWidth;
                field.classList.add('is-erupted');
                if (prefersReducedMotion()) {
                    return;
                }
                field.classList.add('is-rising');
                wave();
                burstRef.current?.animate(
                    springScales().map((scale) => ({ transform: `scale(${scale})` })),
                    { duration: CONTOUR.eruptTime * 1000, easing: 'linear' },
                );
            },
            settleMs: () => {
                if (eruptedAt.current === null) {
                    return 0;
                }
                const total = prefersReducedMotion() ? REDUCED_MOTION_SETTLE_MS : ERUPTION_SETTLE_MS;

                return Math.max(0, total - (performance.now() - eruptedAt.current));
            },
        };
    }, []);

    const halo = Math.min(radius, CONTOUR.clearing + 150);
    const peakLeft = geometry ? Math.round((CONTOUR.peakX / 100) * geometry.width - geometry.originX - CONTOUR.peakSize / 2) : 0;
    const peakTop = geometry ? Math.round((CONTOUR.peakY / 100) * geometry.height - geometry.originY - CONTOUR.peakSize / 2) : 0;

    const style = {
        left: geometry?.originX,
        top: geometry?.originY,
        width: radius * 2 || undefined,
        height: radius * 2 || undefined,
        '--contour-radius': `${radius}px`,
        '--contour-period': `${CONTOUR.breatheSpeed}s`,
        '--contour-depth': (CONTOUR.breatheDepth / 100).toFixed(4),
        '--contour-phase': `${(-CONTOUR.phase * CONTOUR.breatheSpeed).toFixed(2)}s`,
        '--contour-drift': `${(21600 / Math.abs(CONTOUR.drift)).toFixed(0)}s`,
        '--contour-wave-time': `${CONTOUR.waveTime}s`,
        '--contour-wave-intensity': CONTOUR.waveIntensity,
        '--contour-label-delay': `${(CONTOUR.eruptTime * 0.3 + 0.6).toFixed(2)}s`,
    } as CSSProperties;

    const viewBox = `0 0 ${radius * 2} ${radius * 2}`;

    return (
        <div ref={fieldRef} className="contour-field" style={style} aria-hidden="true" data-testid="contour-field">
            {contour && (
                <>
                    <div className="contour-layer contour-drift" style={{ animationDirection: CONTOUR.drift < 0 ? 'reverse' : undefined }}>
                        <div ref={burstRef} className="contour-layer">
                            <svg className="contour-svg contour-even" viewBox={viewBox}>
                                {contour.even.map((ring, index) => (
                                    <path key={index} d={ring.d} strokeOpacity={ring.opacity} strokeWidth={ring.width} />
                                ))}
                            </svg>
                            <svg className="contour-svg contour-odd" viewBox={viewBox}>
                                {contour.odd.map((ring, index) => (
                                    <path key={index} d={ring.d} strokeOpacity={ring.opacity} strokeWidth={ring.width} />
                                ))}
                            </svg>
                        </div>
                        <svg className="contour-svg contour-wave" viewBox={viewBox} style={{ WebkitMaskImage: mask, maskImage: mask }}>
                            <path d={contour.all} strokeWidth={(CONTOUR.weight * 1.5).toFixed(2)} />
                        </svg>
                    </div>
                    <div className="contour-halo" style={{ left: radius - halo, top: radius - halo, width: halo * 2, height: halo * 2 }} />
                    <div className="contour-peak" style={{ left: peakLeft, top: peakTop, width: CONTOUR.peakSize, height: CONTOUR.peakSize }}>
                        <svg viewBox={`0 0 ${CONTOUR.peakSize} ${CONTOUR.peakSize}`}>
                            {peakRings.map((d, index) => (
                                <path key={index} d={d} style={{ animationDelay: `${(CONTOUR.eruptTime * 0.3 + index * 0.09).toFixed(2)}s` }} />
                            ))}
                        </svg>
                        <span>Thread started</span>
                    </div>
                </>
            )}
        </div>
    );
}
