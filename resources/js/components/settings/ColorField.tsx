import { Field, TextInput } from '@geocodio/console-ui';

export function ColorField({
    name,
    label,
    value,
    onChange,
    error,
}: {
    name: string;
    label: string;
    value: string;
    onChange: (value: string) => void;
    error?: string;
}) {
    return (
        <Field label={label} error={error}>
            <div className="relative">
                <span
                    className="absolute top-1/2 left-2 h-4 w-4 -translate-y-1/2 rounded-chip border border-hair"
                    style={{ background: value || '#ffffff' }}
                />
                <TextInput name={name} className="pl-8 font-mono text-[12px]" value={value} onChange={(e) => onChange(e.target.value)} />
            </div>
        </Field>
    );
}
