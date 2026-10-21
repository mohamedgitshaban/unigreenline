<?php

use Illuminate\Support\Facades\Route;
use Modules\HR\Http\Controllers\ApplicantController;
use Modules\HR\Http\Controllers\AttendanceController;
use Modules\HR\Http\Controllers\DepartmentController;
use Modules\HR\Http\Controllers\EmployeeController;
use Modules\HR\Http\Controllers\FleetController;
use Modules\HR\Http\Controllers\FleetServiceTypeController;
use Modules\HR\Http\Controllers\HolidayController;
use Modules\HR\Http\Controllers\HrDashboardController;
use Modules\HR\Http\Controllers\InterviewController;
use Modules\HR\Http\Controllers\JobPositionController;
use Modules\HR\Http\Controllers\LeaveRequestController;
use Modules\HR\Http\Controllers\PayrollRunController;
use Modules\HR\Http\Controllers\PayslipController;
use Modules\HR\Http\Controllers\RecruitmentController;
use Modules\HR\Http\Controllers\RecruitmentStageController;
use Modules\HR\Http\Controllers\RefuseReasonController;
use Modules\HR\Http\Controllers\TimePermissionController;
use Modules\HR\Http\Controllers\VehicleContractController;
use Modules\HR\Http\Controllers\VehicleController;
use Modules\HR\Http\Controllers\VehicleFuelLogController;
use Modules\HR\Http\Controllers\VehicleOdometerLogController;
use Modules\HR\Http\Controllers\VehicleServiceController;
use Modules\HR\Http\Controllers\WorkScheduleController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::get('dashboards/hr', [HrDashboardController::class, 'show'])->defaults('module', 'hr')->can('hr-dashboard.view');
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

    // Recruitment
    Route::get('job-positions', [JobPositionController::class, 'index'])->can('job-positions.view')->exportable('job-positions.export');
    Route::post('job-positions', [JobPositionController::class, 'store'])->can('job-positions.create');
    Route::get('job-positions/{id}', [JobPositionController::class, 'show'])->can('job-positions.view');
    Route::put('job-positions/{id}', [JobPositionController::class, 'update'])->can('job-positions.update');
    Route::delete('job-positions/{id}', [JobPositionController::class, 'destroy'])->can('job-positions.delete');

    Route::get('recruitment-stages', [RecruitmentStageController::class, 'index'])->can('recruitment-stages.view');
    Route::post('recruitment-stages', [RecruitmentStageController::class, 'store'])->can('recruitment-stages.create');
    Route::get('recruitment-stages/{id}', [RecruitmentStageController::class, 'show'])->can('recruitment-stages.view');
    Route::put('recruitment-stages/{id}', [RecruitmentStageController::class, 'update'])->can('recruitment-stages.update');
    Route::delete('recruitment-stages/{id}', [RecruitmentStageController::class, 'destroy'])->can('recruitment-stages.delete');

    Route::get('refuse-reasons', [RefuseReasonController::class, 'index'])->can('refuse-reasons.view');
    Route::post('refuse-reasons', [RefuseReasonController::class, 'store'])->can('refuse-reasons.create');
    Route::get('refuse-reasons/{id}', [RefuseReasonController::class, 'show'])->can('refuse-reasons.view');
    Route::put('refuse-reasons/{id}', [RefuseReasonController::class, 'update'])->can('refuse-reasons.update');
    Route::delete('refuse-reasons/{id}', [RefuseReasonController::class, 'destroy'])->can('refuse-reasons.delete');

    Route::get('applicants', [ApplicantController::class, 'index'])->can('applicants.view')->exportable('applicants.export');
    Route::post('applicants', [ApplicantController::class, 'store'])->can('applicants.create');
    Route::get('applicants/{id}', [ApplicantController::class, 'show'])->can('applicants.view');
    Route::put('applicants/{id}', [ApplicantController::class, 'update'])->can('applicants.update');
    Route::delete('applicants/{id}', [ApplicantController::class, 'destroy'])->can('applicants.delete');
    Route::post('applicants/{id}/move', [ApplicantController::class, 'move'])->can('applicants.move');
    Route::post('applicants/{id}/refuse', [ApplicantController::class, 'refuse'])->can('applicants.refuse');
    Route::post('applicants/{id}/archive', [ApplicantController::class, 'archive'])->can('applicants.archive');
    Route::post('applicants/{id}/restore', [ApplicantController::class, 'restore'])->can('applicants.restore');
    Route::post('applicants/{id}/offer', [ApplicantController::class, 'offer'])->can('applicants.offer');
    Route::post('applicants/{id}/hire', [ApplicantController::class, 'hire'])->can('applicants.hire');
    Route::get('applicants/{id}/history', [ApplicantController::class, 'history'])->can('applicants.history');
    Route::post('applicants/{id}/notes', [ApplicantController::class, 'addNote'])->can('applicants.add-note');
    Route::post('applicants/{id}/resume', [ApplicantController::class, 'uploadResume'])->can('applicants.upload-resume');
    Route::get('applicants/{id}/resume', [ApplicantController::class, 'downloadResume'])->can('applicants.resume');

    Route::get('interviews', [InterviewController::class, 'index'])->can('interviews.view');
    Route::post('interviews', [InterviewController::class, 'store'])->can('interviews.create');
    Route::get('interviews/{id}', [InterviewController::class, 'show'])->can('interviews.view');
    Route::put('interviews/{id}', [InterviewController::class, 'update'])->can('interviews.update');
    Route::delete('interviews/{id}', [InterviewController::class, 'destroy'])->can('interviews.delete');
    Route::post('interviews/{id}/record-result', [InterviewController::class, 'recordResult'])->can('interviews.record-result');

    Route::get('recruitment/pipeline', [RecruitmentController::class, 'pipeline'])->can('recruitment-pipeline.view');
    Route::get('recruitment/analysis', [RecruitmentController::class, 'analysis'])->can('recruitment-analysis.view');

    // Fleet
    Route::get('vehicles', [VehicleController::class, 'index'])->can('vehicles.view')->exportable('vehicles.export');
    Route::post('vehicles', [VehicleController::class, 'store'])->can('vehicles.create');
    Route::get('vehicles/{id}', [VehicleController::class, 'show'])->can('vehicles.view');
    Route::put('vehicles/{id}', [VehicleController::class, 'update'])->can('vehicles.update');
    Route::delete('vehicles/{id}', [VehicleController::class, 'destroy'])->can('vehicles.delete');
    Route::post('vehicles/{id}/assign-driver', [VehicleController::class, 'assignDriver'])->can('vehicles.assign-driver');
    Route::get('vehicles/{id}/assignments', [VehicleController::class, 'assignments'])->can('vehicles.view');
    Route::get('vehicles/{id}/costs', [VehicleController::class, 'costs'])->can('vehicles.cost-summary');

    Route::get('vehicle-odometer-logs', [VehicleOdometerLogController::class, 'index'])->can('vehicle-odometer-logs.view');
    Route::post('vehicle-odometer-logs', [VehicleOdometerLogController::class, 'store'])->can('vehicle-odometer-logs.create');

    Route::get('fleet-service-types', [FleetServiceTypeController::class, 'index'])->can('fleet-service-types.view');
    Route::post('fleet-service-types', [FleetServiceTypeController::class, 'store'])->can('fleet-service-types.create');
    Route::get('fleet-service-types/{id}', [FleetServiceTypeController::class, 'show'])->can('fleet-service-types.view');
    Route::put('fleet-service-types/{id}', [FleetServiceTypeController::class, 'update'])->can('fleet-service-types.update');
    Route::delete('fleet-service-types/{id}', [FleetServiceTypeController::class, 'destroy'])->can('fleet-service-types.delete');

    Route::get('vehicle-services', [VehicleServiceController::class, 'index'])->can('vehicle-services.view')->exportable('vehicle-services.export');
    Route::post('vehicle-services', [VehicleServiceController::class, 'store'])->can('vehicle-services.create');
    Route::get('vehicle-services/{id}', [VehicleServiceController::class, 'show'])->can('vehicle-services.view');
    Route::put('vehicle-services/{id}', [VehicleServiceController::class, 'update'])->can('vehicle-services.update');
    Route::delete('vehicle-services/{id}', [VehicleServiceController::class, 'destroy'])->can('vehicle-services.delete');
    Route::post('vehicle-services/{id}/mark-done', [VehicleServiceController::class, 'markDone'])->can('vehicle-services.mark-done');

    Route::get('vehicle-fuel-logs', [VehicleFuelLogController::class, 'index'])->can('vehicle-fuel-logs.view')->exportable('vehicle-fuel-logs.export');
    Route::post('vehicle-fuel-logs', [VehicleFuelLogController::class, 'store'])->can('vehicle-fuel-logs.create');
    Route::get('vehicle-fuel-logs/{id}', [VehicleFuelLogController::class, 'show'])->can('vehicle-fuel-logs.view');
    Route::put('vehicle-fuel-logs/{id}', [VehicleFuelLogController::class, 'update'])->can('vehicle-fuel-logs.update');
    Route::delete('vehicle-fuel-logs/{id}', [VehicleFuelLogController::class, 'destroy'])->can('vehicle-fuel-logs.delete');

    Route::get('vehicle-contracts', [VehicleContractController::class, 'index'])->can('vehicle-contracts.view')->exportable('vehicle-contracts.export');
    Route::post('vehicle-contracts', [VehicleContractController::class, 'store'])->can('vehicle-contracts.create');
    Route::get('vehicle-contracts/{id}', [VehicleContractController::class, 'show'])->can('vehicle-contracts.view');
    Route::put('vehicle-contracts/{id}', [VehicleContractController::class, 'update'])->can('vehicle-contracts.update');
    Route::delete('vehicle-contracts/{id}', [VehicleContractController::class, 'destroy'])->can('vehicle-contracts.delete');
    Route::post('vehicle-contracts/{id}/close', [VehicleContractController::class, 'close'])->can('vehicle-contracts.close');

    Route::get('fleet/analysis', [FleetController::class, 'analysis'])->can('fleet-analysis.view');

    Route::get('payslips', [PayslipController::class, 'index'])->can('payslips.view')->exportable('payslips.export');
    Route::get('payslips/{id}', [PayslipController::class, 'show'])->can('payslips.view');
});
