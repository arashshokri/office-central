<?php
namespace App\Services;
use Illuminate\Http\JsonResponse;
final class ApiResponse { public static function ok(array $data=[], int $status=200):JsonResponse{return response()->json(['success'=>true,'data'=>$data,'request_id'=>request()->attributes->get('request_id')],$status);} public static function error(string $code,string $message,int $status):JsonResponse{return response()->json(['success'=>false,'code'=>$code,'message'=>$message,'request_id'=>request()->attributes->get('request_id')],$status);} }
