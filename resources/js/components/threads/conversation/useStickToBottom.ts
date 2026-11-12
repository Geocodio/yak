import { useCallback, useEffect, useRef, useState, type RefObject } from 'react';

const NEAR_BOTTOM_PIXELS = 80;

/**
 * Opens the conversation at the newest message and follows new content while the reader is at the bottom.
 * A reader who scrolled up stays put; `hasNew` then says that content arrived below.
 */
export function useStickToBottom(threadId: number): { scroller: RefObject<HTMLDivElement | null>; content: RefObject<HTMLDivElement | null>; hasNew: boolean; jumpToNewest: () => void } {
    const scroller = useRef<HTMLDivElement>(null);
    const content = useRef<HTMLDivElement>(null);
    const isAtBottom = useRef(true);
    const [hasNew, setHasNew] = useState(false);

    useEffect(() => {
        const box = scroller.current;
        const inner = content.current;

        if (box === null || inner === null) {
            return;
        }

        isAtBottom.current = true;
        setHasNew(false);
        box.scrollTop = box.scrollHeight;
        let height = inner.offsetHeight;

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

            if (isAtBottom.current) {
                box.scrollTop = box.scrollHeight;
            } else {
                setHasNew(true);
            }
        });

        observer.observe(inner);
        box.addEventListener('scroll', onScroll, { passive: true });

        return () => {
            observer.disconnect();
            box.removeEventListener('scroll', onScroll);
        };
    }, [threadId]);

    const jumpToNewest = useCallback(() => {
        isAtBottom.current = true;
        setHasNew(false);
        scroller.current?.scrollTo({ top: scroller.current.scrollHeight });
    }, []);

    return { scroller, content, hasNew, jumpToNewest };
}
