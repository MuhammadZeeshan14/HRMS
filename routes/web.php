<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CompanyDepartmentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\MasterDataController;
use App\Http\Controllers\PrivilegeController;
use Illuminate\Support\Facades\Route;

require __DIR__.'/leave-attendance.php';

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'form'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:6,1')->name('login.attempt');
    Route::get('/forgot-password', [AccountController::class, 'forgotForm'])->name('password.request');
    Route::post('/forgot-password', [AccountController::class, 'forgot'])->middleware('throttle:4,1')->name('password.email');
    Route::get('/reset-password/{token}', [AccountController::class, 'resetForm'])->name('password.reset');
    Route::post('/reset-password', [AccountController::class, 'reset'])->middleware('throttle:6,1')->name('password.update');
});
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/my-account', [AccountController::class, 'show'])->name('account.show');
    Route::post('/my-account/password', [AccountController::class, 'password'])->middleware('throttle:6,1')->name('account.password');
    Route::post('/my-account/profile', [AccountController::class, 'profile'])->middleware('throttle:6,1')->name('account.profile');
    Route::post('/my-account/sessions', [AccountController::class, 'sessions'])->middleware('throttle:6,1')->name('account.sessions');
    Route::post('/my-account/recovery', [AccountController::class, 'recovery'])->middleware('throttle:4,1')->name('account.recovery');
    Route::get('/my-account/recovery/verify', [AccountController::class, 'verifyRecovery'])->middleware('signed')->name('account.recovery.verify');
    Route::get('/my-account/notifications/{id}', [AccountController::class, 'notification'])->name('account.notification');
    Route::post('/employees/{employee}/temporary-password', [AccountController::class, 'assisted'])->middleware('throttle:4,1')->name('employees.password');
    Route::get('/access-management', [PrivilegeController::class, 'index'])->name('access.index');
    Route::post('/access-management/{employee}', [PrivilegeController::class, 'update'])->middleware('throttle:10,1')->name('access.update');
    Route::get('/reporting-candidates', [PrivilegeController::class, 'candidates'])->name('reporting.candidates');
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::get('/company-department', CompanyDepartmentController::class)->middleware('role:admin,hr_manager')->name('company-department.index');
    Route::get('/employees/{employee}/exit', [EmployeeController::class, 'exit'])->name('employees.exit');
    Route::put('/employees/{employee}/exit', [EmployeeController::class, 'updateExit'])->name('employees.exit.update');
    Route::resource('employees', EmployeeController::class)->except(['destroy']);
    Route::middleware('configuration')->prefix('master-data')->name('master-data.')->group(function () {
        Route::get('/{master?}', [MasterDataController::class, 'index'])->name('index');
        Route::get('/{master}/{id}/files/{field}', [MasterDataController::class, 'download'])->name('download');
        Route::post('/{master}', [MasterDataController::class, 'store'])->name('store');
        Route::put('/{master}/{id}', [MasterDataController::class, 'update'])->name('update');
        Route::delete('/{master}/{id}', [MasterDataController::class, 'destroy'])->name('destroy');
    });
});
