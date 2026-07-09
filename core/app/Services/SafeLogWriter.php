<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class SafeLogWriter
{
    private const FALLBACK_MAX_LENGTH = 8000;

    /**
     * @param  array<string, mixed>  $context
     */
    public function write(string $channel, string $level, string $message, array $context = []): void
    {
        try {
            Log::channel($channel)->log($level, $message, $context);
        } catch (\Throwable $exception) {
            $this->writeFallback($channel, $level, $message, $context, $exception);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function writeFallback(string $channel, string $level, string $message, array $context, \Throwable $exception): void
    {
        try {
            $payload = (string) json_encode([
                'type' => 'log_channel_write_failed',
                'channel' => $channel,
                'level' => $level,
                'message' => $this->truncate($message),
                'context' => $context,
                'logging_error' => [
                    'class' => $exception::class,
                    'message' => $this->truncate($exception->getMessage()),
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

            error_log($this->truncate($payload));
        } catch (\Throwable) {
            error_log(sprintf(
                '[log_channel_write_failed] channel=%s level=%s message=%s logging_error=%s',
                $channel,
                $level,
                $this->truncate($message),
                $this->truncate($exception->getMessage()),
            ));
        }
    }

    private function truncate(string $value): string
    {
        if (strlen($value) <= self::FALLBACK_MAX_LENGTH) {
            return $value;
        }

        return substr($value, 0, self::FALLBACK_MAX_LENGTH).'... [truncated]';
    }
}
