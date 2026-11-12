export type AccountKind = 'setup_token' | 'api_key';
export type AccountStatus = 'active' | 'invalid' | 'expired';
export type AccountIconName =
    | 'briefcase' | 'user' | 'home' | 'building' | 'flask' | 'code' | 'rocket' | 'zap'
    | 'sparkles' | 'shield' | 'bot' | 'terminal' | 'globe' | 'coffee' | 'school' | 'heart';
export type AccountColorName = 'violet' | 'blue' | 'teal' | 'green' | 'amber' | 'plum' | 'slate';

export type Appearance = { icon: AccountIconName; color: AccountColorName };

/** Mirrors the array shape documented on App\Http\Resources\ClaudeAccountData. */
export type ClaudeAccount = Appearance & {
    id: number;
    name: string;
    kind: AccountKind;
    isSystem: boolean;
    isDefault: boolean;
    defaultModel: string | null;
    maxConcurrentTurns: number;
    status: AccountStatus;
    validatedAt: string | null;
    expiresAt: string | null;
    expiresSoon: boolean;
    lastUsedAt: string | null;
    lastError: string | null;
    unattendedRepos: string[];
};

/** The subset an account picker needs; matches App\Http\Resources\AccountPickerData accounts. */
export type PickerAccount = Appearance & Pick<ClaudeAccount, 'id' | 'name' | 'kind' | 'isSystem' | 'isDefault' | 'defaultModel' | 'status'> & { usable?: boolean };

export type AccountPickerProps = {
    /** The selected account id, or the empty string for "use the default". */
    value: string;
    accounts: PickerAccount[];
    onChange: (id: string) => void;
    disabled?: boolean;
    label: string;
    className?: string;
    defaultLabel?: string;
    defaultAccount?: PickerAccount;
    /** `chip` is the raised composer chip with the account's mark and a Default tag. */
    variant?: 'plain' | 'chip';
};

/** One model family in a picker: `latest` is the family alias, `older` are exact ids newest first. Mirrors App\Services\ClaudeAccounts\ClaudeModelCatalog::families(). */
export type ModelFamily = {
    family: string;
    latest: { value: string; label: string; description: string };
    older: { value: string; label: string }[];
};
