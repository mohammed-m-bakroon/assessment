<?php

namespace Database\Factories;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Generation> */
class GenerationFactory extends Factory
{
    protected $model = Generation::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'idempotency_key' => (string) Str::uuid(),
            'status' => GenerationStatus::Pending,
            'prompt' => 'A lighthouse on a cliff at dawn',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
            'estimated_charge_min' => 60_000_000,
            'estimated_charge_max' => 90_000_000,
        ];
    }

    public function accepted(string $status = 'queued'): static
    {
        return $this->state(fn () => [
            'status' => GenerationStatus::from($status),
            'provider_id' => 'gen_'.Str::upper(Str::random(26)),
        ]);
    }
}
