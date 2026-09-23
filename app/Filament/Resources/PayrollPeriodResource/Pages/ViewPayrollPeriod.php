<?php

namespace App\Filament\Resources\PayrollPeriodResource\Pages;

use App\Filament\Pages\ContractReport;
use App\Filament\Pages\SalaryReport;
use App\Filament\Resources\DisbursementBatchResource;
use App\Filament\Resources\PayrollPeriodResource;
use App\Models\Company;
use App\Models\Contract;
use App\Models\DisbursementBatch;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\PayrollPeriod;
use App\Services\PayrollService;
use App\Settings\GeneralSettings;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

class ViewPayrollPeriod extends ViewRecord
{
    protected static string $resource = PayrollPeriodResource::class;

    /**
     * Carga la planilla con conteos de recibos por estado en una sola query.
     */
    protected function resolveRecord(int|string $key): Model
    {
        return PayrollPeriod::withCount([
            'payrolls as total_payrolls_count',
            'payrolls as draft_payrolls_count' => fn ($q) => $q->where('status', 'draft'),
            'payrolls as approved_payrolls_count' => fn ($q) => $q->where('status', 'approved'),
            'payrolls as paid_payrolls_count' => fn ($q) => $q->where('status', 'paid'),
        ])->findOrFail($key);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate_payrolls')
                ->label('Generar Recibos')
                ->icon('heroicon-o-document-plus')
                ->color('success')
                ->mountUsing(function (?Form $form, Action $action) {
                    $existingIds = $this->record->payrolls()->pluck('employee_id');

                    $pending = Employee::where('status', 'active')
                        ->whereHas(
                            'activeContract',
                            fn ($q) => $q->where('payroll_type', $this->record->frequency)->whereNotNull('salary')
                        )
                        ->when($this->record->company_id, fn ($q) => $q->whereHas('branch', fn ($q) => $q->where('company_id', $this->record->company_id)))
                        ->whereNotIn('id', $existingIds)
                        ->count();

                    if ($pending === 0) {
                        Notification::make()
                            ->warning()
                            ->title('Todos los recibos ya están generados')
                            ->body('No quedan empleados activos sin recibo en esta planilla.')
                            ->send();

                        $action->halt();

                        return;
                    }

                    $form?->fill();
                })
                ->modalHeading('Generar Recibos de Nómina')
                ->modalSubmitActionLabel('Generar')
                ->modalDescription(function () {
                    $existingIds = $this->record->payrolls()->pluck('employee_id');

                    $pending = Employee::where('status', 'active')
                        ->whereHas('activeContract', fn ($q) => $q->where('payroll_type', $this->record->frequency)->whereNotNull('salary'))
                        ->when($this->record->company_id, fn ($q) => $q->whereHas('branch', fn ($q) => $q->where('company_id', $this->record->company_id)))
                        ->whereNotIn('id', $existingIds)
                        ->count();

                    $companyName = $this->record->company?->name;
                    $companyLabel = $companyName ? "Empresa: {$companyName}" : '';
                    $employeeLabel = $pending === 1 ? '1 empleado sin recibo' : "{$pending} empleados sin recibo";

                    return implode(' · ', array_filter([$companyLabel, $employeeLabel]));
                })
                ->action(function (PayrollService $payrollService) {
                    $result = $payrollService->generateForPeriod($this->record);
                    $count = $result['generated'];
                    $skipped = $result['skipped'];

                    if ($count > 0) {
                        $this->record->update(['status' => 'processing']);

                        $reciboLabel = $count === 1 ? '1 recibo generado' : "{$count} recibos generados";
                        $bodyParts = [$this->record->name];
                        if ($this->record->company) {
                            $bodyParts[] = $this->record->company->name;
                        }

                        Notification::make()
                            ->success()
                            ->title($reciboLabel)
                            ->body(implode(' · ', $bodyParts))
                            ->send();

                        // Alerta si hay contratos por vencer en el período de esta planilla
                        $alertDays = app(GeneralSettings::class)->contract_alert_days;
                        $expiringCount = Contract::expiringSoon($alertDays)
                            ->when($this->record->company_id, fn ($q) => $q->whereHas(
                                'employee.branch',
                                fn ($q) => $q->where('company_id', $this->record->company_id)
                            ))
                            ->count();

                        if ($expiringCount > 0) {
                            $label = $expiringCount === 1
                                ? '1 empleado tiene contrato por vencer'
                                : "{$expiringCount} empleados tienen contrato por vencer";

                            Notification::make()
                                ->warning()
                                ->title('Contratos por vencer')
                                ->body("{$label} en los próximos {$alertDays} días.")
                                ->persistent()
                                ->actions([
                                    \Filament\Notifications\Actions\Action::make('ver_reporte')
                                        ->label('Ver reporte')
                                        ->url(ContractReport::getUrl())
                                        ->button(),
                                ])
                                ->send();
                        }
                    } else {
                        Notification::make()
                            ->warning()
                            ->title('No se generaron recibos')
                            ->body('Todos los empleados activos ya tienen recibo en esta planilla.')
                            ->send();
                    }

                    if (! empty($skipped)) {
                        $lines = array_map(
                            fn ($s) => "{$s['name']} (CI: {$s['ci']}) — {$s['reason']}",
                            $skipped
                        );
                        $noun = count($skipped) === 1 ? 'empleado omitido' : 'empleados omitidos';

                        Notification::make()
                            ->warning()
                            ->title(count($skipped).' '.$noun)
                            ->body(implode("\n", $lines))
                            ->persistent()
                            ->send();
                    }

                    $this->js('window.location.reload()');
                })
                ->visible(fn () => in_array($this->record->status, ['draft', 'processing']) && auth()->user()->can('generate_payrolls_period')),

            Action::make('approve_all_payrolls')
                ->label('Aprobar Todos')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Aprobar Todos los Recibos')
                ->modalSubmitActionLabel('Sí, aprobar todos')
                ->modalDescription(function () {
                    $count = $this->record->payrolls()->where('status', 'draft')->count();
                    $noun = $count === 1 ? '1 recibo en borrador' : "{$count} recibos en borrador";

                    return "Se aprobarán {$noun} de la planilla {$this->record->name}. ¿Desea continuar?";
                })
                ->action(function () {
                    $count = $this->record->payrolls()
                        ->where('status', 'draft')
                        ->update([
                            'status' => 'approved',
                            'approved_by_id' => Auth::id(),
                            'approved_at' => now(),
                        ]);

                    $noun = $count === 1 ? '1 recibo aprobado' : "{$count} recibos aprobados";

                    Notification::make()
                        ->success()
                        ->title($noun)
                        ->send();

                    $this->js('window.location.reload()');
                })
                ->visible(fn () => $this->record->status === 'processing'
                    && $this->record->payrolls()->where('status', 'draft')->exists()
                    && auth()->user()->can('approve_payroll')),

            Action::make('close_period')
                ->label('Cerrar Planilla')
                ->icon('heroicon-o-lock-closed')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Cerrar Planilla de Nómina')
                ->modalDescription(function () {
                    $paid = $this->record->payrolls()->where('status', 'paid')->count();
                    $disbursed = $this->record->payrolls()->where('status', 'disbursed')->count();
                    $total = $paid + $disbursed;

                    $detail = $disbursed > 0
                        ? "{$paid} pagados y {$disbursed} acreditados por el banco"
                        : "{$paid} pagados";

                    $base = "La planilla {$this->record->name} tiene {$total} recibos listos ({$detail}). ".
                        'Una vez cerrada, no se podrán generar más recibos ni realizar modificaciones.';

                    $payrollEmployeeIds = $this->record->payrolls()->pluck('employee_id');

                    $missingCount = Employee::where('status', 'active')
                        ->whereHas(
                            'activeContract',
                            fn ($q) => $q
                                ->where('payroll_type', $this->record->frequency)
                                ->whereNotNull('salary')
                        )
                        ->when($this->record->company_id, fn ($q) => $q->whereHas('branch', fn ($q) => $q->where('company_id', $this->record->company_id)))
                        ->whereNotIn('id', $payrollEmployeeIds)
                        ->count();

                    if ($missingCount > 0) {
                        $noun = $missingCount === 1 ? 'empleado activo no tiene recibo' : 'empleados activos no tienen recibo';
                        $base .= " Atención: {$missingCount} {$noun} en esta planilla.";
                    }

                    return $base;
                })
                ->disabled(function () {
                    return $this->record->payrolls()->whereIn('status', ['draft', 'approved'])->exists();
                })
                ->tooltip(function () {
                    $draft = $this->record->payrolls()->where('status', 'draft')->count();
                    $approvedCash = $this->record->payrolls()
                        ->where('status', 'approved')->where('payment_method', 'cash')->count();
                    $approvedTransfer = $this->record->payrolls()
                        ->where('status', 'approved')->where('payment_method', 'transfer')->count();

                    if ($draft === 0 && $approvedCash === 0 && $approvedTransfer === 0) {
                        return null;
                    }

                    $parts = [];
                    if ($draft > 0) {
                        $noun = $draft === 1 ? 'recibo en borrador' : 'recibos en borrador';
                        $parts[] = "{$draft} {$noun}";
                    }
                    if ($approvedCash > 0) {
                        $noun = $approvedCash === 1 ? 'aprobado en efectivo sin marcar como pagado' : 'aprobados en efectivo sin marcar como pagados';
                        $parts[] = "{$approvedCash} {$noun}";
                    }
                    if ($approvedTransfer > 0) {
                        $noun = $approvedTransfer === 1 ? 'aprobado por transferencia pendiente de enviar al banco' : 'aprobados por transferencia pendientes de enviar al banco';
                        $parts[] = "{$approvedTransfer} {$noun}";
                    }

                    return implode(' · ', $parts);
                })
                ->action(function () {
                    $this->record->update([
                        'status' => 'closed',
                        'closed_at' => now(),
                    ]);

                    Notification::make()
                        ->success()
                        ->title('Planilla cerrada')
                        ->body("La planilla {$this->record->name} ha sido cerrada exitosamente.")
                        ->send();

                    $this->refreshFormData(['status', 'closed_at', 'updated_at']);
                })
                ->visible(fn () => $this->record->status === 'processing'
                    && $this->record->payrolls()->exists()
                    && auth()->user()->can('close_payroll_period')),

            Action::make('reopen_period')
                ->label('Reabrir Planilla')
                ->icon('heroicon-o-lock-open')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Reabrir Planilla de Nómina')
                ->modalDescription(
                    fn () => "¿Está seguro de reabrir la planilla {$this->record->name}? ".
                        'Esto permitirá realizar modificaciones nuevamente.'
                )
                ->action(function () {
                    $this->record->update([
                        'status' => 'processing',
                        'closed_at' => null,
                    ]);

                    Notification::make()
                        ->success()
                        ->title('Planilla reabierta')
                        ->body("La planilla {$this->record->name} ha sido reabierta exitosamente.")
                        ->send();

                    $this->refreshFormData(['status', 'closed_at', 'updated_at']);
                })
                ->visible(fn () => $this->record->status === 'closed' && auth()->user()->can('reopen_payroll_period')),

            ActionGroup::make([
                Action::make('create_payroll_batch')
                    ->label('Enviar al Banco')
                    ->icon('heroicon-o-building-library')
                    ->color('info')
                    ->mountUsing(function (Form $form, Action $action) {
                        $hasPayrolls = Payroll::query()
                            ->where('payroll_period_id', $this->record->id)
                            ->where('status', 'approved')
                            ->where('payment_method', 'transfer')
                            ->whereNull('disbursement_batch_id')
                            ->exists();

                        if (! $hasPayrolls) {
                            Notification::make()
                                ->warning()
                                ->title('Sin recibos disponibles')
                                ->body('No hay recibos aprobados por transferencia sin lote asignado en esta planilla.')
                                ->send();

                            $action->halt();

                            return;
                        }

                        $form->fill(['fecha_credito' => today()->format('Y-m-d')]);
                    })
                    ->modalHeading('Crear lote de pago bancario')
                    ->modalSubmitActionLabel('Crear lote')
                    ->form(function () {
                        $companyOptions = Company::query()
                            ->whereHas(
                                'branches.employees.payrolls',
                                fn ($q) => $q
                                    ->where('payroll_period_id', $this->record->id)
                                    ->where('status', 'approved')
                                    ->where('payment_method', 'transfer')
                                    ->whereNull('disbursement_batch_id')
                            )
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->toArray();

                        $periodId = $this->record->id;
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
                                ->helperText('Solo se muestran empresas con recibos aprobados por transferencia sin lote.'),

                            Placeholder::make('missing_accounts_warning')
                                ->label('')
                                ->content(function (Get $get) use ($periodId, $isSingleCompany, $defaultCompanyId) {
                                    $companyId = $isSingleCompany ? $defaultCompanyId : $get('company_id');

                                    if (! $companyId) {
                                        return '';
                                    }

                                    $missing = Payroll::query()
                                        ->where('payroll_period_id', $periodId)
                                        ->where('status', 'approved')
                                        ->where('payment_method', 'transfer')
                                        ->whereNull('disbursement_batch_id')
                                        ->whereHas('employee.branch', fn ($q) => $q->where('company_id', $companyId))
                                        ->whereDoesntHave(
                                            'employee.bankAccounts',
                                            fn ($q) => $q->where('is_primary', true)->where('status', 'active')
                                        )
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
                                    $names = $missing->map(fn ($p) => $p->employee->full_name)->join(', ');

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
                                ->helperText('Fecha en que el banco acreditará los fondos en las cuentas de los empleados.'),

                            Textarea::make('notes')
                                ->label('Notas')
                                ->placeholder('Observaciones opcionales...')
                                ->rows(2),
                        ];
                    })
                    ->action(function (array $data) {
                        $companyOptions = Company::query()
                            ->whereHas(
                                'branches.employees.payrolls',
                                fn ($q) => $q
                                    ->where('payroll_period_id', $this->record->id)
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

                        $missingAccounts = Payroll::query()
                            ->where('payroll_period_id', $this->record->id)
                            ->where('status', 'approved')
                            ->where('payment_method', 'transfer')
                            ->whereNull('disbursement_batch_id')
                            ->whereHas('employee.branch', fn ($q) => $q->where('company_id', $companyId))
                            ->whereDoesntHave(
                                'employee.bankAccounts',
                                fn ($q) => $q->where('is_primary', true)->where('status', 'active')
                            )
                            ->with('employee')
                            ->get();

                        if ($missingAccounts->isNotEmpty()) {
                            Notification::make()
                                ->danger()
                                ->title('Hay empleados sin cuenta bancaria')
                                ->body('Registre las cuentas bancarias faltantes antes de crear el lote.')
                                ->send();

                            return;
                        }

                        $batch = DisbursementBatch::create([
                            'type' => 'payroll',
                            'company_id' => $companyId,
                            'fecha_credito' => $data['fecha_credito'],
                            'status' => 'pending',
                            'notes' => $data['notes'] ?? null,
                            'created_by_id' => Auth::id(),
                        ]);

                        $updated = Payroll::query()
                            ->where('payroll_period_id', $this->record->id)
                            ->where('status', 'approved')
                            ->where('payment_method', 'transfer')
                            ->whereNull('disbursement_batch_id')
                            ->whereHas('employee.branch', fn ($q) => $q->where('company_id', $companyId))
                            ->update(['disbursement_batch_id' => $batch->id]);

                        Notification::make()
                            ->success()
                            ->title('Lote creado')
                            ->body("Se creó el lote con {$updated} ".($updated === 1 ? 'recibo' : 'recibos').'. Redirigiendo...')
                            ->send();

                        $this->redirect(DisbursementBatchResource::getUrl('view', ['record' => $batch]));
                    })
                    ->visible(fn () => $this->record->status === 'processing' && auth()->user()->can('create_disbursement_batch')),

                Action::make('mark_cash_paid')
                    ->label('Marcar Efectivo como Pagado')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Marcar recibos en efectivo como pagados')
                    ->modalDescription(function () {
                        $count = $this->record->payrolls()
                            ->where('payment_method', 'cash')
                            ->where('status', 'approved')
                            ->count();

                        return "Se marcarán como Pagados {$count} recibo(s) aprobados con método de pago Efectivo.";
                    })
                    ->modalSubmitActionLabel('Sí, marcar como pagados')
                    ->before(function (Action $action) {
                        $count = $this->record->payrolls()
                            ->where('payment_method', 'cash')
                            ->where('status', 'approved')
                            ->count();

                        if ($count === 0) {
                            Notification::make()
                                ->warning()
                                ->title('Sin recibos para marcar')
                                ->body('No hay recibos aprobados con método de pago Efectivo en esta planilla.')
                                ->send();

                            $action->cancel();
                        }
                    })
                    ->action(function () {
                        $updated = $this->record->payrolls()
                            ->where('payment_method', 'cash')
                            ->where('status', 'approved')
                            ->update(['status' => 'paid']);

                        Notification::make()
                            ->success()
                            ->title('Recibos marcados como pagados')
                            ->body("{$updated} recibo(s) en efectivo fueron marcados como Pagados.")
                            ->send();

                        $this->js('window.location.reload()');
                    })
                    ->visible(fn () => in_array($this->record->status, ['processing', 'closed'])
                        && $this->record->payrolls()->where('payment_method', 'cash')->where('status', 'approved')->exists()
                        && auth()->user()->can('mark_paid_payroll')),

                Action::make('salary_report')
                    ->label('Reporte de Salarios')
                    ->icon('heroicon-o-document-chart-bar')
                    ->color('gray')
                    ->url(fn () => SalaryReport::getUrl().'?tableFilters[period_id][value]='.$this->record->id)
                    ->openUrlInNewTab()
                    ->visible(fn () => $this->record->payrolls()->exists()),

                Action::make('regenerate_payrolls')
                    ->label('Regenerar Recibos')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Regenerar Todos los Recibos')
                    ->modalDescription(function () {
                        $approved = $this->record->payrolls()->where('status', 'approved')->count();
                        $base = "¿Está seguro de regenerar TODOS los recibos de la planilla {$this->record->name}? ".
                            'Se recalcularán percepciones, deducciones, horas extras, ausencias, cuotas de préstamos, adelantos y cuotas de retiro de mercaderías.';
                        if ($approved > 0) {
                            $noun = $approved === 1 ? 'recibo aprobado será revertido' : 'recibos aprobados serán revertidos';
                            $base .= " Atención: {$approved} {$noun} a borrador y requerirán nueva aprobación.";
                        }

                        return $base;
                    })
                    ->modalSubmitActionLabel('Sí, regenerar')
                    ->action(function (PayrollService $payrollService) {
                        $payrolls = $this->record->payrolls()->whereIn('status', ['draft', 'approved'])->with('employee')->get();

                        if ($payrolls->isEmpty()) {
                            Notification::make()
                                ->warning()
                                ->title('Sin recibos para regenerar')
                                ->body('No hay recibos en estado borrador o aprobado para regenerar.')
                                ->send();

                            return;
                        }

                        $count = 0;
                        $failedEmployees = [];

                        foreach ($payrolls as $payroll) {
                            try {
                                if ($payroll->status === 'approved') {
                                    $payroll->update(['status' => 'draft']);
                                }
                                $payrollService->regenerateForEmployee($payroll);
                                $count++;
                            } catch (\Throwable) {
                                $failedEmployees[] = $payroll->employee->full_name;
                            }
                        }

                        if (! empty($failedEmployees)) {
                            $names = implode(', ', $failedEmployees);
                            Notification::make()
                                ->warning()
                                ->title('Regeneración parcial')
                                ->body("Se regeneraron {$count} recibos. Fallaron: {$names}. Revise el log para más detalles.")
                                ->duration(10000)
                                ->send();
                        } else {
                            Notification::make()
                                ->success()
                                ->title('Recibos regenerados')
                                ->body("Se regeneraron exitosamente {$count} recibos de nómina.")
                                ->send();
                        }
                    })
                    ->visible(fn () => $this->record->status === 'processing' && $this->record->payrolls()->whereIn('status', ['draft', 'approved'])->exists()),

                Action::make('revert_to_draft')
                    ->label('Revertir a Borrador')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('Revertir planilla a Borrador')
                    ->modalDescription(function () {
                        $draftCount = $this->record->payrolls()->where('status', 'draft')->count();

                        return "Esta acción revertirá la planilla \"{$this->record->name}\" a estado Borrador ".
                            "y eliminará los {$draftCount} recibo(s) en borrador. ".
                            'Los recibos aprobados o pagados impiden esta acción.';
                    })
                    ->before(function (Action $action) {
                        $blockedCount = $this->record->payrolls()->whereIn('status', ['approved', 'paid'])->count();
                        if ($blockedCount > 0) {
                            Notification::make()
                                ->danger()
                                ->title('No se puede revertir la planilla')
                                ->body("Hay {$blockedCount} recibo(s) aprobado(s) o pagado(s). Solo se puede revertir si todos los recibos están en borrador.")
                                ->duration(10000)
                                ->send();
                            $action->cancel();
                        }
                    })
                    ->action(function () {
                        $deleted = $this->record->payrolls()->where('status', 'draft')->delete();
                        $this->record->update(['status' => 'draft']);

                        Notification::make()
                            ->success()
                            ->title('Planilla revertida a Borrador')
                            ->body("Se eliminaron {$deleted} recibo(s) en borrador y la planilla volvió a estado Borrador.")
                            ->send();

                        $this->redirect($this->getResource()::getUrl('view', ['record' => $this->record]));
                    })
                    ->visible(fn () => $this->record->status === 'processing'),

                EditAction::make()
                    ->label('Editar')
                    ->icon('heroicon-o-pencil-square')
                    ->color('primary')
                    ->visible(fn () => $this->record->status !== 'closed'),
            ])
                ->label('Más acciones')
                ->icon('heroicon-m-chevron-down')
                ->color('gray')
                ->button(),
        ];
    }
}
