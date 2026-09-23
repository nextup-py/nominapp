<?php

namespace App\Filament\Resources\PayrollPeriodResource\RelationManagers;

use App\Filament\Resources\PayrollResource;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\PayrollItem;
use App\Models\Position;
use App\Services\PayrollPDFGenerator;
use App\Services\PayrollService;
use App\Settings\PayrollSettings;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select as FormSelect;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Infolists\Components\Group;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ActionGroup as TableActionGroup;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group as TableGroup;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Excel;
use pxlrbt\FilamentExcel\Actions\Tables\ExportBulkAction;
use pxlrbt\FilamentExcel\Exports\ExcelExport;

/** Muestra y gestiona los recibos de nómina de una planilla. */
class PayrollsRelationManager extends RelationManager
{
    protected static string $relationship = 'payrolls';

    protected static ?string $title = 'Recibos de Nómina';

    protected static ?string $modelLabel = 'recibo';

    protected static ?string $pluralModelLabel = 'recibos';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitle(fn (Payroll $record): string => "Recibo de {$record->employee->full_name}")
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['employee.activeContract.position', 'approvedBy']))
            ->columns([
                TextColumn::make('employee.ci')
                    ->label('CI')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->copyMessage('CI copiado')
                    ->weight('bold'),

                TextColumn::make('employee.full_name')
                    ->label('Empleado')
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas(
                        'employee',
                        fn ($q) => $q->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                    ))
                    ->sortable()
                    ->wrap(),

                TextColumn::make('employee.activeContract.position.name')
                    ->label('Cargo')
                    ->badge()
                    ->color('info')
                    ->toggleable(),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn ($state) => Payroll::getStatusColors()[$state] ?? 'gray')
                    ->formatStateUsing(fn ($state) => Payroll::getStatusLabels()[$state] ?? $state)
                    ->sortable(),

                TextColumn::make('payment_method')
                    ->label('Método')
                    ->badge()
                    ->color(fn (?string $state) => $state ? (Payroll::getPaymentMethodColors()[$state] ?? 'gray') : 'gray')
                    ->icon(fn (?string $state) => $state ? (Payroll::getPaymentMethodIcons()[$state] ?? null) : null)
                    ->formatStateUsing(fn (?string $state) => $state ? (Payroll::getPaymentMethodLabels()[$state] ?? $state) : '—')
                    ->sortable(),

                TextColumn::make('base_salary')
                    ->label('Salario Base / Jornal')
                    ->money('PYG', locale: 'es_PY')
                    ->sortable()
                    ->description(fn (Payroll $record): ?string => $record->employee->employment_type === 'day_laborer'
                        ? 'Jornal'
                        : null)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('total_perceptions')
                    ->label('Percepciones')
                    ->money('PYG', locale: 'es_PY')
                    ->sortable()
                    ->color('success')
                    ->summarize([
                        Sum::make()
                            ->money('PYG', locale: 'es_PY')
                            ->label('Total'),
                    ])
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('total_deductions')
                    ->label('Deducciones')
                    ->money('PYG', locale: 'es_PY')
                    ->sortable()
                    ->color('danger')
                    ->summarize([
                        Sum::make()
                            ->money('PYG', locale: 'es_PY')
                            ->label('Total'),
                    ])
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('gross_salary')
                    ->label('Salario Bruto')
                    ->money('PYG', locale: 'es_PY')
                    ->sortable()
                    ->summarize([
                        Sum::make()
                            ->money('PYG', locale: 'es_PY')
                            ->label('Total'),
                    ])
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('net_salary')
                    ->label('Salario Neto')
                    ->money('PYG', locale: 'es_PY')
                    ->sortable()
                    ->weight('bold')
                    ->color('success')
                    ->summarize([
                        Sum::make()
                            ->money('PYG', locale: 'es_PY')
                            ->label('Total a Pagar'),
                    ]),

                TextColumn::make('generated_at')
                    ->label('Generado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(Payroll::getStatusLabels())
                    ->native(false),

                SelectFilter::make('payment_method')
                    ->label('Método de pago')
                    ->options(Payroll::getPaymentMethodOptions())
                    ->native(false),

                SelectFilter::make('department_id')
                    ->label('Departamento')
                    ->options(fn () => Department::orderBy('name')->pluck('name', 'id'))
                    ->query(function (Builder $query, array $data): void {
                        if (filled($data['value'])) {
                            $query->whereHas(
                                'employee.activeContract.position',
                                fn (Builder $q) => $q->where('department_id', $data['value'])
                            );
                        }
                    })
                    ->searchable()
                    ->preload()
                    ->native(false),

                SelectFilter::make('branch_id')
                    ->label('Sucursal')
                    ->options(fn () => Branch::orderBy('name')->pluck('name', 'id'))
                    ->query(function (Builder $query, array $data): void {
                        if (filled($data['value'])) {
                            $query->whereHas(
                                'employee',
                                fn (Builder $q) => $q->where('branch_id', $data['value'])
                            );
                        }
                    })
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->visible(fn () => Branch::count() > 1),

                SelectFilter::make('position')
                    ->label('Cargo')
                    ->options(fn () => Position::orderBy('name')->pluck('name', 'id'))
                    ->query(function (Builder $query, array $data): void {
                        if (filled($data['value'])) {
                            $query->whereHas(
                                'employee.activeContract',
                                fn (Builder $q) => $q->where('position_id', $data['value'])
                            );
                        }
                    })
                    ->searchable()
                    ->preload()
                    ->native(false),
            ])
            ->headerActions([

                Action::make('generate_for_employee')
                    ->label('Agregar Recibo')
                    ->icon('heroicon-o-user-plus')
                    ->color('primary')
                    ->mountUsing(function (Form $form) {
                        $period = $this->getOwnerRecord();
                        $existingIds = $period->payrolls()->pluck('employee_id');

                        $hasAvailable = Employee::where('status', 'active')
                            ->whereHas('activeContract', fn ($q) => $q->where('payroll_type', $period->frequency)->whereNotNull('salary'))
                            ->whereNotIn('id', $existingIds)
                            ->exists();

                        $form->fill(['has_available' => $hasAvailable]);
                    })
                    ->modalHeading('Agregar Recibo para Empleado')
                    ->modalSubmitActionLabel('Generar')
                    ->form(function () {
                        $period = $this->getOwnerRecord();
                        $existingIds = $period->payrolls()->pluck('employee_id');

                        return [
                            Hidden::make('has_available'),

                            Placeholder::make('no_employees_notice')
                                ->label('')
                                ->content('Todos los empleados activos ya tienen recibo en esta planilla.')
                                ->visible(fn (Get $get) => ! $get('has_available')),

                            FormSelect::make('employee_id')
                                ->label('Empleado')
                                ->options(
                                    Employee::where('status', 'active')
                                        ->whereHas('activeContract', fn ($q) => $q->where('payroll_type', $period->frequency)->whereNotNull('salary'))
                                        ->whereNotIn('id', $existingIds)
                                        ->get()
                                        ->mapWithKeys(fn ($e) => [$e->id => "{$e->full_name} — CI: {$e->ci}"])
                                        ->toArray()
                                )
                                ->searchable()
                                ->native(false)
                                ->required(fn (Get $get) => (bool) $get('has_available'))
                                ->visible(fn (Get $get) => (bool) $get('has_available'))
                                ->placeholder('Seleccione un empleado sin recibo en esta planilla'),
                        ];
                    })
                    ->action(function (array $data, PayrollService $payrollService, Action $action) {
                        if (empty($data['employee_id'])) {
                            $action->halt();

                            return;
                        }

                        $period = $this->getOwnerRecord();

                        try {
                            $employee = Employee::findOrFail($data['employee_id']);
                            $payrollService->generateForEmployee($employee, $period);

                            if ($period->status === 'draft') {
                                $period->update(['status' => 'processing']);
                            }

                            Notification::make()
                                ->success()
                                ->title('Recibo generado')
                                ->body("El recibo de {$employee->full_name} fue generado exitosamente.")
                                ->send();
                        } catch (\InvalidArgumentException $e) {
                            Notification::make()
                                ->danger()
                                ->title('No se pudo generar el recibo')
                                ->body($e->getMessage())
                                ->persistent()
                                ->send();
                        }
                    })
                    ->visible(fn () => $this->getOwnerRecord()->status !== 'closed'),
            ])
            ->actions([
                Action::make('view')
                    ->label('Ver')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->url(fn (Payroll $record) => PayrollResource::getUrl('view', ['record' => $record]))
                    ->openUrlInNewTab(),

                Action::make('download_pdf')
                    ->label('PDF')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('info')
                    ->form([
                        Radio::make('mode')
                            ->label('Formato')
                            ->options([
                                'print' => 'Para imprimir — 2 copias en hoja horizontal',
                                'employee' => 'Para empleado — 1 copia en hoja vertical',
                            ])
                            ->default('print')
                            ->required(),
                    ])
                    ->modalHeading('Descargar Recibo PDF')
                    ->modalSubmitActionLabel('Descargar')
                    ->action(function (array $data, Payroll $record) {
                        $url = route('payrolls.download', ['payroll' => $record, 'mode' => $data['mode']]);
                        $this->js("window.open('{$url}', '_blank')");
                    }),

                TableActionGroup::make([
                    Action::make('approve')
                        ->label('Aprobar')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Aprobar Recibo')
                        ->modalDescription(fn (Payroll $record) => "¿Aprobar el recibo de {$record->employee->full_name} por ".Payroll::formatCurrency($record->net_salary).'?')
                        ->modalSubmitActionLabel('Sí, aprobar')
                        ->action(function (Payroll $record) {
                            $record->update([
                                'status' => 'approved',
                                'approved_by_id' => Auth::id(),
                                'approved_at' => now(),
                            ]);

                            Notification::make()
                                ->success()
                                ->title('Recibo aprobado')
                                ->send();
                        })
                        ->visible(fn (Payroll $record) => $record->status === 'draft' && $this->getOwnerRecord()->status !== 'closed' && auth()->user()->can('approve_payroll')),

                    // Transición manual approved → disbursed para transferencias (sin lote bancario)
                    Action::make('mark_disbursed')
                        ->label('Marcar Acreditado')
                        ->icon('heroicon-o-building-library')
                        ->color('primary')
                        ->tooltip('Transferencia bancaria: confirma que el dinero fue acreditado en la cuenta del empleado. Próximo paso: Marcar Pagado.')
                        ->requiresConfirmation()
                        ->modalHeading('Marcar como Acreditado')
                        ->modalDescription(fn (Payroll $record) => "¿Confirma que el recibo de {$record->employee->full_name} fue acreditado en cuenta bancaria?")
                        ->modalSubmitActionLabel('Sí, marcar como acreditado')
                        ->action(function (Payroll $record) {
                            $record->update(['status' => 'disbursed']);

                            Notification::make()
                                ->success()
                                ->title('Recibo marcado como acreditado')
                                ->send();
                        })
                        ->visible(fn (Payroll $record) => $record->status === 'approved'
                            && $record->payment_method === 'transfer'
                            && $this->getOwnerRecord()->status !== 'closed'
                            && auth()->user()->can('disburse_payroll')),

                    // Marca como pagado: efectivo aprobado (sin pasar por disbursed) o transferencia ya acreditada
                    Action::make('mark_paid')
                        ->label('Marcar Pagado')
                        ->icon('heroicon-o-banknotes')
                        ->color('primary')
                        ->tooltip(fn (Payroll $record) => $record->payment_method === 'cash'
                            ? 'Efectivo: pago directo al empleado. Flujo: Aprobado → Pagado.'
                            : 'Transferencia: confirma que el banco procesó el pago. Flujo: Aprobado → Acreditado → Pagado.')
                        ->requiresConfirmation()
                        ->modalHeading('Marcar como Pagado')
                        ->modalDescription(fn (Payroll $record) => "¿Confirma que el recibo de {$record->employee->full_name} ha sido pagado?")
                        ->modalSubmitActionLabel('Sí, marcar como pagado')
                        ->action(function (Payroll $record) {
                            $record->update(['status' => 'paid']);

                            Notification::make()
                                ->success()
                                ->title('Recibo marcado como pagado')
                                ->send();
                        })
                        ->visible(fn (Payroll $record) => $this->getOwnerRecord()->status !== 'closed'
                            && (
                                ($record->status === 'approved' && $record->payment_method === 'cash')
                                || $record->status === 'disbursed'
                            )
                            && auth()->user()->can('mark_paid_payroll')),

                    // Revierte disbursed → approved solo si no está en un lote bancario
                    Action::make('revert_disbursed')
                        ->label('Revertir Acreditación')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Revertir Acreditación')
                        ->modalDescription(fn (Payroll $record) => "¿Revertir el recibo de {$record->employee->full_name} a Aprobado? Se quitará el estado de acreditado.")
                        ->modalSubmitActionLabel('Sí, revertir')
                        ->action(function (Payroll $record) {
                            $record->update(['status' => 'approved']);

                            Notification::make()
                                ->success()
                                ->title('Acreditación revertida')
                                ->body("El recibo de {$record->employee->full_name} ha vuelto a estado Aprobado.")
                                ->send();
                        })
                        ->visible(fn (Payroll $record) => $record->status === 'disbursed'
                            && $record->disbursement_batch_id === null
                            && $this->getOwnerRecord()->status !== 'closed'
                            && auth()->user()->can('revert_payroll')),

                    // Revierte paid → disbursed (transferencia) o paid → approved (efectivo)
                    Action::make('revert_paid')
                        ->label('Revertir Pago')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Revertir Pago')
                        ->modalDescription(fn (Payroll $record) => $record->payment_method === 'transfer'
                            ? "¿Revertir el pago de {$record->employee->full_name}? Volverá a estado Acreditado."
                            : "¿Revertir el pago de {$record->employee->full_name}? Volverá a estado Aprobado.")
                        ->modalSubmitActionLabel('Sí, revertir')
                        ->action(function (Payroll $record) {
                            $newStatus = $record->payment_method === 'transfer' ? 'disbursed' : 'approved';
                            $record->update(['status' => $newStatus]);

                            $label = $newStatus === 'disbursed' ? 'Acreditado' : 'Aprobado';

                            Notification::make()
                                ->success()
                                ->title('Pago revertido')
                                ->body("El recibo de {$record->employee->full_name} ha vuelto a estado {$label}.")
                                ->send();
                        })
                        ->visible(fn (Payroll $record) => $record->status === 'paid' && $this->getOwnerRecord()->status !== 'closed' && auth()->user()->can('revert_payroll')),

                    Action::make('unapprove')
                        ->label('Desaprobar')
                        ->icon('heroicon-o-x-circle')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Desaprobar Recibo')
                        ->modalDescription(fn (Payroll $record) => "¿Está seguro de desaprobar el recibo de {$record->employee->full_name}? Volverá a estado Borrador.")
                        ->modalSubmitActionLabel('Sí, desaprobar')
                        ->action(function (Payroll $record) {
                            $record->update([
                                'status' => 'draft',
                                'approved_by_id' => null,
                                'approved_at' => null,
                            ]);

                            Notification::make()
                                ->success()
                                ->title('Recibo desaprobado')
                                ->body("El recibo de {$record->employee->full_name} ha vuelto a estado Borrador.")
                                ->send();
                        })
                        ->visible(fn (Payroll $record) => $record->status === 'approved' && $this->getOwnerRecord()->status !== 'closed' && auth()->user()->can('revert_payroll')),

                    Action::make('add_manual_extra_hours')
                        ->label('Agregar ajuste HE')
                        ->icon('heroicon-o-clock')
                        ->color('warning')
                        ->tooltip('Agregar horas extras manuales a este recibo')
                        ->visible(fn (Payroll $record) => $record->status === 'draft'
                            && $this->getOwnerRecord()->status !== 'closed')
                        ->mountUsing(function (Form $form, Payroll $record) {
                            $settings = app(PayrollSettings::class);
                            $employee = $record->employee;
                            $period = $record->period;

                            if ($employee->employment_type === 'day_laborer') {
                                $hourlyRate = $employee->daily_rate / max(1, $settings->daily_hours);
                            } else {
                                $monthlyHours = $employee->getScheduleForDate($period->start_date)?->getMonthlyHours()
                                    ?? $settings->monthly_hours;
                                $hourlyRate = $employee->base_salary / max(1, $monthlyHours);
                            }

                            $form->fill([
                                'hourly_rate' => round($hourlyRate, 4),
                                'multiplier_diurno' => $settings->overtime_multiplier_diurno,
                                'multiplier_nocturno' => $settings->overtime_multiplier_nocturno,
                                'multiplier_holiday' => $settings->overtime_multiplier_holiday,
                                'multiplier_holiday_nocturno' => $settings->overtime_multiplier_nocturno_holiday,
                                'hours' => 0,
                            ]);
                        })
                        ->form([
                            FormSelect::make('type')
                                ->label('Tipo de hora extra')
                                ->options([
                                    'diurnas' => 'Diurnas (50%)',
                                    'nocturnas' => 'Nocturnas (160%)',
                                    'feriado_domingo' => 'Feriado / Domingo (100%)',
                                    'feriado_nocturno' => 'Feriado Nocturno (160%)',
                                ])
                                ->native(false)
                                ->required()
                                ->live(),

                            TextInput::make('hours')
                                ->label('Horas')
                                ->numeric()
                                ->step(0.5)
                                ->minValue(0.5)
                                ->maxValue(24)
                                ->suffix('hrs')
                                ->required()
                                ->live(debounce: 500),

                            TextInput::make('description')
                                ->label('Descripción (opcional)')
                                ->maxLength(255)
                                ->placeholder('Se genera automáticamente si se deja vacío')
                                ->columnSpanFull(),

                            Hidden::make('hourly_rate'),
                            Hidden::make('multiplier_diurno'),
                            Hidden::make('multiplier_nocturno'),
                            Hidden::make('multiplier_holiday'),
                            Hidden::make('multiplier_holiday_nocturno'),

                            Placeholder::make('amount_preview')
                                ->label('Monto estimado')
                                ->content(function (Get $get) {
                                    $hours = (float) ($get('hours') ?? 0);
                                    $type = $get('type');
                                    $hourlyRate = (float) ($get('hourly_rate') ?? 0);

                                    if ($hours <= 0 || ! $type || $hourlyRate <= 0) {
                                        return 'Seleccione tipo y horas para ver el monto estimado.';
                                    }

                                    $multiplier = match ($type) {
                                        'diurnas' => (float) ($get('multiplier_diurno') ?? 1.5),
                                        'nocturnas' => (float) ($get('multiplier_nocturno') ?? 2.6),
                                        'feriado_domingo' => (float) ($get('multiplier_holiday') ?? 2.0),
                                        'feriado_nocturno' => (float) ($get('multiplier_holiday_nocturno') ?? 2.6),
                                        default => 1.0,
                                    };

                                    $amount = round($hours * $hourlyRate * $multiplier, 0);

                                    return 'Gs. '.number_format($amount, 0, ',', '.');
                                })
                                ->columnSpanFull(),
                        ])
                        ->modalHeading(fn (Payroll $record) => 'Agregar Ajuste HE — '.$record->employee->full_name)
                        ->modalSubmitActionLabel('Agregar')
                        ->action(function (Payroll $record, array $data) {
                            $settings = app(PayrollSettings::class);
                            $employee = $record->employee;
                            $period = $record->period;
                            $hours = (float) $data['hours'];
                            $type = $data['type'];

                            if ($employee->employment_type === 'day_laborer') {
                                $hourlyRate = $employee->daily_rate / max(1, $settings->daily_hours);
                            } else {
                                $monthlyHours = $employee->getScheduleForDate($period->start_date)?->getMonthlyHours()
                                    ?? $settings->monthly_hours;
                                $hourlyRate = $employee->base_salary / max(1, $monthlyHours);
                            }

                            $multiplier = match ($type) {
                                'diurnas' => $settings->overtime_multiplier_diurno,
                                'nocturnas' => $settings->overtime_multiplier_nocturno,
                                'feriado_domingo' => $settings->overtime_multiplier_holiday,
                                'feriado_nocturno' => $settings->overtime_multiplier_nocturno_holiday,
                                default => 1.0,
                            };

                            $amount = round($hours * $hourlyRate * $multiplier, 2);

                            $typeLabels = [
                                'diurnas' => "Horas Extras Diurnas ({$hours}h al 50%)",
                                'nocturnas' => "Horas Extras Nocturnas ({$hours}h al 160%)",
                                'feriado_domingo' => "Horas Extras Feriado/Domingo ({$hours}h al 100%)",
                                'feriado_nocturno' => "Horas Extras Nocturnas Feriado/Domingo ({$hours}h al 160%)",
                            ];

                            $description = filled($data['description'] ?? null)
                                ? $data['description']
                                : ($typeLabels[$type] ?? "Horas Extras Manuales ({$hours}h)");

                            PayrollItem::create([
                                'payroll_id' => $record->id,
                                'type' => 'perception',
                                'perception_type' => 'extra_hours',
                                'description' => $description,
                                'amount' => $amount,
                                'is_manual_override' => true,
                            ]);

                            $record->total_perceptions += $amount;
                            $record->gross_salary += $amount;
                            $record->net_salary += $amount;

                            if ($record->pdf_path && Storage::disk('public')->exists($record->pdf_path)) {
                                Storage::disk('public')->delete($record->pdf_path);
                            }
                            $record->pdf_path = null;
                            $record->save();

                            Notification::make()
                                ->success()
                                ->title('Horas extras agregadas')
                                ->body("Se agregaron {$hours}h ({$typeLabels[$type]}) por ".Payroll::formatCurrency($amount).' al recibo.')
                                ->send();
                        }),

                    Action::make('edit_manual_extra_hours')
                        ->label('Editar ajuste HE')
                        ->icon('heroicon-o-pencil-square')
                        ->color('info')
                        ->tooltip('Editar un ítem de horas extras cargado manualmente')
                        ->visible(fn (Payroll $record) => $record->status === 'draft'
                            && $record->items()->where('is_manual_override', true)->exists()
                            && $this->getOwnerRecord()->status !== 'closed')
                        ->mountUsing(function (Form $form, Payroll $record) {
                            $settings = app(PayrollSettings::class);
                            $employee = $record->employee;
                            $period = $record->period;

                            if ($employee->employment_type === 'day_laborer') {
                                $hourlyRate = $employee->daily_rate / max(1, $settings->daily_hours);
                            } else {
                                $monthlyHours = $employee->getScheduleForDate($period->start_date)?->getMonthlyHours()
                                    ?? $settings->monthly_hours;
                                $hourlyRate = $employee->base_salary / max(1, $monthlyHours);
                            }

                            $form->fill([
                                'hourly_rate' => round($hourlyRate, 4),
                                'multiplier_diurno' => $settings->overtime_multiplier_diurno,
                                'multiplier_nocturno' => $settings->overtime_multiplier_nocturno,
                                'multiplier_holiday' => $settings->overtime_multiplier_holiday,
                                'multiplier_holiday_nocturno' => $settings->overtime_multiplier_nocturno_holiday,
                            ]);
                        })
                        ->form([
                            FormSelect::make('payroll_item_id')
                                ->label('Ítem a editar')
                                ->options(function (Payroll $record) {
                                    return $record->items()
                                        ->where('is_manual_override', true)
                                        ->get()
                                        ->mapWithKeys(fn (PayrollItem $item) => [
                                            $item->id => $item->description.' — '.Payroll::formatCurrency((float) $item->amount),
                                        ]);
                                })
                                ->native(false)
                                ->required()
                                ->live()
                                ->afterStateUpdated(function (?int $state, Set $set) {
                                    if (! $state) {
                                        return;
                                    }

                                    $item = PayrollItem::find($state);
                                    if (! $item) {
                                        return;
                                    }

                                    // Inferir tipo desde la descripción guardada
                                    $typeMap = [
                                        'Horas Extras Diurnas' => 'diurnas',
                                        'Horas Extras Nocturnas (' => 'nocturnas',
                                        'Horas Extras Feriado/Domingo' => 'feriado_domingo',
                                        'Horas Extras Nocturnas Feriado' => 'feriado_nocturno',
                                    ];

                                    $detectedType = null;
                                    foreach ($typeMap as $needle => $type) {
                                        if (str_contains($item->description, $needle)) {
                                            $detectedType = $type;
                                            break;
                                        }
                                    }

                                    $set('type', $detectedType);
                                    $set('description', $item->description);
                                })
                                ->columnSpanFull(),

                            FormSelect::make('type')
                                ->label('Tipo de hora extra')
                                ->options([
                                    'diurnas' => 'Diurnas (50%)',
                                    'nocturnas' => 'Nocturnas (160%)',
                                    'feriado_domingo' => 'Feriado / Domingo (100%)',
                                    'feriado_nocturno' => 'Feriado Nocturno (160%)',
                                ])
                                ->native(false)
                                ->required()
                                ->live(),

                            TextInput::make('hours')
                                ->label('Horas')
                                ->numeric()
                                ->step(0.5)
                                ->minValue(0.5)
                                ->maxValue(24)
                                ->suffix('hrs')
                                ->required()
                                ->live(debounce: 500),

                            TextInput::make('description')
                                ->label('Descripción')
                                ->maxLength(255)
                                ->placeholder('Se genera automáticamente si se deja vacío')
                                ->columnSpanFull(),

                            Hidden::make('hourly_rate'),
                            Hidden::make('multiplier_diurno'),
                            Hidden::make('multiplier_nocturno'),
                            Hidden::make('multiplier_holiday'),
                            Hidden::make('multiplier_holiday_nocturno'),

                            Placeholder::make('amount_preview')
                                ->label('Monto estimado')
                                ->content(function (Get $get) {
                                    $hours = (float) ($get('hours') ?? 0);
                                    $type = $get('type');
                                    $hourlyRate = (float) ($get('hourly_rate') ?? 0);

                                    if ($hours <= 0 || ! $type || $hourlyRate <= 0) {
                                        return 'Seleccione tipo y horas para ver el monto estimado.';
                                    }

                                    $multiplier = match ($type) {
                                        'diurnas' => (float) ($get('multiplier_diurno') ?? 1.5),
                                        'nocturnas' => (float) ($get('multiplier_nocturno') ?? 2.6),
                                        'feriado_domingo' => (float) ($get('multiplier_holiday') ?? 2.0),
                                        'feriado_nocturno' => (float) ($get('multiplier_holiday_nocturno') ?? 2.6),
                                        default => 1.0,
                                    };

                                    $amount = round($hours * $hourlyRate * $multiplier, 0);

                                    return 'Gs. '.number_format($amount, 0, ',', '.');
                                })
                                ->columnSpanFull(),
                        ])
                        ->modalHeading(fn (Payroll $record) => 'Editar Ajuste HE — '.$record->employee->full_name)
                        ->modalSubmitActionLabel('Guardar cambios')
                        ->action(function (Payroll $record, array $data) {
                            $item = PayrollItem::find($data['payroll_item_id']);

                            if (! $item || $item->payroll_id !== $record->id) {
                                Notification::make()->danger()->title('Ítem no encontrado')->send();

                                return;
                            }

                            $settings = app(PayrollSettings::class);
                            $employee = $record->employee;
                            $period = $record->period;
                            $hours = (float) $data['hours'];
                            $type = $data['type'];

                            if ($employee->employment_type === 'day_laborer') {
                                $hourlyRate = $employee->daily_rate / max(1, $settings->daily_hours);
                            } else {
                                $monthlyHours = $employee->getScheduleForDate($period->start_date)?->getMonthlyHours()
                                    ?? $settings->monthly_hours;
                                $hourlyRate = $employee->base_salary / max(1, $monthlyHours);
                            }

                            $multiplier = match ($type) {
                                'diurnas' => $settings->overtime_multiplier_diurno,
                                'nocturnas' => $settings->overtime_multiplier_nocturno,
                                'feriado_domingo' => $settings->overtime_multiplier_holiday,
                                'feriado_nocturno' => $settings->overtime_multiplier_nocturno_holiday,
                                default => 1.0,
                            };

                            $newAmount = round($hours * $hourlyRate * $multiplier, 2);
                            $oldAmount = (float) $item->amount;
                            $diff = $newAmount - $oldAmount;

                            $typeLabels = [
                                'diurnas' => "Horas Extras Diurnas ({$hours}h al 50%)",
                                'nocturnas' => "Horas Extras Nocturnas ({$hours}h al 160%)",
                                'feriado_domingo' => "Horas Extras Feriado/Domingo ({$hours}h al 100%)",
                                'feriado_nocturno' => "Horas Extras Nocturnas Feriado/Domingo ({$hours}h al 160%)",
                            ];

                            $description = filled($data['description'] ?? null)
                                ? $data['description']
                                : ($typeLabels[$type] ?? "Horas Extras Manuales ({$hours}h)");

                            $item->update([
                                'description' => $description,
                                'amount' => $newAmount,
                            ]);

                            // Ajustar totales del recibo por la diferencia
                            $record->total_perceptions += $diff;
                            $record->gross_salary += $diff;
                            $record->net_salary += $diff;

                            if ($record->pdf_path && Storage::disk('public')->exists($record->pdf_path)) {
                                Storage::disk('public')->delete($record->pdf_path);
                            }
                            $record->pdf_path = null;
                            $record->save();

                            Notification::make()
                                ->success()
                                ->title('Ajuste actualizado')
                                ->body("Ítem actualizado a {$hours}h por ".Payroll::formatCurrency($newAmount).'.')
                                ->send();
                        }),

                    Action::make('clear_manual_items')
                        ->label('Limpiar Ajustes')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->tooltip('Eliminar todos los ítems de ajuste manual de este recibo')
                        ->visible(fn (Payroll $record) => $record->status === 'draft'
                            && $record->items()->where('is_manual_override', true)->exists()
                            && $this->getOwnerRecord()->status !== 'closed')
                        ->requiresConfirmation()
                        ->modalHeading(fn (Payroll $record) => 'Limpiar ajustes manuales — '.$record->employee->full_name)
                        ->modalDescription(function (Payroll $record) {
                            $items = $record->items()->where('is_manual_override', true)->get();
                            $totalPerceptions = $items->where('type', 'perception')->sum('amount');
                            $count = $items->count();

                            $desc = "Se eliminarán {$count} ítem(s) cargado(s) manualmente.";
                            if ($totalPerceptions > 0) {
                                $desc .= ' Monto en percepciones: '.Payroll::formatCurrency($totalPerceptions).'.';
                            }

                            return $desc;
                        })
                        ->modalSubmitActionLabel('Sí, limpiar')
                        ->action(function (Payroll $record) {
                            $items = $record->items()->where('is_manual_override', true)->get();
                            $totalPerceptions = $items->where('type', 'perception')->sum('amount');
                            $totalDeductions = $items->where('type', 'deduction')->sum('amount');

                            $record->items()->where('is_manual_override', true)->delete();

                            $record->total_perceptions -= $totalPerceptions;
                            $record->total_deductions -= $totalDeductions;
                            $record->gross_salary -= $totalPerceptions;
                            $record->net_salary = $record->gross_salary - $record->total_deductions;

                            if ($record->pdf_path && Storage::disk('public')->exists($record->pdf_path)) {
                                Storage::disk('public')->delete($record->pdf_path);
                            }
                            $record->pdf_path = null;
                            $record->save();

                            Notification::make()
                                ->success()
                                ->title('Ajustes eliminados')
                                ->body('Los ítems de ajuste manual han sido eliminados del recibo.')
                                ->send();
                        }),

                    Action::make('regenerate')
                        ->label('Regenerar')
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Regenerar Recibo')
                        ->modalDescription(fn (Payroll $record) => "Se recalcularán todos los ítems del recibo de {$record->employee->full_name}. Esta acción reemplazará los valores actuales.")
                        ->modalSubmitActionLabel('Sí, regenerar')
                        ->action(function (Payroll $record, PayrollService $payrollService) {
                            try {
                                $payrollService->regenerateForEmployee($record);

                                Notification::make()
                                    ->success()
                                    ->title('Recibo regenerado')
                                    ->body("El recibo de {$record->employee->full_name} ha sido recalculado exitosamente.")
                                    ->send();
                            } catch (\Throwable $e) {
                                Notification::make()
                                    ->danger()
                                    ->title('Error al regenerar')
                                    ->body('Ocurrió un error al regenerar el recibo: '.$e->getMessage())
                                    ->send();
                            }
                        })
                        ->visible(fn (Payroll $record) => $record->status === 'draft' && $this->getOwnerRecord()->status !== 'closed' && auth()->user()->can('regenerate_payroll')),

                    DeleteAction::make()
                        ->visible(fn (Payroll $record) => $record->status === 'draft' && $this->getOwnerRecord()->status !== 'closed')
                        ->successNotificationTitle('Recibo eliminado exitosamente'),
                ]),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('approve_selected')
                        ->label('Aprobar Seleccionados')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Aprobar Recibos Seleccionados')
                        ->modalDescription('Solo se aprobarán los recibos en estado "Borrador".')
                        ->modalSubmitActionLabel('Sí, aprobar')
                        ->action(function (Collection $records) {
                            $count = 0;
                            foreach ($records as $record) {
                                if ($record->status === 'draft') {
                                    $record->update([
                                        'status' => 'approved',
                                        'approved_by_id' => Auth::id(),
                                        'approved_at' => now(),
                                    ]);
                                    $count++;
                                }
                            }

                            Notification::make()
                                ->success()
                                ->title("{$count} recibos aprobados")
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion()
                        ->visible(fn () => $this->getOwnerRecord()->status !== 'closed' && auth()->user()->can('approve_payroll')),

                    BulkAction::make('mark_disbursed_selected')
                        ->label('Marcar Acreditados')
                        ->icon('heroicon-o-building-library')
                        ->color('primary')
                        ->requiresConfirmation()
                        ->modalHeading('Marcar como Acreditados')
                        ->modalDescription('Solo se marcarán los recibos de transferencia en estado "Aprobado".')
                        ->modalSubmitActionLabel('Sí, marcar como acreditados')
                        ->action(function (Collection $records) {
                            $count = 0;
                            foreach ($records as $record) {
                                if ($record->status === 'approved' && $record->payment_method === 'transfer') {
                                    $record->update(['status' => 'disbursed']);
                                    $count++;
                                }
                            }

                            Notification::make()
                                ->success()
                                ->title("{$count} recibos marcados como acreditados")
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion()
                        ->visible(fn () => $this->getOwnerRecord()->status !== 'closed' && auth()->user()->can('disburse_payroll')),

                    // Marca como pagados: efectivo aprobado o cualquier recibo acreditado
                    BulkAction::make('mark_paid_selected')
                        ->label('Marcar Pagados')
                        ->icon('heroicon-o-banknotes')
                        ->color('info')
                        ->requiresConfirmation()
                        ->modalHeading('Marcar como Pagados')
                        ->modalDescription('Se marcarán como pagados los recibos en efectivo "Aprobados" y los "Acreditados".')
                        ->modalSubmitActionLabel('Sí, marcar como pagados')
                        ->action(function (Collection $records) {
                            $count = 0;
                            foreach ($records as $record) {
                                $eligible = ($record->status === 'approved' && $record->payment_method === 'cash')
                                    || $record->status === 'disbursed';
                                if ($eligible) {
                                    $record->update(['status' => 'paid']);
                                    $count++;
                                }
                            }

                            Notification::make()
                                ->success()
                                ->title("{$count} recibos marcados como pagados")
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion()
                        ->visible(fn () => auth()->user()->can('mark_paid_payroll')),

                    // Revierte paid → disbursed (transferencia) o paid → approved (efectivo)
                    BulkAction::make('revert_paid_selected')
                        ->label('Revertir Pagos')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Revertir Pagos Seleccionados')
                        ->modalDescription('Las transferencias volverán a "Acreditado"; los recibos de efectivo volverán a "Aprobado".')
                        ->modalSubmitActionLabel('Sí, revertir')
                        ->action(function (Collection $records) {
                            $count = 0;
                            foreach ($records as $record) {
                                if ($record->status === 'paid') {
                                    $newStatus = $record->payment_method === 'transfer' ? 'disbursed' : 'approved';
                                    $record->update(['status' => $newStatus]);
                                    $count++;
                                }
                            }

                            Notification::make()
                                ->success()
                                ->title("{$count} pagos revertidos")
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion()
                        ->visible(fn () => $this->getOwnerRecord()->status !== 'closed' && auth()->user()->can('revert_payroll')),

                    BulkAction::make('unapprove_selected')
                        ->label('Desaprobar Seleccionados')
                        ->icon('heroicon-o-x-circle')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Desaprobar Recibos Seleccionados')
                        ->modalDescription('¿Está seguro? Solo se desaprobarán los recibos en estado "Aprobado". Volverán a estado Borrador.')
                        ->modalSubmitActionLabel('Sí, desaprobar')
                        ->action(function (Collection $records) {
                            $count = 0;
                            foreach ($records as $record) {
                                if ($record->status === 'approved') {
                                    $record->update([
                                        'status' => 'draft',
                                        'approved_by_id' => null,
                                        'approved_at' => null,
                                    ]);
                                    $count++;
                                }
                            }

                            Notification::make()
                                ->success()
                                ->title("{$count} recibos desaprobados")
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion()
                        ->visible(fn () => $this->getOwnerRecord()->status !== 'closed' && auth()->user()->can('revert_payroll')),

                    BulkAction::make('download_pdfs')
                        ->label('Descargar PDFs')
                        ->icon('heroicon-o-document-arrow-down')
                        ->color('info')
                        ->form([
                            Radio::make('mode')
                                ->label('Formato')
                                ->options([
                                    'print' => 'Para imprimir — 2 copias por hoja (A4 horizontal)',
                                    'employee' => 'Para empleado — 1 copia por hoja (A4 vertical)',
                                ])
                                ->default('print')
                                ->required(),
                        ])
                        ->modalHeading('Descargar PDFs Seleccionados')
                        ->modalSubmitActionLabel('Descargar')
                        ->action(function (Collection $records, array $data) {
                            $records->load(['employee.activeContract.position.department', 'items']);
                            $mode = $data['mode'];
                            $generator = app(PayrollPDFGenerator::class);

                            $tempDir = storage_path('app/public/temp');
                            if (! is_dir($tempDir)) {
                                mkdir($tempDir, 0755, true);
                            }

                            foreach (glob($tempDir.'/*.{pdf,zip}', GLOB_BRACE) as $file) {
                                if (is_file($file) && (time() - filemtime($file)) > 3600) {
                                    @unlink($file);
                                }
                            }

                            $pdfs = [];
                            foreach ($records as $record) {
                                try {
                                    if ($mode === 'print') {
                                        if (! $record->pdf_path || ! Storage::disk('public')->exists($record->pdf_path)) {
                                            $pdfPath = $generator->generate($record);
                                            $record->update(['pdf_path' => $pdfPath]);
                                            $record->pdf_path = $pdfPath;
                                        }
                                        if ($record->pdf_path && Storage::disk('public')->exists($record->pdf_path)) {
                                            $pdfs[] = ['ci' => $record->employee->ci, 'id' => $record->id, 'content' => Storage::disk('public')->get($record->pdf_path)];
                                        }
                                    } else {
                                        $pdfs[] = ['ci' => $record->employee->ci, 'id' => $record->id, 'content' => $generator->generateContent($record, 'employee')];
                                    }
                                } catch (\Throwable) {
                                    // continúa con los que sí se pueden generar
                                }
                            }

                            if (empty($pdfs)) {
                                Notification::make()
                                    ->warning()
                                    ->title('Sin PDFs disponibles')
                                    ->body('No se pudieron generar los PDFs de los recibos seleccionados.')
                                    ->send();

                                return;
                            }

                            $uniqueId = Str::uuid();
                            $suffix = $mode === 'employee' ? '_empleado' : '';

                            if (count($pdfs) === 1) {
                                $pdf = $pdfs[0];
                                $filename = $uniqueId.'_recibo'.$suffix.'_'.$pdf['ci'].'.pdf';
                                file_put_contents($tempDir.'/'.$filename, $pdf['content']);
                            } else {
                                $filename = $uniqueId.'_recibos'.$suffix.'_'.now()->format('d_m_Y_H_i_s').'.zip';
                                $zip = new \ZipArchive;
                                $zip->open($tempDir.'/'.$filename, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
                                foreach ($pdfs as $pdf) {
                                    $zip->addFromString('recibo'.$suffix.'_'.$pdf['ci'].'_'.$pdf['id'].'.pdf', $pdf['content']);
                                }
                                $zip->close();
                            }

                            $this->js("window.open('".route('payrolls.download.temp', ['filename' => $filename])."', '_blank')");

                            Notification::make()
                                ->success()
                                ->title('Descarga iniciada')
                                ->body('Los recibos se están descargando.')
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion()
                        ->visible(fn () => auth()->user()->can('export_payroll')),

                    ExportBulkAction::make()
                        ->exports([
                            ExcelExport::make()
                                ->fromTable()
                                ->withFilename(fn () => 'recibos_seleccionados_'.now()->format('d_m_Y_H_i_s'))
                                ->withWriterType(Excel::XLSX),
                        ]),

                    DeleteBulkAction::make()
                        ->visible(fn () => $this->getOwnerRecord()->status === 'draft'),
                ]),
            ])
            ->emptyStateHeading('No hay recibos generados')
            ->emptyStateDescription('Los recibos aparecerán aquí una vez que se generen desde la planilla.')
            ->emptyStateIcon('heroicon-o-document-text')
            ->defaultSort('generated_at', 'desc')
            ->defaultGroup('payment_method')
            ->groups([
                TableGroup::make('payment_method')
                    ->label('Método de pago')
                    ->getTitleFromRecordUsing(fn (Payroll $record) => Payroll::getPaymentMethodLabels()[$record->payment_method] ?? '—')
                    ->collapsible(),
            ]);
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Section::make('Información del Empleado')
                    ->schema([
                        Group::make([
                            TextEntry::make('employee.ci')
                                ->label('Cédula de Identidad')
                                ->icon('heroicon-o-identification')
                                ->copyable(),

                            TextEntry::make('employee.full_name')
                                ->label('Nombre Completo'),
                        ])->columns(2),

                        Group::make([
                            TextEntry::make('employee.activeContract.position.name')
                                ->label('Cargo')
                                ->icon('heroicon-o-briefcase')
                                ->badge()
                                ->color('info'),

                            TextEntry::make('employee.activeContract.position.department.name')
                                ->label('Departamento')
                                ->icon('heroicon-o-building-office-2')
                                ->badge()
                                ->color('primary')
                                ->default('N/A'),
                        ])->columns(2),
                    ]),

                Section::make('Detalle de Nómina')
                    ->schema([
                        Group::make([
                            TextEntry::make('base_salary')
                                ->label(fn (Payroll $record): string => $record->employee->employment_type === 'day_laborer'
                                    ? 'Jornal del Período'
                                    : 'Salario Base')
                                ->money('PYG', locale: 'es_PY')
                                ->icon('heroicon-o-banknotes'),

                            TextEntry::make('total_perceptions')
                                ->label('Total Percepciones')
                                ->money('PYG', locale: 'es_PY')
                                ->color('success')
                                ->icon('heroicon-o-plus-circle'),
                        ])->columns(2),

                        Group::make([
                            TextEntry::make('gross_salary')
                                ->label('Salario Bruto')
                                ->money('PYG', locale: 'es_PY')
                                ->weight('bold'),

                            TextEntry::make('total_deductions')
                                ->label('Total Deducciones')
                                ->money('PYG', locale: 'es_PY')
                                ->color('danger')
                                ->icon('heroicon-o-minus-circle'),
                        ])->columns(2),

                        TextEntry::make('net_salary')
                            ->label('Salario Neto a Pagar')
                            ->money('PYG', locale: 'es_PY')
                            ->size('lg')
                            ->weight('bold')
                            ->color('success')
                            ->icon('heroicon-o-currency-dollar'),
                    ]),

                Section::make('Estado y Aprobación')
                    ->schema([
                        Group::make([
                            TextEntry::make('status')
                                ->label('Estado')
                                ->badge()
                                ->color(fn ($state) => Payroll::getStatusColors()[$state] ?? 'gray')
                                ->formatStateUsing(fn ($state) => Payroll::getStatusLabels()[$state] ?? $state),

                            TextEntry::make('payment_method')
                                ->label('Método de Pago')
                                ->badge()
                                ->color(fn (?string $state) => $state ? (Payroll::getPaymentMethodColors()[$state] ?? 'gray') : 'gray')
                                ->formatStateUsing(fn (?string $state) => $state ? (Payroll::getPaymentMethodLabels()[$state] ?? $state) : '—'),

                            TextEntry::make('approvedBy.name')
                                ->label('Aprobado por')
                                ->placeholder('Sin aprobar'),
                        ])->columns(3),
                    ]),

                Section::make('Información Adicional')
                    ->schema([
                        TextEntry::make('generated_at')
                            ->label('Fecha de Generación')
                            ->dateTime('d/m/Y H:i')
                            ->icon('heroicon-o-clock'),

                        TextEntry::make('pdf_path')
                            ->label('PDF Generado')
                            ->formatStateUsing(fn ($state) => $state ? 'Disponible' : 'No generado')
                            ->badge()
                            ->color(fn ($state) => $state ? 'success' : 'gray')
                            ->icon(fn ($state) => $state ? 'heroicon-o-check-circle' : 'heroicon-o-x-circle'),
                    ])
                    ->columns(2)
                    ->collapsed(),
            ]);
    }
}
