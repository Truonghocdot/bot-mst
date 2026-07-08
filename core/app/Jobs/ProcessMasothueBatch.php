<?php

namespace App\Jobs;

use App\Services\MasothueIngestionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessMasothueBatch implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 3;

    public function __construct(
        public string $batchKey,
    ) {
    }

    public function handle(MasothueIngestionService $ingestionService): void
    {
        $ingestionService->processQueuedBatch($this->batchKey);
    }
}
