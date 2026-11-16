import { KeyRound } from 'lucide-react';
import type { ThreadNotice } from '@/types/threads';

export function AccountProblemCard({ notice }: { notice: ThreadNotice }) {
    return (
        <div className="rounded-card bg-fail-soft p-4" data-testid="thread-notice" data-kind={notice.kind}>
            <div className="flex items-start gap-3">
                <KeyRound className="mt-0.5 size-4 shrink-0 text-fail" aria-hidden />
                <div className="min-w-0 flex-1">
                    <p className="text-[13px] font-medium text-body">{notice.title}</p>
                    <p className="mt-1 text-[13px] text-muted">{notice.body}</p>
                    <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2">
                        {notice.accountSettingsUrl !== null && (
                            <a href={notice.accountSettingsUrl} data-testid="thread-replace-token" className="rounded-card bg-fail px-3 py-1.5 text-[13px] font-medium text-white">
                                Replace token
                            </a>
                        )}
                        {notice.startOnOwnAccountUrl !== null && (
                            <a href={notice.startOnOwnAccountUrl} data-testid="thread-start-own-account" className="text-[13px] text-accent-text hover:underline">
                                Start a new thread on your own account
                            </a>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}
