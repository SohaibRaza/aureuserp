<?php

namespace Shafique\Logistics\Filament\Resources\EvidenceResource\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Shafique\Logistics\Filament\Resources\EvidenceResource;
use Shafique\Logistics\Models\Evidence;
use Shafique\Logistics\Models\EvidenceRetentionPolicy;
use Shafique\Logistics\Services\EvidenceStore;
use Webkul\PluginManager\Models\Plugin;
use Webkul\Security\Models\User;
use Webkul\Support\Services\CompanyContext;
use Webkul\TableViews\Filament\Components\PresetView;
use Webkul\TableViews\Filament\Concerns\HasTableViews;

class ListEvidence extends ListRecords
{
    use HasTableViews;

    protected static string $resource = EvidenceResource::class;

    #[Locked]
    public int $companyId;

    public function mount(): void
    {
        parent::mount();
        abort_unless(EvidenceResource::canViewAny(), 403);
        $this->companyId = app(CompanyContext::class)->currentId() ?? abort(403);
        $this->assertCompanyContext();
    }

    public function getSubheading(): ?string
    {
        $this->assertCompanyContext();
        $policy = $this->latestPolicy();

        if (! $policy) {
            return __('logistics::filament/resources/evidence.retention.no-policy');
        }

        return __('logistics::filament/resources/evidence.retention.current-policy', [
            'days'     => $policy->retention_days,
            'approved' => $policy->approved_at->toDateTimeString(),
        ]);
    }

    public function getPresetTableViews(): array
    {
        return [
            'all' => PresetView::make(__('logistics::filament/resources/evidence.table.views.all'))
                ->favorite()
                ->setAsDefault()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('company_id', $this->companyId)),
        ];
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->recordActions([
                Action::make('download')
                    ->label(__('logistics::filament/resources/evidence.actions.download.label'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (): bool => auth()->user()?->can('view_logistics_evidence') ?? false)
                    ->action(function (Evidence $record) {
                        $user = $this->assertCompanyContext('view_logistics_evidence');
                        abort_unless((int) $record->company_id === $this->companyId, 404);

                        return app(EvidenceStore::class)->download($user, (int) $record->getKey());
                    }),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('uploadEvidence')
                ->label(__('logistics::filament/resources/evidence.actions.upload.label'))
                ->icon('heroicon-o-arrow-up-tray')
                ->visible(fn (): bool => auth()->user()?->can('create_logistics_evidence') ?? false)
                ->disabled(fn (): bool => $this->latestPolicy() === null)
                ->tooltip(fn (): ?string => $this->latestPolicy() === null
                    ? __('logistics::filament/resources/evidence.retention.upload-blocked')
                    : null)
                ->modalDescription(__('logistics::filament/resources/evidence.actions.upload.description'))
                ->schema([
                    FileUpload::make('file')
                        ->label(__('logistics::filament/resources/evidence.form.file.label'))
                        ->required()
                        ->acceptedFileTypes(['application/pdf', 'image/png', 'image/jpeg'])
                        ->storeFiles(false),
                    Select::make('purpose')
                        ->label(__('logistics::filament/resources/evidence.form.purpose.label'))
                        ->options(static::purposeOptions())
                        ->required(),
                    Hidden::make('request_key')
                        ->default(fn (): string => (string) Str::uuid())
                        ->rules(['required', 'uuid']),
                ])
                ->action(function (array $data): void {
                    $user = $this->assertCompanyContext('create_logistics_evidence');

                    if ($this->latestPolicy() === null) {
                        throw ValidationException::withMessages([
                            'file' => __('logistics::filament/resources/evidence.retention.upload-blocked'),
                        ]);
                    }

                    if (! ($data['file'] ?? null) instanceof TemporaryUploadedFile
                        || ! in_array($data['purpose'] ?? null, array_keys(static::purposeOptions()), true)
                        || ! Str::isUuid((string) ($data['request_key'] ?? ''))) {
                        throw ValidationException::withMessages([
                            'file' => __('logistics::filament/resources/evidence.form.invalid-upload'),
                        ]);
                    }

                    app(EvidenceStore::class)->store(
                        $user,
                        $this->companyId,
                        $data['file'],
                        $data['purpose'],
                        $data['request_key'],
                    );
                })
                ->successNotificationTitle(__('logistics::filament/resources/evidence.actions.upload.success')),
            Action::make('approveRetentionPolicy')
                ->label(__('logistics::filament/resources/evidence.actions.approve-retention.label'))
                ->icon('heroicon-o-shield-check')
                ->visible(fn (): bool => auth()->user()?->can('manage_logistics_evidence_retention') ?? false)
                ->modalDescription(__('logistics::filament/resources/evidence.actions.approve-retention.description'))
                ->schema([
                    TextInput::make('retention_days')
                        ->label(__('logistics::filament/resources/evidence.form.retention-days.label'))
                        ->integer()
                        ->minValue(1)
                        ->default(fn () => config('logistics.evidence.recommended_retention_days'))
                        ->required(),
                    Textarea::make('reason')
                        ->label(__('logistics::filament/resources/evidence.form.reason.label'))
                        ->required()
                        ->maxLength(1000),
                ])
                ->action(function (array $data): void {
                    $user = $this->assertCompanyContext('manage_logistics_evidence_retention');

                    app(EvidenceStore::class)->approveRetentionPolicy(
                        $user,
                        $this->companyId,
                        (int) $data['retention_days'],
                        $data['reason'],
                    );
                })
                ->successNotificationTitle(__('logistics::filament/resources/evidence.actions.approve-retention.success')),
        ];
    }

    /** @return array<string, string> */
    private static function purposeOptions(): array
    {
        return collect(['load_verification', 'loading_photo', 'delivery_receipt', 'proof_of_delivery'])
            ->mapWithKeys(fn (string $purpose): array => [$purpose => __("logistics::filament/resources/evidence.purposes.{$purpose}")])
            ->all();
    }

    private function latestPolicy(): ?EvidenceRetentionPolicy
    {
        if (! Plugin::query()->where('name', 'logistics')->where('is_installed', true)->exists()) {
            return null;
        }

        return EvidenceRetentionPolicy::query()
            ->where('company_id', $this->companyId)
            ->where('evidence_class', 'operational')
            ->whereNotNull('approved_at')
            ->where('retention_days', '>', 0)
            ->orderByDesc('revision')
            ->first();
    }

    private function assertCompanyContext(?string $permission = null): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User && Plugin::query()->where('name', 'logistics')->where('is_installed', true)->exists(), 403);
        $user = User::withoutGlobalScopes()->findOrFail($user->id);
        abort_unless($user->is_active && ! $user->trashed(), 403);
        abort_unless(app(CompanyContext::class)->currentId() === $this->companyId, 403);
        abort_unless(EvidenceResource::userCanAccessCompany($user, $this->companyId), 403);

        if ($permission !== null) {
            abort_unless($user->can($permission), 403);
        }

        return $user;
    }
}
