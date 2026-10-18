<?php

use Illuminate\Support\Facades\Route;
use Modules\HR\Http\Controllers\AttendanceController;
use Modules\HR\Http\Controllers\DepartmentController;
use Modules\HR\Http\Controllers\EmployeeController;
use Modules\HR\Http\Controllers\HolidayController;
use Modules\HR\Http\Controllers\LeaveRequestController;
use Modules\HR\Http\Controllers\PayrollRunController;
use Modules\HR\Http\Controllers\PayslipController;
use Modules\HR\Http\Controllers\TimePermissionController;
use Modules\HR\Http\Controllers\WorkScheduleController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::get('departments', [DepartmentController::class, 'index'])->can('departments.view');
    Route::post('departments', [DepartmentController::class, 'store'])->can('departments.create');
    Route::get('departments/{department}', [DepartmentController::class, 'show'])->can('departments.view');
    Route::put('departments/{department}', [DepartmentController::class, 'update'])->can('departments.update');
    Route::delete('departments/{department}', [DepartmentController::class, 'destroy'])->can('departments.delete');

    Route::get('work-schedules', [WorkScheduleController::class, 'index'])->can('work-schedules.view');
    Route::post('work-schedules', [WorkScheduleController::class, 'store'])->can('work-schedules.create');
    Route::get('work-schedules/{workSchedule}', [WorkScheduleController::class, 'show'])->can('work-schedules.view');
    Route::put('work-schedules/{workSchedule}', [WorkScheduleController::class, 'update'])->can('work-schedules.update');
    Route::delete('work-schedules/{workSchedule}', [WorkScheduleController::class, 'destroy'])->can('work-schedules.delete');

    Route::get('holidays', [HolidayController::class, 'index'])->can('holidays.view');
    Route::post('holidays', [HolidayController::class, 'store'])->can('holidays.create');
    Route::get('holidays/{holiday}', [HolidayController::class, 'show'])->can('holidays.view');
    Route::put('holidays/{holiday}', [HolidayController::class, 'update'])->can('holidays.update');
    Route::delete('holidays/{holiday}', [HolidayController::class, 'destroy'])->can('holidays.delete');

    Route::get('employees', [EmployeeController::class, 'index'])->can('employees.view')->exportable('employees.export');
    Route::post('employees', [EmployeeController::class, 'store'])->can('employees.create');
    Route::get('employees/{employee}', [EmployeeController::class, 'show'])->can('employees.view');
    Route::get('employees/{employee}/leave-balance', [EmployeeController::class, 'leaveBalance'])->can('employees.view');
    Route::put('employees/{employee}', [EmployeeController::class, 'update'])->can('employees.update');
    Route::delete('employees/{employee}', [EmployeeController::class, 'destroy'])->can('employees.delete');

    Route::get('attendances', [AttendanceController::class, 'index'])->can('attendances.view')->exportable('attendances.export');
    Route::post('attendances', [AttendanceController::class, 'store'])->can('attendances.create');
    Route::post('attendances/check-in', [AttendanceController::class, 'checkIn'])->can('attendances.check-in');
    Route::post('attendances/check-out', [AttendanceController::class, 'checkOut'])->can('attendances.check-out');
    Route::get('attendances/summary', [AttendanceController::class, 'summary'])->can('attendances.view');
    Route::get('attendances/{attendance}', [AttendanceController::class, 'show'])->can('attendances.view');
    Route::put('attendances/{attendance}', [AttendanceController::class, 'update'])->can('attendances.update');
    Route::delete('attendances/{attendance}', [AttendanceController::class, 'destroy'])->can('attendances.delete');

    Route::get('time-permissions', [TimePermissionController::class, 'index'])->can('time-permissions.view');
    Route::post('time-permissions', [TimePermissionController::class, 'store'])->can('time-permissions.create');
    Route::get('time-permissions/{timePermission}', [TimePermissionController::class, 'show'])->can('time-permissions.view');
    Route::put('time-permissions/{timePermission}', [TimePermissionController::class, 'update'])->can('time-permissions.update');
    Route::delete('time-permissions/{timePermission}', [TimePermissionController::class, 'destroy'])->can('time-permissions.delete');
    Route::post('time-permissions/{timePermission}/approve', [TimePermissionController::class, 'approve'])->can('time-permissions.approve');
    Route::post('time-permissions/{timePermission}/reject', [TimePermissionController::class, 'reject'])->can('time-permissions.reject');

    Route::get('leave-requests', [LeaveRequestController::class, 'index'])->can('leave-requests.view');
    Route::post('leave-requests', [LeaveRequestController::class, 'store'])->can('leave-requests.create');
    Route::get('leave-requests/{leaveRequest}', [LeaveRequestController::class, 'show'])->can('leave-requests.view');
    Route::put('leave-requests/{leaveRequest}', [LeaveRequestController::class, 'update'])->can('leave-requests.update');
    Route::delete('leave-requests/{leaveRequest}', [LeaveRequestController::class, 'destroy'])->can('leave-requests.delete');
    Route::post('leave-requests/{leaveRequest}/approve', [LeaveRequestController::class, 'approve'])->can('leave-requests.approve');
    Route::post('leave-requests/{leaveRequest}/reject', [LeaveRequestController::class, 'reject'])->can('leave-requests.reject');

    Route::get('payroll-runs', [PayrollRunController::class, 'index'])->can('payroll-runs.view')->exportable('payroll-runs.export');
    Route::post('payroll-runs', [PayrollRunController::class, 'store'])->can('payroll-runs.create');
    Route::get('payroll-runs/{payrollRun}', [PayrollRunController::class, 'show'])->can('payroll-runs.view');
    Route::get('payroll-runs/{payrollRun}/payslips', [PayrollRunController::class, 'payslips'])->can('payroll-runs.view');
    Route::put('payroll-runs/{payrollRun}/recalculate', [PayrollRunController::class, 'recalculate'])->can('payroll-runs.recalculate');
    Route::post('payroll-runs/{payrollRun}/approve', [PayrollRunController::class, 'approve'])->can('payroll-runs.approve');
    Route::post('payroll-runs/{payrollRun}/pay', [PayrollRunController::class, 'pay'])->can('payroll-runs.pay');
    Route::delete('payroll-runs/{payrollRun}', [PayrollRunController::class, 'destroy'])->can('payroll-runs.delete');

    Route::get('payslips', [PayslipController::class, 'index'])->can('payslips.view')->exportable('payslips.export');
    Route::get('payslips/{id}', [PayslipController::class, 'show'])->can('payslips.view');
});
