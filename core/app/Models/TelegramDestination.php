<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramDestination extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_sent_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'raw_update' => 'array',
        ];
    }
}
