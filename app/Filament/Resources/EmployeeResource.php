<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\UsesNavigationLayout;
use App\Filament\Resources\EmployeeResource\Pages;
use App\Filament\Resources\EmployeeResource\RelationManagers;
use App\Filament\Resources\EmployeeResource\Schemas\EmployeeForm;
use App\Filament\Resources\EmployeeResource\Schemas\EmployeeInfolist;
use App\Filament\Resources\EmployeeResource\Tables\EmployeeTable;
use App\Models\Employee;
use Filament\Forms\Form;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class EmployeeResource extends Resource
{
    use UsesNavigationLayout;

    protected static ?string $model = Employee::class;

    protected static ?string $navigationLabel = 'Empleados';

    protected static ?string $label = 'empleado';

    protected static ?string $pluralLabel = 'empleados';

    protected static ?string $navigationIcon = 'heroicon-o-user-circle';

    protected static ?string $navigationGroup = 'Empleados';

    protected static ?int $navigationSort = 1;

    /**
     * Define el formulario para crear y editar empleados.
     */
    /**
     * Campos de la búsqueda global: nombre, apellido y CI (cada palabra escrita debe coincidir con alguno).
     *
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['first_name', 'last_name', 'ci'];
    }

    /** Máximo de empleados en la búsqueda global. */
    public static function getGlobalSearchResultsLimit(): int
    {
        return 8;
    }

    /** Activos primero y, dentro de cada grupo, por nombre; con la sucursal precargada para el detalle. */
    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()
            ->with('branch')
            ->orderByRaw("employees.status = 'active' desc")
            ->orderBy('first_name')
            ->orderBy('last_name');
    }

    /** Nombre completo; los dados de baja llevan la marca «(Inactivo)» para no abrirlos por error. */
    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return $record->full_name.($record->status === 'active' ? '' : ' (Inactivo)');
    }

    /**
     * Detalle de cada resultado: CI, sucursal y estado.
     *
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            'CI' => (string) $record->ci,
            'Sucursal' => $record->branch?->name ?? 'Sin sucursal',
            'Estado' => $record->status_label,
        ];
    }

    /** Lleva a la ficha del empleado (no a la edición). */
    public static function getGlobalSearchResultUrl(Model $record): ?string
    {
        return static::canView($record) ? static::getUrl('view', ['record' => $record]) : null;
    }

    /**
     * Define el formulario para crear y editar empleados.
     */
    public static function form(Form $form): Form
    {
        return EmployeeForm::configure($form);
    }

    /**
     * Define la infolist para la vista de detalle del empleado.
     */
    public static function infolist(Infolist $infolist): Infolist
    {
        return EmployeeInfolist::configure($infolist);
    }

    /**
     * Define la tabla para listar empleados.
     */
    public static function table(Table $table): Table
    {
        return EmployeeTable::configure($table);
    }

    /**
     * Define las relaciones para el recurso de empleado.
     */
    public static function getRelations(): array
    {
        return [
            RelationManagers\ContractsRelationManager::class,
            RelationManagers\AddressesRelationManager::class,
            RelationManagers\ChildrenRelationManager::class,
            RelationManagers\DocumentsRelationManager::class,
            RelationManagers\VacationsRelationManager::class,
            RelationManagers\LeavesRelationManager::class,
            RelationManagers\EmployeeDeductionsRelationManager::class,
            RelationManagers\EmployeePerceptionsRelationManager::class,
            RelationManagers\BankAccountsRelationManager::class,
            RelationManagers\WarningsRelationManager::class,
            RelationManagers\DevicesRelationManager::class,
        ];
    }

    /**
     * Define las páginas para el recurso de empleado.
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmployees::route('/'),
            'create' => Pages\CreateEmployee::route('/create'),
            'view' => Pages\ViewEmployee::route('/{record}'),
            'edit' => Pages\EditEmployee::route('/{record}/edit'),
        ];
    }
}
