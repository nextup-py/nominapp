<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\UsesNavigationLayout;
use App\Filament\Resources\DocumentTypeResource\Pages;
use App\Models\Company;
use App\Models\DocumentType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;

/** Catálogo de tipos de documento del legajo (cédula, carné de salud…), por empresa y con o sin vencimiento. */
class DocumentTypeResource extends Resource
{
    use UsesNavigationLayout;

    protected static ?string $model = DocumentType::class;

    protected static ?string $navigationGroup = 'Catálogos';

    protected static ?string $navigationLabel = 'Tipos de documento';

    protected static ?string $label = 'Tipo de documento';

    protected static ?string $pluralLabel = 'Tipos de documento';

    protected static ?string $slug = 'tipos-de-documento';

    protected static ?string $navigationIcon = 'heroicon-o-document-duplicate';

    protected static ?int $navigationSort = 5;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Select::make('company_id')
                ->label('Empresa')
                ->options(fn (): array => Company::active()->orderBy('name')->pluck('name', 'id')->all())
                ->default(fn (): ?int => Company::active()->count() === 1 ? Company::active()->value('id') : null)
                ->native(false)
                ->searchable()
                ->required()
                ->live(),
            TextInput::make('name')
                ->label('Nombre')
                ->placeholder('Ej: Cédula de identidad, Carné de salud')
                ->required()
                ->maxLength(120)
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, callable $get): Unique => $rule->where('company_id', $get('company_id')))
                ->validationMessages(['unique' => 'Ya existe un tipo con ese nombre en esta empresa.']),
            Toggle::make('requires_expiry')
                ->label('Tiene vencimiento')
                ->helperText('Al subir un documento de este tipo se pide la fecha de vencimiento y el sistema avisa cuando se acerca.')
                ->default(false),
            Toggle::make('is_active')
                ->label('Activo')
                ->helperText('Los tipos inactivos no se ofrecen al subir documentos; los ya cargados se conservan.')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Nombre')->searchable()->sortable(),
                TextColumn::make('company.name')
                    ->label('Empresa')
                    ->icon('heroicon-o-building-office-2')
                    ->visible(fn (): bool => Company::active()->count() > 1),
                TextColumn::make('requires_expiry')
                    ->label('Vencimiento')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Con vencimiento' : 'Sin vencimiento')
                    ->color(fn (bool $state): string => $state ? 'warning' : 'gray'),
                TextColumn::make('documents_count')->label('Documentos')->counts('documents')->sortable(),
                TextColumn::make('is_active')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Activo' : 'Inactivo')
                    ->color(fn (bool $state): string => $state ? 'success' : 'danger'),
            ])
            ->filters([
                SelectFilter::make('company_id')
                    ->label('Empresa')
                    ->options(fn (): array => Company::orderBy('name')->pluck('name', 'id')->all())
                    ->visible(fn (): bool => Company::active()->count() > 1),
            ])
            ->actions([
                EditAction::make()->label('Editar')->icon('heroicon-o-pencil-square')->color('primary'),
                DeleteAction::make()
                    ->label('Eliminar')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->modalHeading('¿Eliminar tipo de documento?')
                    ->modalDescription('Los documentos ya cargados con este tipo se conservan y pasan a «Sin tipo».')
                    ->modalSubmitActionLabel('Sí, eliminar'),
            ])
            ->defaultSort('name')
            ->paginationPageOptions([10, 25, 50, 100])
            ->emptyStateHeading('Sin tipos de documento')
            ->emptyStateDescription('Creá los tipos que usa tu empresa (cédula, carné de salud…) para clasificar los documentos de los legajos.')
            ->emptyStateIcon('heroicon-o-document-duplicate');
    }

    /** @return Builder<DocumentType> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('company');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageDocumentTypes::route('/')];
    }
}
