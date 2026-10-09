<?php

namespace App\Providers;

use App\Http\Controllers\OfficeUpdateController;
use App\Http\Middleware\OfficeLicenseGate;
use App\Models\User;
use App\Services\OfficeLicense;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class OfficeLicenseServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Numeric throttle middleware shares a user counter across routes.
        // Status polling must never consume check, install or activation quotas.
        foreach (['office-update-check' => 6, 'office-update-install' => 3,
            'office-update-status' => 60, 'office-license-reactivate' => 6] as $name => $attempts) {
            RateLimiter::for($name, fn (Request $request) => Limit::perMinute($attempts)
                ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip()))
                ->response(function (Request $request, array $headers) {
                    $seconds = max(1, (int) ($headers['Retry-After'] ?? 60));

                    return response()->json(['message' => "تعداد درخواست‌های این عملیات زیاد است؛ {$seconds} ثانیه صبر کنید و دوباره تلاش کنید.",
                        'retry_after' => $seconds], 429, $headers + ['Cache-Control' => 'no-store, private']);
                }));
        }
        if (is_file(base_path('office-managed')) && is_dir(base_path('protected-views'))) {
            config(['view.compiled' => base_path('protected-views'), 'view.check_cache_timestamps' => false]);
        }
        $this->app->booted(function () {
            $kernel = $this->app->make(Kernel::class);
            $kernel->prependMiddleware(OfficeLicenseGate::class);
            // Pause job acquisition without deleting queued jobs.
            Queue::looping(fn () => app(OfficeLicense::class)->decision()['allowed'] && ! app(OfficeLicense::class)->maintenance());
        });

        Route::middleware('web')->group(function () {
            Route::middleware('auth')->group(function () {
                Route::get('/settings/system-update', [OfficeUpdateController::class, 'index'])->name('settings.system-update');
                Route::post('/settings/system-update/check', [OfficeUpdateController::class, 'check'])->middleware('throttle:office-update-check')->name('settings.system-update.check');
                Route::post('/settings/system-update/install', [OfficeUpdateController::class, 'update'])->middleware('throttle:office-update-install')->name('settings.system-update.install');
                Route::get('/settings/system-update/status', [OfficeUpdateController::class, 'status'])->middleware('throttle:office-update-status')->name('settings.system-update.status');
            });
            Route::get('/internal/license/access', fn () => response('', 204));
            Route::get('/license', function () {
                if (app(OfficeLicense::class)->decision()['allowed'] && in_array(auth()->user()?->role, ['admin', 'general_manager'], true)) {
                    return redirect()->route('settings.system-update');
                }

                return view('office-agent.locked', array_merge(app(OfficeLicense::class)->decision(),
                    ['helperEnabled' => app(OfficeLicense::class)->enabled()]));
            })->middleware('auth')->name('office-agent.license');
            Route::post('/license/reactivate', function (Request $request) {
                abort_unless(in_array($request->user()->role, ['admin', 'general_manager'], true), 403);
                $data = $request->validate(['license_key' => ['required', 'string', 'min:10', 'max:40']]);
                try {
                    app(OfficeLicense::class)->reactivate($data['license_key']);
                } catch (\RuntimeException $error) {
                    return back()->withErrors(['license_key' => $error->getMessage()]);
                }

                return redirect('/')->with('success', 'لایسنس فعال شد؛ اطلاعات سامانه حفظ شده است.');
            })->middleware(['auth', 'throttle:office-license-reactivate'])->name('office-agent.reactivate');
        });

        Artisan::command('office-agent:health {--expected-version=} {--json}', function () {
            $version = trim(file_get_contents(base_path('VERSION')));
            if ($this->option('expected-version') && $version !== $this->option('expected-version')) {
                throw new \RuntimeException('Installed Office version does not match the assigned release.');
            }
            if (is_dir(base_path('protected-views')) && ! extension_loaded('ionCube Loader')) {
                throw new \RuntimeException('Protected runtime loader is missing.');
            }
            DB::select('SELECT 1');
            Redis::connection()->ping();
            $probe = storage_path('app/.agent-health-'.bin2hex(random_bytes(8)));
            if (file_put_contents($probe, 'ok') !== 2) {
                throw new \RuntimeException('Storage is not writable.');
            }
            unlink($probe);
            $pending = array_diff(app('migrator')->getMigrationFiles(database_path('migrations'))
                ? array_keys(app('migrator')->getMigrationFiles(database_path('migrations'))) : [],
                app('migration.repository')->getRan());
            if ($pending) {
                throw new \RuntimeException('Pending migrations exist.');
            }
            if ($this->option('json')) {
                $this->line(json_encode(['status' => 'ok', 'version' => $version]));
            } else {
                $this->info('Office application, database, cache, storage and migrations: OK');
            }
        });

        Artisan::command('office-agent:admin', function () {
            $data = json_decode(stream_get_contents(STDIN, 4096), true, 8, JSON_THROW_ON_ERROR);
            if (! filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL) || strlen($data['password'] ?? '') < 12) {
                throw new \RuntimeException('Valid administrator email and strong password are required.');
            }
            // Never overwrite an existing administrator or a customer's user.
            if (User::whereIn('role', ['admin', 'general_manager'])->exists()) {
                $this->info('Existing administrators preserved.');

                return;
            }
            DB::transaction(function () use ($data) {
                if (User::where('email', $data['email'])->exists()) {
                    throw new \RuntimeException('Administrator email already belongs to another user.');
                }
                $user = new User;
                $user->forceFill(['name' => $data['name'] ?: 'مدیر سامانه', 'email' => $data['email'],
                    'password' => Hash::make($data['password']), 'role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
                $user->save();
            });
            $this->info('Initial administrator created.');
        });
    }
}
