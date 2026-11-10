<?php

namespace App\Filament\Pages;

use App\Filament\Resources\TerminalResource;
use App\Models\Terminal;
use Filament\Pages\Page;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

/** Página informativa sobre los modos de marcación disponibles y sus URLs. */
class AttendanceModes extends Page
{
    /** Acceso restringido por permiso (las páginas de Filament no consultan la policy por sí solas). */
    public static function canAccess(): bool
    {
        return TerminalResource::canViewAny();
    }

    protected static ?string $navigationLabel = 'Modos de Marcación';

    protected static ?string $navigationIcon = 'heroicon-o-qr-code';

    protected static ?string $navigationGroup = 'Asistencias';

    protected static ?int $navigationSort = 10;

    protected static ?string $title = 'Modos de Marcación';

    protected static string $view = 'filament.pages.attendance-modes';

    /**
     * Pasa las URLs y los QR codes SVG de cada modo a la vista.
     * Las terminales se listan individualmente ya que cada una tiene su propio código.
     *
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $mobileUrl = route('mark.show');

        $terminals = Terminal::with('branch')
            ->where('status', 'active')
            ->orderBy('name')
            ->get()
            ->map(function (Terminal $terminal) {
                $url = route('terminal.show', $terminal->code);

                return [
                    'name' => $terminal->name,
                    'branch' => $terminal->branch?->name,
                    'url' => $url,
                    'qr' => (string) QrCode::format('svg')->size(150)->margin(1)->generate($url),
                ];
            });

        return [
            'mobileUrl' => $mobileUrl,
            'mobileQr' => (string) QrCode::format('svg')->size(150)->margin(1)->generate($mobileUrl),
            'terminals' => $terminals,
        ];
    }
}
