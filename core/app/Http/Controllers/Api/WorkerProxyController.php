<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ProxyRotationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WorkerProxyController extends Controller
{
    public function show(Request $request, ProxyRotationService $proxyRotationService): JsonResponse
    {
        $expectedToken = (string) config('services.worker.token');
        $providedToken = (string) $request->bearerToken();

        if ($expectedToken === '' || ! hash_equals($expectedToken, $providedToken)) {
            return response()->json([
                'message' => 'Unauthorized worker token.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $proxy = $proxyRotationService->getCurrentWorkerProxy();

            return response()->json([
                'ok' => true,
                'enabled' => (bool) ($proxy['enabled'] ?? false),
                'proxy' => $proxy,
            ]);
        } catch (\Throwable $exception) {
            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }
}
