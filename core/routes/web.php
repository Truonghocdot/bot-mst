<?php

use App\Http\Controllers\Admin\AdminAuthController;
use App\Livewire\Admin\TelegramAdminDashboard;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::middleware('guest')->group(function (): void {
    Route::get('/admin/login', [AdminAuthController::class, 'create'])->name('admin.login');
    Route::post('/admin/login', [AdminAuthController::class, 'store'])->name('admin.login.store');
});

Route::middleware('admin.panel')->group(function (): void {
    Route::post('/admin/logout', [AdminAuthController::class, 'destroy'])->name('admin.logout');
    Route::get('/admin', TelegramAdminDashboard::class)->name('admin.dashboard');
});
