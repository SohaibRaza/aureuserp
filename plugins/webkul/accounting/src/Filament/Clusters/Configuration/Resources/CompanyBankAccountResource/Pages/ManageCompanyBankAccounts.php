<?php

namespace Webkul\Accounting\Filament\Clusters\Configuration\Resources\CompanyBankAccountResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Validation\ValidationException;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\CompanyBankAccountResource;

class ManageCompanyBankAccounts extends ManageRecords
{
    protected static string $resource = CompanyBankAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->mutateDataUsing(function (array $data): array {
                    $company = current_company();

                    if (! $company) {
                        throw ValidationException::withMessages([
                            'company_id' => 'Select an active company before creating a bank account.',
                        ]);
                    }

                    $data['company_id'] = $company->id;
                    $data['partner_id'] = null;

                    return $data;
                }),
        ];
    }
}
