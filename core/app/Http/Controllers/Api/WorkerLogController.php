<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SafeLogWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WorkerLogController extends Controller
{
    public function store(Request $request, SafeLogWriter $safeLogWriter): JsonResponse
    {
        $expectedToken = (string) config('services.worker.token');
        $providedToken = (string) $request->bearerToken();

        if ($expectedToken === '' || ! hash_equals($expectedToken, $providedToken)) {
            return response()->json([
                'message' => 'Unauthorized worker token.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $validated = $request->validate([
            'entries' => ['required', 'array', 'min:1'],
            'entries.*.level' => ['required', 'string', 'in:debug,info,notice,warning,error,critical,alert,emergency'],
            'entries.*.message' => ['required', 'string', 'max:5000'],
            'entries.*.event' => ['nullable', 'string', 'max:255'],
            'entries.*.worker_name' => ['nullable', 'string', 'max:100'],
            'entries.*.source' => ['nullable', 'string', 'max:100'],
            'entries.*.timestamp' => ['nullable', 'string', 'max:100'],
            'entries.*.context' => ['nullable', 'array'],
        ]);

        foreach ($validated['entries'] as $entry) {
            $safeLogWriter->write(
                'worker_remote',
                $entry['level'],
                $entry['message'],
                [
                    'event' => $entry['event'] ?? null,
                    'worker_name' => $entry['worker_name'] ?? null,
                    'source' => $entry['source'] ?? null,
                    'timestamp' => $entry['timestamp'] ?? null,
                    'context' => $entry['context'] ?? [],
                ],
            );
        }

        return response()->json([
            'ok' => true,
            'accepted' => count($validated['entries']),
        ]);
    }
}
