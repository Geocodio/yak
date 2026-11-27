import { useCallback, useEffect, useRef, useState, type RefObject } from 'react';
import { followStep } from './followStep';
import { NEAR_BOTTOM_PIXELS, readerLeftBottom } from './readerLeftBottom';

const DETACHED_BOTTOM_PIXELS = 4;
const READER_SCROLL_WINDOW_MS = 500;
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

            if (Date.now() < holdUntil) {
                return;
            }

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
        // A reader who scrolled up stays detached until back within a few pixels of the bottom.
        let isDetached = false;
        let isReaderActive = false;
        let wasAtBottom = false;
        let scrollTopAtInput = 0;
        let lastReaderInputAt = 0;
        let settleFrame = 0;
        const distanceFromBottom = (): number => box.scrollHeight - box.scrollTop - box.clientHeight;
        const catchUp = (): void => {
            if (!isAtBottom.current || distanceFromBottom() <= 0.5) {
                return;
            }

            if (isStreamingRef.current && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                follow();
            } else {
                toBottom();
                lastScrollTop = box.scrollTop;
            }
        };
        // The input only stops the eased loop; where the view ended up decides whether the reader left the bottom.
        const settleReaderInput = (): void => {
            settleFrame = 0;
            isReaderActive = false;

            if (readerLeftBottom({ scrollTopAtInput, scrollTopNow: box.scrollTop, distanceFromBottom: distanceFromBottom() })) {
                if (box.scrollTop < scrollTopAtInput) {
                    isDetached = true;
                }

                isAtBottom.current = false;
            } else if (wasAtBottom) {
                isAtBottom.current = true;
                catchUp();
            }
        };
        const beginReaderInput = (): void => {
            lastReaderInputAt = Date.now();

            if (!isReaderActive) {
                isReaderActive = true;
                wasAtBottom = isAtBottom.current;
                scrollTopAtInput = box.scrollTop;
            }

            stopFollow();
        };
        const scheduleSettle = (): void => {
            cancelAnimationFrame(settleFrame);
            settleFrame = requestAnimationFrame(settleReaderInput);
        };
        const onWheel = (event: WheelEvent): void => {
            if (event.deltaY < 0) {
                beginReaderInput();
                scheduleSettle();
            }
        };
        const onKeyDown = (event: KeyboardEvent): void => {
            const target = event.target;

            if (target instanceof HTMLElement && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName))) {
                return;
            }

            if (['ArrowUp', 'PageUp', 'Home'].includes(event.key) || (event.key === ' ' && event.shiftKey)) {
                beginReaderInput();
                scheduleSettle();
            }
        };
        const onTouchEnd = (): void => {
            lastReaderInputAt = Date.now();

            if (isReaderActive) {
                scheduleSettle();
            }
        };
        const onScroll = (): void => {
            // Growth never moves the scroll position up, so a move up is the reader even when content grew in the same frame.
            const hasMovedUp = box.scrollTop < lastScrollTop;
            lastScrollTop = box.scrollTop;

            if (box.scrollHeight !== pinnedHeight && !hasMovedUp) {
                return;
            }

            if (hasMovedUp && (isReaderActive || Date.now() - lastReaderInputAt < READER_SCROLL_WINDOW_MS)) {
                isDetached = true;
            }

            isAtBottom.current = distanceFromBottom() < (isDetached ? DETACHED_BOTTOM_PIXELS : NEAR_BOTTOM_PIXELS);

            if (isAtBottom.current) {
                isDetached = false;
            }

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
                if (!isReaderActive) {
                    catchUp();
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
        box.addEventListener('wheel', onWheel, { passive: true });
        box.addEventListener('touchmove', beginReaderInput, { passive: true });
        box.addEventListener('touchend', onTouchEnd, { passive: true });
        box.addEventListener('touchcancel', onTouchEnd, { passive: true });
        box.addEventListener('keydown', onKeyDown);
        inner.addEventListener('click', onInteract);

        return () => {
            stopFollow();
            finishFollow.current = () => {};
            observer.disconnect();
            boxObserver.disconnect();
            box.removeEventListener('scroll', onScroll);
            box.removeEventListener('wheel', onWheel);
            box.removeEventListener('touchmove', beginReaderInput);
            box.removeEventListener('touchend', onTouchEnd);
            box.removeEventListener('touchcancel', onTouchEnd);
            box.removeEventListener('keydown', onKeyDown);
            cancelAnimationFrame(settleFrame);
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
