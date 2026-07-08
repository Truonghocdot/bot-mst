<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompanyLead extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'active_date' => 'date',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'phone_changed_at' => 'datetime',
            'phone_numbers' => 'array',
            'raw_payload' => 'array',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(CompanyLeadEvent::class);
    }

    public function batchItems(): HasMany
    {
        return $this->hasMany(IngestionBatchItem::class);
    }
}
