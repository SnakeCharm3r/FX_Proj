<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\SignalController;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\OrderflowController;

Route::get('/',                    [DashboardController::class, 'index'])->name('dashboard');
Route::get('/setups',              [SetupController::class,     'index'])->name('setups.index');
Route::get('/setups/{setup}',      [SetupController::class,     'show'])->name('setups.show');
Route::get('/signals',             [SignalController::class,    'index'])->name('signals.index');
Route::get('/orderflow/delta',     [OrderflowController::class, 'delta'])->name('orderflow.delta');
Route::get('/orderflow/imbalance', [OrderflowController::class, 'imbalance'])->name('orderflow.imbalance');
Route::get('/orderflow/cvd',       [OrderflowController::class, 'cvd'])->name('orderflow.cvd');
Route::get('/alerts',              [AlertController::class,     'index'])->name('alerts.index');
Route::get('/settings',            [SettingsController::class,  'index'])->name('settings.index');
