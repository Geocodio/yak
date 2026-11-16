import { ArrowUpRight } from 'lucide-react';
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
        <div className="border-t border-hair bg-panel-2/50 px-3 py-2 text-[12.5px] text-muted" data-testid="start-thread-runs-on">
            <div className="grid grid-cols-[auto_minmax(0,1fr)] items-center gap-x-2 gap-y-1.5 sm:flex sm:flex-wrap">
                <span className="mr-0.5">Runs on</span>
                <AccountPicker variant="chip" label="Claude account" value={accountId} accounts={picker.accounts} onChange={onAccountChange} disabled={disabled} defaultLabel={defaultLabel} defaultAccount={defaultAccount} className="justify-self-start" />
                <span>with</span>
                <ModelPicker variant="chip" models={picker.models} value={model} onChange={onModelChange} disabled={disabled} testId="composer-model" className="justify-self-start" />
                <a href={accountsHelpUrl} target="_blank" rel="noreferrer" className="col-span-2 mt-0.5 inline-flex items-center gap-0.5 underline underline-offset-2 hover:text-body sm:ml-auto sm:mt-0">
                    How accounts work <ArrowUpRight size={12} aria-hidden="true" />
                </a>
            </div>
            {note && <div className="mt-1.5">{note}</div>}
        </div>
    );
}
