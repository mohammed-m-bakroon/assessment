<?php

namespace App\Pixelforge;

use RuntimeException;

/**
 * A request to Pixelforge that did not succeed.
 *
 * `code` is Pixelforge's error code (e.g. "concurrency_limit_exceeded"), or
 * "connection_error" when we never got a response.
 */
class PixelforgeException extends RuntimeException
{
    /** Errors where nothing was created and trying the same request again later can work. */
    private const RETRYABLE = [
        'rate_limit_exceeded',
        'concurrency_limit_exceeded',
        'overloaded',
        'connection_error',
        'server_error',
    ];

    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?int $status = null,
        public readonly ?int $retryAfter = null,
        public readonly array $body = [],
    ) {
        parent::__construct($message);
    }

    public function isRetryable(): bool
    {
        return in_array($this->errorCode, self::RETRYABLE, true);
    }
}
