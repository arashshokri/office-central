<?php

namespace App\Services;

use App\Enums\InstallationStatus;
use App\Models\DownloadToken;
use App\Models\Installation;
use App\Models\InstallationEvent;
use App\Models\License;
use App\Models\Release;
use App\Models\SecurityEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class InstallerService
{
    public function __construct(
        private HardwareFingerprint $hardware,
        private LicenseKeyService $keys,
        private LicenseStateService $licenses,
        private AgentProtocol $protocol,
    ) {}

    public function begin(array $data, string $ip, ?Installation $origin = null): array
    {
        return DB::transaction(function () use ($data, $ip, $origin): array {
            $license = License::where('license_key_hash', $this->keys->hash($data['license_key']))->lockForUpdate()->first();
            $this->require($license !== null, 'LICENSE_NOT_FOUND', 'License was not found.', 404);
            $this->require(in_array($license->activation_mode, ['installer_once', 'attach_once'], true), 'INSTALLER_LICENSE_REQUIRED', 'Use a helper license.', 422);
            if ($error = $this->licenses->validateLicense($license)) {
                throw new InstallerException(...$error);
            }
            $license->load(['product', 'release', 'customer']);
            $this->require($license->product->slug === 'office', 'INSTALLER_PRODUCT_INVALID', 'The Office helper requires the Office product.', 422);
            $this->require($license->product->status === 'active' && $license->customer->status === 'active', 'LICENSE_DISABLED', 'Customer or product is disabled.', 403);
            if ($license->activation_mode === 'installer_once') {
                $this->require($license->release?->status->value === 'published' && is_array($license->release->runtime_manifest), 'RUNTIME_PACKAGE_REQUIRED', 'Assign an installation package for a new Office installation.', 409);
            }
            if (! $origin) {
                $this->require(($data['intent'] ?? 'install') === ($license->activation_mode === 'attach_once' ? 'connect' : 'install'),
                    'HELPER_MODE_MISMATCH', 'Use an existing Office license to connect, or an installation license for a new server.', 422);
            }
            $fingerprint = $this->hardware->make($data['hardware'], 2);
            $existing = $license->installations()->where('client_request_id', $data['client_request_id'])->first();
            if ($existing) {
                $this->require(hash_equals($existing->fingerprint, $fingerprint)
                    && hash_equals($existing->device_public_key, $data['device_public_key'])
                    && $existing->reactivated_from === $origin?->uuid, 'RESUME_IDENTITY_MISMATCH', 'Resume must use the same device and request identity.', 409);

                return $this->activationResponse($existing, $data['hardware']);
            }
            $this->require($license->consumed_at === null, 'LICENSE_CONSUMED', 'This installation code has already been consumed.', 409);
            $this->require(! $license->installations()->exists(), 'LICENSE_RESERVED', 'This code is reserved for another installation. Contact the administrator.', 409);
            if ($origin) {
                $this->require($origin->customer_id === $license->customer_id && $origin->product_id === $license->product_id,
                    'LICENSE_PRODUCT_MISMATCH', 'The replacement code must belong to the same customer and product.', 403);
                $this->require($origin->completed_at !== null, 'INSTALLATION_NOT_COMPLETE', 'Only a completed Office instance can be reactivated.', 409);
            }
            $uuid = (string) Str::uuid();
            $token = $this->protocol->credential($uuid, $data['device_public_key']);
            $installation = Installation::create(array_merge([
                'uuid' => $uuid,
                'license_id' => $license->id,
                'customer_id' => $license->customer_id,
                'product_id' => $license->product_id,
                'release_id' => $origin?->release_id ?? $license->release_id,
                'target_release_id' => $origin && $origin->release_id !== $license->release_id ? $license->release_id : null,
                'installation_token_hash' => hash('sha256', $token),
                'device_public_key' => $data['device_public_key'],
                'client_request_id' => $data['client_request_id'],
                'fingerprint' => $fingerprint,
                'fingerprint_version' => 2,
                'hostname' => $data['hostname'],
                'agent_version' => $data['agent_version'] ?? null,
                'application_version' => $origin?->application_version,
                'first_ip' => $ip,
                'last_ip' => $ip,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
                'status' => $origin ? InstallationStatus::Active : InstallationStatus::Pending,
                'completed_at' => $origin ? now() : null,
                'activated_at' => $origin ? now() : null,
                'reactivated_from' => $origin?->uuid,
            ], $this->hardware->componentHashes($data['hardware'])));
            if ($origin) {
                // Consume only the replacement bootstrap code. The original
                // installation, original license and customer data stay intact.
                $license->update(['consumed_at' => now(), 'activated_at' => now(), 'status' => 'active']);
            }
            $this->event($installation, $origin ? 'reactivated' : 'installation_reserved', ['origin' => $origin?->uuid]);

            return $this->activationResponse($installation, $data['hardware']);
        }, 5);
    }

    private function activationResponse(Installation $installation, array $hardware): array
    {
        return [
            'installation_id' => $installation->uuid,
            'credential' => $this->protocol->credential($installation->uuid, $installation->device_public_key),
            'signed_state' => $this->state($installation, $hardware),
        ];
    }

    public function state(Installation $installation, array $hardware, ?string $agentVersion = null): array
    {
        $presented = $this->hardware->make($hardware, 2);

        return DB::transaction(function () use ($installation, $presented, $agentVersion): array {
            $installation = Installation::whereKey($installation->id)->lockForUpdate()->firstOrFail();
            $installation->load(['license.product', 'license.updateRelease', 'release', 'targetRelease']);
            $license = $installation->license;
            $access = 'allowed'; $code = 'ACCESS_ALLOWED'; $message = null;
            if (! hash_equals($installation->fingerprint, $presented)) {
                $access = 'locked'; $code = 'LICENSE_HARDWARE_MISMATCH';
                $message = config('office.clone_lock_message');
                // This decision belongs only to the presented hardware. Never
                // mark the legitimate original installation locked.
                SecurityEvent::firstOrCreate([
                    'type' => 'clone_detected',
                    'installation_id' => $installation->id,
                    'request_id' => 'v2-'.substr($presented, 0, 32),
                ], ['license_id' => $license->id, 'ip_address' => request()->ip(), 'context' => ['presented_fingerprint' => $presented], 'occurred_at' => now()]);
            } elseif ($error = $this->licenses->validateInstallation($installation)) {
                $access = 'locked'; [$code, $message] = $error;
            } elseif ($license->temporarily_locked_at) {
                $access = 'locked'; $code = 'TEMPORARY_LOCK'; $message = $license->temporary_lock_message ?: config('office.default_lock_message');
            } elseif (! $installation->completed_at) {
                $access = 'provisioning'; $code = 'INSTALLATION_PENDING'; $message = 'Installation is not confirmed yet.';
            }
            $installation->agent_sequence++;
            // A cloned device must not overwrite the original's health/version.
            if (hash_equals($installation->fingerprint, $presented)) {
                $installation->last_seen_at = now(); $installation->last_ip = request()->ip();
                if ($agentVersion) { $installation->agent_version = $agentVersion; }
            }
            $installation->last_state_synced_at = now();
            $installation->save();
            $update = $this->offeredUpdate($installation);
            $release = $update ?? $installation->release;
            $payload = [
                'kind' => 'state', 'protocol' => 2, 'installation_id' => $installation->uuid,
                'license_id' => $license->uuid, 'sequence' => $installation->agent_sequence,
                'hardware_fingerprint' => $installation->fingerprint, 'presented_fingerprint' => $presented,
                'device_public_key' => $installation->device_public_key,
                'access' => $access, 'code' => $code, 'message' => $message,
                'completed' => $installation->completed_at !== null,
                'activation_mode' => $license->activation_mode,
                'application_version' => $installation->application_version,
                'license' => ['id' => $license->uuid, 'display_key' => $license->license_key_encrypted,
                    'activated_at' => $installation->activated_at?->toISOString(), 'expires_at' => $license->expires_at?->toISOString(),
                    'status' => $license->status->value, 'customer' => $license->customer?->name],
                'update' => ['available' => $access === 'allowed' && $update !== null, 'release_id' => $update?->uuid,
                    'version' => $update?->version, 'security' => (bool) $update?->is_security,
                    'channel' => $update?->channel->value, 'notes' => $update ? mb_substr((string) $update->release_notes, 0, 4000) : null],
                'product' => $license->product->slug,
                'issued_at' => now()->toISOString(),
                'offline_policy' => 'keep_last_signed_state_indefinitely',
                'poll_after_seconds' => config('office.agent_poll_seconds'),
                'agent_endpoint' => rtrim(config('office.agent_url'), '/'),
                'deployment' => $license->deployment_config,
                'package' => $release ? [
                    'release_id' => $release->uuid, 'version' => $release->version,
                    'sha256' => $release->package_sha256, 'size' => $release->package_size,
                    'manifest' => $release->deploymentManifest(),
                ] : null,
            ];

            return $this->protocol->envelope($payload);
        }, 3);
    }

    public function complete(Installation $installation, array $data): array
    {
        return DB::transaction(function () use ($installation, $data): array {
            $license = License::whereKey($installation->license_id)->lockForUpdate()->firstOrFail();
            $installation = Installation::whereKey($installation->id)->lockForUpdate()->firstOrFail();
            $installation->load(['release', 'targetRelease', 'license.updateRelease']);
            $update = $this->offeredUpdate($installation);
            // A completion response can be lost just before the administrator
            // authorizes the next version. A receipt for the installed release
            // must stay idempotent without consuming the newer permission.
            if ($installation->completed_at && $installation->release?->uuid === ($data['release_id'] ?? null)) {
                $update = null;
            }
            $release = $update ?? $installation->release;
            $this->require(hash_equals($installation->fingerprint, $this->hardware->make($data['hardware'], 2)),
                'LICENSE_HARDWARE_MISMATCH', 'Completion must come from the reserved hardware.', 403);
            if ($error = $this->licenses->validateLicense($license)) {
                throw new InstallerException(...$error);
            }
            $this->require($installation->status !== InstallationStatus::Locked && ! $license->temporarily_locked_at,
                'INSTALLATION_LOCKED', 'Installation is locked by the administrator.', 403);
            if ($license->activation_mode === 'attach_once' && ! $installation->completed_at) {
                $this->require($data['health_ok'] === true && (! $release || $data['application_version'] === $release->version),
                    'INSTALLATION_RECEIPT_INVALID', 'Existing Office must pass its health checks and match the assigned version, if any.', 422);
            } else {
                $this->require($release && ($data['release_id'] ?? null) === $release->uuid
                    && hash_equals($release->package_sha256, $data['package_sha256'] ?? '')
                    && $data['application_version'] === $release->version
                    && $data['health_ok'] === true, 'INSTALLATION_RECEIPT_INVALID', 'The installed release, checksum and successful health check must match.', 422);
            }
            if (! $installation->completed_at) {
                $installation->update(['status' => InstallationStatus::Active, 'completed_at' => now(), 'activated_at' => now(),
                    'application_version' => $data['application_version'],
                    'deployment_receipt' => collect($data)->except('hardware')->all()]);
                $license->update(['status' => 'active', 'activated_at' => now(), 'consumed_at' => now()]);
                $this->event($installation, 'installation_completed', ['release_id' => $data['release_id'] ?? null, 'mode' => $license->activation_mode]);
            }
            if ($update) {
                $installation->update(['release_id' => $release->id, 'target_release_id' => null,
                    'application_version' => $release->version, 'deployment_receipt' => collect($data)->except('hardware')->all()]);
                $this->event($installation, 'installation_updated', ['release_id' => $release->uuid]);
            }

            return ['signed_state' => $this->state($installation, $data['hardware'])];
        }, 5);
    }

    private function offeredUpdate(Installation $installation): ?Release
    {
        if (! $installation->completed_at) { return null; }
        $release = $installation->targetRelease ?? $installation->license->updateRelease;
        if (! $release || ! $release->isOfficeUpdateReady()
            || $release->product_id !== $installation->product_id
            || ! version_compare($release->version, $installation->application_version ?: '0.0.0', '>')) { return null; }
        return $release;
    }

    public function download(Installation $installation, array $data): array
    {
        $installation->load(['license.updateRelease', 'release', 'targetRelease']);
        $this->require(hash_equals($installation->fingerprint, $this->hardware->make($data['hardware'], 2)),
            'LICENSE_HARDWARE_MISMATCH', 'Package requests must come from the bound hardware.', 403);
        if ($error = $this->licenses->validateInstallation($installation)) {
            throw new InstallerException(...$error);
        }
        $this->require(! $installation->license->temporarily_locked_at, 'TEMPORARY_LOCK', 'Installation is locked.', 403);
        $release = $this->offeredUpdate($installation) ?? $installation->release;
        $this->require($release?->uuid === $data['release_id'] && $release->status->value === 'published'
            && $release->deploymentManifest() && $release->package_path, 'RELEASE_NOT_ASSIGNED', 'Only the release assigned to this installation can be downloaded.', 403);
        $token = 'odt_'.Str::random(64);
        $expiry = now()->addSeconds(config('office.download_token_lifetime_seconds'));
        DownloadToken::create(['token_hash' => hash('sha256', $token), 'installation_id' => $installation->id,
            'release_id' => $release->id, 'expires_at' => $expiry]);

        return ['signed_download' => $this->protocol->envelope([
            'kind' => 'download', 'protocol' => 2, 'installation_id' => $installation->uuid,
            'hardware_fingerprint' => $installation->fingerprint,
            'release_id' => $release->uuid, 'sha256' => $release->package_sha256, 'size' => $release->package_size,
            'url' => rtrim(config('office.agent_url'), '/').'/api/v1/packages/download/'.$token,
            'expires_at' => $expiry->toISOString(),
        ])];
    }

    private function event(Installation $installation, string $type, array $context): void
    {
        InstallationEvent::create(['installation_id' => $installation->id, 'type' => $type, 'context' => $context, 'occurred_at' => now()]);
    }

    private function require(bool $condition, string $code, string $message, int $status): void
    {
        if (! $condition) { throw new InstallerException($code, $message, $status); }
    }
}
