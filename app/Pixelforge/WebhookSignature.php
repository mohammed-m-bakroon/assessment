<?php

namespace App\Pixelforge;

/**
 * Verifies the Pixelforge-Signature header: "t=<unix time>,v1=<hex HMAC-SHA256>",
 * where the HMAC is computed over "<t>.<raw request body>" with the signing secret.
 */
class WebhookSignature
{
    public function __construct(
        private readonly ?string $secret,
        private readonly int $toleranceSeconds = 300,
    ) {}

    public function isValid(string $payload, ?string $header, ?int $now = null): bool
    {
        if (blank($this->secret) || blank($header)) {
            return false;
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);
            if ($key === 't' && ctype_digit((string) $value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && $value !== null) {
                $signatures[] = $value;
            }
        }

        // Reject replays of old (or far-future) deliveries.
        if ($timestamp === null || abs(($now ?? time()) - $timestamp) > $this->toleranceSeconds) {
            return false;
        }

        $expected = self::sign($payload, $timestamp, $this->secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    public static function sign(string $payload, int $timestamp, string $secret): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
    }
}
