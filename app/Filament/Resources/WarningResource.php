<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WarningResource\Pages;
use App\Filament\Resources\WarningResource\RelationManagers\AuditsRelationManager;
use App\Filament\Traits\HasModuleAccess;
use App\Models\Warning;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Form;
use Filament\Infolists\Components\Group;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use pxlrbt\FilamentExcel\Actions\Tables\ExportBulkAction;
use pxlrbt\FilamentExcel\Exports\ExcelExport;

/** Resource Filament para gestionar amonestaciones laborales. */
class WarningResource extends Resource
{
    use HasModuleAccess;

    protected static string $moduleFlag = 'warnings_enabled';

    protected static ?string $model = Warning::class;

    protected static ?string $navigationLabel = 'Amonestaciones';

    protected static ?string $label = 'amonestación';

    protected static ?string $pluralLabel = 'amonestaciones';

    protected static ?string $slug = 'amonestaciones';

    protected static ?string $navigationIcon = 'heroicon-o-flag';

    protected static ?string $navigationGroup = 'Empleados';

    protected static ?int $navigationSort = 5;

    /**
     * Define el formulario de creación/edición de amonestaciones.
     */
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Información de la Amonestación')
                    ->columns(2)
                    ->schema([
                        Select::make('employee_id')
                            ->label('Empleado')
                            ->relationship(
                                name: 'employee',
                                modifyQueryUsing: fn (Builder $query) => $query
                                    ->where('status', 'active')
                                    ->orderBy('first_name')
                                    ->orderBy('last_name'),
                            )
                            ->getOptionLabelFromRecordUsing(fn (Model $record) => $record->full_name_with_ci)
                            ->searchable(['first_name', 'last_name', 'ci'])
                            ->native(false)
                            ->required()
                            ->columnSpanFull()
                            ->disabled(fn (string $operation) => $operation === 'edit')
                            ->helperText('Solo se muestran empleados activos.'),

                        Select::make('type')
                            ->label('Tipo')
                            ->options(Warning::getTypeOptions())
                            ->native(false)
                            ->required(),

                        Select::make('reason')
                            ->label('Motivo')
                            ->options(Warning::getReasonOptions())
                            ->native(false)
                            ->required(),

                        DatePicker::make('issued_at')
                            ->label('Fecha de emisión')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->closeOnDateSelection()
                            ->required()
                            ->default(now())
                            ->maxDate(now())
                            ->visibleOn('edit'),

                        Select::make('issued_by_id')
                            ->label('Emitida por')
                            ->relationship('issuedBy', 'name')
                            ->native(false)
                            ->default(fn () => Auth::id())
                            ->required()
                            ->visibleOn('edit'),

                        Textarea::make('description')
                            ->label('Descripción del hecho')
                            ->required()
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),

                Section::make('Documento Firmado')
                    ->icon('heroicon-o-paper-clip')
                    ->collapsed()
                    ->visibleOn('edit')
                    ->schema([
                        FileUpload::make('document_path')
                            ->label('PDF firmado')
                            ->disk('public')
                            ->directory('warnings')
                            ->acceptedFileTypes(['application/pdf'])
                            ->maxSize(5120)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * Define el infolist de visualización de una amonestación.
     */
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                InfolistSection::make('Empleado')
                    ->schema([
                        Group::make([
                            TextEntry::make('employee.full_name')
                                ->label('Nombre')
                                ->icon('heroicon-o-user'),

                            TextEntry::make('employee.ci')
                                ->label('CI')
                                ->icon('heroicon-o-identification')
                                ->badge()
                                ->color('gray')
                                ->copyable(),

                            TextEntry::make('employee.activeContract.position.name')
                                ->label('Cargo')
                                ->icon('heroicon-o-briefcase')
                                ->badge()
                                ->color('info')
                                ->placeholder('-'),

                            TextEntry::make('employee.branch.company.name')
                                ->label('Empresa')
                                ->icon('heroicon-o-building-office-2')
                                ->badge()
                                ->color('success')
                                ->placeholder('-'),
                        ])->columns(2),
                    ]),

                InfolistSection::make('Datos de la Amonestación')
                    ->schema([
                        Group::make([
                            TextEntry::make('type')
                                ->label('Tipo')
                                ->badge()
                                ->formatStateUsing(fn ($state) => Warning::getTypeLabel($state))
                                ->color(fn ($state) => Warning::getTypeColor($state))
                                ->icon(fn ($state) => Warning::getTypeIcon($state)),

                            TextEntry::make('reason')
                                ->label('Motivo')
                                ->badge()
                                ->color('gray')
                                ->formatStateUsing(fn ($state) => Warning::getReasonLabel($state)),

                            TextEntry::make('issued_at')
                                ->label('Fecha de emisión')
                                ->date('d/m/Y')
                                ->icon('heroicon-o-calendar'),

                            TextEntry::make('issuedBy.name')
                                ->label('Emitida por')
                                ->icon('heroicon-o-user-circle'),
                        ])->columns(2),

                        TextEntry::make('description')
                            ->label('Descripción del hecho')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * Define la tabla de listado de amonestaciones.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['employee', 'issuedBy']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                ImageColumn::make('employee.photo')
                    ->label('Foto')
                    ->circular()
                    ->defaultImageUrl(fn ($record) => $record->employee?->avatar_url)
                    ->toggleable(),

                TextColumn::make('employee.full_name')
                    ->label('Empleado')
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas(
                        'employee',
                        fn ($q) => $q->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                    ))
                    ->sortable()
                    ->wrap(),

                TextColumn::make('employee.ci')
                    ->label('CI')
                    ->sortable()
                    ->searchable()
                    ->toggleable()
                    ->badge()
                    ->color('gray')
                    ->copyable()
                    ->tooltip('Haz clic para copiar')
                    ->copyMessage('CI copiada al portapapeles'),

                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn ($state) => Warning::getTypeLabel($state))
                    ->color(fn ($state) => Warning::getTypeColor($state))
                    ->icon(fn ($state) => Warning::getTypeIcon($state))
                    ->sortable(),

                TextColumn::make('reason')
                    ->label('Motivo')
                    ->formatStateUsing(fn ($state) => Warning::getReasonLabel($state))
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('issued_at')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('issuedBy.name')
                    ->label('Emitida por')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->label('Editado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Tipo')
                    ->options(Warning::getTypeOptions())
                    ->multiple()
                    ->native(false),

                SelectFilter::make('reason')
                    ->label('Motivo')
                    ->options(Warning::getReasonOptions())
                    ->multiple()
                    ->native(false),

                SelectFilter::make('employee_id')
                    ->label('Empleado')
                    ->relationship('employee', 'first_name')
                    ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->full_name} (CI: {$record->ci})")
                    ->searchable(['first_name', 'last_name', 'ci'])
                    ->multiple()
                    ->native(false),

                Filter::make('issued_at')
                    ->label('Fecha de emisión')
                    ->form([
                        DatePicker::make('from')
                            ->label('Desde')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->closeOnDateSelection(),
                        DatePicker::make('until')
                            ->label('Hasta')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->closeOnDateSelection(),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'], fn ($q, $date) => $q->whereDate('issued_at', '>=', $date))
                        ->when($data['until'], fn ($q, $date) => $q->whereDate('issued_at', '<=', $date))),
            ])
            ->actions([
                Action::make('export_pdf')
                    ->label('PDF')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->url(fn (Warning $record) => route('warnings.pdf', $record))
                    ->openUrlInNewTab(),

                Action::make('download_signed')
                    ->label('Firmado')
                    ->icon('heroicon-o-paper-clip')
                    ->color('success')
                    ->visible(fn (Warning $record) => filled($record->document_path))
                    ->action(fn (Warning $record) => response()->download(
                        Storage::disk('public')->path($record->document_path)
                    )),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    ExportBulkAction::make()->exports([
                        ExcelExport::make()
                            ->fromTable()
                            ->withFilename('amonestaciones_'.now()->format('Y_m_d_H_i_s').'.xlsx'),
                    ])
                        ->label('Exportar a Excel')
                        ->color('info')
                        ->icon('heroicon-o-arrow-down-tray'),

                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No hay amonestaciones registradas')
            ->emptyStateDescription('Las amonestaciones emitidas a empleados aparecerán aquí.')
            ->emptyStateIcon('heroicon-o-exclamation-triangle');
    }

    public static function getRelations(): array
    {
        return [
            AuditsRelationManager::class,
        ];
    }

    /**
     * Define las páginas del recurso.
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWarnings::route('/'),
            'create' => Pages\CreateWarning::route('/create'),
            'view' => Pages\ViewWarning::route('/{record}'),
            'edit' => Pages\EditWarning::route('/{record}/edit'),
        ];
    }
}
