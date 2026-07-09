<?php

namespace App\Services;

use App\Models\TelegramDestination;

class TelegramWebhookService
{
    public function __construct(
        private readonly OperationsLogService $operationsLog,
    ) {
    }

    /**
     * @param  array<string, mixed>  $update
     */
    public function captureDestination(array $update): ?TelegramDestination
    {
        $chat = $this->extractChat($update);

        if (! is_array($chat) || empty($chat['id'])) {
            return null;
        }

        $chatId = (string) $chat['id'];
        $title = $this->resolveChatTitle($chat);
        $chatType = (string) ($chat['type'] ?? 'unknown');
        $source = $this->resolveSource($update);

        $destination = TelegramDestination::query()->firstOrNew([
            'chat_id' => $chatId,
        ]);

        $destination->fill([
            'label' => $destination->exists ? ($destination->label ?: $title) : $title,
            'notes' => $destination->notes ?: 'Được phát hiện tự động từ webhook Telegram.',
            'source' => $destination->exists ? $destination->source : 'telegram_webhook',
            'telegram_chat_type' => $chatType,
            'is_active' => $destination->exists ? $destination->is_active : false,
            'last_seen_at' => now(),
            'last_error_message' => null,
            'raw_update' => $update,
        ]);

        $destination->save();

        $this->operationsLog->info('Captured Telegram destination from webhook.', [
            'chat_id' => $destination->chat_id,
            'label' => $destination->label,
            'source' => $source,
            'telegram_chat_type' => $chatType,
            'is_active' => $destination->is_active,
        ]);

        return $destination;
    }

    /**
     * @param  array<string, mixed>  $update
     * @return array<string, mixed>|null
     */
    private function extractChat(array $update): ?array
    {
        $candidates = [
            $update['message']['chat'] ?? null,
            $update['edited_message']['chat'] ?? null,
            $update['channel_post']['chat'] ?? null,
            $update['edited_channel_post']['chat'] ?? null,
            $update['my_chat_member']['chat'] ?? null,
            $update['chat_member']['chat'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate) && ! empty($candidate['id'])) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $chat
     */
    private function resolveChatTitle(array $chat): string
    {
        if (! empty($chat['title']) && is_string($chat['title'])) {
            return $chat['title'];
        }

        $parts = array_filter([
            $chat['first_name'] ?? null,
            $chat['last_name'] ?? null,
        ]);

        if ($parts !== []) {
            return implode(' ', $parts);
        }

        if (! empty($chat['username']) && is_string($chat['username'])) {
            return '@'.$chat['username'];
        }

        return 'Chat '.$chat['id'];
    }

    /**
     * @param  array<string, mixed>  $update
     */
    private function resolveSource(array $update): string
    {
        foreach (['my_chat_member', 'chat_member', 'message', 'edited_message', 'channel_post', 'edited_channel_post'] as $source) {
            if (array_key_exists($source, $update)) {
                return $source;
            }
        }

        return 'telegram_webhook';
    }
}
