<?php

use App\Http\Controllers\AdvanceController;
use App\Http\Controllers\AdvanceReportController;
use App\Http\Controllers\AguinaldoController;
use App\Http\Controllers\AttendanceExportController;
use App\Http\Controllers\AttendanceFaceMarkController;
use App\Http\Controllers\AttendancePdfReportController;
use App\Http\Controllers\ContractController;
use App\Http\Controllers\ContractReportController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EmployeeFaceController;
use App\Http\Controllers\EmployeeReportController;
use App\Http\Controllers\FaceEnrollmentController;
use App\Http\Controllers\LiquidacionController;
use App\Http\Controllers\LoanController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\MerchandiseReportController;
use App\Http\Controllers\MerchandiseWithdrawalController;
use App\Http\Controllers\MobileLinkController;
use App\Http\Controllers\OrgChartController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\SalaryReportController;
use App\Http\Controllers\ScheduleEmployeeController;
use App\Http\Controllers\ShiftPlannerController;
use App\Http\Controllers\TerminalSetupController;
use App\Http\Controllers\VacationDocumentController;
use App\Http\Controllers\VacationReportController;
use App\Http\Controllers\WarningController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas Públicas - Marcación de Asistencia
|--------------------------------------------------------------------------
|
| Rutas para el sistema de marcación facial sin autenticación.
| Estas rutas son accesibles desde kioscos/terminales públicas.
|
*/

Route::get('/marcar', [AttendanceFaceMarkController::class, 'show'])->name('mark.show');
Route::get('/marcar/manifest.json', [AttendanceFaceMarkController::class, 'markManifest'])->name('mark.manifest');

// Terminal legacy — mantener activa hasta migrar todos los dispositivos físicos
Route::get('/terminal', [AttendanceFaceMarkController::class, 'terminal'])->name('terminal.legacy');

// Terminal identificada por código — nueva arquitectura
Route::get('/terminal/{code}', [AttendanceFaceMarkController::class, 'terminalByCode'])->name('terminal.show');
Route::get('/terminal/{code}/manifest.json', [AttendanceFaceMarkController::class, 'terminalManifest'])->name('terminal.manifest');

// Provisión del terminal como PWA offline — enlace de un solo uso generado desde TerminalResource,
// emite el token Sanctum que el terminal usará contra la API de sincronización (routes/api.php).
// Prefijo explícito en el throttle: sin él, comparte bucket de rate limit (por
// IP, sin distinguir ruta — ver ThrottleRequests::resolveRequestSignature())
// con cualquier otra ruta pública que use 'throttle:X,Y' sin prefijo, como
// 'registro-facial' más abajo.
Route::prefix('terminal/{code}/setup/{setupToken}')->name('terminal.setup.')->middleware('throttle:10,1,terminal-setup')->group(function () {
    Route::get('/', [TerminalSetupController::class, 'show'])->name('show');
    Route::post('/claim', [TerminalSetupController::class, 'claim'])->name('claim');
});

// Vinculación del dispositivo personal para marcación offline — el propio empleado se
// identifica con CI + fecha de nacimiento (sin enlace de un solo uso generado por
// un admin, a diferencia del terminal: acá el dato personal ES la credencial).
// Emite el token Sanctum que el dispositivo usará contra la API de sincronización
// (routes/api.php, prefijo v1/mobile). El POST (intento de credencial) tiene
// throttling más estricto que el GET (solo carga la página) — CI+fecha de
// nacimiento es una credencial de baja entropía, más fácil de fuerza bruta
// que el token random de 64 caracteres del setup de terminales.
Route::prefix('vincular-dispositivo')->name('device-link.')->group(function () {
    Route::get('/', [MobileLinkController::class, 'show'])->name('show')->middleware('throttle:20,1,device-link-show');
    // Prefijos distintos en cada throttle: sin esto, ambos comparten la misma clave de
    // rate limit (dominio+IP — Laravel no distingue por maxAttempts/decayMinutes ni por
    // ruta), y cada request cuenta doble contra un único bucket compartido.
    Route::post('/', [MobileLinkController::class, 'claim'])->name('claim')->middleware([
        'throttle:5,1,device-link-minute',
        'throttle:15,1440,device-link-day',
    ]);
});

/*
|--------------------------------------------------------------------------
| Rutas Públicas - Auto-registro Facial
|--------------------------------------------------------------------------
|
| Rutas para que empleados registren su rostro sin autenticación.
| El acceso se controla mediante token único y temporal.
|
*/

// Prefijo explícito por el mismo motivo que 'terminal.setup' arriba.
Route::prefix('registro-facial')->name('face-enrollment.')->middleware('throttle:10,1,face-enrollment')->group(function () {
    Route::get('/{token}', [FaceEnrollmentController::class, 'show'])->name('show');
    Route::post('/{token}', [FaceEnrollmentController::class, 'store'])->name('store');
});

/*
|--------------------------------------------------------------------------
| Rutas Autenticadas
|--------------------------------------------------------------------------
|
| Rutas que requieren autenticación de usuario.
| Incluyen exportaciones, gestión de horarios, recibos, etc.
|
*/

Route::middleware(['auth'])->group(function () {

    // Captura de rostro de empleados
    Route::prefix('employees/{employee}')->name('face.')->group(function () {
        Route::get('/capture-face', [EmployeeFaceController::class, 'show'])->name('capture');
        Route::post('/capture-face', [EmployeeFaceController::class, 'store'])->name('capture.store');
    });

    // Exportación de asistencias
    Route::get('/asistencias/{attendance_day}/export', [AttendanceExportController::class, 'export'])
        ->name('attendance-days.export');

    // Recibos de pago (nómina)
    Route::prefix('recibos/{payroll}')->name('payrolls.')->group(function () {
        Route::get('/download', [PayrollController::class, 'download'])->name('download');
        Route::get('/view', [PayrollController::class, 'view'])->name('view');
    });
    Route::get('/recibos/temp/{filename}', [PayrollController::class, 'downloadTemp'])
        ->name('payrolls.download.temp')
        ->where('filename', '.+');

    // Recibos de aguinaldo (13° salario)
    Route::get('/aguinaldos/{aguinaldo}/download', [AguinaldoController::class, 'download'])->name('aguinaldos.download');

    // Liquidaciones (finiquitos)
    Route::prefix('liquidaciones/{liquidacion}')->name('liquidaciones.')->group(function () {
        Route::get('/download', [LiquidacionController::class, 'download'])->name('download');
        Route::get('/view', [LiquidacionController::class, 'view'])->name('view');
    });

    // Amonestaciones
    Route::get('/amonestaciones/{warning}/pdf', [WarningController::class, 'show'])->name('warnings.pdf');

    // Préstamos y adelantos (rutas estáticas deben ir ANTES de las dinámicas con {advance})
    Route::get('/prestamos/{loan}/pdf', [LoanController::class, 'show'])->name('loans.pdf');
    Route::get('/retiros-mercaderia/reporte/pdf', [MerchandiseReportController::class, 'pdf'])->name('merchandise.report.pdf');
    Route::get('/retiros-mercaderia/{merchandiseWithdrawal}/pdf', [MerchandiseWithdrawalController::class, 'show'])->name('merchandise-withdrawals.pdf');
    Route::get('/adelantos/pdf/masivo', [AdvanceController::class, 'bulkPdf'])->name('advances.pdf.bulk');
    Route::get('/adelantos/reporte/pdf', [AdvanceReportController::class, 'pdf'])->name('advances.report.pdf');
    Route::get('/nominas/reporte/salarios/pdf', [SalaryReportController::class, 'pdf'])->name('salary-report.pdf');
    Route::get('/asistencia/reporte/asistencias/pdf', [AttendancePdfReportController::class, 'attendance'])->name('attendance.report.attendance.pdf');
    Route::get('/asistencia/reporte/ausencias/pdf', [AttendancePdfReportController::class, 'absence'])->name('attendance.report.absence.pdf');
    Route::get('/asistencia/reporte/overtime/pdf', [AttendancePdfReportController::class, 'overtime'])->name('attendance.report.overtime.pdf');
    Route::get('/empleados/reporte/pdf', [EmployeeReportController::class, 'pdf'])->name('employees.report.pdf');
    Route::get('/adelantos/{advance}/pdf', [AdvanceController::class, 'show'])->name('advances.pdf');

    // Reportes de contratos (rutas estáticas deben ir ANTES de /contratos/{contract}/pdf)
    Route::get('/contratos/reporte/pdf', [ContractReportController::class, 'pdf'])
        ->name('contracts.report.pdf');

    // Contratos laborales
    Route::get('/contratos/{contract}/pdf', [ContractController::class, 'show'])->name('contracts.pdf');
    Route::get('/preview/plantilla-contrato/{contractTemplate}', [ContractController::class, 'previewTemplate'])->name('contract-templates.preview');

    // Legajo del empleado
    Route::get('/empleados/{employee}/legajo', [EmployeeController::class, 'legajo'])->name('employees.legajo');

    // Administración de horarios
    Route::post('/admin/schedules/{schedule}/remove-employee/{employee}', [ScheduleEmployeeController::class, 'removeEmployee'])
        ->name('schedules.remove-employee');

    // Documentos y reportes de vacaciones
    Route::get('/vacaciones/documentos/{filename}', [VacationDocumentController::class, 'download'])
        ->name('vacation.documents.download');
    Route::get('/vacaciones/reporte/pdf', [VacationReportController::class, 'pdf'])
        ->name('vacation.report.pdf');

    // Descarga de archivos de log
    Route::get('/logs/download', [LogController::class, 'download'])->name('logs.download');

    // Organigrama de empresas
    Route::prefix('empresas/{company}')->name('org-chart.')->group(function () {
        Route::get('/organigrama', [OrgChartController::class, 'show'])->name('show');
        Route::get('/organigrama/pdf', [OrgChartController::class, 'exportPdf'])->name('pdf');
    });

    // Planificador visual de turnos rotativos
    Route::prefix('admin/shift-planner')->name('shift-planner.')->group(function () {
        Route::get('/data', [ShiftPlannerController::class, 'data'])->name('data');
        Route::get('/shifts', [ShiftPlannerController::class, 'shifts'])->name('shifts');
        Route::post('/override', [ShiftPlannerController::class, 'storeOverride'])->name('override.store');
        Route::delete('/override', [ShiftPlannerController::class, 'destroyOverride'])->name('override.destroy');
    });
});
