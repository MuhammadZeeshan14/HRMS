<?php

use App\Http\Controllers\LeaveAttendanceController as C;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('leave-attendance')->name('la.')->group(function () {
    Route::get('/attendance/today', [\App\Http\Controllers\EmployeeAttendanceController::class, 'today'])->name('employee.today');
    Route::post('/attendance/punch', [\App\Http\Controllers\EmployeeAttendanceController::class, 'punch'])->middleware('throttle:30,1')->name('employee.punch');
    Route::get('/attendance/my-history', [\App\Http\Controllers\EmployeeAttendanceController::class, 'history'])->name('employee.history');
    Route::get('/dashboard', [C::class, 'dashboard'])->name('dashboard');
    Route::get('/setup', [C::class, 'setupOverview'])->name('setup.overview');
    Route::get('/', [C::class, 'register'])->name('register');
    Route::get('/attendance/entry', [C::class, 'attendanceForm'])->name('attendance.form');
    Route::post('/attendance/entry', [C::class, 'attendanceStore'])->name('attendance.store');
    Route::get('/attendance/history/{employee}', [C::class, 'history'])->name('history');
    Route::get('/corrections', [C::class, 'corrections'])->name('corrections');
    Route::post('/corrections/{correction}/decision', [C::class, 'correctionDecision'])->name('corrections.decide');
    Route::get('/leave/apply', [C::class, 'leaveForm'])->name('leave.form');
    Route::get('/leave/preview', [C::class, 'leavePreview'])->name('leave.preview');
    Route::post('/leave/apply', [C::class, 'leaveStore'])->name('leave.store');
    Route::get('/leave', [C::class, 'leaves'])->name('leaves');
    Route::post('/leave/{leave}/decision', [C::class, 'leaveDecision'])->name('leaves.decide');
    Route::get('/balances', [C::class, 'balances'])->name('balances');
    Route::get('/setup/{kind?}', [C::class, 'setup'])->name('setup');
    Route::post('/setup/assignments/bulk', [C::class, 'assignShifts'])->name('setup.assignments.bulk');
    Route::put('/setup/assignments/bulk', [C::class, 'editShifts'])->name('setup.assignments.bulk-edit');
    Route::post('/setup/{kind}', [C::class, 'setupSave'])->name('setup.store');
    Route::put('/setup/{kind}/{id}', [C::class, 'setupSave'])->name('setup.update');
    Route::delete('/setup/{kind}/{id}', [C::class, 'setupDelete'])->name('setup.delete');
    Route::get('/import', [C::class, 'importForm'])->name('import');
    Route::get('/import/template', [C::class, 'template'])->name('import.template');
    Route::post('/import', [C::class, 'importUpload'])->name('import.upload');
    Route::get('/import/{batch}', [C::class, 'importPreview'])->name('import.preview');
    Route::post('/import/{batch}/mapping', [C::class, 'importMap'])->name('import.map');
    Route::post('/import/{batch}/layout', [C::class, 'importLayout'])->name('import.layout');
    Route::post('/import/{batch}/review', [C::class, 'importReview'])->name('import.review');
    Route::post('/import/{batch}/commit', [C::class, 'importCommit'])->name('import.commit');
});
