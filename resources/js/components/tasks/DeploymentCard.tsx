import { Button, StatusPill } from '@geocodio/console-ui';
import { STATUS_TONE } from '@/lib/deploymentStatus';
import type { DeploymentData } from '@/types/tasks';

export function DeploymentCard({ deployment }: { deployment: DeploymentData }) {
    if (!deployment) {
        return null;
    }

    return (
        <section data-testid="deployment-card">
            <h2 className="mb-2 text-[11px] font-semibold uppercase tracking-wide text-faint">Deployment</h2>
            <div className="flex items-center justify-between rounded-card border border-hair bg-panel p-3 shadow-card">
                <div className="min-w-0">
                    <div className="flex items-center gap-1.5">
                        <StatusPill
                            tone={STATUS_TONE[deployment.status] ?? 'idle'}
                            label={deployment.status.charAt(0).toUpperCase() + deployment.status.slice(1)}
                        />
                    </div>
                    <div className="mt-1 truncate font-mono text-[11px] text-muted">{deployment.hostname}</div>
                </div>
                <Button variant="link" className="text-[12px]" onClick={() => window.open(deployment.url, '_blank', 'noopener,noreferrer')}>
                    Open
                </Button>
            </div>
        </section>
    );
}
