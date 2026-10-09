<?php

namespace App\Models;

use App\Enums\PackageSource;
use App\Enums\ReleaseChannel;
use App\Enums\ReleaseStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Release extends Model
{
    use HasUuids,SoftDeletes;

    protected $guarded = [];

    public function uniqueIds()
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return ['channel' => ReleaseChannel::class, 'status' => ReleaseStatus::class, 'source_type' => PackageSource::class, 'published_at' => 'datetime', 'runtime_manifest' => 'array', 'source_manifest' => 'array', 'is_security' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $release) {
            if ($release->getRawOriginal('status') === 'published' && $release->isDirty(['product_id', 'version', 'channel', 'package_filename', 'package_path', 'package_size', 'package_sha256', 'runtime_manifest'])) {
                throw new \DomainException('Published release packages are immutable.');
            }
            if ($release->getRawOriginal('status') === 'published' && $release->getRawOriginal('source_manifest') && $release->isDirty('source_manifest')) {
                throw new \DomainException('Validated source manifests are immutable.');
            }
        });
    }

    public function product()
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function isOfficeRuntimeReady(): bool
    {
        return $this->status === ReleaseStatus::Published && ! empty($this->runtime_manifest) && ! empty($this->package_path);
    }

    public function deploymentManifest(): ?array
    {
        return $this->runtime_manifest ?: $this->source_manifest;
    }

    public function isOfficeUpdateReady(): bool
    {
        return $this->isOfficeRuntimeReady() || ($this->status === ReleaseStatus::Published
            && ($this->source_manifest['format'] ?? '') === 'office-source-v1' && ! empty($this->package_path));
    }
}
