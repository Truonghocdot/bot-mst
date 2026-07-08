<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyLeadEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'observed_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function companyLead(): BelongsTo
    {
        return $this->belongsTo(CompanyLead::class);
    }
}
