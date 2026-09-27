import { useEffect, useRef } from 'react';

type RowId = number | string;

/**
 * Entrance classes for rows that join a list after its first render.
 *
 * A row that appears above every row already shown arrived with a refresh
 * and gets `row-arrive` (fade in plus a short accent tint); a row that
 * appears below them was loaded by infinite scroll and gets `row-enter`
 * (fade in only). The first render, and any render that shares no row with
 * the previous ones (a filter or tab change), animates nothing.
 *
 * Seen ids are recorded after commit rather than during render, so a
 * StrictMode double render still assigns the class, and an assigned class
 * stays on its row so a later re-render cannot cut the animation short.
 */
export function useRowEntrance(ids: RowId[]): (id: RowId) => string | undefined {
    const seenRef = useRef<Set<RowId> | null>(null);
    const assignedRef = useRef(new Map<RowId, string>());

    const seen = seenRef.current;
    const firstSeenIndex = seen === null ? -1 : ids.findIndex((id) => seen.has(id));
    const computed = new Map<RowId, string>();

    if (seen !== null && firstSeenIndex !== -1) {
        ids.forEach((id, index) => {
            if (!seen.has(id) && !assignedRef.current.has(id)) {
                computed.set(id, index < firstSeenIndex ? 'row-arrive' : 'row-enter');
            }
        });
    }

    useEffect(() => {
        computed.forEach((className, id) => assignedRef.current.set(id, className));
        seenRef.current = new Set(firstSeenIndex === -1 && seenRef.current !== null ? ids : [...(seenRef.current ?? []), ...ids]);
    });

    return (id) => assignedRef.current.get(id) ?? computed.get(id);
}
