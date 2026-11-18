<?php

namespace App\Filament\Actions;

use App\Models\Position;
use App\Services\PositionMergeService;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;
use Illuminate\Support\HtmlString;

/**
 * Acción de fila «Fusionar en otro cargo» del listado de cargos: mueve los contratos, los cargos dependientes y las
 * sucursales de marcación del cargo seleccionado a otro cargo de la misma empresa y elimina el sobrante, mostrando
 * antes qué se movería.
 */
class PositionMergeAction
{
    /**
     * Construye la acción de tabla.
     */
    public static function make(): Action
    {
        return Action::make('merge_into')
            ->label('Fusionar en otro cargo')
            ->icon('heroicon-o-arrows-pointing-in')
            ->color('warning')
            ->authorize(fn () => (auth()->user()?->can('update_position') && auth()->user()?->can('delete_position')) ?? false)
            ->modalHeading(fn (Position $record) => "Fusionar «{$record->name}» en otro cargo")
            ->modalDescription('El cargo elegido se conserva; este se elimina después de pasarle sus contratos. Es útil para unir duplicados (por ejemplo, el mismo cargo escrito con una mayúscula distinta).')
            ->form([
                Select::make('into_id')
                    ->label('Conservar el cargo')
                    ->options(fn (Position $record) => self::targets($record))
                    ->searchable()
                    ->native(false)
                    ->required()
                    ->live(),

                Placeholder::make('preview')
                    ->label('Qué se mueve')
                    ->content(fn (Get $get, Position $record) => self::previewHtml($record, $get('into_id'))),
            ])
            ->modalSubmitActionLabel('Sí, fusionar y eliminar')
            ->action(function (Position $record, array $data): void {
                $into = Position::query()->findOrFail($data['into_id']);

                try {
                    $result = app(PositionMergeService::class)->merge($record, $into);
                } catch (\InvalidArgumentException $e) {
                    Notification::make()->danger()->title('No se pudo fusionar')->body($e->getMessage())->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title("«{$record->name}» se fusionó en «{$into->name}»")
                    ->body("{$result['contracts']} contrato(s) y {$result['children']} cargo(s) dependiente(s) pasaron al cargo conservado.")
                    ->send();
            });
    }

    /**
     * Cargos de la misma empresa a los que se puede fusionar el cargo dado, con su departamento.
     *
     * @return array<int, string>
     */
    private static function targets(Position $position): array
    {
        $companyId = $position->department?->company_id;

        return Position::query()
            ->with('department')
            ->whereKeyNot($position->id)
            ->active()
            ->whereHas('department', fn ($query) => $query->where('company_id', $companyId))
            ->get()
            ->sortBy('name')
            ->mapWithKeys(fn (Position $p) => [$p->id => $p->name.' — '.$p->department?->name])
            ->toArray();
    }

    /**
     * Vista previa de la fusión: cuántos contratos y cargos se mueven y qué configuraciones difieren.
     */
    private static function previewHtml(Position $from, mixed $intoId): HtmlString
    {
        if (blank($intoId) || ! ($into = Position::query()->find($intoId))) {
            return new HtmlString('<span style="color: rgb(var(--gray-500));">Elegí el cargo que se conserva para ver qué se movería.</span>');
        }

        $preview = app(PositionMergeService::class)->preview($from, $into);

        if ($preview['error'] !== null) {
            return new HtmlString('<span style="color: rgb(var(--danger-600));">'.e($preview['error']).'</span>');
        }

        $html = '<div style="font-size: .875rem; line-height: 1.5;">';
        $html .= '<div><strong>'.$preview['contracts'].'</strong> contrato(s) ('.$preview['active_employees'].' empleado(s) activo(s)) pasan a «'.e($into->name).'».</div>';
        $html .= '<div><strong>'.$preview['children'].'</strong> cargo(s) que reportaban a este pasan a reportar a «'.e($into->name).'».</div>';

        foreach ($preview['differences'] as $difference) {
            $html .= '<div style="color: rgb(var(--warning-600));">'.e($difference).'</div>';
        }

        return new HtmlString($html.'</div>');
    }
}
