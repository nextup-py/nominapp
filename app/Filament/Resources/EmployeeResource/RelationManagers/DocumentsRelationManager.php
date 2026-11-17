<?php

namespace App\Filament\Resources\EmployeeResource\RelationManagers;

use App\Models\Document;
use App\Models\DocumentType;
use App\Settings\GeneralSettings;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Gestiona los documentos adjuntos del empleado desde su vista de detalle. */
class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    protected static ?string $title = 'Documentos';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'Documento';

    protected static ?string $pluralModelLabel = 'Documentos';

    public function isReadOnly(): bool
    {
        return false;
    }

    /**
     * Define el formulario para subir y editar documentos.
     */
    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('document_type_id')
                    ->label('Tipo de documento')
                    ->options(fn (?Document $record): array => $this->typeOptions($record))
                    ->native(false)
                    ->searchable()
                    ->live()
                    ->afterStateUpdated(function (Set $set, Get $get, ?string $state): void {
                        $type = $state ? DocumentType::find($state) : null;

                        if ($type && blank($get('name'))) {
                            $set('name', $type->name);
                        }
                    })
                    ->helperText('Los tipos los define cada empresa en Catálogos → Tipos de documento. Dejalo vacío si no corresponde ninguno.')
                    ->columnSpanFull(),

                TextInput::make('name')
                    ->label('Nombre del documento')
                    ->placeholder('Ej: Contrato laboral, CV, Certificado IPS...')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),

                DatePicker::make('expires_at')
                    ->label('Vencimiento')
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->required(fn (Get $get): bool => (bool) DocumentType::find($get('document_type_id'))?->requires_expiry)
                    ->helperText(fn (Get $get): ?string => DocumentType::find($get('document_type_id'))?->requires_expiry
                        ? 'Este tipo de documento vence: la fecha es obligatoria.'
                        : 'Opcional. Si la cargás, el sistema avisa cuando se acerca.')
                    ->columnSpanFull(),

                FileUpload::make('file_path')
                    ->label('Archivo')
                    ->helperText('Formatos permitidos: PDF, imágenes (JPEG, PNG, GIF) y documentos de Office (Word, Excel, PowerPoint). Tamaño máximo: 10 MB. Los archivos nuevos son privados: solo se descargan con permiso.')
                    ->disk(fn (?Document $record): string => $record?->disk ?: Document::PRIVATE_DISK)
                    ->directory('documents')
                    ->acceptedFileTypes(Document::getAcceptedFileTypes())
                    ->maxSize(10240) // 10 MB
                    ->getUploadedFileNameForStorageUsing(fn ($file): string => 'documento_'.$this->getOwnerRecord()->id.'_'.now()->format('Y-m-d_H-i-s').'_'.Str::random(4).'.'.$file->getClientOriginalExtension())
                    ->required()
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Tipos activos de la empresa del empleado (más el ya asignado al editar, aunque esté inactivo).
     *
     * @return array<int, string>
     */
    private function typeOptions(?Document $record): array
    {
        $companyId = $this->getOwnerRecord()->branch?->company_id;

        return DocumentType::query()
            ->where(fn ($q) => $q->where(fn ($a) => $a->where('company_id', $companyId)->where('is_active', true))
                ->when($record?->document_type_id, fn ($b) => $b->orWhere('id', $record->document_type_id)))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Define la tabla de documentos con columnas, filtros y acciones.
     */
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Nombre del documento')
                    ->sortable()
                    ->searchable()
                    ->wrap()
                    ->weight('medium'),

                TextColumn::make('type.name')
                    ->label('Tipo de documento')
                    ->placeholder('Sin tipo')
                    ->toggleable(),

                TextColumn::make('expires_at')
                    ->label('Vencimiento')
                    ->date('d/m/Y')
                    ->placeholder('Sin vencimiento')
                    ->description(fn (Document $record): ?string => ($status = $record->expiryStatus()) === 'none' ? null : Document::getExpiryLabels()[$status])
                    ->color(fn (Document $record): string => Document::getExpiryColors()[$record->expiryStatus()])
                    ->sortable(),

                TextColumn::make('file_type')
                    ->label('Archivo')
                    ->state(fn (Document $record): string => $record->file_extension)
                    ->badge()
                    ->color(fn (Document $record): string => $record->file_type_color)
                    ->icon(fn (Document $record): string => $record->file_type_icon)
                    ->sortable(),

                TextColumn::make('file_size')
                    ->label('Tamaño')
                    ->state(fn (Document $record): string => $record->file_size_formatted)
                    ->alignEnd(),

                TextColumn::make('created_at_description')
                    ->label('Fecha de carga')
                    ->description(fn (Document $record): string => $record->created_at_since)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at_description')
                    ->label('Última modificación')
                    ->description(fn (Document $record): string => $record->updated_at_since)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('expiry')
                    ->label('Vencimiento')
                    ->options(['expired' => 'Vencidos', 'expiring' => 'Por vencer', 'valid' => 'Vigentes'])
                    ->query(function (Builder $query, array $data): Builder {
                        $alertDays = (int) app(GeneralSettings::class)->document_alert_days;

                        return match ($data['value'] ?? null) {
                            'expired' => $query->expired(),
                            'expiring' => $query->expiringWithin(max($alertDays, 0)),
                            'valid' => $query->whereNotNull('expires_at')->where('expires_at', '>', today()->addDays(max($alertDays, 0))->toDateString()),
                            default => $query,
                        };
                    })
                    ->native(false),

                SelectFilter::make('document_type_id')
                    ->label('Tipo de documento')
                    ->options(fn (): array => DocumentType::orderBy('name')->pluck('name', 'id')->all())
                    ->native(false),

                SelectFilter::make('file_type')
                    ->label('Tipo de archivo')
                    ->options(Document::getFileTypeFilterOptions())
                    ->query(function ($query, $state) {
                        if (! $state['value']) {
                            return $query;
                        }
                        $extensions = explode(',', $state['value']);

                        return $query->where(function ($q) use ($extensions) {
                            foreach ($extensions as $ext) {
                                $q->orWhere('file_path', 'like', '%.'.$ext);
                            }
                        });
                    })
                    ->native(false)
                    ->placeholder('Selecciona un tipo de archivo'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Subir documento')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->modalHeading('Subir nuevo documento')
                    ->modalSubmitActionLabel('Subir')
                    ->mutateFormDataUsing(fn (array $data): array => [
                        ...$data,
                        'disk' => Document::PRIVATE_DISK,
                        'uploaded_by_id' => auth()->id(),
                    ]),
            ])
            ->actions([
                Action::make('descargar')
                    ->label('Descargar')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->visible(fn (Document $record): bool => auth()->user()?->can('view', $record) ?? false)
                    ->url(fn (Document $record): string => route('documents.download', $record))
                    ->openUrlInNewTab(),

                EditAction::make()
                    ->label('Editar')
                    ->icon('heroicon-o-pencil-square')
                    ->color('primary'),

                DeleteAction::make()
                    ->label('Eliminar')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->modalHeading('¿Eliminar documento?')
                    ->modalSubmitActionLabel('Sí, eliminar')
                    ->before(function ($record) {
                        // Eliminar el archivo físico del storage
                        Storage::disk($record->disk ?: 'public')->delete($record->file_path);
                    }),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->before(function ($records) {
                            // Eliminar los archivos físicos del storage
                            foreach ($records as $record) {
                                Storage::disk($record->disk ?: 'public')->delete($record->file_path);
                            }
                        }),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginationPageOptions([10, 25, 50, 100])
            ->emptyStateHeading('No hay documentos')
            ->emptyStateDescription('Comienza subiendo documentos del empleado')
            ->emptyStateIcon('heroicon-o-document-arrow-up');
    }
}
