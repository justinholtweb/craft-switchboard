<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use JustinHoltWeb\Switchboard\Http\Controllers\BellController;

// Registered under both the CP and the site action prefixes. The controller refuses
// anyone who isn't signed in, and only ever touches the signed-in user's notifications.
Route::get('notifications', [BellController::class, 'feed']);
Route::post('notifications/mark-read', [BellController::class, 'markRead']);
