<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OperationsLogService;
use App\Services\TelegramWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TelegramWebhookController extends Controller
{
    public function store(Request $request, TelegramWebhookService $telegramWebhookService, OperationsLogService $operationsLog): JsonResponse
    {
        $expectedSecret = (string) config('services.telegram.webhook_secret');
        $providedSecret = (string) $request->header('X-Telegram-Bot-Api-Secret-Token');

        if ($expectedSecret !== '' && ! hash_equals($expectedSecret, $providedSecret)) {
            $operationsLog->warning('Rejected Telegram webhook due to invalid secret.', [
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'message' => 'Invalid Telegram webhook secret.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $destination = $telegramWebhookService->captureDestination($request->all());

        $operationsLog->info('Processed Telegram webhook update.', [
            'captured' => $destination !== null,
            'destination_id' => $destination?->id,
        ]);

        return response()->json([
            'ok' => true,
            'captured' => $destination !== null,
            'destination_id' => $destination?->id,
        ]);
    }
}
