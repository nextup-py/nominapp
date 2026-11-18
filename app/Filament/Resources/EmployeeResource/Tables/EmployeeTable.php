<?php

namespace App\Filament\Resources\EmployeeResource\Tables;

use App\Filament\Actions\EmployeeRehireAction;
use App\Filament\Actions\EmployeeTerminationAction;
use App\Filament\Actions\MobileMarkingFields;
use App\Filament\Actions\ReportingLineActions;
use App\Filament\Actions\ScheduleAssignmentActions;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\FaceEnrollment;
use App\Services\EmployeeProfileCompleteness;
use App\Settings\GeneralSettings;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/** Listado de empleados. */
final class EmployeeTable
{
    /** Define la tabla para listar empleados. */
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['branch.company', 'activeContract'])->withExists(['addresses', 'bankAccounts'])->withLatestConsentVersion())
            ->columns([
                ImageColumn::make('photo')
                    ->label('Foto')
                    ->circular()
                    ->defaultImageUrl(fn ($record) => $record->avatar_url)
                    ->toggleable(),

                TextColumn::make('full_name')
                    ->label('Nombre')
                    ->getStateUsing(fn (Employee $record): string => $record->full_name)
                    ->description(fn (Employee $record): string => 'CI: '.$record->ci)
                    ->searchable(query: fn (Builder $query, string $search) => $query
                        ->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('ci', 'like', "%{$search}%")
                    )
                    ->sortable(['first_name', 'last_name'])
                    ->wrap(),

                TextColumn::make('activeContract.position.name')
                    ->label('Cargo')
                    ->icon('heroicon-o-briefcase')
                    ->description(fn (Employee $record): ?string => $record->branch?->name)
                    // Filament ordenaría con una subconsulta sobre todos los contratos del empleado: con más de uno
                    // (uno vencido y otro vigente, renovaciones) MySQL falla con «Subquery returns more than 1 row».
                    // Se ordena por el cargo del contrato vigente más reciente, el mismo que muestra la columna.
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy(
                        Contract::query()
                            ->select('positions.name')
                            ->join('positions', 'positions.id', '=', 'contracts.position_id')
                            ->whereColumn('contracts.employee_id', 'employees.id')
                            ->whereIn('contracts.status', ['active', 'suspended'])
                            ->orderByDesc('contracts.start_date')
                            ->limit(1),
                        $direction,
                    ))
                    ->searchable()
                    ->toggleable()
                    ->wrap()
                    ->placeholder('Sin contrato vigente'),

                TextColumn::make('reportsTo.full_name')
                    ->label('Reporta a')
                    ->icon('heroicon-o-user')
                    ->placeholder('Sin superior directo')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->wrap(),

                TextColumn::make('branch.name')
                    ->label('Sucursal')
                    ->icon('heroicon-o-building-storefront')
                    ->sortable()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->wrap()
                    ->badge()
                    ->color('info'),

                TextColumn::make('profile_completeness')
                    ->label('Ficha')
                    ->state(fn (Employee $record): int => EmployeeProfileCompleteness::percent($record))
                    ->formatStateUsing(fn (int $state): string => $state.'%')
                    ->badge()
                    ->color(fn (int $state): string => $state >= 100 ? 'success' : ($state >= 70 ? 'warning' : 'danger'))
                    ->tooltip(fn (Employee $record): ?string => ($missing = EmployeeProfileCompleteness::missing($record))
                        ? 'Falta: '.implode(', ', array_map(fn (string $key): string => EmployeeProfileCompleteness::labels()[$key], $missing))
                        : 'Ficha completa')
                    ->toggleable(),

                TextColumn::make('status')
                    ->label('Estado')
                    ->icon(fn (Employee $record): string => $record->status_icon)
                    ->color(fn (Employee $record): string => $record->status_color)
                    ->formatStateUsing(fn (Employee $record): string => $record->status_label)
                    ->badge()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('phone')
                    ->label('Contacto')
                    ->icon('heroicon-o-phone')
                    ->default('-')
                    ->url(fn (Employee $record): ?string => $record->phone ? $record->contact_url : null)
                    ->openUrlInNewTab()
                    ->tooltip(fn (Employee $record): string => $record->phone ? 'Haz clic para enviar WhatsApp' : 'Sin teléfono registrado')
                    ->sortable()
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('activeContract.payroll_type')
                    ->label('Nómina')
                    ->icon('heroicon-o-calendar')
                    ->formatStateUsing(fn ($state): string => Employee::getPayrollTypeOptions()[$state] ?? 'Sin contrato')
                    ->badge()
                    ->color('info')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('mobile_status')
                    ->label('Celular')
                    ->getStateUsing(fn (Employee $record): string => Employee::getMobileStatusLabels()[$record->mobileStatus()])
                    ->badge()
                    ->color(fn (Employee $record): string => Employee::getMobileStatusColors()[$record->mobileStatus()])
                    ->tooltip(fn (Employee $record): ?string => $record->mobile_last_heartbeat_at
                        ? 'Última conexión: '.$record->mobile_last_heartbeat_at->format('d/m/Y H:i')
                        : null)
                    ->toggleable(),

                TextColumn::make('mobile_marking_allowed')
                    ->label('Marca desde el celular')
                    ->getStateUsing(fn (Employee $record): string => $record->mobileMarkingLabel())
                    ->badge()
                    ->color(fn (Employee $record): string => $record->canMarkFromMobile() ? 'success' : 'gray')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('privacy_consent')
                    ->label('Aviso de privacidad')
                    ->getStateUsing(fn (Employee $record): string => Employee::getPrivacyConsentStatusLabels()[$record->privacyConsentStatus()])
                    ->badge()
                    ->color(fn (Employee $record): string => Employee::getPrivacyConsentStatusColors()[$record->privacyConsentStatus()])
                    ->tooltip('Si el empleado aceptó la versión vigente del aviso de privacidad de su empresa')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('mobile_linked_at')
                    ->label('Dispositivo vinculado')
                    ->formatStateUsing(fn ($state) => $state ? 'Vinculado' : 'No vinculado')
                    ->badge()
                    ->color(fn ($state) => $state ? 'success' : 'gray')
                    ->tooltip(fn (Employee $record) => $record->mobile_linked_at?->format('d/m/Y H:i'))
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('mobile_last_heartbeat_at')
                    ->label('Último sync dispositivo')
                    ->since()
                    ->placeholder('Sin sincronizar')
                    ->tooltip(fn (Employee $record) => $record->mobile_last_heartbeat_at?->format('d/m/Y H:i'))
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Registrado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->label('Actualizado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('company_id')
                    ->label('Empresa')
                    ->options(
                        fn () => Company::orderBy('name')
                            ->get()
                            ->mapWithKeys(fn ($c) => [$c->id => $c->name.($c->trade_name ? ' — '.$c->trade_name : '')])
                    )
                    ->placeholder('Todas las empresas')
                    ->searchable()
                    ->native(false)
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'])) {
                            return $query;
                        }

                        return $query->whereHas('branch', fn ($q) => $q->where('company_id', $data['value']));
                    }),

                SelectFilter::make('branch_id')
                    ->label('Sucursal')
                    ->relationship('branch', 'name')
                    ->placeholder('Todas las sucursales')
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->multiple(),

                SelectFilter::make('department_id')
                    ->label('Departamento')
                    ->options(fn () => Department::orderBy('name')->pluck('name', 'id'))
                    ->placeholder('Todos los departamentos')
                    ->searchable()
                    ->native(false)
                    ->multiple()
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['values'])) {
                            return $query;
                        }

                        return $query->whereHas('activeContract.position', fn ($q) => $q->whereIn('department_id', $data['values']));
                    }),

                SelectFilter::make('payroll_type')
                    ->label('Tipo de nómina')
                    ->options(Employee::getPayrollTypeOptions())
                    ->placeholder('Todos los tipos')
                    ->native(false)
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'])) {
                            return $query;
                        }

                        return $query->whereHas('activeContract', fn ($q) => $q->where('payroll_type', $data['value']));
                    }),

                Filter::make('hire_date')
                    ->label('Inicio de contrato activo')
                    ->form([
                        DatePicker::make('hired_from')
                            ->label('Desde')
                            ->native(false)
                            ->closeOnDateSelection(),
                        DatePicker::make('hired_until')
                            ->label('Hasta')
                            ->native(false)
                            ->closeOnDateSelection(),
                    ])
                    ->columns(2)
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['hired_from'],
                                fn (Builder $query, $date): Builder => $query->whereHas('activeContract', fn ($q) => $q->where('start_date', '>=', Carbon::parse($date)->startOfDay())),
                            )
                            ->when(
                                $data['hired_until'],
                                fn (Builder $query, $date): Builder => $query->whereHas('activeContract', fn ($q) => $q->where('start_date', '<=', Carbon::parse($date)->endOfDay())),
                            );
                    }),

                TernaryFilter::make('has_face')
                    ->label('Reconocimiento facial')
                    ->placeholder('Todos')
                    ->trueLabel('Con rostro registrado')
                    ->falseLabel('Sin rostro registrado')
                    ->queries(
                        true: fn (Builder $query) => $query->withFace(),
                        false: fn (Builder $query) => $query->withoutFace(),
                        blank: fn (Builder $query) => $query,
                    ),

                TernaryFilter::make('mobile_marking')
                    ->label('Marcación desde el celular')
                    ->placeholder('Todos')
                    ->trueLabel('Pueden marcar desde el celular')
                    ->falseLabel('Solo desde el terminal')
                    ->queries(
                        true: fn (Builder $query) => $query->mobileMarking(true),
                        false: fn (Builder $query) => $query->mobileMarking(false),
                        blank: fn (Builder $query) => $query,
                    ),

                SelectFilter::make('mobile_status')
                    ->label('Estado del celular')
                    ->options(Employee::getMobileStatusLabels())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->mobileStatus($data['value'])
                        : $query),

                SelectFilter::make('privacy_consent')
                    ->label('Aviso de privacidad')
                    ->options([
                        'current' => 'Aceptó la versión vigente',
                        'outdated' => 'Aceptó una versión anterior',
                        'none' => 'Sin registro de aceptación',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->privacyConsentStatus($data['value'])
                        : $query),

                SelectFilter::make('profile_missing')
                    ->label('Ficha: falta')
                    ->options(EmployeeProfileCompleteness::labels())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->missingProfileItem($data['value'])
                        : $query),

                Filter::make('without_manager')
                    ->label('Sin superior directo')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->whereNull('reports_to_id')),

                Filter::make('without_schedule')
                    ->label('Sin horario asignado')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->withoutSchedule()),

                Filter::make('expired_contract')
                    ->label('Contrato vencido sin renovar')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->withExpiredContract()),

                SelectFilter::make('birthday_month')
                    ->label('Mes de cumpleaños')
                    ->options([
                        1 => 'Enero',
                        2 => 'Febrero',
                        3 => 'Marzo',
                        4 => 'Abril',
                        5 => 'Mayo',
                        6 => 'Junio',
                        7 => 'Julio',
                        8 => 'Agosto',
                        9 => 'Septiembre',
                        10 => 'Octubre',
                        11 => 'Noviembre',
                        12 => 'Diciembre',
                    ])
                    ->placeholder('Todos los meses')
                    ->native(false)
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'])) {
                            return $query;
                        }

                        return $query->whereMonth('birth_date', $data['value']);
                    }),

            ])
            ->actions([
                ActionGroup::make([
                    ScheduleAssignmentActions::row(),
                    ...MobileMarkingFields::rowActions(),

                    Action::make('capture_face')
                        ->label(fn (Employee $record): string => $record->has_face ? 'Re-enrolar rostro' : 'Enrolar rostro')
                        ->icon('heroicon-o-camera')
                        ->tooltip(fn (Employee $record): string => $record->has_face ? 'Capturar nuevo descriptor facial (reemplaza el actual)' : 'Registrar rostro del empleado por primera vez')
                        ->url(fn (Employee $record): string => route('face.capture', $record))
                        ->color(fn (Employee $record): string => $record->has_face ? 'warning' : 'success')
                        ->authorize(fn (): bool => auth()->user()?->can('update_employee') ?? false)
                        ->visible(fn (Employee $record): bool => $record->status === 'active'),

                    Action::make('generate_enrollment')
                        ->label('Generar enlace de enrolamiento')
                        ->icon('heroicon-o-link')
                        ->color('info')
                        ->tooltip('Generar enlace temporal para que el empleado registre su rostro desde su propio dispositivo')
                        ->authorize(fn (): bool => auth()->user()?->can('update_employee') ?? false)
                        ->visible(fn (Employee $record): bool => $record->status === 'active')
                        ->requiresConfirmation()
                        ->modalHeading('Generar Enlace de Registro Facial')
                        ->modalDescription(fn (Employee $record) => "Se generará un enlace temporal para que {$record->first_name} {$record->last_name} registre su rostro. El enlace expirará en ".app(GeneralSettings::class)->face_enrollment_expiry_hours.' horas.')
                        ->modalSubmitActionLabel('Generar Enlace')
                        ->action(function (Employee $record) {
                            $settings = app(GeneralSettings::class);
                            $enrollment = FaceEnrollment::createForEmployee(
                                $record,
                                Auth::id(),
                                $settings->face_enrollment_expiry_hours
                            );

                            $url = route('face-enrollment.show', $enrollment->token);

                            Notification::make()
                                ->success()
                                ->title('Enlace generado — expira en '.$settings->face_enrollment_expiry_hours.'h')
                                ->body($url)
                                ->persistent()
                                ->actions([
                                    NotificationAction::make('send_whatsapp')
                                        ->label('Enviar por WhatsApp')
                                        ->url('https://api.whatsapp.com/send?phone=595'.ltrim($record->phone ?? '', '0').'&text='.urlencode("Hola {$record->first_name}, usa este enlace para registrar tu rostro: {$url}"))
                                        ->openUrlInNewTab()
                                        ->visible(fn () => filled($record->phone)),
                                ])
                                ->send();
                        }),

                    EmployeeTerminationAction::make(Action::class),
                    EmployeeRehireAction::make(Action::class),

                    Action::make('change_status')
                        ->label('Cambiar estado')
                        ->icon('heroicon-o-arrow-path')
                        ->color('gray')
                        ->tooltip('Cambiar el estado del empleado (activo o inactivo)')
                        ->modalSubmitActionLabel('Cambiar estado')
                        ->authorize(fn (): bool => auth()->user()?->can('update_employee') ?? false)
                        ->form([
                            Select::make('status')
                                ->label('Nuevo estado')
                                ->options(
                                    fn (Employee $record) => collect(Employee::getStatusOptions())
                                        ->except($record->status)
                                        ->toArray()
                                )
                                ->required()
                                ->native(false),
                        ])
                        ->action(function (Employee $record, array $data, Action $action): void {
                            if ($data['status'] === 'inactive' && $record->activeContract !== null) {
                                Notification::make()
                                    ->danger()
                                    ->title('No se puede desactivar')
                                    ->body('El empleado tiene un contrato vigente. Usá «Dar de baja» desde su ficha para terminar el contrato y registrar la baja.')
                                    ->send();
                                $action->halt();

                                return;
                            }

                            if ($data['status'] === 'active' && ($blocker = $record->activationBlocker())) {
                                Notification::make()->danger()->title('No se puede activar')->body($blocker)->send();
                                $action->halt();

                                return;
                            }

                            $record->update(['status' => $data['status']]);

                            Notification::make()
                                ->success()
                                ->title('Estado actualizado')
                                ->body("El estado de {$record->full_name} cambió a: ".Employee::getStatusOptions()[$data['status']])
                                ->send();
                        }),

                    Action::make('revoke_mobile_session')
                        ->label('Revocar sesión móvil')
                        ->icon('heroicon-o-device-phone-mobile')
                        ->color('danger')
                        ->tooltip('Desvincula el dispositivo vinculado a este empleado para marcación offline — requerirá vincularse de nuevo desde /vincular-dispositivo')
                        ->authorize(fn (): bool => auth()->user()?->can('update_employee_device') ?? false)
                        ->visible(fn (Employee $record): bool => $record->hasMobileLinked())
                        ->requiresConfirmation()
                        ->modalHeading('Revocar sesión móvil')
                        ->modalDescription(function (Employee $record): string {
                            $base = "El dispositivo vinculado de {$record->first_name} {$record->last_name} perderá acceso a la marcación offline de inmediato. Deberá vincularse de nuevo con CI + fecha de nacimiento.";

                            $linkedAt = $record->mobile_linked_at?->format('d/m/Y H:i');
                            $lastSync = $record->mobile_last_heartbeat_at?->diffForHumans() ?? 'nunca sincronizó';

                            return "{$base} Vinculado el {$linkedAt}. Último sync: {$lastSync}.";
                        })
                        ->modalSubmitActionLabel('Sí, revocar')
                        ->action(function (Employee $record) {
                            $record->revokeMobileToken();

                            Notification::make()
                                ->success()
                                ->title('Sesión móvil revocada')
                                ->body('El empleado deberá vincular su dispositivo de nuevo para volver a marcar offline.')
                                ->send();
                        }),
                ]),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    ScheduleAssignmentActions::bulk(),

                    MobileMarkingFields::bulk(),

                    BulkAction::make('activate')
                        ->label('Activar seleccionados')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->authorize(fn (): bool => auth()->user()?->can('update_employee') ?? false)
                        ->requiresConfirmation()
                        ->modalHeading('Activar empleados')
                        ->modalDescription('Solo se activan los empleados inactivos que tienen un contrato vigente; el resto se omite (para esos, usá «Reingresar»).')
                        ->modalSubmitActionLabel('Sí, activar')
                        ->action(function (Collection $records): void {
                            $records->loadMissing('activeContract');
                            $activated = 0;
                            $skipped = 0;

                            foreach ($records as $employee) {
                                if ($employee->status === 'active') {
                                    continue;
                                }

                                if ($employee->activationBlocker() !== null) {
                                    $skipped++;

                                    continue;
                                }

                                $employee->update(['status' => 'active']);
                                $activated++;
                            }

                            Notification::make()
                                ->{$activated === 0 ? 'warning' : 'success'}()
                                ->title($activated === 0 ? 'Sin cambios' : 'Empleados activados')
                                ->body("{$activated} activado(s)".($skipped > 0 ? ", {$skipped} omitido(s) por no tener contrato vigente" : '').'.')
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('deactivate')
                        ->label('Desactivar seleccionados')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->authorize(fn (): bool => auth()->user()?->can('update_employee') ?? false)
                        ->requiresConfirmation()
                        ->modalHeading('Desactivar empleados')
                        ->modalDescription(function (Collection $records) {
                            $records->loadMissing('activeContract');
                            $withContract = $records->filter(fn ($e) => $e->activeContract !== null)->count();
                            $base = "Se desactivarán {$records->count()} empleado(s): se cierran su horario y sus percepciones y deducciones vigentes.";

                            return $withContract > 0
                                ? $base." Atención: {$withContract} empleado(s) con contrato activo serán ignorados (procesá una Liquidación para darlos de baja)."
                                : $base;
                        })
                        ->modalSubmitActionLabel('Sí, desactivar')
                        ->action(function (Collection $records): void {
                            $records->loadMissing('activeContract');
                            $withContract = $records->filter(fn ($e) => $e->activeContract !== null);
                            $toDeactivate = $records->reject(fn ($e) => $e->activeContract !== null);

                            foreach ($toDeactivate as $employee) {
                                $employee->update(['status' => 'inactive']);
                            }

                            $parts = [];
                            if ($toDeactivate->isNotEmpty()) {
                                $parts[] = "{$toDeactivate->count()} desactivado(s)";
                            }
                            if ($withContract->isNotEmpty()) {
                                $parts[] = "{$withContract->count()} ignorado(s) por tener contrato activo";
                            }

                            Notification::make()
                                ->{$toDeactivate->isEmpty() ? 'warning' : 'success'}()
                                ->title($toDeactivate->isEmpty() ? 'Sin cambios' : 'Empleados desactivados')
                                ->body(implode(', ', $parts).'.')
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    ReportingLineActions::bulkAssign(),

                    BulkAction::make('set_advance_percent')
                        ->label('Configurar adelanto automático')
                        ->icon('heroicon-o-banknotes')
                        ->color('info')
                        ->authorize(fn (): bool => auth()->user()?->can('update_contract') ?? false)
                        ->form([
                            Select::make('advance_percent')
                                ->label('Porcentaje de adelanto')
                                ->options([
                                    '' => 'Sin adelanto automático',
                                    '10' => '10%',
                                    '15' => '15%',
                                    '20' => '20%',
                                    '25' => '25% (máximo legal)',
                                ])
                                ->native(false)
                                ->required()
                                ->helperText('Se actualizará el contrato activo mensualizado de cada empleado seleccionado.'),
                        ])
                        ->requiresConfirmation()
                        ->modalHeading('Configurar adelanto automático')
                        ->modalDescription('El sistema generará automáticamente el adelanto el 1° de cada mes para los empleados configurados.')
                        ->modalSubmitActionLabel('Aplicar configuración')
                        ->action(function (Collection $records, array $data): void {
                            $percent = $data['advance_percent'] !== '' ? (int) $data['advance_percent'] : null;
                            $updated = 0;

                            foreach ($records as $employee) {
                                $contract = $employee->activeContract;
                                if ($contract && $contract->salary_type === 'mensual') {
                                    $contract->update(['advance_percent' => $percent]);
                                    $updated++;
                                }
                            }

                            $body = $percent
                                ? "Adelanto del {$percent}% configurado en {$updated} contrato(s)."
                                : "Adelanto automático desactivado en {$updated} contrato(s).";

                            Notification::make()
                                ->success()
                                ->title('Adelanto automático actualizado')
                                ->body($body)
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No hay empleados registrados')
            ->emptyStateDescription('Comienza agregando tu primer empleado al sistema')
            ->emptyStateIcon('heroicon-o-user-circle');
    }
}
