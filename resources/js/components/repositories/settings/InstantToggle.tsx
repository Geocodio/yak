import { Select, Toggle, toast } from '@geocodio/console-ui';
import { router } from '@inertiajs/react';
import { useEffect, useState, type ReactNode } from 'react';
import { SettingRow } from '@/components/repositories/settings/SettingsCard';
import repos from '@/routes/repos';

type Payload = Record<string, string | number | boolean | null>;

/**
 * Saves one setting the moment it changes. The server answers with a "Saved"
 * toast; when the request fails for any reason `onFailure` puts the control
 * back and the user is told why. Only the named fields are sent, so no other setting is touched.
 */
export function useInstantSave(slug: string) {
    const [isSaving, setIsSaving] = useState(false);

    const save = (payload: Payload, onFailure: () => void) => {
        let hasSucceeded = false;

        router.patch(repos.update.url(slug), payload, {
            preserveScroll: true,
            preserveState: true,
            onStart: () => setIsSaving(true),
            onSuccess: () => {
                hasSucceeded = true;
            },
            // Server and network failures already toast from app.tsx; a rejected value has no other place to show.
            onError: (errors) => toast.error(Object.values(errors)[0] ?? 'Could not save that change.'),
            onFinish: () => {
                setIsSaving(false);

                if (!hasSucceeded) {
                    onFailure();
                }
            },
        });
    };

    return { save, isSaving };
}

/** A switch row that saves on flip. `intercept` can take over a flip (to ask first) and call `commit` later. */
export function InstantToggle({
    slug,
    field,
    label,
    description,
    checked,
    badge,
    disabled = false,
    below,
    intercept,
}: {
    slug: string;
    field: string;
    label: string;
    description: ReactNode;
    checked: boolean;
    badge?: ReactNode;
    disabled?: boolean;
    below?: ReactNode;
    intercept?: (next: boolean, commit: (extra?: Payload) => void) => boolean;
}) {
    const { save, isSaving } = useInstantSave(slug);
    const [value, setValue] = useState(checked);

    useEffect(() => setValue(checked), [checked]);

    const commit = (next: boolean, extra: Payload = {}) => {
        setValue(next);
        save({ [field]: next, ...extra }, () => setValue(!next));
    };

    const onCheckedChange = (next: boolean) => {
        if (intercept?.(next, (extra) => commit(next, extra))) {
            return;
        }

        commit(next);
    };

    return (
        <SettingRow label={label} description={description} badge={badge} below={below}>
            <Toggle checked={value} onCheckedChange={onCheckedChange} label={label} disabled={disabled || isSaving} />
        </SettingRow>
    );
}

/** A select row that saves on change. An empty string is sent as null. */
export function InstantSelectRow({
    slug,
    field,
    label,
    description,
    value,
    options,
}: {
    slug: string;
    field: string;
    label: string;
    description: ReactNode;
    value: string;
    options: { value: string; label: string }[];
}) {
    const { save, isSaving } = useInstantSave(slug);
    const [current, setCurrent] = useState(value);

    useEffect(() => setCurrent(value), [value]);

    const onChange = (next: string | null) => {
        const previous = current;
        const nextValue = next ?? '';

        setCurrent(nextValue);
        save({ [field]: nextValue === '' ? null : nextValue }, () => setCurrent(previous));
    };

    return (
        <SettingRow label={label} description={description}>
            <Select className="w-full min-w-[220px]" aria-label={label} options={options} value={current} onChange={onChange} disabled={isSaving} />
        </SettingRow>
    );
}
