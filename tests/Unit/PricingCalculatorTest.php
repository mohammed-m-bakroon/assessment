<?php

namespace Tests\Unit;

use App\Billing\Money;
use App\Billing\PricingCalculator;
use App\Billing\TokenUsage;
use PHPUnit\Framework\TestCase;

class PricingCalculatorTest extends TestCase
{
    private const RATES = [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];

    private function calculator(int $markup = 25): PricingCalculator
    {
        $config = require __DIR__.'/../../config/pixelforge.php';

        return new PricingCalculator($config['tokens'], self::RATES, $markup);
    }

    public function test_provider_cost_matches_the_usage_report_example_from_the_docs(): void
    {
        // GET /v1/usage example: 41 text, 0 image, 3300 output, 2950 reasoning => "0.169080000"
        $cost = $this->calculator()->providerCost(new TokenUsage(41, 0, 3300, 2950), self::RATES);

        $this->assertSame('0.169080000', Money::format($cost));
    }

    public function test_provider_cost_matches_the_succeeded_generation_example(): void
    {
        // 12×5 + 765×10 + 6450×40 + 3184×12.5 = 305,510 per 1M tokens => $0.30551
        $cost = $this->calculator()->providerCost(new TokenUsage(12, 765, 6450, 3184), self::RATES);

        $this->assertSame('0.305510000', Money::format($cost));
    }

    public function test_a_single_token_costs_fractions_of_a_cent_exactly(): void
    {
        $calc = $this->calculator();

        $this->assertSame(5_000, $calc->providerCost(new TokenUsage(1, 0, 0, 0), self::RATES));
        $this->assertSame(12_500, $calc->providerCost(new TokenUsage(0, 0, 0, 1), self::RATES));
        // 12,500 × 1.25 = 15,625 nano-dollars: still exact, no rounding.
        $this->assertSame(15_625, $calc->userCharge(12_500));
    }

    public function test_user_charge_adds_25_percent(): void
    {
        $this->assertSame(381_887_500, $this->calculator()->userCharge(305_510_000));
        $this->assertSame(0, $this->calculator()->userCharge(0));
    }

    public function test_fractional_nano_dollars_round_up_never_down(): void
    {
        // A hypothetical rate that doesn't divide evenly: 1 token at $0.000000001 per 1M.
        $cost = $this->calculator()->providerCost(new TokenUsage(1, 0, 0, 0), [...self::RATES, 'input_text_tokens' => '0.000000001']);
        $this->assertSame(1, $cost);

        // 1 nano × 1.25 = 1.25 nano => we charge 2, never less than our cost.
        $this->assertSame(2, $this->calculator()->userCharge(1));
    }

    public function test_estimate_matches_the_maximum_cost_example_from_the_docs(): void
    {
        // high, 1536x1024, 400-char prompt, 2 reference images => max $0.373875
        $estimate = $this->calculator()->estimate(str_repeat('a', 400), '1536x1024', 'high', 2);

        $this->assertSame(115, $estimate->maxTokens->inputTextTokens);
        $this->assertSame(1530, $estimate->maxTokens->inputImageTokens);
        $this->assertSame(6450, $estimate->maxTokens->outputTokens);
        $this->assertSame(8000, $estimate->maxTokens->reasoningTokens);
        $this->assertSame('0.373875000', Money::format($estimate->providerCostMax));
        $this->assertSame('0.467343750', Money::format($estimate->chargeMax));
    }

    public function test_estimate_minimum_uses_lowest_text_and_reasoning_tokens(): void
    {
        $estimate = $this->calculator()->estimate(str_repeat('a', 400), '1536x1024', 'high', 2);

        $this->assertSame(85, $estimate->minTokens->inputTextTokens);
        $this->assertSame(1000, $estimate->minTokens->reasoningTokens);
        // 85×5 + 1530×10 + 6450×40 + 1000×12.5 = 286,225 per 1M
        $this->assertSame('0.286225000', Money::format($estimate->providerCostMin));
    }

    public function test_estimate_counts_unicode_characters_not_bytes(): void
    {
        // 8 multi-byte characters => ceil(8 / 4) = 2 estimated text tokens
        $estimate = $this->calculator()->estimate('日本語の灯台です', '1024x1024', 'low', 0);

        $this->assertSame(1, $estimate->minTokens->inputTextTokens);  // floor(2 × 0.85)
        $this->assertSame(3, $estimate->maxTokens->inputTextTokens);  // ceil(2 × 1.15)
    }

    public function test_money_round_trips_pixelforge_decimal_strings(): void
    {
        $this->assertSame(169_080_000, Money::toNanos('0.169080000'));
        $this->assertSame(1_500_000_000, Money::toNanos('1.5'));
        $this->assertSame('12.500000000', Money::format(12_500_000_000));
        $this->assertSame('-0.000000001', Money::format(-1));
    }
}
