<?php
namespace App\Services;
use App\Models\AuditLog;
final class AuditService { public function record(string $action,?object $subject=null,?array $before=null,?array $after=null):void { AuditLog::create(['user_id'=>auth()->id(),'action'=>$action,'subject_type'=>$subject?get_class($subject):null,'subject_id'=>$subject->id??null,'before'=>$before,'after'=>$after,'ip_address'=>request()->ip(),'request_id'=>request()->attributes->get('request_id'),'occurred_at'=>now()]); } }
