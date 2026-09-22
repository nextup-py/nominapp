<?php

namespace App\Filament\Resources\LoanResource\Pages;

use App\Exports\LoansExport;
use App\Filament\Pages\LoanReport;
use App\Filament\Resources\DisbursementBatchResource;
use App\Filament\Resources\LoanResource;
use App\Models\Company;
use App\Models\DisbursementBatch;
use App\Models\Loan;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Get;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Maatwebsite\Excel\Facades\Excel;

class ListLoans extends ListRecords
{
    protected static string $resource = LoanResource::class;

    /** @var array<string, mixed>|null Cache de conteos para badges de tabs. */
    protected ?array $loanCounts = null;

    /**
     * Obtiene conteos por estado para los badges de tabs (cacheado para evitar N+1).
     *
     * @return array<string, mixed>
     */
    protected function getLoanCounts(): array
    {
        if ($this->loanCounts === null) {
            $counts = Loan::query()
                ->selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status')
                ->toArray();

            $this->loanCounts = [
                'total' => array_sum($counts),
                'by_status' => $counts,
            ];
        }

        return $this->loanCounts;
    }

    /**
     * Define las acciones del encabezado de la página.
     *
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('go_to_report')
                ->label('Ver Reporte')
                ->icon('heroicon-o-chart-bar')
                ->color('gray')
                ->url(LoanReport::getUrl()),

            Action::make('create_loan_batch')
                ->label('Crear Lote Bancario')
                ->icon('heroicon-o-building-library')
                ->color('info')
                ->mountUsing(function (Form $form, Action $action) {
                    $hasLoans = Loan::query()
                        ->where('status', 'approved')
                        ->where('payment_method', 'transfer')
                        ->whereNull('disbursement_batch_id')
                        ->exists();

                    if (! $hasLoans) {
                        Notification::make()
                            ->warning()
                            ->title('Sin préstamos disponibles')
                            ->body('No hay préstamos aprobados por transferencia bancaria sin lote asignado.')
                            ->send();

                        $action->halt();

                        return;
                    }

                    $form->fill(['fecha_credito' => today()->format('Y-m-d')]);
                })
                ->modalHeading('Crear lote bancario de préstamos')
                ->modalSubmitActionLabel('Crear lote')
                ->form(function () {
                    $companyOptions = Company::query()
                        ->whereHas('branches.employees.loans', fn ($q) => $q
                            ->where('status', 'approved')
                            ->where('payment_method', 'transfer')
                            ->whereNull('disbursement_batch_id')
                        )
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->toArray();

                    $isSingleCompany = count($companyOptions) <= 1;
                    $defaultCompanyId = $isSingleCompany && count($companyOptions) === 1
                        ? array_key_first($companyOptions)
                        : null;

                    return [
                        Select::make('company_id')
                            ->label('Empresa')
                            ->options($companyOptions)
                            ->required()
                            ->native(false)
                            ->live()
                            ->visible(fn () => ! $isSingleCompany)
                            ->default($defaultCompanyId)
                            ->helperText('Solo se muestran empresas con préstamos aprobados por transferencia sin lote.'),

                        Placeholder::make('missing_accounts_warning')
                            ->label('')
                            ->content(function (Get $get) use ($isSingleCompany, $defaultCompanyId) {
                                $companyId = $isSingleCompany ? $defaultCompanyId : $get('company_id');

                                if (! $companyId) {
                                    return '';
                                }

                                $missing = Loan::query()
                                    ->where('status', 'approved')
                                    ->where('payment_method', 'transfer')
                                    ->whereNull('disbursement_batch_id')
                                    ->whereHas('employee.branch', fn ($q) => $q->where('company_id', $companyId))
                                    ->whereDoesntHave('employee.bankAccounts', fn ($q) => $q->where('is_primary', true)->where('status', 'active'))
                                    ->with('employee')
                                    ->get();

                                if ($missing->isEmpty()) {
                                    return new HtmlString(
                                        '<div class="rounded-lg bg-success-50 border border-success-200 p-3 text-sm text-success-700">'
                                        .'✓ Todos los empleados tienen cuenta bancaria activa registrada.'
                                        .'</div>'
                                    );
                                }

                                $count = $missing->count();
                                $label = $count === 1 ? 'empleado' : 'empleados';
                                $names = $missing->map(fn ($l) => $l->employee->full_name)->join(', ');

                                return new HtmlString(
                                    '<div class="rounded-lg bg-danger-50 border border-danger-200 p-4 text-sm">'
                                    .'<p class="font-semibold text-danger-700 mb-1">⚠ '.$count.' '.$label.' sin cuenta bancaria activa</p>'
                                    .'<p class="text-danger-600 mb-2">No se puede crear el lote hasta que todos los empleados tengan cuenta bancaria registrada.</p>'
                                    .'<p class="text-danger-700">'.$names.'</p>'
                                    .'</div>'
                                );
                            })
                            ->visible(fn (Get $get) => $isSingleCompany ? (bool) $defaultCompanyId : (bool) $get('company_id')),

                        DatePicker::make('fecha_credito')
                            ->label('Fecha de acreditación')
                            ->required()
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->closeOnDateSelection()
                            ->helperText('Fecha en que el banco acreditará los fondos a los empleados.'),

                        Textarea::make('notes')
                            ->label('Notas')
                            ->placeholder('Observaciones opcionales...')
                            ->rows(2),
                    ];
                })
                ->action(function (array $data) {
                    $companyOptions = Company::query()
                        ->whereHas('branches.employees.loans', fn ($q) => $q
                            ->where('status', 'approved')
                            ->where('payment_method', 'transfer')
                            ->whereNull('disbursement_batch_id')
                        )
                        ->pluck('id')
                        ->toArray();

                    $companyId = $data['company_id'] ?? (count($companyOptions) === 1 ? $companyOptions[0] : null);

                    if (! $companyId) {
                        Notification::make()->danger()->title('Seleccione una empresa')->send();

                        return;
                    }

                    $missingAccounts = Loan::query()
                        ->where('status', 'approved')
                        ->where('payment_method', 'transfer')
                        ->whereNull('disbursement_batch_id')
                        ->whereHas('employee.branch', fn ($q) => $q->where('company_id', $companyId))
                        ->whereDoesntHave('employee.bankAccounts', fn ($q) => $q->where('is_primary', true)->where('status', 'active'))
                        ->count();

                    if ($missingAccounts > 0) {
                        Notification::make()
                            ->danger()
                            ->title('Hay empleados sin cuenta bancaria')
                            ->body('Registre las cuentas bancarias faltantes antes de crear el lote.')
                            ->send();

                        return;
                    }

                    $batch = DisbursementBatch::create([
                        'type' => 'loan',
                        'company_id' => $companyId,
                        'fecha_credito' => $data['fecha_credito'],
                        'notes' => $data['notes'] ?? null,
                        'status' => 'pending',
                        'created_by_id' => Auth::id(),
                    ]);

                    Loan::query()
                        ->where('status', 'approved')
                        ->where('payment_method', 'transfer')
                        ->whereNull('disbursement_batch_id')
                        ->whereHas('employee.branch', fn ($q) => $q->where('company_id', $companyId))
                        ->update(['disbursement_batch_id' => $batch->id]);

                    Notification::make()
                        ->success()
                        ->title('Lote bancario creado')
                        ->body('El lote de préstamos fue creado. Descargá el TXT y confirmá el resultado bancario.')
                        ->send();

                    $this->redirect(DisbursementBatchResource::getUrl('view', ['record' => $batch]));
                }),

            Action::make('export')
                ->label('Exportar Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('info')
                ->visible(fn () => auth()->user()->can('export_loan'))
                ->requiresConfirmation()
                ->modalHeading('Exportar Préstamos')
                ->modalDescription('Se exportarán todos los préstamos a un archivo Excel.')
                ->modalSubmitActionLabel('Exportar')
                ->action(function () {
                    Notification::make()
                        ->title('Exportación iniciada')
                        ->body('El archivo se descargará en un momento.')
                        ->success()
                        ->send();

                    return Excel::download(
                        new LoansExport(null),
                        'prestamos_'.now()->format('Y_m_d_H_i_s').'.xlsx'
                    );
                }),

            CreateAction::make()
                ->label('Nuevo Préstamo')
                ->icon('heroicon-o-plus'),
        ];
    }

    /**
     * Define las pestañas de filtrado por estado.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $counts = $this->getLoanCounts();
        $byStatus = $counts['by_status'];

        return [
            'all' => Tab::make('Todos')
                ->badge($counts['total']),

            'pending' => Tab::make('Pendientes')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'pending'))
                ->badge($byStatus['pending'] ?? 0)
                ->badgeColor('warning'),

            'approved' => Tab::make('Aprobados')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'approved'))
                ->badge($byStatus['approved'] ?? 0)
                ->badgeColor('info'),

            'disbursed' => Tab::make('Desembolsados')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'disbursed'))
                ->badge($byStatus['disbursed'] ?? 0)
                ->badgeColor('primary'),

            'paid' => Tab::make('Pagados')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'paid'))
                ->badge($byStatus['paid'] ?? 0)
                ->badgeColor('success'),

            'rejected' => Tab::make('Rechazados')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'rejected'))
                ->badge($byStatus['rejected'] ?? 0)
                ->badgeColor('danger'),

            'cancelled' => Tab::make('Cancelados')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'cancelled'))
                ->badge($byStatus['cancelled'] ?? 0)
                ->badgeColor('gray'),
        ];
    }

    /**
     * Define la pestaña activa por defecto
     */
    public function getDefaultActiveTab(): string|int|null
    {
        return 'all';
    }
}
