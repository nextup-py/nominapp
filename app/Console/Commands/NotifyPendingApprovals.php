<?php

namespace App\Console\Commands;

use App\Models\Advance;
use App\Models\EmployeeLeave;
use App\Models\Loan;
use App\Models\MerchandiseWithdrawal;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Notifications\PendingApprovalsNotification;
use App\Services\TeamScopeService;
use Illuminate\Console\Command;

/**
 * Avisa a quienes aprueban de las solicitudes pendientes (adelantos, préstamos, retiros de mercadería y permisos).
 * Un solo aviso resumen por usuario, con los tipos que puede aprobar; si ya tiene uno sin leer, no se repite.
 */
class NotifyPendingApprovals extends Command
{
    protected $signature = 'approvals:notify-pending';

    protected $description = 'Avisa a quienes aprueban de las solicitudes pendientes de decisión';

    public function handle(): int
    {
        $totals = [
            'advances' => Advance::query()->where('status', 'pending')->count(),
            'loans' => Loan::query()->where('status', 'pending')->count(),
            'merchandise_withdrawals' => MerchandiseWithdrawal::query()->where('status', 'pending')->count(),
            'employee_leaves' => EmployeeLeave::query()->where('status', 'pending')->count(),
            'overtime_requests' => OvertimeRequest::query()->where('status', OvertimeRequest::STATUS_PENDING_HR)->count(),
            'overtime_boss' => OvertimeRequest::query()->where('status', OvertimeRequest::STATUS_PENDING_BOSS)->count(),
        ];

        if (array_sum($totals) === 0) {
            $this->info('No hay solicitudes pendientes de aprobar.');

            return self::SUCCESS;
        }

        $notified = 0;

        foreach (User::all() as $user) {
            $counts = [];

            foreach (PendingApprovalsNotification::types() as $key => [, , $permission]) {
                if ($key === 'overtime_boss') {
                    $mine = $totals[$key] > 0 && ($user->can('view_team') || $user->can('view_branch_team'))
                        ? OvertimeRequest::query()->where('status', OvertimeRequest::STATUS_PENDING_BOSS)
                            ->whereIn('employee_id', app(TeamScopeService::class)->employeeIds($user))->count()
                        : 0;
                    if ($mine > 0) {
                        $counts[$key] = $mine;
                    }

                    continue;
                }

                if ($totals[$key] > 0 && $user->can($permission)) {
                    $counts[$key] = $totals[$key];
                }
            }

            if ($counts === []) {
                continue;
            }

            $alreadyPending = $user->unreadNotifications()
                ->where('type', PendingApprovalsNotification::class)
                ->exists();

            if ($alreadyPending) {
                continue;
            }

            $user->notify(new PendingApprovalsNotification($counts));
            $notified++;
        }

        $this->info('Solicitudes pendientes: '.json_encode($totals).". Aviso enviado a {$notified} usuario(s).");

        return self::SUCCESS;
    }
}
