import { router } from '@inertiajs/react';
import { Button } from '@geocodio/console-ui';
import { CircleAlert, CircleHelp, ExternalLink, RefreshCw } from 'lucide-react';
import { Callout } from '@/components/riskProfile/RiskProfileStates';
import { formatAgoLong } from '@/lib/format';
import type { RiskProfilePage } from '@/types/riskProfile';

const FILE = '.yak/risk-profile.yml';
const mono = 'font-mono text-text';

export function readAgo(iso: string | null): string {
    return iso ? formatAgoLong(iso) : 'earlier';
}

export function InvalidFileBanner({ page, branch }: { page: RiskProfilePage; branch: string }) {
    const file = page.file;

    if (!file?.error || !file.validCommitSha) {
        return null;
    }

    const errorLines = file.error.split('\n').filter((line) => line !== '');
    const pullRequest = file.errorPullRequest;

    return (
        <Callout tone="fail" icon={<CircleAlert size={16} className="text-fail" />}>
            <b data-testid="risk-banner-invalid">
                <span className="font-mono">{FILE}</span> on {branch} is invalid
            </b>
            <p className="text-muted">
                {file.errorCommitSha && (
                    <>
                        Introduced in <span className={mono}>{file.errorCommitSha.slice(0, 7)}</span>
                        {pullRequest && (
                            <>
                                {' '}
                                by{' '}
                                <a href={pullRequest.url} target="_blank" rel="noopener noreferrer" className="text-accent-text hover:underline">
                                    #{pullRequest.number} "{pullRequest.title}"
                                </a>
                            </>
                        )}
                        .{' '}
                    </>
                )}
                Yak keeps using the version from <span className={mono}>{file.validCommitSha.slice(0, 7)}</span> until the file is fixed.
            </p>
            <pre className="whitespace-pre-wrap break-words rounded-control bg-panel px-3 py-2 font-mono text-[12px] text-fail">{errorLines.join('\n')}</pre>
            {file.blobUrl && (
                <a
                    href={file.blobUrl}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="inline-flex h-8 items-center gap-1.5 rounded-control border border-hair bg-panel px-3 text-[13px] font-medium text-text hover:bg-panel-2"
                >
                    <ExternalLink size={13} />
                    Open the file
                </a>
            )}
        </Callout>
    );
}

export function UnreachableBanner({ page }: { page: RiskProfilePage }) {
    const sha = (page.commitSha ?? page.file?.validCommitSha)?.slice(0, 7);

    return (
        <Callout tone="warn" icon={<CircleAlert size={16} className="text-warn" />}>
            <b data-testid="risk-banner-unreachable">
                Could not read <span className="font-mono">.yak/</span> from GitHub
            </b>
            <p className="text-muted">
                Using the version{sha && <> from <span className={mono}>{sha}</span></>}, read {readAgo(page.readAt)}. GitHub said: {page.readError ?? 'no response'}.
            </p>
            <Button icon={<RefreshCw size={13} />} onClick={() => router.reload({ only: ['riskProfile'] })}>
                Try again
            </Button>
        </Callout>
    );
}

export function OpenQuestionsBanner({ count, onJump }: { count: number; onJump: () => void }) {
    return (
        <Callout tone="warn" icon={<CircleHelp size={16} className="text-warn" />}>
            <b data-testid="risk-banner-questions">
                {count === 1 ? '1 open question in the profile' : `${count} open questions in the profile`}
            </b>
            <p className="text-muted">While any are open, Yak’s PR reviewer does not auto-approve pull requests in this repository.</p>
            <Button onClick={onJump}>Jump to questions</Button>
        </Callout>
    );
}

export function DeadAreasBanner({ count, branch }: { count: number; branch: string }) {
    return (
        <Callout tone="warn" icon={<CircleAlert size={16} className="text-warn" />}>
            <b data-testid="risk-banner-dead-areas">
                {count} {count === 1 ? 'area matches' : 'areas match'} no file on {branch}
            </b>
            <p className="text-muted">Yak ignores {count === 1 ? 'it' : 'them'} until a path matches. The directory may have been moved or deleted.</p>
        </Callout>
    );
}

