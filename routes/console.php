<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\DB;
use App\Models\License;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(fn()=>License::whereNotNull('expires_at')->where('expires_at','<',now())->whereNotIn('status',['expired','revoked'])->update(['status'=>'expired']))->everyFiveMinutes()->name('expire-licenses')->withoutOverlapping();
Schedule::call(fn()=>DB::table('request_nonces')->where('expires_at','<',now())->delete())->hourly()->name('cleanup-nonces')->withoutOverlapping();
Schedule::call(fn()=>DB::table('download_tokens')->where('expires_at','<',now())->delete())->hourly()->name('cleanup-download-tokens')->withoutOverlapping();
