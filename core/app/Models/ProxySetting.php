<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProxySetting extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'last_provider_response' => 'array',
            'last_resolved_at' => 'datetime',
        ];
    }
}
