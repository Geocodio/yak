import { CapacityMeter } from '@/components/threads/conversation/CapacityMeter';
import type { ThreadCapacity } from '@/types/threads';

/** The three limits a waiting or working thread can hit; renders nothing without capacity values. */
export function CapacityBlock({ capacity, accountName }: { capacity: ThreadCapacity | null; accountName: string | null }) {
    if (capacity === null) {
        return null;
    }

    const isFull = (limit: { used: number; limit: number }) => limit.used >= limit.limit;

    return (
        <section className="rounded-card border border-hair bg-app p-3" data-testid="capacity-block">
            <h3 className="mb-3 text-[12px] text-muted">Capacity, what is full</h3>
            <div className="flex flex-col gap-3">
                <CapacityMeter label={`Turns on ${accountName ?? 'the account'}`} used={capacity.turns.used} limit={capacity.turns.limit} blocking={isFull(capacity.turns)} />
                <CapacityMeter label="Awake sandboxes" used={capacity.sandboxes.used} limit={capacity.sandboxes.limit} blocking={isFull(capacity.sandboxes)} />
                <CapacityMeter label="Your share" used={capacity.share.used} limit={capacity.share.limit} blocking={isFull(capacity.share)} />
            </div>
        </section>
    );
}
