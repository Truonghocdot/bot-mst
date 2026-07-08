<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramDestinationDelivery extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'response_payload' => 'array',
            'last_attempt_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function batchItem(): BelongsTo
    {
        return $this->belongsTo(IngestionBatchItem::class, 'ingestion_batch_item_id');
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(TelegramDestination::class, 'telegram_destination_id');
    }
}
