<?php

namespace App\Providers;

use App\Http\Middleware\OfficeLicenseGate;
use App\Services\OfficeLicense;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class OfficeLicenseServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (is_file(base_path('office-managed')) && is_dir(base_path('protected-views'))) {
            config(['view.compiled' => base_path('protected-views'), 'view.check_cache_timestamps' => false]);
        }
        $this->app->booted(function () {
            $kernel = $this->app->make(\Illuminate\Contracts\Http\Kernel::class);
            $kernel->prependMiddleware(OfficeLicenseGate::class);
            // Pause job acquisition without deleting queued jobs.
            Queue::looping(fn () => app(OfficeLicense::class)->decision()['allowed']);
        });

        Route::middleware('web')->group(function () {
            Route::get('/internal/license/access', fn () => response('', 204));
            Route::get('/license', function () {
                return view('office-agent.locked', array_merge(app(OfficeLicense::class)->decision(),
                    ['helperEnabled' => app(OfficeLicense::class)->enabled()]));
            })->middleware('auth')->name('office-agent.license');
            Route::post('/license/reactivate', function (\Illuminate\Http\Request $request) {
                abort_unless($request->user()->role === 'admin', 403);
                $data = $request->validate(['license_key' => ['required', 'string', 'min:10', 'max:40']]);
                try {
                    app(OfficeLicense::class)->reactivate($data['license_key']);
                } catch (\RuntimeException $error) {
                    return back()->withErrors(['license_key' => $error->getMessage()]);
                }
                return redirect('/')->with('success', 'لایسنس فعال شد؛ اطلاعات سامانه حفظ شده است.');
            })->middleware(['auth', 'throttle:6,1'])->name('office-agent.reactivate');
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
            \Illuminate\Support\Facades\Redis::connection()->ping();
            $probe = storage_path('app/.agent-health-'.bin2hex(random_bytes(8)));
            if (file_put_contents($probe, 'ok') !== 2) { throw new \RuntimeException('Storage is not writable.'); }
            unlink($probe);
            $pending = array_diff(app('migrator')->getMigrationFiles(database_path('migrations'))
                ? array_keys(app('migrator')->getMigrationFiles(database_path('migrations'))) : [],
                app('migration.repository')->getRan());
            if ($pending) { throw new \RuntimeException('Pending migrations exist.'); }
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
            if (\App\Models\User::whereIn('role', ['admin', 'general_manager'])->exists()) {
                $this->info('Existing administrators preserved.'); return;
            }
            DB::transaction(function () use ($data) {
                if (\App\Models\User::where('email', $data['email'])->exists()) { throw new \RuntimeException('Administrator email already belongs to another user.'); }
                $user = new \App\Models\User;
                $user->forceFill(['name' => $data['name'] ?: 'مدیر سامانه', 'email' => $data['email'],
                    'password' => Hash::make($data['password']), 'role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
                $user->save();
            });
            $this->info('Initial administrator created.');
        });
    }
}
