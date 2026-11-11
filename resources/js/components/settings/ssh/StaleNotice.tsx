import { AlertTriangle } from 'lucide-react';
import { timeAgo } from './KeyRow';

export function StaleNotice({ checkedAt }: { checkedAt: string | null }) {
    return (
        <div role="status" className="flex items-start gap-2 rounded-card border border-hair bg-warn-soft px-4 py-3 text-[13px]" data-testid="ssh-stale-notice">
            <AlertTriangle size={15} className="mt-0.5 shrink-0 text-warn" aria-hidden="true" />
            <p>GitHub could not be reached, so Yak is using the keys it cached{checkedAt ? ` ${timeAgo(checkedAt)}` : ''}. They keep working for up to 7 days.</p>
        </div>
    );
}
