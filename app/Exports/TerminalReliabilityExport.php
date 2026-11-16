<?php

namespace App\Exports;

use App\Models\Terminal;
use App\Services\TerminalReliabilityService;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Confiabilidad de los terminales en un rango de fechas: una fila por terminal con su disponibilidad, cortes,
 * marcaciones, conflictos y calidad de identificación. Es lo que se le muestra al cliente o a quien da soporte.
 */
class TerminalReliabilityExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping, WithStyles
{
    /**
     * @param  array<int, int>|null  $ids  Limita a estos terminales (los del listado filtrado); null = todos.
     */
    public function __construct(
        private readonly CarbonInterface $from,
        private readonly CarbonInterface $to,
        private readonly ?array $ids = null,
    ) {}

    /** @return Collection<int, array{terminal: Terminal, summary: array<string, mixed>}> */
    public function collection()
    {
        $service = app(TerminalReliabilityService::class);

        return Terminal::query()
            ->with('branch.company')
            ->when($this->ids !== null, fn ($query) => $query->whereIn('terminals.id', $this->ids))
            ->orderBy('name')
            ->get()
            ->map(fn (Terminal $terminal) => [
                'terminal' => $terminal,
                'summary' => $service->summary($terminal, $this->from, $this->to),
            ]);
    }

    /** @return array<int, string> */
    public function headings(): array
    {
        return [
            'Empresa', 'Sucursal', 'Terminal', 'Estado', 'Responsable', 'Disponibilidad (%)', 'Minutos medidos',
            'Minutos en línea', 'Cortes', 'Minutos sin conexión', 'Corte más largo (min)', 'Marcaciones', 'Conflictos',
            'Intentos de identificación', 'No reconocidos (%)', 'Tiempo medio de identificación (s)',
        ];
    }

    /**
     * @param  array{terminal: Terminal, summary: array<string, mixed>}  $row
     * @return array<int, mixed>
     */
    public function map($row): array
    {
        /** @var Terminal $terminal */
        $terminal = $row['terminal'];
        $summary = $row['summary'];

        return [
            $terminal->branch?->company?->display_name ?? '—',
            $terminal->branch?->name ?? '—',
            $terminal->name,
            Terminal::getStatusOptions()[$terminal->status] ?? $terminal->status,
            $terminal->responsibleContact() ?? '—',
            $summary['availability'] ?? 'Sin datos',
            $summary['expected_minutes'],
            $summary['online_minutes'],
            $summary['outages'],
            $summary['outage_minutes'],
            $summary['longest_outage_minutes'],
            $summary['marks'],
            $summary['conflicts'],
            $summary['identification_attempts'],
            $summary['identification_failure_percent'] ?? 'Sin datos',
            $summary['average_identification_ms'] === null ? 'Sin datos' : round($summary['average_identification_ms'] / 1000, 1),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
