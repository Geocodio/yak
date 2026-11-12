import { cn } from '@geocodio/console-ui';
import { Search, SquarePlus, Wrench, type LucideIcon } from 'lucide-react';
import { useMemo, useRef, useState, type ComponentProps } from 'react';
import { YakMark } from '@/components/brand/YakMark';
import { StartThreadPanel } from '@/components/threads/start/StartThreadPanel';
import { ContourField } from './ContourField';
import { StartFieldContext, type StartExample, type StartFieldHandle } from './StartFieldContext';

const EXAMPLES: { icon: LucideIcon; text: string; mode: StartExample['mode'] }[] = [
    { icon: Wrench, text: 'Fix the footer links on mobile', mode: 'fix' },
    { icon: SquarePlus, text: 'Add an FAQ section to the pricing page', mode: 'fix' },
    { icon: Search, text: 'Find out why docs search feels slow', mode: 'research' },
];

type Props = Omit<ComponentProps<typeof StartThreadPanel>, 'example'> & { testId: string };

/**
 * The start screen: contour terrain behind a centred column with the mark, the
 * headline, the composer and a few example requests. The terrain reacts to the
 * composer through `StartFieldContext`.
 */
export function StartScreen({ testId, ...panelProps }: Props) {
    const composerRef = useRef<HTMLDivElement>(null);
    const handleRef = useRef<StartFieldHandle | null>(null);
    const [example, setExample] = useState<StartExample | undefined>(undefined);
    const exampleCount = useRef(0);

    // A stable proxy, so the composer never re-renders when the field mounts.
    const field = useMemo<StartFieldHandle>(
        () => ({
            wave: () => handleRef.current?.wave(),
            erupt: () => handleRef.current?.erupt(),
            settleMs: () => handleRef.current?.settleMs() ?? 0,
        }),
        [],
    );

    return (
        <div className="relative min-h-0 flex-1 overflow-y-auto" data-testid={testId}>
            <div className="relative flex min-h-full items-center justify-center overflow-hidden pb-8 pl-[max(16px,env(safe-area-inset-left))] pr-[max(16px,env(safe-area-inset-right))] pt-16 max-sm:pt-[60px]">
                <ContourField anchorRef={composerRef} handleRef={handleRef} />
                <div className="relative z-[2] flex w-full min-w-0 max-w-[700px] flex-col items-center" data-testid="start-screen">
                    <YakMark className="mb-2.5 size-[52px] drop-shadow-[0_6px_10px_rgba(26,26,31,0.14)] sm:size-[60px]" title="Yak" />
                    <h1 className="start-glow text-center text-[clamp(28px,5.4vw,40px)] font-semibold leading-[1.1] tracking-[-0.028em] text-balance text-body">Where to next?</h1>
                    <p className="start-glow mb-[26px] mt-3 max-w-[44ch] text-center text-[15px] leading-normal text-balance text-muted">
                        Point Yak at a problem. You get a preview to click through and a pull request to review.
                    </p>

                    <div ref={composerRef} className="w-full">
                        <StartFieldContext value={field}>
                            <StartThreadPanel {...panelProps} example={example} />
                        </StartFieldContext>
                    </div>

                    <p className="start-glow mt-[22px] text-[11px] font-semibold uppercase tracking-[0.06em] text-faint">Or start from an example</p>
                    <div className="mt-2.5 flex flex-wrap justify-center gap-2" role="group" aria-label="Example requests" data-testid="start-examples">
                        {EXAMPLES.map(({ icon: Icon, text, mode }) => (
                            <button
                                key={text}
                                type="button"
                                onClick={() => setExample({ id: ++exampleCount.current, text, mode })}
                                className={cn(
                                    'inline-flex h-9 items-center gap-2 rounded-[10px] border border-hair bg-panel pl-2.5 pr-[13px] text-[13px] font-medium text-body',
                                    'shadow-[0_1px_0_rgba(26,26,31,0.04),0_1px_2px_rgba(26,26,31,0.06)] transition-[transform,box-shadow,border-color,background-color] duration-150',
                                    'hover:-translate-y-px hover:border-[var(--accent-edge)] hover:bg-[var(--accent-tint)] hover:shadow-[0_2px_0_rgba(26,26,31,0.03),0_4px_10px_-2px_rgba(26,26,31,0.12)]',
                                    'active:translate-y-px active:scale-[0.98] active:bg-[var(--accent-tint-strong)] active:shadow-none',
                                    'motion-reduce:transition-none motion-reduce:hover:translate-y-0',
                                )}
                                data-testid="start-example"
                            >
                                <Icon size={14} className="text-accent-text" aria-hidden="true" />
                                {text}
                            </button>
                        ))}
                    </div>
                </div>
            </div>
        </div>
    );
}
