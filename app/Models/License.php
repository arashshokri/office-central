<?php

namespace App\Models;

use App\Enums\LicenseStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class License extends Model
{
    use HasUuids,SoftDeletes;

    protected $guarded = [];

    protected $hidden = ['license_key_hash', 'license_key_encrypted'];

    public function uniqueIds()
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return ['license_key_encrypted' => 'encrypted', 'status' => LicenseStatus::class, 'deployment_config' => 'array', 'consumed_at' => 'datetime', 'expires_at' => 'datetime', 'activated_at' => 'datetime', 'temporarily_locked_at' => 'datetime', 'temporarily_unlocked_at' => 'datetime', 'metadata' => 'array'];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function product()
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function release()
    {
        return $this->belongsTo(Release::class)->withTrashed();
    }

    public function installations()
    {
        return $this->hasMany(Installation::class);
    }

    public function updateRelease()
    {
        return $this->belongsTo(Release::class, 'update_release_id');
    }

    public function temporaryLocker()
    {
        return $this->belongsTo(User::class, 'temporary_locked_by');
    }
}
