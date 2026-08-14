<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use SolutionsTI\DiscountEngine\Laravel\Http\Controllers\RuleController;
use SolutionsTI\DiscountEngine\Laravel\Http\Controllers\SimulatorController;

Route::get('/', [RuleController::class, 'index'])->name('rules.index');
Route::get('/nova', [RuleController::class, 'create'])->name('rules.create');
Route::post('/', [RuleController::class, 'store'])->name('rules.store');
Route::get('/{rule}/editar', [RuleController::class, 'edit'])->name('rules.edit');
Route::put('/{rule}', [RuleController::class, 'update'])->name('rules.update');
Route::post('/{rule}/alternar', [RuleController::class, 'toggle'])->name('rules.toggle');
Route::delete('/{rule}', [RuleController::class, 'destroy'])->name('rules.destroy');

Route::get('/simulador', [SimulatorController::class, 'index'])->name('simulator.index');
Route::post('/simulador', [SimulatorController::class, 'run'])->name('simulator.run');
