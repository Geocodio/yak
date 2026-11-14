import { useCallback, useEffect, useRef, useState, type RefObject } from 'react';

const NEAR_BOTTOM_PIXELS = 80;
const INTERACT_HOLD_MS = 1000;

/**
 * Opens the conversation at the newest message (unless `startAtBottom` is false) and follows new content while the reader is at the bottom.
 * Only scrolling decides whether the reader follows: a reader who scrolled up stays put, and `hasNew` then says that
 * content arrived below. A click inside the content holds the follow for a moment, so a row the reader just opened is
 * not scrolled away from under them, and following resumes after that. With `latestKey`, only a new key raises `hasNew`,
 * so an image loading or a row re-rendering above never claims a reply that is not there.
 */
export function useStickToBottom(threadId: string, startAtBottom = true, latestKey?: string): { scroller: RefObject<HTMLDivElement | null>; content: RefObject<HTMLDivElement | null>; hasNew: boolean; jumpToNewest: () => void } {
    const scroller = useRef<HTMLDivElement>(null);
    const content = useRef<HTMLDivElement>(null);
    const isAtBottom = useRef(true);
    const [hasNew, setHasNew] = useState(false);
    const hasLatestKey = useRef(latestKey !== undefined);
    const seenKey = useRef(latestKey);

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

        let height = inner.offsetHeight;
        let holdUntil = 0;
        const toBottom = (): void => {
            box.scrollTop = box.scrollHeight;
        };

        const onScroll = (): void => {
            isAtBottom.current = box.scrollHeight - box.scrollTop - box.clientHeight < NEAR_BOTTOM_PIXELS;

            if (isAtBottom.current) {
                setHasNew(false);
            }
        };
        const observer = new ResizeObserver(() => {
            const hasGrown = inner.offsetHeight > height;
            height = inner.offsetHeight;

            if (!hasGrown) {
                return;
            }

            const isHeld = Date.now() < holdUntil;

            if (isAtBottom.current && !isHeld) {
                toBottom();
            } else if (!isHeld && !hasLatestKey.current) {
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
        boxObserver.observe(box);
        box.addEventListener('scroll', onScroll, { passive: true });
        inner.addEventListener('click', onInteract);

        return () => {
            observer.disconnect();
            boxObserver.disconnect();
            box.removeEventListener('scroll', onScroll);
            inner.removeEventListener('click', onInteract);
        };
    }, [threadId]);

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

    return { scroller, content, hasNew, jumpToNewest };
}
