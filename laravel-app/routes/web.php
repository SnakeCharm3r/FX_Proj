<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\SignalController;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\OrderflowController;
use App\Http\Controllers\StrategyTesterController;
use App\Http\Controllers\ScalpStrategyTesterController;

Route::get('/',                    [DashboardController::class, 'index'])->name('dashboard');
Route::view('/zones', 'zones.index')->name('zones.index');
Route::view('/opportunities','opportunities.index')->name('opportunities.index');
Route::get('/setups',              [SetupController::class,     'index'])->name('setups.index');
Route::get('/setups/tester',       [StrategyTesterController::class, 'index'])->name('setups.tester');
Route::get('/setups/tester/scalp', [ScalpStrategyTesterController::class, 'index'])->name('setups.tester.scalp');
Route::get('/setup-observations/{observation}', [SetupController::class,'observation'])->whereNumber('observation')->name('setups.observation');
Route::get('/setups/{setup}',      [SetupController::class,     'show'])->name('setups.show');
Route::get('/signals',             [SignalController::class,    'index'])->name('signals.index');
Route::get('/orderflow/delta',     [OrderflowController::class, 'delta'])->name('orderflow.delta');
Route::get('/orderflow/imbalance', [OrderflowController::class, 'imbalance'])->name('orderflow.imbalance');
Route::get('/orderflow/cvd',       [OrderflowController::class, 'cvd'])->name('orderflow.cvd');
Route::get('/alerts',              [AlertController::class,     'index'])->name('alerts.index');
Route::get('/settings',            [SettingsController::class,  'index'])->name('settings.index');
Route::view('/monitor', 'monitor.index')->name('monitor.index');
Route::view('/orderflow/footprint', 'orderflow.footprint')->name('orderflow.footprint');
