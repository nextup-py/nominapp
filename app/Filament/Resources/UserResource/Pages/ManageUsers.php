<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Actions\UserInviteAction;
use App\Filament\Resources\UserResource;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Database\Eloquent\Builder;

class ManageUsers extends ManageRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            UserInviteAction::make(),
            CreateAction::make()
                ->label('Nuevo Usuario')
                ->icon('heroicon-o-user-plus')
                ->successNotificationTitle('Usuario creado exitosamente'),
        ];
    }

    /** @var array<string, int>|null Conteos de las pestañas, calculados en una sola consulta. */
    protected ?array $userCounts = null;

    /**
     * Totales por pestaña en una sola consulta.
     *
     * @return array<string, int>
     */
    protected function getUserCounts(): array
    {
        if ($this->userCounts === null) {
            $row = User::query()
                ->selectRaw('count(*) as total, sum(is_active = 1) as active, sum(is_active = 0) as inactive')
                ->selectRaw('sum(not exists (select 1 from model_has_roles where model_has_roles.model_id = users.id and model_has_roles.model_type = ?)) as no_role', [User::class])
                ->first();

            $this->userCounts = [
                'all' => (int) $row->total,
                'active' => (int) $row->active,
                'inactive' => (int) $row->inactive,
                'no_role' => (int) $row->no_role,
            ];
        }

        return $this->userCounts;
    }

    /** Pestañas: Todos / Activos / Inactivos / Sin rol. */
    public function getTabs(): array
    {
        $counts = $this->getUserCounts();

        return [
            'all' => Tab::make('Todos')->badge($counts['all']),
            'active' => Tab::make('Activos')->badge($counts['active'])
                ->modifyQueryUsing(fn (Builder $query) => $query->where('users.is_active', true)),
            'inactive' => Tab::make('Inactivos')->badge($counts['inactive'])->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('users.is_active', false)),
            'no_role' => Tab::make('Sin rol')->badge($counts['no_role'])->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereDoesntHave('roles')),
        ];
    }
}
