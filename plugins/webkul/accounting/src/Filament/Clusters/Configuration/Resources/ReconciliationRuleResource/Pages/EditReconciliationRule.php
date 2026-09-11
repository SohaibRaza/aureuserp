<?php

namespace Webkul\Accounting\Filament\Clusters\Configuration\Resources\ReconciliationRuleResource\Pages;

use Filament\Resources\Pages\EditRecord;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\ReconciliationRuleResource;

class EditReconciliationRule extends EditRecord
{
    protected static string $resource = ReconciliationRuleResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['automation_safety'] = (float) ($data['approval_threshold'] ?? 14) >= 18 ? 'strict' : 'balanced';
        $data['condition_rules'] = collect($data['conditions'] ?? [])
            ->map(fn (mixed $value, string $field): array => [
                'field'         => $field,
                'value'         => $value,
                'partner_value' => $field === 'partner_id' ? $value : null,
            ])->values()->all();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $conditionRows = collect($data['condition_rules'] ?? [])
            ->map(fn (array $condition): array => [...$condition, 'value' => $condition['partner_value'] ?? ($condition['value'] ?? null)])
            ->filter(fn (array $condition): bool => filled($condition['field'] ?? null) && filled($condition['value'] ?? null));

        $data['conditions'] = $conditionRows->mapWithKeys(fn (array $condition): array => [(string) $condition['field'] => $condition['value']])->all();
        $data['approval_threshold'] = ($data['automation_safety'] ?? 'balanced') === 'strict' ? 18 : 14;
        unset($data['automation_safety'], $data['condition_rules']);

        return $data;
    }
}
