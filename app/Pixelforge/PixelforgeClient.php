<?php

namespace App\Pixelforge;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper around the Pixelforge REST API.
 *
 * It does not retry by itself: retries belong to the queued job, which can wait
 * without holding a worker and always resends the same Idempotency-Key.
 */
class PixelforgeClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
    ) {}

    /**
     * @param  array{prompt: string, size: string, quality: string, reference_images: array, metadata: array}  $params
     * @return array The generation object (status "queued", or later on an idempotent replay).
     */
    public function createGeneration(array $params, string $idempotencyKey): array
    {
        return $this->send(fn (PendingRequest $http) => $http
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('generations', $params));
    }

    public function getGeneration(string $id): array
    {
        return $this->send(fn (PendingRequest $http) => $http->get('generations/'.rawurlencode($id)));
    }

    /** Pixelforge's usage report for this API key (UTC dates, inclusive). */
    public function usage(string $startDate, string $endDate): array
    {
        return $this->send(fn (PendingRequest $http) => $http->get('usage', [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]));
    }

    private function send(callable $request): array
    {
        if (blank($this->apiKey)) {
            throw new PixelforgeException('missing_api_key', 'PIXELFORGE_API_KEY is not configured.');
        }

        $http = Http::baseUrl(rtrim($this->baseUrl, '/').'/')
            ->withToken($this->apiKey)
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(15);

        try {
            $response = $request($http);
        } catch (ConnectionException $e) {
            // Includes timeouts. The request may or may not have reached Pixelforge,
            // which is exactly why creates always carry an Idempotency-Key.
            throw new PixelforgeException('connection_error', $e->getMessage());
        }

        if ($response->successful()) {
            return $response->json();
        }

        throw $this->toException($response);
    }

    private function toException(Response $response): PixelforgeException
    {
        $error = $response->json('error') ?? [];
        $code = $error['code'] ?? ($response->serverError() ? 'server_error' : 'http_'.$response->status());
        $retryAfter = $response->header('Retry-After');

        return new PixelforgeException(
            $code,
            $error['message'] ?? "Pixelforge returned HTTP {$response->status()}.",
            $response->status(),
            is_numeric($retryAfter) ? (int) $retryAfter : null,
            $error,
        );
    }
}
