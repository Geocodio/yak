<?php

namespace App\Http\Resources;

use App\Services\Settings\EditableSettings;

/**
 * The Limits page's settings, grouped as the page shows them. Mirrors resources/js/types/limits.ts.
 */
final class LimitsData
{
    /**
     * @return list<array{
     *     id: string,
     *     title: string,
     *     description: string|null,
     *     settings: list<array{id: string, label: string, help: string|null, unit: string|null, type: 'int'|'decimal'|'bool', min: int|float|null, max: int|float|null, default: int|float|bool, value: int|float|bool, isOverridden: bool}>
     * }>
     */
    public static function groups(EditableSettings $settings): array
    {
        $groups = [];

        foreach (EditableSettings::GROUP_ORDER as $groupId) {
            $rows = [];

            foreach ($settings->definitions() as $definition) {
                if ($definition['group'] !== $groupId) {
                    continue;
                }

                $rows[] = [
                    'id' => $definition['id'],
                    'label' => $definition['label'],
                    'help' => $definition['help'],
                    'unit' => $definition['unit'],
                    'type' => $definition['type'],
                    'min' => $definition['min'],
                    'max' => $definition['max'],
                    'default' => $settings->defaultValue($definition),
                    'value' => $settings->currentValue($definition),
                    'isOverridden' => $settings->isOverridden($definition),
                ];
            }

            $groups[] = ['id' => $groupId, 'title' => EditableSettings::GROUPS[$groupId]['title'], 'description' => EditableSettings::GROUPS[$groupId]['description'], 'settings' => $rows];
        }

        return $groups;
    }
}
