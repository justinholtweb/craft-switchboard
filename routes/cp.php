<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use JustinHoltWeb\Switchboard\Http\Controllers\DeliveriesController;
use JustinHoltWeb\Switchboard\Http\Controllers\RulesController;
use JustinHoltWeb\Switchboard\Plugin;

// The cp group already applies auth + can:accessCp (and Laravel's CSRF check on POST).
Route::middleware('can:accessPlugin-switchboard')->prefix('switchboard')->group(function () {
    Route::middleware('can:'.Plugin::PERMISSION_MANAGE)->group(function () {
        Route::get('', [RulesController::class, 'index']);
        Route::get('rules/new', [RulesController::class, 'create']);
        Route::get('rules/{id}', [RulesController::class, 'edit'])->whereNumber('id');
        Route::post('rules/save', [RulesController::class, 'save']);
        Route::post('rules/delete', [RulesController::class, 'delete']);
        Route::post('rules/test', [RulesController::class, 'test']);
    });

    Route::get('deliveries', DeliveriesController::class)->middleware('can:'.Plugin::PERMISSION_DELIVERIES);
});
