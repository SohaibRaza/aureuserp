<?php

namespace Webkul\Accounting\Filament\Clusters\Configuration\Resources\ReconciliationRuleResource\Pages;

use App\Services\Recon\ReconciliationRuleEngine;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Throwable;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\ReconciliationRuleResource;

class CreateReconciliationRule extends CreateRecord
{
    protected static string $resource = ReconciliationRuleResource::class;

    protected function getCreatedNotification(): Notification
    {
        return Notification::make()
            ->success()
            ->title('Reconciliation rule created')
            ->body('The rule is active and will be used in future matching runs.');
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function handleRecordCreation(array $data): Model
    {
        $data['company_id'] = current_company_id();
        $data['approval_threshold'] = ($data['automation_safety'] ?? 'balanced') === 'strict' ? 18 : 14;
        $conditionRows = collect($data['condition_rules'] ?? [])
            ->map(fn (array $condition): array => [...$condition, 'value' => $condition['partner_value'] ?? ($condition['value'] ?? null)])
            ->filter(fn (array $condition): bool => filled($condition['field'] ?? null) && filled($condition['value'] ?? null))
            ->values();
        if ($conditionRows->pluck('field')->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['condition_rules' => 'Use each transaction detail only once per rule.']);
        }
        $data['conditions'] = $conditionRows->mapWithKeys(fn (array $condition): array => [(string) $condition['field'] => $condition['value']])->all();
        unset($data['automation_safety'], $data['condition_rules']);

        try {
            return app(ReconciliationRuleEngine::class)->createVersion($data);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Notification::make()
                ->danger()
                ->title('Rule could not be created')
                ->body('Please review the values and try again. No changes were saved.')
                ->send();

            throw $exception;
        }
    }
}
