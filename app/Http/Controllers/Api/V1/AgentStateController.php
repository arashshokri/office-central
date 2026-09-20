<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ActivationService;
use App\Services\AgentStateService;
use App\Services\ApiResponse;
use Illuminate\Http\Request;

class AgentStateController extends Controller
{
    public function __invoke(Request $request, ActivationService $activation, AgentStateService $states)
    {
        $data = $request->validate([
            'hardware' => ['required', 'array'],
            'application_version' => ['nullable', 'string', 'max:50'],
            'agent_version' => ['nullable', 'string', 'max:50'],
            'health' => ['nullable', 'array', 'max:100'],
        ]);
        $installation = $request->attributes->get('installation');

        try {
            $mismatch = $activation->verifyFingerprint(
                $installation,
                $data['hardware'],
                $request->ip(),
                $request->attributes->get('request_id'),
            );
        } catch (\InvalidArgumentException $exception) {
            return ApiResponse::error('LICENSE_INVALID', $exception->getMessage(), 422);
        }

        if ($mismatch) {
            return ApiResponse::error(...$mismatch);
        }

        $installation->update([
            'last_seen_at' => now(),
            'last_ip' => $request->ip(),
            'application_version' => $data['application_version'] ?? $installation->application_version,
            'agent_version' => $data['agent_version'] ?? $installation->agent_version,
            'health' => $data['health'] ?? $installation->health,
        ]);

        return ApiResponse::ok($states->issue($installation));
    }
}
