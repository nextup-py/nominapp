<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FaceEnrollmentResource\Pages;
use App\Filament\Traits\HasModuleAccess;
use App\Models\Employee;
use App\Models\FaceEnrollment;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use pxlrbt\FilamentExcel\Actions\Pages\ExportAction;
use pxlrbt\FilamentExcel\Actions\Tables\ExportBulkAction;
use pxlrbt\FilamentExcel\Exports\ExcelExport;

class FaceEnrollmentResource extends Resource
{
    use HasModuleAccess;

    protected static string $moduleFlag = 'biometric_attendance_enabled';

    // Configuración general del recurso
    protected static ?string $model = FaceEnrollment::class;

    protected static ?string $navigationLabel = 'Registro Facial';

    protected static ?string $label = 'registro facial';

    protected static ?string $pluralLabel = 'registros faciales';

    protected static ?string $slug = 'registros-faciales';

    protected static ?string $navigationIcon = 'heroicon-o-finger-print';

    protected static ?string $navigationGroup = 'Asistencias';

    protected static ?int $navigationSort = 4;

    /**
     * Define la tabla del recurso, con columnas, filtros y acciones personalizadas para la gestión de registros faciales.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.full_name')
                    ->label('Empleado')
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas(
                        'employee',
                        fn ($q) => $q->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                    ))
                    ->sortable(['first_name']),

                TextColumn::make('employee.ci')
                    ->label('CI')
                    ->badge()
                    ->color('gray')
                    ->copyable()
                    ->searchable(),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => FaceEnrollment::getStatusLabel($state))
                    ->color(fn (string $state): string => FaceEnrollment::getStatusColor($state))
                    ->icon(fn (string $state): string => FaceEnrollment::getStatusIcon($state)),

                TextColumn::make('expires_at')
                    ->label('Expira')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->description(fn (FaceEnrollment $record): string => $record->isExpired() && $record->isPendingCapture() ? 'Expirado' : ''),

                ImageColumn::make('snapshot_path')
                    ->label('Foto')
                    ->disk('public')
                    ->height(56)
                    ->width(48)
                    ->defaultImageUrl(null)
                    ->extraImgAttributes(['class' => 'rounded object-cover'])
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('face_score')
                    ->label('Calidad')
                    ->badge()
                    ->placeholder('—')
                    ->formatStateUsing(fn ($record) => $record->face_score !== null ? $record->getQualityLabel() : '—')
                    ->color(fn ($record) => $record->face_score !== null ? $record->getQualityColor() : 'gray')
                    ->description(fn ($record) => $record->face_score !== null ? number_format($record->face_score, 4) : '')
                    ->toggleable(),

                TextColumn::make('source')
                    ->label('Origen')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => FaceEnrollment::getSourceLabel($state))
                    ->color(fn (string $state): string => FaceEnrollment::getSourceColor($state))
                    ->icon(fn (string $state): string => FaceEnrollment::getSourceIcon($state))
                    ->toggleable(),

                TextColumn::make('samples_count')
                    ->label('Muestras')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('captured_at')
                    ->label('Capturado')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('generatedBy.name')
                    ->label('Generado por')
                    ->badge()
                    ->color('gray')
                    ->toggleable(),

                TextColumn::make('reviewedBy.name')
                    ->label('Revisado por')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('reviewed_at')
                    ->label('Revisado')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('review_notes')
                    ->label('Notas de revisión')
                    ->limit(50)
                    ->tooltip(fn ($record) => $record->review_notes)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(FaceEnrollment::getStatusOptions())
                    ->native(false)
                    ->multiple(),

                SelectFilter::make('source')
                    ->label('Origen')
                    ->options(FaceEnrollment::getSourceOptions())
                    ->native(false),

                SelectFilter::make('employee_id')
                    ->label('Empleado')
                    ->relationship('employee', 'first_name')
                    ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->full_name} (CI: {$record->ci})")
                    ->searchable(['first_name', 'last_name', 'ci'])
                    ->preload(false)
                    ->native(false),
            ])
            ->actions([
                Action::make('approve')
                    ->label('Aprobar')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->tooltip('Aprobar este registro facial y asignarlo al empleado')
                    ->visible(fn (FaceEnrollment $record) => $record->isPendingApproval())
                    ->requiresConfirmation()
                    ->modalHeading('Aprobar Registro Facial')
                    ->modalDescription(function (FaceEnrollment $record) {
                        $name = $record->employee
                            ? "{$record->employee->first_name} {$record->employee->last_name}"
                            : 'empleado eliminado';

                        return "¿Aprobar el registro facial de {$name}? El descriptor será asignado al empleado para marcación de asistencia.";
                    })
                    ->modalSubmitActionLabel('Aprobar')
                    ->form([
                        Textarea::make('review_notes')
                            ->label('Notas de revisión')
                            ->placeholder('Notas opcionales...')
                            ->rows(2),
                    ])
                    ->action(function (FaceEnrollment $record, array $data) {
                        $result = $record->approve(Auth::id(), $data['review_notes'] ?? null);

                        Notification::make()
                            ->success()
                            ->title('Registro Facial Aprobado')
                            ->body($result['message'])
                            ->send();
                    }),

                Action::make('reject')
                    ->label('Rechazar')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->tooltip('Rechazar este registro facial')
                    ->visible(fn (FaceEnrollment $record) => $record->isPendingApproval())
                    ->requiresConfirmation()
                    ->modalHeading('Rechazar Registro Facial')
                    ->modalDescription(function (FaceEnrollment $record) {
                        $name = $record->employee
                            ? "{$record->employee->first_name} {$record->employee->last_name}"
                            : 'empleado eliminado';

                        return "¿Rechazar el registro facial de {$name}?";
                    })
                    ->modalSubmitActionLabel('Rechazar')
                    ->form([
                        Textarea::make('review_notes')
                            ->label('Motivo del rechazo')
                            ->placeholder('Indique el motivo...')
                            ->rows(3)
                            ->required(),
                    ])
                    ->action(function (FaceEnrollment $record, array $data) {
                        $result = $record->reject(Auth::id(), $data['review_notes']);

                        Notification::make()
                            ->warning()
                            ->title('Registro Facial Rechazado')
                            ->body($result['message'])
                            ->send();
                    }),

                Action::make('copy_link')
                    ->label('Ver Enlace')
                    ->icon('heroicon-o-link')
                    ->color('info')
                    ->tooltip('Ver y copiar el enlace de captura facial')
                    ->visible(fn (FaceEnrollment $record) => $record->isValid())
                    ->fillForm(fn (FaceEnrollment $record): array => [
                        'enrollment_url' => route('face-enrollment.show', $record->token),
                    ])
                    ->modalHeading('Enlace de Captura Facial')
                    ->modalDescription(fn (FaceEnrollment $record) => $record->expires_at
                        ? 'Válido hasta: '.$record->expires_at->translatedFormat('l d/m/Y H:i').' ('.$record->expires_at->diffForHumans().')'
                        : 'Sin fecha de expiración.'
                    )
                    ->form([
                        TextInput::make('enrollment_url')
                            ->label('Enlace de captura')
                            ->readOnly()
                            ->extraInputAttributes([
                                'onclick' => 'this.select()',
                                'class' => 'font-mono text-xs',
                            ]),
                    ])
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Cerrar')
                    ->extraModalFooterActions(fn (FaceEnrollment $record): array => static::whatsappShareAction(
                        $record->employee,
                        route('face-enrollment.show', $record->token)
                    )),

                Action::make('regenerate_link')
                    ->label('Regenerar Enlace')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->tooltip('Generar un nuevo enlace de captura para este empleado')
                    ->visible(fn (FaceEnrollment $record) => $record->isRejected()
                        || $record->status === 'expired'
                        || ($record->isPendingCapture() && $record->isExpired()))
                    ->requiresConfirmation()
                    ->modalHeading('Regenerar Enlace de Captura')
                    ->modalDescription(function (FaceEnrollment $record) {
                        $name = $record->employee
                            ? "{$record->employee->first_name} {$record->employee->last_name}"
                            : 'empleado eliminado';

                        return "Se creará un nuevo enlace de captura para {$name}.";
                    })
                    ->form([
                        Select::make('expiry_hours')
                            ->label('Vigencia del enlace')
                            ->options([4 => '4 horas', 24 => '24 horas', 72 => '72 horas'])
                            ->default(24)
                            ->native(false)
                            ->required(),
                    ])
                    ->action(function (FaceEnrollment $record, array $data) {
                        $enrollment = FaceEnrollment::createForEmployee(
                            $record->employee,
                            Auth::id(),
                            (int) $data['expiry_hours']
                        );

                        $url = route('face-enrollment.show', $enrollment->token);

                        Notification::make()
                            ->success()
                            ->title('Nuevo enlace generado')
                            ->body($url)
                            ->persistent()
                            ->actions([
                                \Filament\Notifications\Actions\Action::make('open')
                                    ->label('Abrir enlace')
                                    ->url($url)
                                    ->openUrlInNewTab(),
                                ...static::whatsappNotificationAction($enrollment->employee, $url),
                            ])
                            ->send();
                    }),

                DeleteAction::make()
                    ->label('Eliminar')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->tooltip('Eliminar este registro facial')
                    ->modalHeading('¿Eliminar registro facial?')
                    ->modalDescription(function (FaceEnrollment $record) {
                        $name = $record->employee
                            ? "{$record->employee->first_name} {$record->employee->last_name}"
                            : 'empleado eliminado';

                        return "¿Eliminar el registro facial de {$name}? Esta acción no se puede deshacer.";
                    })
                    ->modalSubmitActionLabel('Sí, eliminar'),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('bulk_approve')
                        ->label('Aprobar seleccionados')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Aprobar Registros Faciales')
                        ->modalDescription('¿Aprobar todos los registros faciales seleccionados?')
                        ->action(function (Collection $records) {
                            $count = 0;
                            foreach ($records as $record) {
                                if ($record->isPendingApproval()) {
                                    $record->approve(Auth::id());
                                    $count++;
                                }
                            }

                            Notification::make()
                                ->success()
                                ->title("$count registro(s) aprobado(s)")
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('bulk_reject')
                        ->label('Rechazar seleccionados')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Rechazar Registros Faciales')
                        ->form([
                            Textarea::make('review_notes')
                                ->label('Motivo del rechazo')
                                ->required()
                                ->rows(3),
                        ])
                        ->action(function (Collection $records, array $data) {
                            $count = 0;
                            foreach ($records as $record) {
                                if ($record->isPendingApproval()) {
                                    $record->reject(Auth::id(), $data['review_notes']);
                                    $count++;
                                }
                            }

                            Notification::make()
                                ->warning()
                                ->title("$count registro(s) rechazado(s)")
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    ExportBulkAction::make()
                        ->label('Exportar seleccionados')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->color('info')
                        ->tooltip('Exportar los registros faciales seleccionados a Excel')
                        ->exports([
                            ExcelExport::make()
                                ->fromTable()
                                ->except([
                                    'created_at',
                                    'updated_at',
                                ])
                                ->withFilename(fn () => 'registros_faciales_'.now()->format('d_m_Y_H_i_s')),
                        ]),

                    DeleteBulkAction::make()
                        ->modalHeading('Eliminar Registros Faciales')
                        ->modalDescription('¿Eliminar todos los registros faciales seleccionados? Esta acción no se puede deshacer.'),
                ]),
            ])
            ->emptyStateHeading('No hay registros faciales')
            ->emptyStateDescription('No se han encontrado registros faciales. Los registros pendientes de captura aparecerán aquí para su revisión y aprobación.')
            ->emptyStateIcon('heroicon-o-finger-print')
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(10);
    }

    /**
     * Construye la URL de WhatsApp para compartir el enlace de captura facial
     * con el empleado, o null si no tiene teléfono cargado.
     */
    private static function buildWhatsappUrl(?Employee $employee, string $url): ?string
    {
        if (! $employee || blank($employee->phone)) {
            return null;
        }

        $message = "Hola {$employee->first_name}, usa este enlace para registrar tu rostro: {$url}";

        return 'https://api.whatsapp.com/send?phone=595'.ltrim($employee->phone, '0').'&text='.urlencode($message);
    }

    /**
     * Botón "Enviar por WhatsApp" para el pie de un modal (ej. "Ver Enlace").
     *
     * @return array<int, Action>
     */
    private static function whatsappShareAction(?Employee $employee, string $url): array
    {
        $whatsappUrl = static::buildWhatsappUrl($employee, $url);

        if (! $whatsappUrl) {
            return [];
        }

        return [
            Action::make('send_whatsapp')
                ->label('Enviar por WhatsApp')
                ->icon('heroicon-o-chat-bubble-left-right')
                ->color('success')
                ->url($whatsappUrl)
                ->openUrlInNewTab(),
        ];
    }

    /**
     * Acción "Enviar por WhatsApp" para el arreglo de acciones de una
     * `Filament\Notifications\Notification` (ej. tras regenerar el enlace).
     *
     * @return array<int, \Filament\Notifications\Actions\Action>
     */
    private static function whatsappNotificationAction(?Employee $employee, string $url): array
    {
        $whatsappUrl = static::buildWhatsappUrl($employee, $url);

        if (! $whatsappUrl) {
            return [];
        }

        return [
            \Filament\Notifications\Actions\Action::make('send_whatsapp')
                ->label('Enviar por WhatsApp')
                ->icon('heroicon-o-chat-bubble-left-right')
                ->color('success')
                ->url($whatsappUrl)
                ->openUrlInNewTab(),
        ];
    }

    /**
     * Devuelve la acción de exportar a Excel para usar en el header de la página
     */
    public static function getExcelExportAction(): ExportAction
    {
        return ExportAction::make('export_excel')
            ->label('Exportar a Excel')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('info')
            ->tooltip('Exportar registros faciales respetando filtros y tab activo')
            ->exports([
                ExcelExport::make()
                    ->fromTable()
                    ->except(['created_at'])
                    ->withFilename(fn () => 'registros_faciales_'.now()->format('d_m_Y_H_i_s')),
            ]);
    }

    /**
     * Devuelve las páginas del recurso, en este caso solo la página de listado personalizada con pestañas
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFaceEnrollments::route('/'),
        ];
    }

    /**
     * Devuelve el número de registros faciales pendientes de aprobación para mostrar en el badge de navegación
     */
    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::where('status', 'pending_approval')->count() ?: null;
    }

    /**
     * Devuelve el color del badge de navegación
     */
    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /**
     * Devuelve el tooltip para el badge de navegación
     */
    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Registros faciales pendientes de aprobación';
    }
}
