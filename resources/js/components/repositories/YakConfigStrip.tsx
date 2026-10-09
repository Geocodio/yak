import { Badge, Button, Skeleton } from '@geocodio/console-ui';
import { ExternalLink, FolderGit2, Pencil, RefreshCw, TriangleAlert } from 'lucide-react';
import type { ReactNode } from 'react';
import { useRouterAction } from '@/lib/useRouterAction';
import { formatAgo } from '@/lib/format';
import repos from '@/routes/repos';
import type { YakConfig, YakConfigFile } from '@/types/repositories';

const SURFACE = {
    neutral: 'border-hair bg-panel-2',
    ok: 'border-hair bg-ok-soft',
    warn: 'border-hair bg-warn-soft',
    fail: 'border-hair bg-fail-soft',
} as const;

function Strip({ tone, icon, title, children, action }: { tone: keyof typeof SURFACE; icon: ReactNode; title: ReactNode; children?: ReactNode; action?: ReactNode }) {
    return (
        <div data-testid="yak-config-strip" className={`mb-4 flex flex-wrap items-start gap-3 rounded-card border p-4 ${SURFACE[tone]}`}>
            <div className="mt-0.5 shrink-0">{icon}</div>
            <div className="min-w-0 flex-1 space-y-1.5">
                <div className="text-[13px] font-medium">{title}</div>
                {children}
            </div>
            {action}
        </div>
    );
}

function Sha({ sha, url }: { sha: string; url?: string | null }) {
    const short = sha.slice(0, 7);

    return url ? (
        <a href={url} target="_blank" rel="noopener noreferrer" className="font-mono text-accent-text hover:underline">
            {short}
        </a>
    ) : (
        <span className="font-mono">{short}</span>
    );
}

function ErrorLines({ error }: { error: string | null }) {
    return error ? <pre className="whitespace-pre-wrap break-words rounded-control bg-panel px-3 py-2 font-mono text-[12px] text-fail">{error}</pre> : null;
}

export function YakConfigStripSkeleton({ branch }: { branch: string }) {
    return (
        <div data-testid="yak-config-strip-loading" className={`mb-4 flex items-start gap-3 rounded-card border p-4 ${SURFACE.neutral}`}>
            <RefreshCw size={16} className="mt-0.5 shrink-0 text-faint" />
            <div className="flex-1 space-y-2">
                <div className="text-[13px] font-medium">
                    Reading <span className="font-mono">.yak/</span> from {branch}…
                </div>
                <Skeleton className="h-3 w-2/3" />
            </div>
        </div>
    );
}

function InvalidFileStrip({ file }: { file: YakConfigFile }) {
    const neverValid = file.validCommitSha === null;

    return (
        <Strip
            tone="fail"
            icon={<TriangleAlert size={16} className="text-fail" />}
            title={
                neverValid ? (
                    <>
                        <span className="font-mono">.yak/{file.name}</span> has never been valid
                    </>
                ) : (
                    <>
                        <span className="font-mono">.yak/{file.name}</span> is invalid
                    </>
                )
            }
            action={
                file.editUrl && (
                    <a href={file.editUrl} target="_blank" rel="noopener noreferrer">
                        <Button icon={<Pencil size={13} />}>
                            Fix on GitHub
                        </Button>
                    </a>
                )
            }
        >
            {neverValid ? (
                <p className="text-[12px] text-muted">Settings from this file come from Yak until it is fixed.</p>
            ) : (
                <p className="text-[12px] text-muted">
                    {file.errorCommitSha && (
                        <>
                            Introduced in <Sha sha={file.errorCommitSha} url={file.errorCommitUrl} />
                            {file.errorPullRequest && (
                                <>
                                    {' '}
                                    by{' '}
                                    <a href={file.errorPullRequest.url} target="_blank" rel="noopener noreferrer" className="text-accent-text hover:underline">
                                        #{file.errorPullRequest.number} {file.errorPullRequest.title}
                                    </a>
                                </>
                            )}
                            .{' '}
                        </>
                    )}
                    Yak keeps using the version from <Sha sha={file.validCommitSha as string} /> until the file is fixed.
                </p>
            )}
            <ErrorLines error={file.error} />
        </Strip>
    );
}

export function YakConfigStrip({ config, slug, branch, setupDone, guideUrl }: { config: YakConfig; slug: string; branch: string; setupDone: boolean; guideUrl: string }) {
    const action = useRouterAction();

    if (config.state === 'unavailable') {
        return null;
    }

    if (config.state === 'unreachable') {
        return (
            <Strip
                tone="warn"
                icon={<TriangleAlert size={16} className="text-warn" />}
                title={
                    <>
                        Could not read <span className="font-mono">.yak/</span> from GitHub
                    </>
                }
                action={
                    <Button
                        data-testid="yak-config-refresh"
                        icon={<RefreshCw size={13} />}
                        pending={action.isPending('config-refresh')}
                        onClick={() =>
                            action.run('config-refresh', 'post', repos.config.refresh.url(slug))
                        }
                    >
                        Try again
                    </Button>
                }
            >
                <p className="text-[12px] text-muted">
                    {config.commitSha ? (
                        <>
                            Using the version from <Sha sha={config.commitSha} url={config.commitUrl} />
                            {config.readAt && <>, read {formatAgo(config.readAt)}</>}.
                        </>
                    ) : (
                        'Yak has not read .yak/ yet, so the values below come from Yak.'
                    )}{' '}
                    GitHub said: {config.readError}
                </p>
            </Strip>
        );
    }

    const invalidFiles = config.files.filter((file) => !file.valid);

    if (config.files.length === 0) {
        return setupDone ? (
            <Strip
                tone="neutral"
                icon={<FolderGit2 size={16} className="text-muted" />}
                title="Settings are stored in Yak, not in the repository"
                action={
                    <a href={guideUrl} target="_blank" rel="noopener noreferrer">
                        <Button icon={<ExternalLink size={13} />}>
                            How .yak/ works
                        </Button>
                    </a>
                }
            >
                <p className="text-[12px] text-muted">You can commit a .yak/ directory to manage settings through pull requests.</p>
            </Strip>
        ) : (
            <Strip
                tone="neutral"
                icon={<FolderGit2 size={16} className="text-muted" />}
                title="Run setup to create this repository's Yak config"
                action={
                    <Button
                        variant="primary"
                        pending={action.isPending('rerun-setup-strip')}
                        onClick={() => action.run('rerun-setup-strip', 'post', repos.rerunSetup.url(slug))}
                    >
                        Run setup
                    </Button>
                }
            >
                <p className="text-[12px] text-muted">
                    Setup prepares the sandbox for Yak tasks. You can commit a .yak/ directory to manage settings through pull requests.
                </p>
            </Strip>
        );
    }

    if (invalidFiles.length > 0) {
        return (
            <>
                {invalidFiles.map((file) => (
                    <InvalidFileStrip key={file.name} file={file} />
                ))}
            </>
        );
    }

    return (
        <>
            <Strip
                tone="ok"
                icon={<FolderGit2 size={16} className="text-ok" />}
                title={
                    <>
                        Config from <span className="font-mono">.yak/</span> on {branch}
                        {config.commitSha && (
                            <>
                                {' '}
                                at <Sha sha={config.commitSha} url={config.commitUrl} />
                            </>
                        )}
                    </>
                }
                action={
                    config.directoryUrl && (
                        <a href={config.directoryUrl} target="_blank" rel="noopener noreferrer">
                            <Button icon={<ExternalLink size={13} />}>
                                Open .yak/ on GitHub
                            </Button>
                        </a>
                    )
                }
            >
                <p className="text-[12px] text-muted">{config.readAt ? `Read ${formatAgo(config.readAt)}. ` : ''}Yak reads again after every push to {branch}.</p>
                <div className="flex flex-wrap gap-1.5">
                    {['config.yml', 'AGENTS.md', 'preview.yml', 'risk-profile.yml'].map((name) => {
                        const file = config.files.find((candidate) => candidate.name === name);

                        return file ? (
                            <Badge key={name} tone={file.valid ? 'ok' : 'fail'}>
                                {name}
                            </Badge>
                        ) : (
                            <Badge key={name} tone="neutral">
                                {name} not committed
                            </Badge>
                        );
                    })}
                </div>
            </Strip>
        </>
    );
}
