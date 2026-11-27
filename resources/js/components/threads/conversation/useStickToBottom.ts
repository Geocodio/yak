import { useCallback, useEffect, useRef, useState, type RefObject } from 'react';
import { followStep } from './followStep';

const NEAR_BOTTOM_PIXELS = 80;
const INTERACT_HOLD_MS = 1000;

/**
 * A card in the `footer` element after the content is followed like content: it is scrolled into view when the reader is at the bottom, and raises `hasNew` when the reader has scrolled up.
 * Opens the conversation at the newest message (unless `startAtBottom` is false) and follows new content while the reader is at the bottom.
 * Only scrolling decides whether the reader follows: a reader who scrolled up stays put, and `hasNew` then says that
 * content arrived below. A click inside the content holds the follow for a moment, so a row the reader just opened is
 * not scrolled away from under them, and following resumes after that. With `latestKey`, only a new key raises `hasNew`,
 * so an image loading or a row re-rendering above never claims a reply that is not there.
 * While `isStreaming`, growth is followed with a short eased scroll instead of a jump (a jump when the reader prefers
 * reduced motion); a wheel, touch or key scroll by the reader stops the follow at once.
 */
export function useStickToBottom(threadId: string, startAtBottom = true, latestKey?: string, isStreaming = false): { scroller: RefObject<HTMLDivElement | null>; content: RefObject<HTMLDivElement | null>; footer: RefObject<HTMLDivElement | null>; hasNew: boolean; jumpToNewest: () => void } {
    const scroller = useRef<HTMLDivElement>(null);
    const content = useRef<HTMLDivElement>(null);
    const footer = useRef<HTMLDivElement>(null);
    const isAtBottom = useRef(true);
    const [hasNew, setHasNew] = useState(false);
    const hasLatestKey = useRef(latestKey !== undefined);
    const seenKey = useRef(latestKey);
    const isStreamingRef = useRef(isStreaming);
    isStreamingRef.current = isStreaming;
    const finishFollow = useRef<() => void>(() => {});

    useEffect(() => {
        const box = scroller.current;
        const inner = content.current;

        if (box === null || inner === null) {
            return;
        }

        isAtBottom.current = startAtBottom;
        setHasNew(false);

        if (startAtBottom) {
            box.scrollTop = box.scrollHeight;
        }

        const footerElement = footer.current;
        let height = inner.offsetHeight;
        let footerHeight = footerElement?.offsetHeight ?? 0;
        let holdUntil = 0;
        // The scroll height the follow last pinned or measured. A scroll event that sees another height ran after the
        // content grew, so its distance from the bottom is the growth and not the reader leaving; the resize observer
        // follows that growth instead.
        let pinnedHeight = box.scrollHeight;
        const toBottom = (): void => {
            pinnedHeight = box.scrollHeight;
            box.scrollTop = pinnedHeight;
        };

        let lastScrollTop = box.scrollTop;
        let frame = 0;
        let lastFrameAt = 0;
        const stopFollow = (): void => {
            cancelAnimationFrame(frame);
            frame = 0;
        };
        // Each frame closes part of the gap to the bottom. The position written here is recorded as the follow's own,
        // so the scroll event it raises is never read as the reader.
        const followFrame = (now: number): void => {
            frame = 0;

            const target = box.scrollHeight - box.clientHeight;

            if (!isAtBottom.current || target <= box.scrollTop + 0.5) {
                return;
            }

            pinnedHeight = box.scrollHeight;
            box.scrollTop = followStep(box.scrollTop, target, (now - lastFrameAt) / 1000);
            lastScrollTop = box.scrollTop;
            lastFrameAt = now;
            frame = requestAnimationFrame(followFrame);
        };
        const follow = (): void => {
            if (frame === 0) {
                lastFrameAt = performance.now();
                frame = requestAnimationFrame(followFrame);
            }
        };
        finishFollow.current = () => {
            stopFollow();

            if (isAtBottom.current) {
                toBottom();
                lastScrollTop = box.scrollTop;
            }
        };
        // Only input that can move the view up counts: a wheel down at the bottom raises no scroll event to restore the flag.
        const onReaderScroll = (event: Event): void => {
            if (event instanceof WheelEvent && event.deltaY >= 0) {
                return;
            }

            if (event instanceof KeyboardEvent && !['ArrowUp', 'PageUp', 'Home'].includes(event.key) && !(event.key === ' ' && event.shiftKey)) {
                return;
            }

            isAtBottom.current = false;
            stopFollow();
        };
        const onScroll = (): void => {
            // Growth never moves the scroll position up, so a move up is the reader even when content grew in the same frame.
            const hasMovedUp = box.scrollTop < lastScrollTop;
            lastScrollTop = box.scrollTop;

            if (box.scrollHeight !== pinnedHeight && !hasMovedUp) {
                return;
            }

            isAtBottom.current = box.scrollHeight - box.scrollTop - box.clientHeight < NEAR_BOTTOM_PIXELS;

            if (isAtBottom.current) {
                setHasNew(false);
            }
        };
        const observer = new ResizeObserver(() => {
            const hasFooterGrown = (footerElement?.offsetHeight ?? 0) > footerHeight;
            const hasGrown = inner.offsetHeight > height || hasFooterGrown;
            height = inner.offsetHeight;
            footerHeight = footerElement?.offsetHeight ?? 0;
            pinnedHeight = box.scrollHeight;

            if (!hasGrown) {
                return;
            }

            const isHeld = Date.now() < holdUntil;

            if (isAtBottom.current && !isHeld) {
                if (isStreamingRef.current && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                    follow();
                } else {
                    toBottom();
                }

                // A field the reader is typing in stays in view when its card grows above it.
                if (hasFooterGrown && footerElement?.contains(document.activeElement)) {
                    (document.activeElement as HTMLElement).scrollIntoView({ block: 'nearest' });
                }
            } else if (!isHeld && (!hasLatestKey.current || hasFooterGrown)) {
                setHasNew(true);
            }
        });
        // The scroller shrinks when the composer grows; the newest message stays in view.
        const boxObserver = new ResizeObserver(() => {
            if (isAtBottom.current) {
                toBottom();
            }
        });

        const onInteract = (): void => {
            holdUntil = Date.now() + INTERACT_HOLD_MS;
        };

        observer.observe(inner);
        if (footerElement !== null) {
            observer.observe(footerElement);
        }
        boxObserver.observe(box);
        box.addEventListener('scroll', onScroll, { passive: true });
        box.addEventListener('wheel', onReaderScroll, { passive: true });
        box.addEventListener('touchmove', onReaderScroll, { passive: true });
        box.addEventListener('keydown', onReaderScroll);
        inner.addEventListener('click', onInteract);

        return () => {
            stopFollow();
            finishFollow.current = () => {};
            observer.disconnect();
            boxObserver.disconnect();
            box.removeEventListener('scroll', onScroll);
            box.removeEventListener('wheel', onReaderScroll);
            box.removeEventListener('touchmove', onReaderScroll);
            box.removeEventListener('keydown', onReaderScroll);
            inner.removeEventListener('click', onInteract);
        };
    }, [threadId]);

    // The streamed text is final: stop easing and land exactly on the bottom.
    useEffect(() => {
        if (!isStreaming) {
            finishFollow.current();
        }
    }, [isStreaming]);

    useEffect(() => {
        if (latestKey === seenKey.current) {
            return;
        }

        seenKey.current = latestKey;

        if (!isAtBottom.current) {
            setHasNew(true);
        }
    }, [latestKey]);

    const jumpToNewest = useCallback(() => {
        isAtBottom.current = true;
        setHasNew(false);
        scroller.current?.scrollTo({ top: scroller.current.scrollHeight });
    }, []);

    return { scroller, content, footer, hasNew, jumpToNewest };
}
