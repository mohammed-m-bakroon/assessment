<?php

namespace App\Billing;

/** Up-front price range for a generation. All amounts in nano-dollars. */
final readonly class Estimate
{
    public function __construct(
        public TokenUsage $minTokens,
        public TokenUsage $maxTokens,
        public int $providerCostMin,
        public int $providerCostMax,
        public int $chargeMin,
        public int $chargeMax,
    ) {}

    public function toArray(): array
    {
        return [
            'charge_min' => Money::format($this->chargeMin),
            'charge_max' => Money::format($this->chargeMax),
            'tokens_min' => $this->minTokens->toArray(),
            'tokens_max' => $this->maxTokens->toArray(),
        ];
    }
}
