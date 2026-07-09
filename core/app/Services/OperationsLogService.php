<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class OperationsLogService
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function write(string $level, string $message, array $context = []): void
    {
        Log::channel('operations')->log($level, $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function warning(string $message, array $context = []): void
    {
        $this->write('warning', $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }
}
