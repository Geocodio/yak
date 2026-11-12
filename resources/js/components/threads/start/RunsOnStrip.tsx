import { ArrowUpRight, CircleHelp } from 'lucide-react';
import type { ReactNode } from 'react';
import { AccountPicker } from '@/components/accounts/AccountPicker';
import { ModelPicker } from '@/components/accounts/ModelPicker';
import type { NewThreadPageData } from '@/types/threads';

type Picker = NewThreadPageData['picker'];

/** The shaded "Runs on {account} with {model}" strip under the toolbar; `note` renders as a second line. */
export function RunsOnStrip({
    picker,
    accountId,
    onAccountChange,
    model,
    onModelChange,
    disabled,
    accountsHelpUrl,
    note,
    defaultLabel,
    defaultAccount,
}: {
    picker: Picker;
    accountId: string;
    onAccountChange: (id: string) => void;
    model: string | null;
    onModelChange: (model: string) => void;
    disabled: boolean;
    accountsHelpUrl: string;
    note?: ReactNode;
    defaultLabel: string;
    defaultAccount?: Picker['accounts'][number];
}) {
    return (
        <div className="border-t border-hair bg-panel-2 px-[18px] py-2.5 text-[12px] text-muted" data-testid="start-thread-runs-on">
            <div className="flex flex-wrap items-center gap-2">
                <span>Runs on</span>
                <AccountPicker label="Claude account" value={accountId} accounts={picker.accounts} onChange={onAccountChange} disabled={disabled} defaultLabel={defaultLabel} defaultAccount={defaultAccount} className="h-7 min-w-[160px]" />
                <span>with</span>
                <ModelPicker variant="inline" models={picker.models} value={model} onChange={onModelChange} disabled={disabled} testId="composer-model" />
                <span title="The model runs on the chosen account" className="inline-flex text-faint">
                    <CircleHelp size={13} aria-hidden="true" />
                </span>
                <a href={accountsHelpUrl} target="_blank" rel="noreferrer" className="ml-auto inline-flex items-center gap-0.5 underline">
                    How accounts work <ArrowUpRight size={12} aria-hidden="true" />
                </a>
            </div>
            {note && <div className="mt-1.5">{note}</div>}
        </div>
    );
}
