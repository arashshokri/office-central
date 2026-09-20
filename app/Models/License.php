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

    public function uniqueIds()
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return ['status' => LicenseStatus::class, 'expires_at' => 'datetime', 'activated_at' => 'datetime', 'temporarily_locked_at' => 'datetime', 'temporarily_unlocked_at' => 'datetime', 'metadata' => 'array'];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function release()
    {
        return $this->belongsTo(Release::class);
    }

    public function installations()
    {
        return $this->hasMany(Installation::class);
    }

    public function temporaryLocker()
    {
        return $this->belongsTo(User::class, 'temporary_locked_by');
    }
}
