<?php

namespace App\Observers;

use App\Models\Employee;
use App\Services\EmployeePhotoThumbnailService;
use App\Services\ScheduleAssignmentService;
use Carbon\Carbon;

class EmployeeObserver
{
    /**
     * Regenera `photo_thumbnail` en la misma escritura que cambia `photo` —
     * en `saving()` (no `updated()`/`created()`) para que quede en el mismo
     * INSERT/UPDATE, sin una segunda query.
     */
    public function saving(Employee $employee): void
    {
        if ($employee->isDirty('photo')) {
            $employee->photo_thumbnail = EmployeePhotoThumbnailService::generate($employee->photo);
        }
    }

    public function created(Employee $employee): void
    {
        if (! Employee::$skipMandatoryDeductions) {
            $employee->assignMandatoryDeductions();
        }
    }

    public function updated(Employee $employee): void
    {
        if ($employee->wasChanged('mobile_marking_allowed') && $employee->hasMobileLinked() && ! $employee->canMarkFromMobile()) {
            $employee->revokeMobileToken();
        }

        if (! $employee->isDirty('status')) {
            return;
        }

        if ($employee->status === 'inactive') {
            $employee->activeEmployeePerceptions->each->deactivate();
            $employee->activeEmployeeDeductions->each->deactivate();
            ScheduleAssignmentService::closeActive($employee, Carbon::today());
        }
    }
}
