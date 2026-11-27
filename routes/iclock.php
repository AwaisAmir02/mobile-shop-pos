<?php

use App\Http\Controllers\Biometric\AdmsController;
use Illuminate\Support\Facades\Route;

/*
 * Fingerprint devices on ZKTeco's ADMS protocol (App\Biometric\Adms). The
 * paths are fixed by the device, so they sit at the site root, outside /api.
 * Stateless: no session, no CSRF. A device only gets answers when a shop has
 * registered its serial number (AdmsController::device).
 */
Route::match(['GET', 'POST'], 'iclock/cdata', [AdmsController::class, 'cdata'])->middleware('throttle:600,1')->name('iclock.cdata');
Route::get('iclock/getrequest', [AdmsController::class, 'getrequest'])->middleware('throttle:600,1')->name('iclock.getrequest');
Route::post('iclock/devicecmd', [AdmsController::class, 'devicecmd'])->middleware('throttle:600,1')->name('iclock.devicecmd');
