<?php

namespace App\Services;

use App\Models\ProxySetting;
use Illuminate\Support\Facades\Http;

class ProxyRotationService
{
    public function getSettings(): ProxySetting
    {
        return ProxySetting::query()->firstOrCreate(
            ['scope' => 'worker'],
            [
                'is_enabled' => false,
                'provider' => 'proxyxoay.shop',
                'api_url' => 'https://proxyxoay.shop/api/get.php',
                'request_method' => 'GET',
                'carrier' => 'random',
                'province_code' => '0',
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateSettings(array $attributes): ProxySetting
    {
        $settings = $this->getSettings();

        $settings->fill([
            'is_enabled' => (bool) ($attributes['is_enabled'] ?? false),
            'provider' => (string) ($attributes['provider'] ?? 'proxyxoay.shop'),
            'api_url' => (string) ($attributes['api_url'] ?? 'https://proxyxoay.shop/api/get.php'),
            'request_method' => strtoupper((string) ($attributes['request_method'] ?? 'GET')),
            'api_key' => $attributes['api_key'] ?? null,
            'carrier' => (string) ($attributes['carrier'] ?? 'random'),
            'province_code' => (string) ($attributes['province_code'] ?? '0'),
            'whitelist' => $this->nullableString($attributes['whitelist'] ?? null),
            'notes' => $this->nullableString($attributes['notes'] ?? null),
        ]);

        $settings->save();

        return $settings->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function getCurrentWorkerProxy(): array
    {
        $settings = $this->getSettings();

        if (! $settings->is_enabled) {
            return [
                'enabled' => false,
                'provider' => $settings->provider,
                'message' => 'Rotating proxy is disabled.',
            ];
        }

        if (! $settings->last_proxy_http) {
            return [
                'enabled' => false,
                'provider' => $settings->provider,
                'message' => 'No resolved proxy is available yet.',
            ];
        }

        return $this->formatResolvedProxy($settings, $this->storedProxyPayload($settings), false);
    }

    /**
     * @return array<string, mixed>
     */
    public function refreshWorkerProxy(): array
    {
        $settings = $this->getSettings();

        if (! $settings->is_enabled) {
            return [
                'enabled' => false,
                'provider' => $settings->provider,
                'message' => 'Rotating proxy is disabled.',
            ];
        }

        if (! is_string($settings->api_key) || trim($settings->api_key) === '') {
            throw $this->storeFailure($settings, 'Proxy API key is missing.');
        }

        try {
            $response = $this->sendProviderRequest($settings);
            $payload = $response->json();

            if (! is_array($payload)) {
                throw new \RuntimeException('Proxy provider returned a non-JSON response.');
            }

            if ((int) ($payload['status'] ?? 0) !== 100) {
                $message = (string) ($payload['message'] ?? 'Proxy provider returned an unsuccessful status.');

                $settings->forceFill([
                    'last_error_message' => $message,
                ])->save();

                if ($settings->last_proxy_http) {
                    return array_merge(
                        $this->formatResolvedProxy($settings, $this->storedProxyPayload($settings), false),
                        [
                            'provider_message' => $message,
                            'refresh_skipped' => true,
                        ],
                    );
                }

                throw new \RuntimeException($message);
            }

            $resolvedProxy = $this->persistResolvedProxy($settings, $payload);

            return array_merge(
                $this->formatResolvedProxy($settings, $resolvedProxy, false),
                [
                    'provider_message' => (string) ($payload['message'] ?? ''),
                    'refresh_skipped' => false,
                ],
            );
        } catch (\Throwable $exception) {
            throw $this->storeFailure($settings, $exception->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function persistResolvedProxy(ProxySetting $settings, array $payload): array
    {
        $httpProxy = $this->parseProviderProxyString((string) ($payload['proxyhttp'] ?? ''));
        $socksProxy = $this->parseProviderProxyString((string) ($payload['proxysocks5'] ?? ''));
        $expiresInSeconds = $this->extractLifetimeSeconds((string) ($payload['message'] ?? ''));
        $resolvedAt = now();

        $settings->forceFill([
            'last_proxy_http' => $httpProxy['server'],
            'last_proxy_socks5' => $socksProxy['server'],
            'last_network' => $this->nullableString($payload['Nha Mang'] ?? null),
            'last_location' => $this->nullableString($payload['Vi Tri'] ?? null),
            'last_expires_in_seconds' => $expiresInSeconds,
            'last_resolved_at' => $resolvedAt,
            'last_error_message' => null,
            'last_provider_response' => $payload,
        ])->save();

        return [
            'server' => $httpProxy['server'],
            'username' => $httpProxy['username'],
            'password' => $httpProxy['password'],
            'socks5_server' => $socksProxy['server'],
            'network' => $settings->last_network,
            'location' => $settings->last_location,
            'expires_in_seconds' => $expiresInSeconds,
            'resolved_at' => $resolvedAt->toIso8601String(),
            'provider_payload' => $payload,
        ];
    }

    private function sendProviderRequest(ProxySetting $settings): \Illuminate\Http\Client\Response
    {
        $params = [
            'key' => (string) $settings->api_key,
            'nhamang' => $settings->carrier ?: 'random',
            'tinhthanh' => $settings->province_code ?: '0',
            'whitelist' => $settings->whitelist ?: '',
        ];

        $request = Http::acceptJson()
            ->timeout(15)
            ->retry(2, 500);

        $method = strtoupper((string) $settings->request_method);

        if ($method === 'POST') {
            return $request->asForm()->post($settings->api_url, $params)->throw();
        }

        return $request->get($settings->api_url, $params)->throw();
    }

    /**
     * @return array{server: ?string, username: ?string, password: ?string}
     */
    private function parseProviderProxyString(string $value): array
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return [
                'server' => null,
                'username' => null,
                'password' => null,
            ];
        }

        $parts = explode(':', $trimmed);
        $host = $parts[0] ?? '';
        $port = $parts[1] ?? '';

        if ($host === '' || $port === '') {
            throw new \RuntimeException("Invalid proxy string returned by provider: [{$value}]");
        }

        $username = $this->nullableString($parts[2] ?? null);
        $password = $this->nullableString($parts[3] ?? null);

        return [
            'server' => "http://{$host}:{$port}",
            'username' => $username,
            'password' => $password,
        ];
    }

    private function extractLifetimeSeconds(string $message): ?int
    {
        if (preg_match('/(\d+)s/i', $message, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    private function nullableString(mixed $value): ?string
    {
        $stringValue = trim((string) $value);

        return $stringValue === '' ? null : $stringValue;
    }

    /**
     * @return array<string, mixed>
     */
    private function storedProxyPayload(ProxySetting $settings): array
    {
        return [
            'server' => $settings->last_proxy_http,
            'username' => null,
            'password' => null,
            'socks5_server' => $settings->last_proxy_socks5,
            'network' => $settings->last_network,
            'location' => $settings->last_location,
            'expires_in_seconds' => $settings->last_expires_in_seconds,
            'resolved_at' => $settings->last_resolved_at?->toIso8601String(),
            'provider_payload' => $settings->last_provider_response,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function formatResolvedProxy(ProxySetting $settings, array $payload, bool $cacheHit): array
    {
        return [
            'enabled' => true,
            'provider' => $settings->provider,
            'server' => $payload['server'] ?? null,
            'username' => $payload['username'] ?? null,
            'password' => $payload['password'] ?? null,
            'socks5_server' => $payload['socks5_server'] ?? null,
            'network' => $payload['network'] ?? null,
            'location' => $payload['location'] ?? null,
            'expires_in_seconds' => $payload['expires_in_seconds'] ?? null,
            'resolved_at' => $payload['resolved_at'] ?? null,
            'provider_payload' => $payload['provider_payload'] ?? null,
            'cache_hit' => $cacheHit,
        ];
    }

    private function storeFailure(ProxySetting $settings, string $message): \RuntimeException
    {
        $settings->forceFill([
            'last_error_message' => $message,
            'last_resolved_at' => now(),
        ])->save();

        return new \RuntimeException($message);
    }
}
