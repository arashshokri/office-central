<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Services\AgentProtocol;
use App\Services\ApiResponse;
use App\Services\InstallerException;
use App\Services\InstallerService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class InstallerController extends Controller
{
    public function begin(Request $request, AgentProtocol $protocol, InstallerService $installer)
    {
        $data = $this->activationData($request);
        if (! $protocol->verifyRequest($request, $data['device_public_key'])) {
            return ApiResponse::error('DEVICE_SIGNATURE_INVALID', 'A valid device signature is required.', 401);
        }

        return $this->respond(fn () => $installer->begin($data, $request->ip()));
    }

    public function reactivate(Request $request, InstallerService $installer)
    {
        $request->validate(['health_ok' => ['required', 'accepted']]);
        $data = $this->activationData($request);
        $origin = $request->attributes->get('installation');
        if (! hash_equals($origin->device_public_key, $data['device_public_key'])) {
            return ApiResponse::error('DEVICE_SIGNATURE_INVALID', 'Use the authenticated device key.', 401);
        }

        return $this->respond(fn () => $installer->begin($data, $request->ip(), $origin));
    }

    public function state(Request $request, InstallerService $installer)
    {
        $data = $request->validate(array_merge($this->hardwareRules(), ['agent_version' => ['nullable', 'regex:/^\d+\.\d+\.\d+(?:-[A-Za-z0-9.-]+)?$/D', 'max:50']]));

        return $this->respond(fn () => ['signed_state' => $installer->state($request->attributes->get('installation'), $data['hardware'], $data['agent_version'] ?? null)]);
    }

    public function complete(Request $request, InstallerService $installer)
    {
        $data = $request->validate(array_merge($this->hardwareRules(), [
            'release_id' => ['nullable', 'uuid'],
            'package_sha256' => ['nullable', 'regex:/^[a-f0-9]{64}$/D'],
            'application_version' => ['required', 'string', 'max:50', 'regex:/^\d+\.\d+\.\d+(?:-[A-Za-z0-9.-]+)?$/D'],
            'health_ok' => ['required', 'accepted'],
        ]));
        // Accepted form values must not weaken the service's strict receipt.
        $data['health_ok'] = $request->boolean('health_ok');

        return $this->respond(fn () => $installer->complete($request->attributes->get('installation'), $data));
    }

    public function download(Request $request, InstallerService $installer)
    {
        $data = $request->validate(array_merge($this->hardwareRules(), ['release_id' => ['required', 'uuid']]));

        return $this->respond(fn () => $installer->download($request->attributes->get('installation'), $data));
    }

    private function activationData(Request $request): array
    {
        return $request->validate(array_merge($this->hardwareRules(), [
            'license_key' => ['required', 'string', 'max:40'],
            'hostname' => ['required', 'string', 'max:255'],
            'client_request_id' => ['required', 'uuid'],
            'device_public_key' => ['required', 'regex:/^[A-Za-z0-9_-]{43}$/D'],
            'agent_version' => ['nullable', 'string', 'max:50'],
            'intent' => ['nullable', 'in:install,connect'],
        ]));
    }

    private function hardwareRules(): array
    {
        return ['hardware' => ['required', 'array'],
            'hardware.product_uuid' => ['required', 'string', 'max:64'],
            'hardware.machine_id' => ['required', 'string', 'max:64']];
    }

    private function respond(callable $operation)
    {
        try {
            return ApiResponse::ok($operation());
        } catch (InstallerException $error) {
            return ApiResponse::error($error->errorCode, $error->getMessage(), $error->httpStatus);
        } catch (\InvalidArgumentException $error) {
            throw ValidationException::withMessages(['hardware' => $error->getMessage()]);
        }
    }
}
