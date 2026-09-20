<?php

use App\Http\Controllers\Api\V1\ActivationController;
use App\Http\Controllers\Api\V1\AgentStateController;
use App\Http\Controllers\Api\V1\HeartbeatController;
use App\Http\Controllers\Api\V1\PackageController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['agent.https', 'throttle:agent'])->group(function () {
    Route::post('/licenses/activate', ActivationController::class);
    Route::post('/agent/activate', ActivationController::class);
    Route::get('/packages/download/{token}', [PackageController::class, 'download'])->middleware('throttle:downloads');
    Route::middleware(['installation.auth', 'replay'])->group(function () {
        Route::post('/agent/state', AgentStateController::class);
        Route::post('/installations/heartbeat', HeartbeatController::class);
        Route::post('/installations/lease', AgentStateController::class);
        Route::post('/packages/token', [PackageController::class, 'token']);
    });
});
