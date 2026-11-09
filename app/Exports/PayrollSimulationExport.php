<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Exporta una simulación de nómina a Excel: la hoja «Resumen» (una fila por empleado, pensada para cruzar
 * por cédula contra el cálculo manual del cliente) y la hoja «Detalle» (cada concepto de cada recibo).
 */
class PayrollSimulationExport implements WithMultipleSheets
{
    use Exportable;

    /** @param  array{rows: list<array<string, mixed>>, totals: array<string, mixed>}  $simulation  Resultado de PayrollSimulationService::simulate(). */
    public function __construct(private readonly array $simulation) {}

    /** @return array<int, object> */
    public function sheets(): array
    {
        $summary = collect($this->simulation['rows'])->map(fn (array $row) => [
            $row['ci'], $row['name'], $row['branch'], $row['payment_method'] === 'cash' ? 'Efectivo' : 'Transferencia',
            $row['base_salary'], $row['perceptions'], $row['ips'], $row['other_deductions'], $row['deductions'], $row['net'],
        ])->all();

        $detail = collect($this->simulation['rows'])->flatMap(fn (array $row) => collect($row['items'])->map(fn (array $item) => [
            $row['ci'], $row['name'], $item['type'] === 'perception' ? 'Percepción' : 'Deducción', $item['description'], $item['amount'],
        ]))->all();

        return [
            new ArraySheet($summary, ['CI', 'Empleado', 'Sucursal', 'Forma de pago', 'Salario base', 'Percepciones', 'IPS', 'Otros descuentos', 'Total descuentos', 'Neto'], 'Resumen'),
            new ArraySheet($detail, ['CI', 'Empleado', 'Tipo', 'Concepto', 'Monto'], 'Detalle'),
        ];
    }
}
