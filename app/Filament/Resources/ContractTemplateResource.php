<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ContractTemplateResource\Pages;
use App\Filament\Resources\ContractTemplateResource\RelationManagers;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractTemplate;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action as TableAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

/** Recurso para gestionar las plantillas de cuerpo/cláusulas por tipo de contrato. */
class ContractTemplateResource extends Resource
{
    protected static ?string $model = ContractTemplate::class;

    protected static ?string $navigationGroup = 'Catálogos';

    protected static ?string $navigationIcon = 'heroicon-o-document-duplicate';

    protected static ?string $navigationLabel = 'Plantillas de Contratos';

    protected static ?string $modelLabel = 'Plantilla de Contrato';

    protected static ?string $pluralModelLabel = 'Plantillas de Contratos';

    protected static ?string $slug = 'plantillas-contratos';

    protected static ?int $navigationSort = 3;

    /** Botones comunes del RichEditor para todas las secciones. */
    private static function richEditorToolbar(): array
    {
        return ['bold', 'italic', 'underline', 'orderedList', 'bulletList', 'redo', 'undo'];
    }

    /** Componente de referencia de variables reutilizable. */
    private static function variablesReference(): Placeholder
    {
        $vars = ContractTemplate::getAvailableVariables();

        $items = collect($vars)
            ->map(fn ($desc, $token) => '<code style="background:#f3f4f6;color:#1f2937;padding:1px 4px;border-radius:3px;font-size:11px;">'
                .e($token).'</code> — '
                .e($desc))
            ->implode('<br>');

        return Placeholder::make('variables_reference')
            ->label('Variables disponibles')
            ->content(new HtmlString('<div style="line-height:1.8;">'.$items.'</div>'))
            ->columnSpanFull();
    }

    /**
     * Define el formulario de edición de la plantilla con 4 tabs por sección.
     */
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Placeholder::make('company_name_display')
                    ->label('Empresa')
                    ->content(fn ($record) => $record?->company?->name ?? '—')
                    ->visible(fn ($record) => $record !== null)
                    ->columnSpanFull(),

                Tabs::make('Secciones del contrato')
                    ->tabs([
                        Tabs\Tab::make('Párrafo Introductorio')
                            ->icon('heroicon-o-document-text')
                            ->schema([
                                self::variablesReference(),

                                Actions::make([
                                    FormAction::make('load_default_intro')
                                        ->label('Cargar texto base')
                                        ->icon('heroicon-o-arrow-down-tray')
                                        ->color('gray')
                                        ->action(fn (Set $set) => $set('intro_text', ContractTemplate::getDefaultIntroText())),
                                ])->columnSpanFull(),

                                RichEditor::make('intro_text')
                                    ->label('Texto del párrafo introductorio')
                                    ->helperText('Si se deja vacío, el PDF usará el párrafo introductorio estándar hardcodeado. Use las variables de arriba para insertar datos dinámicos.')
                                    ->toolbarButtons(self::richEditorToolbar())
                                    ->columnSpanFull(),
                            ]),

                        Tabs\Tab::make('Cuerpo / Cláusulas')
                            ->icon('heroicon-o-list-bullet')
                            ->schema([
                                self::variablesReference(),

                                Actions::make([
                                    FormAction::make('load_default_body')
                                        ->label('Cargar texto base')
                                        ->icon('heroicon-o-arrow-down-tray')
                                        ->color('gray')
                                        ->action(fn (Set $set) => $set('body', ContractTemplate::getDefaultBodyText())),
                                ])->columnSpanFull(),

                                RichEditor::make('body')
                                    ->label('Cuerpo / Cláusulas')
                                    ->helperText('Este contenido se pre-rellena automáticamente al crear un contrato con este tipo. Puede personalizarse por contrato.')
                                    ->toolbarButtons(self::richEditorToolbar())
                                    ->columnSpanFull(),
                            ]),

                        Tabs\Tab::make('Texto de Cierre')
                            ->icon('heroicon-o-check-circle')
                            ->schema([
                                self::variablesReference(),

                                Actions::make([
                                    FormAction::make('load_default_closing')
                                        ->label('Cargar texto base')
                                        ->icon('heroicon-o-arrow-down-tray')
                                        ->color('gray')
                                        ->action(fn (Set $set) => $set('closing_text', ContractTemplate::getDefaultClosingText())),
                                ])->columnSpanFull(),

                                RichEditor::make('closing_text')
                                    ->label('Texto de cierre')
                                    ->helperText('Se muestra después de las cláusulas y antes de las firmas (ej: declaraciones finales, lugar y fecha de suscripción).')
                                    ->toolbarButtons(self::richEditorToolbar())
                                    ->columnSpanFull(),
                            ]),

                        Tabs\Tab::make('Notas en Firmas')
                            ->icon('heroicon-o-pencil')
                            ->schema([
                                Placeholder::make('signature_notes_help')
                                    ->label('')
                                    ->content('Texto breve que se muestra bajo las líneas de firma del empleado y el empleador (ej: aclaraciones de cargo, número de ejemplares, etc.).')
                                    ->columnSpanFull(),

                                Actions::make([
                                    FormAction::make('load_default_signature_notes')
                                        ->label('Cargar texto base')
                                        ->icon('heroicon-o-arrow-down-tray')
                                        ->color('gray')
                                        ->action(fn (Set $set) => $set('signature_notes', ContractTemplate::getDefaultSignatureNotes())),
                                ])->columnSpanFull(),

                                Textarea::make('signature_notes')
                                    ->label('Notas en la sección de firmas')
                                    ->rows(4)
                                    ->columnSpanFull(),

                                Placeholder::make('signature_labels_help')
                                    ->label('Etiquetas de las líneas de firma')
                                    ->content('Si se dejan vacíos, se usarán los textos por defecto.')
                                    ->columnSpanFull(),

                                TextInput::make('signature_employee_label')
                                    ->label('Etiqueta lado empleado')
                                    ->placeholder('Trabajador')
                                    ->maxLength(100),

                                TextInput::make('signature_employer_label')
                                    ->label('Etiqueta lado empleador')
                                    ->placeholder('Empleador o responsable legal')
                                    ->maxLength(100),

                                TextInput::make('signature_employer_sublabel')
                                    ->label('Sub-etiqueta lado empleador')
                                    ->placeholder('Firma y Sello')
                                    ->maxLength(100),
                            ])
                            ->columns(3),

                        Tabs\Tab::make('Presentación')
                            ->icon('heroicon-o-adjustments-horizontal')
                            ->schema([
                                Toggle::make('show_header')
                                    ->label('Mostrar encabezado de empresa')
                                    ->helperText('Muestra el logo, nombre, RUC y datos de contacto de la empresa en la parte superior del PDF.')
                                    ->default(true)
                                    ->columnSpanFull(),

                                Toggle::make('show_footer')
                                    ->label('Mostrar pie de página')
                                    ->helperText('Muestra "Documento generado el [fecha]" al final del PDF.')
                                    ->default(true)
                                    ->columnSpanFull(),

                                Placeholder::make('title_help')
                                    ->label('Título del documento')
                                    ->content('Si se dejan vacíos, se usarán los textos por defecto. El subtítulo por defecto se deriva del tipo de contrato.')
                                    ->columnSpanFull(),

                                TextInput::make('document_title')
                                    ->label('Título principal')
                                    ->placeholder('CONTRATO INDIVIDUAL DE TRABAJO')
                                    ->maxLength(255)
                                    ->columnSpanFull(),

                                TextInput::make('document_subtitle')
                                    ->label('Subtítulo')
                                    ->placeholder('Derivado del tipo (ej: Por Tiempo Indefinido)')
                                    ->helperText('Si se define, reemplaza el subtítulo derivado del tipo de contrato.')
                                    ->maxLength(255)
                                    ->columnSpanFull(),

                                TextInput::make('document_art_reference')
                                    ->label('Referencia al artículo legal')
                                    ->placeholder('(En cumplimiento del Art. 48 del C. De T.)')
                                    ->helperText('Dejar en blanco para mostrar el texto por defecto. Borrar el texto para ocultar esta línea.')
                                    ->maxLength(255)
                                    ->columnSpanFull(),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Define la tabla de listado de plantillas.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('company.name')
                    ->label('Empresa')
                    ->badge()
                    ->color('primary')
                    ->visible(fn () => Company::active()->count() > 1),

                TextColumn::make('type')
                    ->label('Tipo de Contrato')
                    ->formatStateUsing(fn ($state) => $state ? Contract::getTypeLabel($state) : '—')
                    ->badge()
                    ->color(fn ($state) => $state ? Contract::getTypeColor($state) : 'gray')
                    ->icon(fn ($state) => $state ? Contract::getTypeIcon($state) : null),

                TextColumn::make('sections_configured')
                    ->label('Secciones')
                    ->html()
                    ->getStateUsing(function (ContractTemplate $record): HtmlString {
                        $sections = [
                            'Intro' => filled($record->intro_text),
                            'Cláusulas' => filled($record->body),
                            'Cierre' => filled($record->closing_text),
                            'Firmas' => filled($record->signature_notes),
                        ];

                        $badges = collect($sections)->map(function (bool $filled, string $label): string {
                            $color = $filled
                                ? 'background:#166534;color:#bbf7d0;'
                                : 'background:#92400e;color:#fde68a;';

                            return "<span style=\"{$color}display:inline-block;padding:2px 10px;border-radius:9999px;font-size:0.72rem;font-weight:600;margin:2px 2px\">{$label}</span>";
                        })->implode('');

                        return new HtmlString("<div style=\"display:flex;flex-wrap:wrap;gap:2px\">{$badges}</div>");
                    }),

                TextColumn::make('updated_at')
                    ->label('Última modificación')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->actions([
                TableAction::make('preview_pdf')
                    ->label('Vista Previa')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->url(fn (ContractTemplate $record) => route('contract-templates.preview', $record))
                    ->openUrlInNewTab(),
                EditAction::make()
                    ->label('Editar')
                    ->icon('heroicon-o-pencil-square')
                    ->color('primary'),
            ])
            ->paginated(false);
    }

    /**
     * @return array<class-string>
     */
    public static function getRelations(): array
    {
        return [
            RelationManagers\ContractTemplateAuditsRelationManager::class,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListContractTemplates::route('/'),
            'view' => Pages\ViewContractTemplate::route('/{record}'),
            'edit' => Pages\EditContractTemplate::route('/{record}/edit'),
        ];
    }
}
