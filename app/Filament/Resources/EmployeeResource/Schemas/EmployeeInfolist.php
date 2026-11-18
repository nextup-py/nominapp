<?php

namespace App\Filament\Resources\EmployeeResource\Schemas;

use App\Models\Employee;
use App\Models\FaceEnrollment;
use App\Services\EmployeeProfileCompleteness;
use App\Settings\GeneralSettings;
use Filament\Infolists\Components\Actions\Action as InfolistAction;
use Filament\Infolists\Components\Grid as InfoGrid;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\Section as InfoSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

/** Ficha (detalle) del empleado. */
final class EmployeeInfolist
{
    /** Define la infolist para la vista de detalle del empleado. */
    public static function configure(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            InfoSection::make('Ficha incompleta')
                ->icon('heroicon-o-exclamation-triangle')
                ->iconColor('warning')
                ->visible(fn (Employee $record): bool => EmployeeProfileCompleteness::missing($record) !== [])
                ->schema([
                    TextEntry::make('_profile_missing')
                        ->hiddenLabel()
                        ->getStateUsing(fn (Employee $record): string => 'Falta cargar: '.implode(', ', array_map(fn (string $key): string => EmployeeProfileCompleteness::labels()[$key], EmployeeProfileCompleteness::missing($record))).' ('.EmployeeProfileCompleteness::percent($record).'% completa).'),
                ]),

            InfoSection::make('Datos Personales')
                ->icon('heroicon-o-user')
                ->schema([
                    InfoGrid::make(4)
                        ->schema([
                            ImageEntry::make('photo')
                                ->hiddenLabel()
                                ->circular()
                                ->defaultImageUrl(fn (Employee $record) => $record->avatar_url)
                                ->columnSpan(1),

                            InfoGrid::make(3)
                                ->columnSpan(3)
                                ->schema([
                                    TextEntry::make('ci')
                                        ->label('Cédula de Identidad')
                                        ->icon('heroicon-o-identification')
                                        ->copyable()
                                        ->copyMessage('CI copiada')
                                        ->badge()
                                        ->color('gray'),

                                    TextEntry::make('full_name')
                                        ->label('Nombre completo')
                                        ->getStateUsing(fn (Employee $record) => $record->full_name)
                                        ->icon('heroicon-o-user'),

                                    TextEntry::make('status')
                                        ->label('Estado')
                                        ->getStateUsing(fn (Employee $record) => $record->status_label)
                                        ->badge()
                                        ->color(fn (Employee $record) => $record->status_color)
                                        ->icon(fn (Employee $record) => $record->status_icon),

                                    TextEntry::make('birth_date')
                                        ->label('Fecha de nacimiento')
                                        ->getStateUsing(
                                            fn (Employee $record) => $record->birth_date
                                                ? $record->birth_date->format('d/m/Y').' · '.$record->age_description
                                                : null
                                        )
                                        ->icon('heroicon-o-cake')
                                        ->placeholder('No registrada'),

                                    TextEntry::make('gender')
                                        ->label('Género')
                                        ->getStateUsing(fn (Employee $record) => $record->gender_label)
                                        ->badge()
                                        ->color(fn (Employee $record) => $record->gender_color)
                                        ->placeholder('No registrado'),

                                    TextEntry::make('marital_status')
                                        ->label('Estado civil')
                                        ->getStateUsing(fn (Employee $record) => $record->marital_status_label)
                                        ->placeholder('No registrado'),
                                ]),
                        ]),

                    InfoGrid::make(4)
                        ->schema([
                            TextEntry::make('phone')
                                ->label('Teléfono')
                                ->icon('heroicon-o-phone')
                                ->url(fn (Employee $record) => $record->phone ? $record->contact_url : null)
                                ->openUrlInNewTab()
                                ->copyable()
                                ->copyMessage('Teléfono copiado')
                                ->placeholder('Sin teléfono'),

                            TextEntry::make('email')
                                ->label('Correo electrónico')
                                ->icon('heroicon-o-envelope')
                                ->copyable()
                                ->copyMessage('Email copiado')
                                ->placeholder('Sin email'),

                            TextEntry::make('nationality')
                                ->label('Nacionalidad')
                                ->placeholder('No registrada'),

                            TextEntry::make('maternity_protection_until')
                                ->label('Protección de maternidad')
                                ->date('d/m/Y')
                                ->badge()
                                ->color(fn (Employee $record) => $record->isUnderMaternityProtection() ? 'danger' : 'gray')
                                ->visible(fn (Employee $record): bool => $record->maternity_protection_until !== null),

                        ]),
                ])
                ->collapsible(),

            InfoSection::make('Ubicación en la organización')
                ->icon('heroicon-o-building-office-2')
                ->columns(3)
                ->schema([
                    TextEntry::make('branch.company.name')
                        ->label('Empresa')
                        ->icon('heroicon-o-building-office-2')
                        ->badge()
                        ->color('info'),

                    TextEntry::make('branch.name')
                        ->label('Sucursal')
                        ->icon('heroicon-o-building-storefront')
                        ->badge()
                        ->color('info'),

                    TextEntry::make('activeContract.position.name')
                        ->label('Cargo')
                        ->icon('heroicon-o-briefcase')
                        ->badge()
                        ->color('primary')
                        ->hidden(fn (Employee $record) => $record->activeContract === null),

                    TextEntry::make('activeContract.position.department.name')
                        ->label('Departamento')
                        ->icon('heroicon-o-building-office')
                        ->badge()
                        ->color('gray')
                        ->hidden(fn (Employee $record) => $record->activeContract === null),

                    TextEntry::make('reportsTo.full_name')
                        ->label('Reporta a')
                        ->icon('heroicon-o-arrow-up-circle')
                        ->getStateUsing(fn (Employee $record) => $record->reportsTo
                            ? $record->reportsTo->full_name.($record->reportsTo->activeContract?->position ? ' · '.$record->reportsTo->activeContract->position->name : '')
                            : null
                        )
                        ->placeholder('Sin superior asignado'),

                    TextEntry::make('subordinates_count')
                        ->label('Subordinados directos')
                        ->icon('heroicon-o-arrow-down-circle')
                        ->getStateUsing(fn (Employee $record) => $record->subordinates()->where('status', 'active')->count())
                        ->suffix(fn (Employee $record) => $record->subordinates()->where('status', 'active')->count() === 1 ? ' empleado' : ' empleados')
                        ->badge()
                        ->color(fn (Employee $record) => $record->subordinates()->where('status', 'active')->count() > 0 ? 'primary' : 'gray'),
                ])
                ->collapsible(),

            InfoSection::make('Contrato')
                ->icon('heroicon-o-document-text')
                ->columns(3)
                ->schema([
                    // Aviso cuando no hay contrato activo
                    TextEntry::make('_contract_notice')
                        ->hiddenLabel()
                        ->getStateUsing(fn () => 'Este empleado no tiene un contrato activo. Creá uno desde la pestaña "Contratos".')
                        ->icon('heroicon-o-exclamation-triangle')
                        ->color('warning')
                        ->columnSpanFull()
                        ->hidden(fn (Employee $record) => $record->activeContract !== null),

                    TextEntry::make('hire_date')
                        ->label('Antigüedad')
                        ->getStateUsing(
                            fn (Employee $record) => $record->hire_date
                                ? $record->hire_date->format('d/m/Y').' · '.$record->antiquity_description
                                : null
                        )
                        ->hidden(fn (Employee $record) => $record->activeContract === null),

                    TextEntry::make('employment_type')
                        ->label('Tipo de empleo')
                        ->getStateUsing(fn (Employee $record) => $record->employment_type_label)
                        ->badge()
                        ->color(fn (Employee $record) => $record->employment_type_color ?? 'gray')
                        ->icon(fn (Employee $record) => $record->employment_type_icon ?? 'heroicon-o-question-mark-circle')
                        ->hidden(fn (Employee $record) => $record->activeContract === null),

                    TextEntry::make('activeContract.salary')
                        ->visible(fn (): bool => auth()->user()?->can('view_any_contract') ?? false)
                        ->label('Salario')
                        ->icon('heroicon-o-banknotes')
                        ->getStateUsing(
                            fn (Employee $record) => $record->activeContract
                                ? 'Gs. '.number_format((int) $record->activeContract->salary, 0, ',', '.').($record->activeContract->salary_type === 'jornal' ? '/día' : '/mes')
                                : null
                        )
                        ->badge()
                        ->color('success')
                        ->hidden(fn (Employee $record) => $record->activeContract === null),

                    TextEntry::make('activeContract.payroll_type')
                        ->label('Tipo de nómina')
                        ->getStateUsing(fn (Employee $record) => $record->payroll_type_label)
                        ->badge()
                        ->color(fn (Employee $record) => $record->payroll_type_color ?? 'gray')
                        ->icon(fn (Employee $record) => $record->payroll_type_icon ?? 'heroicon-o-calendar')
                        ->hidden(fn (Employee $record) => $record->activeContract === null),

                    TextEntry::make('payment_method')
                        ->label('Método de pago')
                        ->getStateUsing(fn (Employee $record) => $record->payment_method_label)
                        ->badge()
                        ->color(fn (Employee $record) => $record->payment_method_color ?? 'gray')
                        ->icon(fn (Employee $record) => $record->payment_method_icon ?? 'heroicon-o-question-mark-circle')
                        ->hidden(fn (Employee $record) => $record->activeContract === null),
                ])
                ->collapsible(),

            InfoSection::make('Marcación: rostro y celular')
                ->description('Por defecto se marca desde el terminal; el celular se habilita en «Más acciones».')
                ->icon('heroicon-o-camera')
                ->columns(4)
                ->headerActions([
                    InfolistAction::make('capture_face')
                        ->label(fn (Employee $record) => $record->has_face ? 'Actualizar rostro' : 'Enrolar rostro')
                        ->icon('heroicon-o-camera')
                        ->color(fn (Employee $record) => $record->has_face ? 'warning' : 'success')
                        ->url(fn (Employee $record) => route('face.capture', $record))
                        ->visible(fn (Employee $record) => $record->status === 'active'),

                    InfolistAction::make('generate_enrollment')
                        ->label('Enlace de enrolamiento')
                        ->icon('heroicon-o-link')
                        ->color('info')
                        ->visible(fn (Employee $record) => $record->status === 'active')
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
                ])
                ->schema([
                    TextEntry::make('face_tooltip')
                        ->label('Rostro')
                        ->getStateUsing(fn (Employee $record) => $record->face_tooltip)
                        ->badge()
                        ->color(fn (Employee $record) => $record->has_face ? 'success' : 'warning'),

                    TextEntry::make('_mobile_status')
                        ->label('Estado del celular')
                        ->getStateUsing(fn (Employee $record) => Employee::getMobileStatusLabels()[$record->mobileStatus()])
                        ->hint(fn (Employee $record): ?string => $record->hasMobileLinked()
                            ? 'Vinculado el '.$record->mobile_linked_at->format('d/m/Y H:i')
                            : null)
                        ->badge()
                        ->color(fn (Employee $record) => Employee::getMobileStatusColors()[$record->mobileStatus()]),

                    TextEntry::make('_mobile_marking')
                        ->label('Puede marcar desde el celular')
                        ->getStateUsing(fn (Employee $record) => $record->mobileMarkingLabel())
                        ->badge()
                        ->color(fn (Employee $record) => $record->canMarkFromMobile() ? 'success' : 'gray'),

                    TextEntry::make('mobile_last_heartbeat_at')
                        ->label('Última conexión del celular')
                        ->since()
                        ->placeholder('Nunca se conectó'),
                ])
                ->collapsible(),

            InfoSection::make('Registro')
                ->icon('heroicon-o-clock')
                ->columns(2)
                ->schema([
                    TextEntry::make('created_at')
                        ->label('Registrado')
                        ->getStateUsing(fn (Employee $record) => $record->created_at->format('d/m/Y H:i').' · '.$record->created_at_since),

                    TextEntry::make('updated_at')
                        ->label('Última actualización')
                        ->getStateUsing(fn (Employee $record) => $record->updated_at->format('d/m/Y H:i').' · '.$record->updated_at_since),
                ])
                ->collapsible(),
        ]);
    }
}
