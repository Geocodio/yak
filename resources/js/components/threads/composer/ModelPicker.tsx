import { Menu } from '@geocodio/console-ui';
import { ChevronDown } from 'lucide-react';

export function ModelPicker({ models, value, onChange, disabled }: { models: string[]; value: string | null; onChange: (model: string) => void; disabled?: boolean }) {
    if (models.length === 0) {
        return null;
    }

    return (
        <Menu
            trigger={
                <span className="flex items-center gap-1 text-[12px] text-muted">
                    {value ?? 'Model'} <ChevronDown size={12} aria-hidden="true" />
                </span>
            }
            aria-label="Model"
            disabled={disabled}
            align="start"
            items={models.map((model) => ({
                key: model,
                label: model,
                checked: model === value,
                onSelect: () => onChange(model),
                testId: `composer-model-${model}`,
            }))}
            data-testid="composer-model"
        />
    );
}
