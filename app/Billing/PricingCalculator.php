<?php

namespace App\Billing;

/**
 * Turns token counts into money.
 *
 *   provider cost = Σ tokens × rate / 1,000,000          (rates are USD per 1M tokens)
 *   user charge   = provider cost × (100 + markup) / 100
 *
 * Everything is integer nano-dollar math. With Pixelforge's published rates both
 * results are always whole nano-dollars; if a future rate ever produced a
 * fraction of a nano-dollar we round the provider cost and the charge *up*,
 * so we never charge less than we pay.
 */
final class PricingCalculator
{
    public function __construct(
        private readonly array $tokenConfig,
        private readonly array $defaultRates,
        private readonly int $markupPercent,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            config('pixelforge.tokens'),
            config('pixelforge.rates'),
            (int) config('pixelforge.markup_percent'),
        );
    }

    /**
     * @param  array<string, string>  $rates  USD per 1M tokens, keyed like TokenUsage::TYPES
     */
    public function providerCost(TokenUsage $usage, array $rates): int
    {
        // tokens × (nano-dollars per 1M tokens), then divide by 1M once at the end.
        $total = 0;
        foreach ($usage->toArray() as $type => $tokens) {
            if (! isset($rates[$type])) {
                throw new \InvalidArgumentException("Missing rate for [{$type}].");
            }
            $total += $tokens * Money::toNanos($rates[$type]);
        }

        return self::ceilDiv($total, 1_000_000);
    }

    public function userCharge(int $providerCost): int
    {
        return self::ceilDiv($providerCost * (100 + $this->markupPercent), 100);
    }

    public function estimate(string $prompt, string $size, string $quality, int $referenceImages, ?array $rates = null): Estimate
    {
        $rates ??= $this->defaultRates;
        $cfg = $this->tokenConfig;

        $textEstimate = (int) ceil(mb_strlen($prompt) / $cfg['characters_per_text_token']);
        $variance = $cfg['text_token_variance_percent'];
        $imageTokens = $referenceImages * $cfg['per_reference_image'];
        $outputTokens = $cfg['output'][$quality][$size]
            ?? throw new \InvalidArgumentException("Unknown size/quality [{$size}/{$quality}].");
        $reasoning = $cfg['reasoning'][$quality];

        $min = new TokenUsage(
            intdiv($textEstimate * (100 - $variance), 100),
            $imageTokens,
            $outputTokens,
            $reasoning['min'],
        );
        $max = new TokenUsage(
            self::ceilDiv($textEstimate * (100 + $variance), 100),
            $imageTokens,
            $outputTokens,
            $reasoning['max'],
        );

        $providerMin = $this->providerCost($min, $rates);
        $providerMax = $this->providerCost($max, $rates);

        return new Estimate(
            $min,
            $max,
            $providerMin,
            $providerMax,
            $this->userCharge($providerMin),
            $this->userCharge($providerMax),
        );
    }

    private static function ceilDiv(int $a, int $b): int
    {
        return intdiv($a + $b - 1, $b);
    }
}
