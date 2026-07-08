<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IngestionBatch extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'observed_at' => 'datetime',
            'processed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function previousBatch(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_batch_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(IngestionBatchItem::class);
    }
}
