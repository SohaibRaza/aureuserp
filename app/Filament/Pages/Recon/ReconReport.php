<?php

namespace App\Filament\Pages\Recon;

use App\Models\Recon\BankReconciliation;
use App\Models\Recon\ReconMatch;
use App\Models\Recon\ReconMatchLine;
use App\Models\Recon\StatementLine as BankStatementLine;
use App\Services\Recon\FinalizationService;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class ReconReport extends Page
{
    use HasPageShield;

    protected string $view = 'filament.pages.recon.report';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static ?string $slug = 'recon-report';

    protected static bool $shouldRegisterNavigation = false;

    protected static function getPagePermission(): ?string
    {
        return 'page_filament_recon_report';
    }

    public ?BankReconciliation $record = null;

    public function mount(): void
    {
        $this->record = BankReconciliation::query()
            ->where('company_id', current_company_id())
            ->findOrFail(request()->query('record'));
        Gate::authorize('view', $this->record);
    }

    public function getTitle(): string
    {
        return 'Reconciliation Report — '.$this->record?->name;
    }

    public function getReportProperty(): array
    {
        $svc = app(FinalizationService::class);

        if ($this->record->status !== 'reopened' && ($snapshot = $this->record->snapshots()->latest('version')->first())) {
            $payload = $snapshot->payload;
            $lines = collect($payload['statement_lines'])->map(function (array $line): object {
                $line['transaction_date'] = isset($line['transaction_date']) ? Carbon::parse($line['transaction_date']) : null;

                return (object) $line;
            });
            $matches = collect($payload['matches'] ?? [])->map(function (array $match): object {
                $match['confirmed_at'] = isset($match['confirmed_at']) ? Carbon::parse($match['confirmed_at']) : null;

                return (object) $match;
            });

            return [
                'reconciled'        => $lines->where('is_reconciled', true)->values(),
                'unreconciled'      => $lines->where('is_reconciled', false)->values(),
                'reconciling_items' => collect($payload['reconciling_items'])->map(fn (array $item) => (object) $item),
                'adjustments'       => collect($payload['adjustments'])->map(fn (array $adjustment) => (object) $adjustment),
                'difference'        => $payload['calculation'],
                'status'            => $this->record->status,
                'snapshot_version'  => $snapshot->version,
                'snapshot_hash'     => $snapshot->payload_hash,
                'matches'           => $matches,
            ];
        }

        $statementLines = BankStatementLine::where('statement_id', $this->record->statement_id)->orderBy('id')->get();

        $matchedIds = ReconMatchLine::whereIn('match_id', function ($q) {
            $q->select('id')->from('recon_matches')
                ->where('reconciliation_id', $this->record->id)
                ->where('status', 'confirmed');
        })
            ->where('lineable_type', ReconMatchLine::TYPE_STATEMENT_LINE)
            ->pluck('lineable_id')
            ->all();

        $reconciled = $statementLines->filter(fn ($l) => $l->is_reconciled || in_array($l->id, $matchedIds))->values();
        $unreconciled = $statementLines->reject(fn ($l) => $l->is_reconciled || in_array($l->id, $matchedIds))->values();

        $reconcilingItems = $this->record->reconcilingItems()
            ->whereIn('status', ['open', 'carried_forward'])
            ->with(['moveLine', 'statementLine'])
            ->get();

        $diff = $svc->computeDifference($this->record);

        return [
            'reconciled'        => $reconciled,
            'unreconciled'      => $unreconciled,
            'reconciling_items' => $reconcilingItems,
            'adjustments'       => $this->record->adjustments()->get(),
            'difference'        => $diff,
            'status'            => $this->record->status,
            'snapshot_version'  => null,
            'snapshot_hash'     => null,
            'matches'           => $this->confirmedMatches(),
        ];
    }

    /**
     * U13: Confirmed matches with accounting provenance for reporting.
     */
    private function confirmedMatches(): Collection
    {
        return ReconMatch::query()
            ->where('reconciliation_id', $this->record->id)
            ->where('company_id', $this->record->company_id)
            ->where('status', 'confirmed')
            ->orderBy('id')
            ->get();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label('Export CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->action(function () {
                    $r = $this->report;
                    $rec = $this->record;

                    $csv = fopen('php://temp', 'r+');

                    fputcsv($csv, ['Reconciliation', $rec->name, 'Status', $r['status']]);
                    fputcsv($csv, []);

                    fputcsv($csv, ['RECONCILED BANK ITEMS']);
                    fputcsv($csv, ['Date', 'Partner', 'Reference', 'Amount']);
                    foreach ($r['reconciled'] as $l) {
                        fputcsv($csv, [$l->transaction_date?->toDateString(), $l->partner_name, $l->payment_reference, number_format((float) $l->amount, 2, '.', '')]);
                    }

                    fputcsv($csv, []);
                    fputcsv($csv, ['UNRECONCILED BANK ITEMS']);
                    fputcsv($csv, ['Date', 'Partner', 'Reference', 'Amount']);
                    foreach ($r['unreconciled'] as $l) {
                        fputcsv($csv, [$l->transaction_date?->toDateString(), $l->partner_name, $l->payment_reference, number_format((float) $l->amount, 2, '.', '')]);
                    }

                    fputcsv($csv, []);
                    fputcsv($csv, ['OPEN RECONCILING ITEMS']);
                    fputcsv($csv, ['Classification', 'Origin period', 'Status', 'Amount']);
                    foreach ($r['reconciling_items'] as $item) {
                        $classification = $item->classification instanceof \BackedEnum ? $item->classification->value : $item->classification;
                        $status = $item->status instanceof \BackedEnum ? $item->status->value : $item->status;
                        $origin = $item->origin_period_end ? Carbon::parse($item->origin_period_end)->toDateString() : null;
                        fputcsv($csv, [$classification, $origin, $status, number_format((float) $item->amount, 2, '.', '')]);
                    }

                    fputcsv($csv, []);
                    fputcsv($csv, ['ADJUSTMENTS']);
                    fputcsv($csv, ['Type', 'Amount', 'Notes']);
                    foreach ($r['adjustments'] as $adj) {
                        fputcsv($csv, [$adj->type, number_format((float) $adj->amount, 2, '.', ''), $adj->notes]);
                    }

                    fputcsv($csv, []);
                    fputcsv($csv, ['DIFFERENCE OF THE DAY', number_format($r['difference']['difference'], 2, '.', '')]);

                    rewind($csv);
                    $contents = stream_get_contents($csv);

                    return response()->streamDownload(function () use ($contents) {
                        echo $contents;
                    }, 'recon-'.$rec->id.'.csv');
                }),
        ];
    }
}
