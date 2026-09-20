<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RepositoryIntegration extends Model
{
    protected $guarded = [];

    protected $hidden = ['encrypted_access_token', 'encrypted_webhook_secret'];

    protected function casts(): array
    {
        return [
            'encrypted_access_token' => 'encrypted',
            'encrypted_webhook_secret' => 'encrypted',
            'enabled' => 'boolean',
            'auto_publish' => 'boolean',
            'last_sync_at' => 'datetime',
        ];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
