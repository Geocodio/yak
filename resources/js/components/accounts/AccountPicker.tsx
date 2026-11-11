import { Menu } from '@geocodio/console-ui';
import { ChevronDown } from 'lucide-react';
import type { AccountPickerProps, PickerAccount } from '@/types/claudeAccounts';
import { AccountMark } from './AccountMark';

/**
 * A menu-based picker: console-ui's Select has no room for icons, Menu does.
 * With `defaultLabel` the first entry means "use the default" and its value is the empty string.
 */
export function AccountPicker({ value, accounts, onChange, disabled, label, className, defaultLabel, defaultAccount }: AccountPickerProps) {
    const current = accounts.find((account) => String(account.id) === value) ?? (defaultLabel ? undefined : accounts[0]);
    const mine = accounts.filter((account) => !account.isSystem);
    const shared = accounts.filter((account) => account.isSystem);
    const isUsable = (account: PickerAccount) => account.usable ?? account.status === 'active';

    const item = (account: PickerAccount, dividerAbove?: boolean) => ({
        key: String(account.id),
        label: isUsable(account) ? `${account.name}${account.isSystem ? ' (shared)' : ''}` : `${account.name} (needs a new secret)`,
        icon: <AccountMark appearance={account} size={18} dimmed={!isUsable(account)} />,
        checked: String(account.id) === value,
        disabled: !isUsable(account),
        dividerAbove,
        onSelect: () => onChange(String(account.id)),
    });

    const shown = current ?? defaultAccount;

    return (
        <Menu
            aria-label={label}
            disabled={disabled}
            className={className}
            trigger={
                <span className="flex min-w-0 flex-1 items-center gap-1.5">
                    {shown && <AccountMark appearance={shown} size={18} />}
                    <span className="truncate">{current ? current.name : defaultLabel}</span>
                    <ChevronDown size={12} className="ml-auto shrink-0 text-faint" aria-hidden="true" />
                </span>
            }
            items={[
                ...(defaultLabel ? [{ key: 'default', label: defaultLabel, icon: defaultAccount ? <AccountMark appearance={defaultAccount} size={18} /> : undefined, checked: value === '', onSelect: () => onChange('') }] : []),
                ...mine.map((account, index) => item(account, index === 0 && !!defaultLabel)),
                ...shared.map((account, index) => item(account, index === 0 && (mine.length > 0 || !!defaultLabel))),
            ]}
        />
    );
}
