<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin client for the Go network engine (backend/).
 *
 * PHP must never open a socket to a router. When the engine is enabled, admin
 * actions and scheduled sweeps go through this client, which calls the engine's
 * loopback-only API with a shared bearer token.
 */
class NetworkEngineClient
{
    public function enabled(): bool
    {
        return (bool) config('network-engine.enabled')
            && ! empty(config('network-engine.token'));
    }

    /**
     * Trigger the expiry sweep (realtime vouchers + optionally billing PPP).
     */
    public function runSweep(): bool
    {
        return $this->send('POST', '/api/v1/sweep') !== null;
    }

    /**
     * Suspend a PPP customer. Returns "soft", "fallback" or null on failure.
     */
    public function suspendPpp(string $router, string $username): ?string
    {
        $data = $this->send('POST', '/api/v1/ppp/suspend', [
            'router' => $router,
            'username' => $username,
        ]);

        return $data['mode'] ?? null;
    }

    public function restorePpp(string $router, string $username): bool
    {
        return $this->send('POST', '/api/v1/ppp/restore', [
            'router' => $router,
            'username' => $username,
        ]) !== null;
    }

    public function disconnectPpp(string $router, string $username): bool
    {
        return $this->send('POST', '/api/v1/ppp/disconnect', [
            'router' => $router,
            'username' => $username,
        ]) !== null;
    }

    public function disconnectHotspot(string $router, string $username): bool
    {
        return $this->send('POST', '/api/v1/hotspot/disconnect', [
            'router' => $router,
            'username' => $username,
        ]) !== null;
    }

    /**
     * Remove a hotspot voucher user and delete its row.
     */
    public function expireHotspot(string $router, string $username): bool
    {
        return $this->send('POST', '/api/v1/hotspot/expire', [
            'router' => $router,
            'username' => $username,
        ]) !== null;
    }

    /**
     * @return array<int, array{name: string, host: string, api_port: int, managed: bool}>
     */
    public function routers(): array
    {
        return $this->send('GET', '/api/v1/routers')['routers'] ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function send(string $method, string $path, array $payload = []): ?array
    {
        $url = config('network-engine.url').$path;

        try {
            $response = Http::withToken((string) config('network-engine.token'))
                ->acceptJson()
                ->timeout((int) config('network-engine.timeout', 10))
                ->send($method, $url, ['json' => $payload]);
        } catch (\Throwable $e) {
            Log::warning('Network engine unreachable', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Network engine rejected request', [
                'path' => $path,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        return $response->json();
    }
}
