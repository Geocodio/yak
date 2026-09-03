import { useEffect, useRef, useState } from 'react';
import type { BlockKind, VideoThemeColors, VideoThemeFonts } from '@/types/settings';

type PreviewTheme = { colors: VideoThemeColors; fonts: VideoThemeFonts; logo: string | null };

declare global {
    interface Window {
        YakVideoPreview?: {
            mount: (el: HTMLElement, options: { theme: PreviewTheme }) => void;
            mountCard: (el: HTMLElement, kind: string) => void;
            update: (theme: PreviewTheme) => void;
            seekToBlock: (kind: string) => void;
        };
    }
}

const BLOCK_KINDS: { kind: BlockKind; label: string }[] = [
    { kind: 'title', label: 'Title' },
    { kind: 'chapter', label: 'Chapter' },
    { kind: 'shot', label: 'Shot' },
    { kind: 'summary', label: 'Summary' },
];

const PREVIEW_SCRIPT_ID = 'yak-video-preview-script';
const PREVIEW_SCRIPT_SRC = '/vendor/video-preview.js';

/**
 * The theme page's live player. Renders the real `PreviewWalkthrough`
 * composition through a globally-loaded bundle (`window.YakVideoPreview`,
 * built by `video/scripts/build-preview.mjs`) rather than an iframe -- the
 * bundle exposes an imperative mount/update/seek API on `window`, not a
 * postMessage-driven document of its own, and the browser test suite
 * (`tests/Browser/VideoThemePreviewTest.php`) asserts against that same
 * top-level `window.YakVideoPreview` global. `previewAvailable` (whether the
 * bundle file exists on this server) lets the component skip attempting a
 * script load it already knows will 404.
 */
export function LivePreview({
    theme,
    googleFontsHref,
    fontPickerHref,
    previewAvailable,
}: {
    theme: PreviewTheme;
    googleFontsHref: string;
    fontPickerHref: string;
    previewAvailable: boolean;
}) {
    const [active, setActive] = useState<BlockKind>('title');
    const [mounted, setMounted] = useState(false);
    const [failed, setFailed] = useState(!previewAvailable);
    const playerRef = useRef<HTMLDivElement>(null);
    const stripRef = useRef<HTMLDivElement>(null);
    const themeRef = useRef(theme);
    const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEffect(() => {
        themeRef.current = theme;
    }, [theme]);

    useEffect(() => {
        if (!previewAvailable) {
            return;
        }

        let cancelled = false;

        const attach = () => {
            if (cancelled) {
                return;
            }

            if (!window.YakVideoPreview || !playerRef.current || !stripRef.current) {
                setFailed(true);
                return;
            }

            window.YakVideoPreview.mount(playerRef.current, { theme: themeRef.current });

            stripRef.current.querySelectorAll<HTMLElement>('[data-block-kind]').forEach((button) => {
                const surface = button.querySelector<HTMLElement>('[data-card-surface]');
                if (surface) {
                    window.YakVideoPreview?.mountCard(surface, button.dataset.blockKind ?? '');
                }
            });

            setMounted(true);
        };

        if (window.YakVideoPreview) {
            attach();

            return;
        }

        let script = document.getElementById(PREVIEW_SCRIPT_ID) as HTMLScriptElement | null;

        if (!script) {
            script = document.createElement('script');
            script.id = PREVIEW_SCRIPT_ID;
            script.src = PREVIEW_SCRIPT_SRC;
            script.defer = true;
            document.head.appendChild(script);
        }

        const onLoad = () => attach();
        const onError = () => setFailed(true);

        script.addEventListener('load', onLoad, { once: true });
        script.addEventListener('error', onError, { once: true });

        return () => {
            cancelled = true;
            script?.removeEventListener('load', onLoad);
            script?.removeEventListener('error', onError);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [previewAvailable]);

    useEffect(() => {
        if (!mounted) {
            return;
        }

        if (timerRef.current) {
            clearTimeout(timerRef.current);
        }

        timerRef.current = setTimeout(() => {
            window.YakVideoPreview?.update(theme);
        }, 250);

        return () => {
            if (timerRef.current) {
                clearTimeout(timerRef.current);
            }
        };
    }, [theme, mounted]);

    const seek = (kind: BlockKind) => {
        setActive(kind);

        if (mounted) {
            window.YakVideoPreview?.seekToBlock(kind);
        }
    };

    return (
        <div className="flex flex-col gap-4 lg:sticky lg:top-6 lg:self-start" data-testid="video-theme-preview-column">
            {/* The preview uses the same families the renderer downloads, so it matches an actual render. */}
            <link rel="preconnect" href="https://fonts.googleapis.com" />
            <link rel="preconnect" href="https://fonts.gstatic.com" crossOrigin="" />
            <link rel="stylesheet" href={googleFontsHref} />
            {/* Every selectable family at 400, so the font pickers preview each option in its own face. */}
            <link rel="stylesheet" href={fontPickerHref} />

            <div className="flex flex-wrap items-center justify-between gap-2">
                <h3 className="text-[13px] font-medium">Live preview</h3>
                <div className="flex gap-1">
                    {BLOCK_KINDS.map(({ kind, label }) => (
                        <button
                            key={kind}
                            type="button"
                            data-testid={`preview-chip-${kind}`}
                            onClick={() => seek(kind)}
                            className={`rounded-full px-2.5 py-1 text-[11px] font-semibold tracking-wide uppercase transition-colors ${
                                active === kind ? 'bg-ink text-panel' : 'bg-panel-2 text-muted hover:bg-hair'
                            }`}
                        >
                            {label}
                        </button>
                    ))}
                </div>
            </div>

            <div data-testid="video-theme-preview" data-theme={JSON.stringify(theme)} className="overflow-hidden rounded-card bg-panel-2 shadow-card">
                <div ref={playerRef} style={{ aspectRatio: '1440 / 952' }} />
            </div>

            {/* One still per block kind, painted from the same theme as the player. Clicking one seeks the player to that card. */}
            <div ref={stripRef} data-testid="video-theme-card-strip" className="grid grid-cols-4 gap-2.5">
                {BLOCK_KINDS.map(({ kind, label }) => (
                    <button
                        key={kind}
                        type="button"
                        data-block-kind={kind}
                        data-testid={`preview-card-${kind}`}
                        onClick={() => seek(kind)}
                        aria-pressed={active === kind}
                        aria-label={`Jump to the ${label} card`}
                        className={`group overflow-hidden rounded-chip transition ${
                            active === kind ? 'ring-2 ring-accent ring-offset-2' : 'ring-1 ring-hair hover:ring-hair-strong'
                        }`}
                    >
                        <span data-card-surface className="block w-full bg-panel-2" style={{ aspectRatio: '1440 / 952' }} />
                    </button>
                ))}
            </div>

            {failed && (
                <p className="text-[12px] text-faint">
                    The preview bundle is not built in this environment, so the player is hidden. Everything else on this page still works.
                </p>
            )}

            <p className="text-[12px] text-faint">
                Preview renders the real composition with a sample script. Click a card to jump to it. Save applies the theme to the next render.
            </p>
        </div>
    );
}
