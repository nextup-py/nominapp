<?php

namespace Database\Seeders;

use App\Models\Contract;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Completa la plantilla de la demo hasta ~40 empleados activos repartidos en las sucursales, con rostro registrado
 * (para que los terminales los puedan cachear e identificar) y superior directo por sucursal (para «Mi equipo» y el
 * organigrama). Corre justo después de `EmployeeSeeder` para que los seeders siguientes (horarios, percepciones, asistencia,
 * nómina…) también lo cubran. Todo es determinista: la misma corrida da los mismos empleados.
 */
class DemoStaffSeeder extends Seeder
{
    /** Cantidad de empleados activos a la que se completa la demo. */
    public const TARGET_ACTIVE = 40;

    /** Un rostro de cada `FACE_EVERY` queda sin registrar, para mostrar el pendiente de enrolamiento. */
    private const FACE_EVERY = 14;

    /** @var array<int, string> */
    private const FEMALE = ['LUCÍA', 'VALENTINA', 'CAMILA', 'GABRIELA', 'NATALIA', 'ROMINA', 'ELENA', 'MARISOL', 'FIORELLA', 'DAHIANA', 'LETICIA', 'NOELIA', 'JAZMÍN', 'CAROLINA'];

    /** @var array<int, string> */
    private const MALE = ['MARTÍN', 'GUSTAVO', 'EMILIANO', 'RICARDO', 'SEBASTIÁN', 'HUGO', 'ALEJANDRO', 'ÓSCAR', 'DANIEL', 'NICOLÁS', 'FABIÁN', 'CÉSAR', 'IVÁN', 'JAVIER'];

    /** @var array<int, string> */
    private const LAST = ['BENÍTEZ', 'ACOSTA', 'VILLALBA', 'CABRERA', 'AYALA', 'RIVEROS', 'MEZA', 'ORTIZ', 'DUARTE', 'SANABRIA', 'FRANCO', 'LEZCANO', 'PAREDES', 'ROJAS', 'CÁCERES', 'NÚÑEZ', 'ESPÍNOLA', 'VERA', 'GAMARRA', 'BOGADO', 'ESCOBAR', 'ALMADA', 'MOREL', 'AQUINO'];

    /**
     * Sucursal, cargo y horario de cada empleado nuevo, en orden. Se toman los primeros que falten para llegar al objetivo (y se repite el patrón si hiciera falta más).
     *
     * @var array<int, array{0: string, 1: string, 2: string}>
     */
    private const SLOTS = [
        ['Sucursal Central', 'Supervisor/a de Ventas', 'Estándar'],
        ['Sucursal Central', 'Ejecutivo/a de Ventas', 'Estándar'],
        ['Sucursal Central', 'Representante de Atención', 'Mañana'],
        ['Sucursal Central', 'Representante de Atención', 'Tarde'],
        ['Sucursal Este', 'Líder de CAC', 'Estándar'],
        ['Sucursal Este', 'Representante de Atención', 'Mañana'],
        ['Sucursal Norte', 'Encargado/a de Depósito', 'Estándar'],
        ['Sucursal Norte', 'Coordinador/a Logístico', 'Estándar'],
        ['Sucursal Central', 'Auxiliar Contable', 'Estándar'],
        ['Sucursal Central', 'Asistente Administrativo', 'Estándar'],
        ['Sucursal Este', 'Ejecutivo/a de Ventas', 'Tarde'],
        ['Sucursal Central', 'Soporte IT', 'Estándar'],
        ['Sucursal Norte', 'Operario/a de Producción', 'Mañana'],
        ['Sucursal Norte', 'Operario/a de Producción', 'Tarde'],
        ['Sucursal Central', 'Analista de RRHH', 'Estándar'],
        ['Sucursal Este', 'Representante de Atención', 'Tarde'],
        ['Sucursal Central', 'Diseñador/a Gráfico/a', 'Estándar'],
        ['Sucursal Central', 'Ejecutivo/a de Ventas', 'Mañana'],
        ['Sucursal Sur', 'Supervisor/a de Ventas', 'Estándar'],
        ['Sucursal Sur', 'Ejecutivo/a de Ventas', 'Estándar'],
        ['Sucursal Norte', 'Inspector/a de Calidad', 'Estándar'],
        ['Sucursal Central', 'Administrador/a de Sistemas', 'Estándar'],
        ['Sucursal Este', 'Ejecutivo/a de Ventas', 'Mañana'],
        ['Sucursal Central', 'Especialista en Marketing', 'Estándar'],
        ['Sucursal Central', 'Representante de Atención', 'Estándar'],
        ['Sucursal Norte', 'Operario/a de Producción', 'Estándar'],
        ['Sucursal Central', 'Ejecutivo/a de Ventas', 'Tarde'],
        ['Sucursal Este', 'Encargado/a de Depósito', 'Mañana'],
    ];

    public function run(): void
    {
        $userId = DB::table('users')->value('id');
        $branches = DB::table('branches')->pluck('id', 'name');
        $schedules = DB::table('schedules')->pluck('id', 'name');
        $positions = DB::table('positions')->get(['id', 'name', 'department_id'])->keyBy('name');
        $missing = max(0, self::TARGET_ACTIVE - Employee::query()->where('status', 'active')->count());

        for ($i = 0; $i < $missing; $i++) {
            [$branchName, $positionName, $scheduleName] = self::SLOTS[$i % count(self::SLOTS)];

            $this->createEmployee($i, $userId, $branches[$branchName] ?? null, $positions[$positionName] ?? null, $schedules[$scheduleName] ?? null);
        }

        $this->registerFaces();
        $this->assignManagers();
    }

    /** Crea el empleado y su contrato vigente; el observer asigna las deducciones obligatorias. */
    private function createEmployee(int $i, ?int $userId, ?int $branchId, ?object $position, ?int $scheduleId): void
    {
        $female = $i % 2 === 0;
        $first = $female ? self::FEMALE[intdiv($i, 2) % count(self::FEMALE)] : self::MALE[intdiv($i, 2) % count(self::MALE)];
        $last = self::LAST[$i % count(self::LAST)].' '.self::LAST[($i * 7 + 3) % count(self::LAST)];
        $handle = Str::slug(Str::ascii(mb_strtolower($first.'.'.explode(' ', $last)[0])), '.');

        $employee = Employee::create([
            'first_name' => $first,
            'last_name' => $last,
            'ci' => (string) (9100000 + $i * 113),
            'birth_date' => Carbon::create(1976 + ($i * 5) % 26, 1 + ($i * 7) % 12, 1 + ($i * 11) % 27)->toDateString(),
            'gender' => $female ? 'femenino' : 'masculino',
            'phone' => '0981'.str_pad((string) (200100 + $i * 311), 6, '0', STR_PAD_LEFT),
            'email' => "{$handle}{$i}@empresa.com",
            'branch_id' => $branchId,
            'schedule_id' => $scheduleId,
            'status' => 'active',
        ]);

        Contract::create(array_merge([
            'employee_id' => $employee->id,
            'status' => 'active',
            'created_by_id' => $userId,
            'trial_days' => 30,
            'salary_type' => 'mensual',
            'payroll_type' => 'monthly',
            'work_modality' => 'presencial',
            'position_id' => $position?->id,
            'department_id' => $position?->department_id,
        ], $this->contractTerms($i)));
    }

    /**
     * Condiciones del contrato: casi todos indefinidos; dos recién ingresados (fin del período de prueba cerca) y
     * dos a plazo fijo por vencer, para mostrar los avisos de contratos.
     *
     * @return array<string, mixed>
     */
    private function contractTerms(int $i): array
    {
        $salary = 3200000 + (($i * 370000) % 4300000);
        $start = Carbon::today()->subMonths(6 + ($i * 5) % 60)->startOfMonth();

        return match ($i) {
            2, 9 => ['type' => 'indefinido', 'start_date' => Carbon::today()->subDays(24)->toDateString(), 'salary' => 3200000],
            5, 14 => ['type' => 'plazo_fijo', 'start_date' => Carbon::today()->subMonths(10)->toDateString(), 'end_date' => Carbon::today()->addDays(12 + $i)->toDateString(), 'salary' => $salary],
            default => ['type' => 'indefinido', 'start_date' => $start->toDateString(), 'salary' => $salary],
        };
    }

    /** Registra un rostro de ejemplo a casi todos los empleados activos (vector de 128 valores, determinista). */
    private function registerFaces(): void
    {
        Employee::query()->where('status', 'active')->orderBy('id')->get()->each(function (Employee $employee, int $index) {
            if ($index % self::FACE_EVERY === self::FACE_EVERY - 1) {
                return;
            }

            mt_srand($employee->id * 7919);
            $descriptor = array_map(fn () => round((mt_rand() / mt_getrandmax()) * 0.4 - 0.2, 6), range(1, 128));
            mt_srand();

            $employee->forceFill(['face_descriptor' => $descriptor])->save();
        });
    }

    /** El primer cargo de mando de cada sucursal pasa a ser el superior directo del resto del equipo de esa sucursal. */
    private function assignManagers(): void
    {
        $leadership = '/Gerente|Supervisor|Líder|Coordinador|Encargado|Jefe/u';

        Employee::query()->where('status', 'active')->with(['activeContract.position'])->get()->groupBy('branch_id')->each(function ($team) use ($leadership) {
            $manager = $team->first(fn (Employee $e) => preg_match($leadership, (string) $e->activeContract?->position?->name) === 1);

            if (! $manager) {
                return;
            }

            Employee::query()
                ->whereIn('id', $team->where('id', '!=', $manager->id)->pluck('id'))
                ->update(['reports_to_id' => $manager->id]);
        });
    }
}
