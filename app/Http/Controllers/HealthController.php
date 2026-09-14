<?php
namespace App\Http\Controllers;
use Illuminate\Support\Facades\{DB,Redis,Storage};
class HealthController extends Controller { public function __invoke(){ $checks=['application'=>'ok']; try{DB::select('select 1');$checks['database']='ok';}catch(\Throwable){$checks['database']='unavailable';} try{Redis::ping();$checks['redis']='ok';}catch(\Throwable){$checks['redis']='unavailable';} try{Storage::disk(config('office.package_disk'))->exists('.health');$checks['package_storage']='ok';}catch(\Throwable){$checks['package_storage']='unavailable';} $ok=!in_array('unavailable',$checks,true); return response()->json(['status'=>$ok?'ok':'degraded','checks'=>$checks,'version'=>config('office.version')],$ok?200:503); } }
