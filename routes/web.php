<?php

use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\LicenseController;
use App\Http\Controllers\Admin\MonitoringController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ReleaseController;
use App\Http\Controllers\Admin\RepositoryIntegrationController;
use App\Http\Controllers\Admin\SecurityController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\TwoFactorChallengeController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);
Route::get('/login', [AuthController::class, 'loginForm'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:6,1');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
Route::post('/locale/{locale}', [AuthController::class, 'locale'])->name('locale');
Route::get('/two-factor-challenge', [TwoFactorChallengeController::class, 'create'])->name('two-factor.challenge');
Route::post('/two-factor-challenge', [TwoFactorChallengeController::class, 'store'])->middleware('throttle:6,1')->name('two-factor.verify');
Route::middleware(['auth', 'role:super_admin,admin,viewer'])->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::get('/products', [ProductController::class, 'index'])->name('products.index');
    Route::get('/releases', [ReleaseController::class, 'index'])->name('releases.index');
    Route::get('/licenses', [LicenseController::class, 'index'])->name('licenses.index');
    Route::get('/licenses/{license}', [LicenseController::class, 'show'])->name('licenses.show');
    Route::get('/installations', [MonitoringController::class, 'installations'])->name('installations.index');
    Route::get('/installations/{installation}', [MonitoringController::class, 'installation'])->name('installations.show');
    Route::get('/security-events', [MonitoringController::class, 'security'])->name('security.index');
    Route::get('/audit-logs', [MonitoringController::class, 'audit'])->name('audit.index');
});
Route::middleware(['auth', 'role:super_admin,admin'])->group(function () {
    Route::get('/customers/create', [CustomerController::class, 'create'])->name('customers.create');
    Route::post('/customers', [CustomerController::class, 'store'])->name('customers.store');
    Route::get('/products/create', [ProductController::class, 'create'])->name('products.create');
    Route::post('/products', [ProductController::class, 'store'])->name('products.store');
    Route::get('/releases/create', [ReleaseController::class, 'create'])->name('releases.create');
    Route::post('/releases', [ReleaseController::class, 'store'])->name('releases.store');
    Route::get('/licenses/create', [LicenseController::class, 'create'])->name('licenses.create');
    Route::post('/licenses', [LicenseController::class, 'store'])->name('licenses.store');
    Route::post('/releases/{release}/publish', [ReleaseController::class, 'publish'])->name('releases.publish');
    Route::post('/licenses/{license}/status/{status}', [LicenseController::class, 'status'])->name('licenses.status');
    Route::post('/licenses/{license}/temporary-lock', [LicenseController::class, 'temporaryLock'])->name('licenses.temporary-lock');
    Route::delete('/licenses/{license}/temporary-lock', [LicenseController::class, 'temporaryUnlock'])->name('licenses.temporary-unlock');
    Route::post('/installations/{installation}/status/{status}', [MonitoringController::class, 'installationStatus'])->name('installations.status');
    Route::put('/installations/{installation}/target-release', [MonitoringController::class, 'targetRelease'])->name('installations.target-release');
});
Route::middleware(['auth', 'role:super_admin,admin,viewer'])->group(function () {
    Route::get('/security/settings', [SecurityController::class, 'show'])->name('security.settings');
    Route::post('/security/two-factor', [SecurityController::class, 'begin'])->name('security.two-factor.begin');
    Route::post('/security/two-factor/confirm', [SecurityController::class, 'confirm'])->name('security.two-factor.confirm');
    Route::delete('/security/two-factor', [SecurityController::class, 'disable'])->name('security.two-factor.disable');
});
Route::middleware(['auth', 'role:super_admin'])->group(function () {
    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::post('/users', [UserController::class, 'store'])->name('users.store');
    Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
});
Route::middleware(['auth', 'role:super_admin,admin'])->group(function () {
    Route::get('/repositories', [RepositoryIntegrationController::class, 'index'])->name('repositories.index');
    Route::post('/repositories', [RepositoryIntegrationController::class, 'store'])->name('repositories.store');
    Route::post('/repositories/{integration}/sync', [RepositoryIntegrationController::class, 'sync'])->name('repositories.sync');
});
