<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ActivationService;
use App\Services\AgentStateService;
use App\Services\ApiResponse;
use Illuminate\Http\Request;

class ActivationController extends Controller
{
    public function __invoke(Request $request, ActivationService $activation, AgentStateService $states)
    {
        $data = $request->validate([
            'license_key' => ['required', 'string', 'max:40'],
            'hostname' => ['required', 'string', 'max:255'],
            'fingerprint_version' => ['sometimes', 'integer', 'in:1'],
            'hardware' => ['required', 'array'],
            'hardware.machine_id' => ['nullable', 'string', 'max:512'],
            'hardware.product_uuid' => ['nullable', 'string', 'max:512'],
            'hardware.system_serial' => ['nullable', 'string', 'max:512'],
            'hardware.board_serial' => ['nullable', 'string', 'max:512'],
            'hardware.primary_disk_serial' => ['nullable', 'string', 'max:512'],
            'hardware.cpu_model' => ['nullable', 'string', 'max:255'],
            'hardware.cpu_cores' => ['nullable', 'integer', 'min:1', 'max:4096'],
            'hardware.ram_bytes' => ['nullable', 'integer', 'min:0'],
            'hardware.disk_size_bytes' => ['nullable', 'integer', 'min:0'],
            'hardware.virtualization_type' => ['nullable', 'string', 'max:100'],
            'os' => ['nullable', 'array'],
            'os.name' => ['nullable', 'string', 'max:100'],
            'os.version' => ['nullable', 'string', 'max:100'],
            'os.kernel' => ['nullable', 'string', 'max:100'],
            'agent_version' => ['nullable', 'string', 'max:50'],
            'application_version' => ['nullable', 'string', 'max:50'],
        ]);

        try {
            $result = $activation->activate($data['license_key'], $data, $request->ip(), $request->attributes->get('request_id'));
        } catch (\InvalidArgumentException $exception) {
            return ApiResponse::error('LICENSE_INVALID', $exception->getMessage(), 422);
        }

        if (isset($result['error'])) {
            return ApiResponse::error(...$result['error']);
        }

        $installation = $result['installation']->load(['license.product', 'release', 'targetRelease']);
        $response = [
            'installation_id' => $installation->uuid,
            'status' => $installation->status->value,
            'credential' => $result['token'],
            'credential_returned_once' => $result['token'] !== null,
        ];

        if ($result['token']) {
            $response['signed_state'] = $states->issue($installation);
        }

        return ApiResponse::ok($response, $result['token'] ? 201 : 200);
    }
}
