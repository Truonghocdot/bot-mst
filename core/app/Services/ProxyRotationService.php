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
    public function resolveWorkerProxy(): array
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

                throw new \RuntimeException($message);
            }

            $httpProxy = $this->parseProviderProxyString((string) ($payload['proxyhttp'] ?? ''));
            $socksProxy = $this->parseProviderProxyString((string) ($payload['proxysocks5'] ?? ''));
            $expiresInSeconds = $this->extractLifetimeSeconds((string) ($payload['message'] ?? ''));

            $settings->forceFill([
                'last_proxy_http' => $httpProxy['server'],
                'last_proxy_socks5' => $socksProxy['server'],
                'last_network' => $this->nullableString($payload['Nha Mang'] ?? null),
                'last_location' => $this->nullableString($payload['Vi Tri'] ?? null),
                'last_expires_in_seconds' => $expiresInSeconds,
                'last_resolved_at' => now(),
                'last_error_message' => null,
                'last_provider_response' => $payload,
            ])->save();

            return [
                'enabled' => true,
                'provider' => $settings->provider,
                'server' => $httpProxy['server'],
                'username' => $httpProxy['username'],
                'password' => $httpProxy['password'],
                'socks5_server' => $socksProxy['server'],
                'network' => $settings->last_network,
                'location' => $settings->last_location,
                'expires_in_seconds' => $expiresInSeconds,
                'resolved_at' => now()->toIso8601String(),
                'provider_payload' => $payload,
            ];
        } catch (\Throwable $exception) {
            throw $this->storeFailure($settings, $exception->getMessage());
        }
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

    private function storeFailure(ProxySetting $settings, string $message): \RuntimeException
    {
        $settings->forceFill([
            'last_error_message' => $message,
            'last_resolved_at' => now(),
        ])->save();

        return new \RuntimeException($message);
    }
}
