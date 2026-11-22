<?php

namespace App\Billing;

final readonly class TokenUsage
{
    public const TYPES = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];

    public function __construct(
        public int $inputTextTokens,
        public int $inputImageTokens,
        public int $outputTokens,
        public int $reasoningTokens,
    ) {}

    /** Build from Pixelforge's `usage` object. */
    public static function fromArray(array $usage): self
    {
        return new self(
            (int) ($usage['input_text_tokens'] ?? 0),
            (int) ($usage['input_image_tokens'] ?? 0),
            (int) ($usage['output_tokens'] ?? 0),
            (int) ($usage['reasoning_tokens'] ?? 0),
        );
    }

    /** @return array<string, int> keyed like Pixelforge's usage / rates objects */
    public function toArray(): array
    {
        return [
            'input_text_tokens' => $this->inputTextTokens,
            'input_image_tokens' => $this->inputImageTokens,
            'output_tokens' => $this->outputTokens,
            'reasoning_tokens' => $this->reasoningTokens,
        ];
    }
}
