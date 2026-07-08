<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IngestionBatchItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'active_date' => 'date',
            'observed_at' => 'datetime',
            'marked_at' => 'datetime',
            'is_new_since_previous_batch' => 'boolean',
            'phone_numbers' => 'array',
            'payload' => 'array',
        ];
    }

    public function ingestionBatch(): BelongsTo
    {
        return $this->belongsTo(IngestionBatch::class);
    }

    public function companyLead(): BelongsTo
    {
        return $this->belongsTo(CompanyLead::class);
    }
}
