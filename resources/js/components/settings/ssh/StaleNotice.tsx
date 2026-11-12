import { AlertTriangle } from 'lucide-react';
import { timeAgo } from './KeyRow';

export function StaleNotice({ checkedAt }: { checkedAt: string | null }) {
    return (
        <div role="status" className="flex items-start gap-2 rounded-card border border-hair bg-warn-soft px-4 py-3 text-[13px]" data-testid="ssh-stale-notice">
            <AlertTriangle size={15} className="mt-0.5 shrink-0 text-warn" aria-hidden="true" />
            <p>Yak could not reach GitHub just now, so this list is from {checkedAt ? timeAgo(checkedAt) : 'an earlier check'}. Connections still work with these keys. Yak tries again the next time you connect.</p>
        </div>
    );
}
