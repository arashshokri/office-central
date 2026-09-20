<?php

namespace App\Services;

use App\Enums\InstallationStatus;
use App\Models\AgentStateSnapshot;
use App\Models\Installation;
use App\Models\License;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AgentStateService
{
    public function __construct(private SigningService $signer) {}

    public function issue(Installation $installation): array
    {
        return DB::transaction(function () use ($installation): array {
            $installation = Installation::whereKey($installation->getKey())->lockForUpdate()->firstOrFail();
            $installation->load(['license.product', 'release', 'targetRelease']);
            $license = $installation->license;

            if ($license->expires_at?->isPast() && ! in_array($license->status->value, ['expired', 'revoked'], true)) {
                License::whereKey($license->getKey())
                    ->whereNotIn('status', ['expired', 'revoked'])
                    ->update(['status' => 'expired', 'state_revision' => DB::raw('state_revision + 1')]);
                $installation->unsetRelation('license')->load('license.product');
                $license = $installation->license;
            }

            $snapshot = AgentStateSnapshot::where('installation_id', $installation->id)
                ->where('state_revision', $license->state_revision)
                ->latest('id')
                ->first();
            $now = now();

            if ($snapshot) {
                $installation->forceFill(['last_state_synced_at' => $now])->saveQuietly();

                return $this->response($snapshot->payload, $snapshot->signature);
            }

            [$access, $code, $message] = $this->resolveAccess($installation);
            $payload = [
                'state_id' => (string) Str::uuid(),
                'state_revision' => (int) $license->state_revision,
                'installation_id' => $installation->uuid,
                'license_id' => $license->uuid,
                'access' => $access,
                'code' => $code,
                'message' => $message,
                'issued_at' => $now->toISOString(),
                'server_timestamp' => $now->timestamp,
                'poll_after_seconds' => config('office.agent_poll_seconds'),
                'offline_policy' => 'keep_last_signed_state_indefinitely',
                'central_endpoint' => rtrim((string) config('office.public_url'), '/'),
                'product' => [
                    'uuid' => $license->product->uuid,
                    'slug' => $license->product->slug,
                ],
                'current_release' => $installation->release ? [
                    'uuid' => $installation->release->uuid,
                    'version' => $installation->release->version,
                ] : null,
                'target_release' => $installation->targetRelease ? [
                    'uuid' => $installation->targetRelease->uuid,
                    'version' => $installation->targetRelease->version,
                ] : null,
            ];
            $signature = $this->signer->sign($payload);

            AgentStateSnapshot::create([
                'uuid' => $payload['state_id'],
                'installation_id' => $installation->id,
                'license_id' => $license->id,
                'state_revision' => $payload['state_revision'],
                'access_state' => $access,
                'payload' => $payload,
                'signature' => $signature,
                'issued_at' => $now,
            ]);

            $installation->forceFill(['last_state_synced_at' => $now])->saveQuietly();

            return $this->response($payload, $signature);
        }, 3);
    }

    private function response(array $payload, string $signature): array
    {
        return [
            'state' => $payload,
            'signature' => $signature,
            'algorithm' => 'Ed25519',
            'public_key' => $this->signer->publicKey(),
        ];
    }

    private function resolveAccess(Installation $installation): array
    {
        $license = $installation->license;

        if ($license->temporarily_locked_at !== null) {
            return ['locked', 'TEMPORARY_LOCK', $license->temporary_lock_message ?: config('office.default_lock_message')];
        }

        if ($installation->status === InstallationStatus::Locked) {
            return ['locked', 'INSTALLATION_LOCKED', config('office.default_lock_message')];
        }

        if ($license->status->value === 'suspended') {
            return ['locked', 'LICENSE_SUSPENDED', config('office.default_lock_message')];
        }

        if ($license->status->value === 'revoked') {
            return ['locked', 'LICENSE_REVOKED', config('office.default_lock_message')];
        }

        if ($license->status->value === 'expired' || $license->expires_at?->isPast()) {
            return ['locked', 'LICENSE_EXPIRED', config('office.default_lock_message')];
        }

        return ['allowed', 'ACCESS_ALLOWED', null];
    }
}
