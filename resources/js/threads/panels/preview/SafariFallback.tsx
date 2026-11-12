import { Button } from '@geocodio/console-ui';
import { ExternalLink } from 'lucide-react';
import { CopyableAddress } from '@/components/CopyableAddress';

/** Desktop Safari cannot complete the preview sign-in inside a frame, so the preview opens as its own tab. */
export function SafariFallback({ url, host }: { url: string; host: string | null }) {
    return (
        <div className="flex min-h-0 flex-1 items-center justify-center p-6" data-testid="preview-safari-fallback">
            <div className="flex max-w-md flex-col gap-3 rounded-card border border-hair bg-panel p-6">
                <h2 className="text-[15px] font-semibold text-body">Open the preview in a new tab</h2>
                <p className="text-[13px] text-muted">Safari does not allow this preview to sign you in inside the panel. It works the same in its own tab.</p>
                {host !== null && <CopyableAddress address={host} />}
                <div>
                    <Button variant="primary" icon={<ExternalLink size={13} />} onClick={() => window.open(url, '_blank', 'noopener')} data-testid="preview-safari-open">
                        Open in new tab
                    </Button>
                </div>
            </div>
        </div>
    );
}
